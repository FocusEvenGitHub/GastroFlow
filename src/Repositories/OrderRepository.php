<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\OrderNumberCounter;
use App\Money;
use App\OrderCancelledException;
use Illuminate\Database\Capsule\Manager as DB;

class OrderRepository
{
    /**
     * Return orders with their items. Item name is the order-time snapshot
     * (spec 023); description/category_name are still live-joined from menu.
     * If $date is provided, filter by that date; otherwise use current day.
     */
    public function getOrdersByStatus(string $status, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        // business_date, not whereDate('created_at', ...) (spec 025): the
        // latter wraps an indexed column in DATE(...), which MySQL can't use
        // an index to satisfy — business_date is the same concept as a plain,
        // already-indexed column (spec 019's uniq_order_number_per_day).
        $query = Order::with(['items.menuItem.category'])->where('business_date', $date);
        if ($status === 'all') {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->status($status)->orderBy('created_at', 'asc');
        }

        $orders = $query->get();

        return $orders->map(function ($order) {
            $items = $order->items->map(function ($item) {
                return [
                    'item_id'        => $item->id,
                    'name'           => $item->item_name,
                    'description'    => $item->menuItem->description ?? '',
                    'quantity'       => (int)$item->quantity,
                    'notes'          => $item->notes ?? '',
                    'dining_option'  => $item->dining_option ?? 'local',
                    'unit_price'     => (float) $item->unit_price,
                    'packaging_cost' => (float) $item->packaging_cost,
                    'category_name'  => $item->menuItem->category->name ?? null,
                ];
            })->all();

            return [
                'id'            => $order->id,
                'order_number'  => $order->order_number,
                'customer_name' => $order->customer_name,
                'status'        => $order->status,
                'created_at'    => $order->created_at->toDateTimeString(),
                'updated_at'    => $order->updated_at->toDateTimeString(),
                'items'         => $items,
            ];
        })->all();
    }

    /**
     * Create an order and its items inside a transaction.
     *
     * Every item's menu item is resolved and validated (exists, available)
     * *before* the Order row is created or an order_number is allocated —
     * spec 022: an invalid/unavailable item must reject the whole order, not
     * silently become a zero-price line, and a doomed request shouldn't
     * consume a ticket number.
     */
    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            // Locked (spec 023 code review): without this, a concurrent
            // availability toggle in the gap between this check and the
            // OrderItem inserts below (same transaction, but an unlocked read)
            // could let an order reference an item that became unavailable a
            // moment later. The lock blocks that concurrent update until this
            // transaction commits or rolls back.
            //
            // Batched into one whereIn()+lockForUpdate() query, sorted by id,
            // instead of one locked query per item (code review fix): besides
            // avoiding N+1 queries, locking in a consistent (ascending id)
            // order across every concurrent order is what actually prevents a
            // classic lock-ordering deadlock — two orders locking the same
            // two menu items in opposite sequence (e.g. [20,10] vs [10,20])
            // would otherwise each hold one lock while waiting on the other.
            $itemIds = array_values(array_unique(array_map(fn($item) => (int) $item['id'], $data['items'])));
            sort($itemIds);
            $menuItemsById = MenuItem::whereIn('id', $itemIds)->lockForUpdate()->orderBy('id')->get()->keyBy('id');

            $resolvedItems = [];
            foreach ($data['items'] as $item) {
                $menuItem = $menuItemsById->get((int) $item['id']);
                if (!$menuItem) {
                    throw new \DomainException("Item de cardápio não encontrado: #{$item['id']}");
                }
                if (!$menuItem->available) {
                    throw new \DomainException("Item indisponível: {$menuItem->name}");
                }
                $resolvedItems[] = ['input' => $item, 'menuItem' => $menuItem];
            }

            $customerName = null;
            if (isset($data['customer_name']) && is_string($data['customer_name'])) {
                $trimmed = trim($data['customer_name']);
                if ($trimmed !== '') {
                    $customerName = $trimmed;
                }
            }

            $businessDate = date('Y-m-d');
            $manualOrderNumber = isset($data['order_number']) && $data['order_number'] !== ''
                ? (string) $data['order_number']
                : null;
            $orderNumber = $manualOrderNumber ?? $this->allocateNextNumber($businessDate);

            $order = Order::create([
                'order_number'  => $orderNumber,
                'business_date' => $businessDate,
                'customer_name' => $customerName,
                'status'        => Order::STATUS_PENDING,
            ]);

            foreach ($resolvedItems as $resolved) {
                $item = $resolved['input'];
                $menuItem = $resolved['menuItem'];
                $unitPrice = Money::fromReais($menuItem->price);
                $diningOption = $item['dining_option'] ?? 'local';
                $quantity = (int) $item['quantity'];
                $packagingCost = self::packagingCostFor($diningOption, $quantity);

                OrderItem::create([
                    'order_id'       => $order->id,
                    'menu_item_id'   => $item['id'],
                    'item_name'      => $menuItem->name,
                    'quantity'       => $item['quantity'],
                    'notes'          => $item['notes'] ?? '',
                    'dining_option'  => $diningOption,
                    'unit_price'     => $unitPrice->toReais(),
                    'packaging_cost' => $packagingCost->toReais(),
                ]);
            }

            return $order;
        });
    }

    /**
     * Mark order as done. Throws DomainException if the order is cancelled
     * (terminal — spec 020). Row-locked (spec 023 code review) so a
     * concurrent cancel/complete/uncomplete on the same order can't read a
     * stale pre-transition status and silently overwrite the other's result.
     */
    public function completeOrder(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->status === Order::STATUS_CANCELLED) {
                throw new \DomainException('Não é possível concluir um pedido cancelado.');
            }
            $order->status = Order::STATUS_DONE;
            $order->save();
        });
    }

    /**
     * Reopen a completed order (undo "dar baixa"). Throws DomainException if
     * the order is cancelled (terminal — spec 020). Row-locked — see
     * completeOrder().
     */
    public function uncompleteOrder(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->status === Order::STATUS_CANCELLED) {
                throw new \DomainException('Não é possível reabrir um pedido cancelado.');
            }
            $order->status = Order::STATUS_PENDING;
            $order->save();
        });
    }

    /**
     * Cancel an order (replaces the old hard-delete-as-cancellation
     * behavior — spec 020). Preserves the row for history/audit. Throws
     * DomainException if already cancelled. Row-locked — see completeOrder().
     */
    public function cancelOrder(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::where('id', $id)->lockForUpdate()->firstOrFail();
            if ($order->status === Order::STATUS_CANCELLED) {
                throw new \DomainException('Este pedido já está cancelado.');
            }
            $order->status = Order::STATUS_CANCELLED;
            $order->save();
        });
    }

    /**
     * Non-consuming preview of what the next auto-assigned order_number for
     * today would be. Does not lock or increment order_number_counters —
     * actual allocation only happens inside createOrder()'s transaction, so
     * calling this repeatedly without creating an order returns the same
     * value each time (spec 019).
     */
    public function getNextNumber(): int
    {
        $counter = OrderNumberCounter::where('business_date', date('Y-m-d'))->first();
        return ($counter ? $counter->last_number : 0) + 1;
    }

    /**
     * Atomically allocate the next order_number for $businessDate, under a
     * row lock on its order_number_counters entry. Never called for a
     * manually-overridden order_number — automatic generation is
     * concurrency-safe and independent of manual overrides (spec 019).
     *
     * Locks the row directly instead of unconditionally upserting first:
     * an unconditional INSERT ... IGNORE immediately followed by SELECT ...
     * FOR UPDATE on the same, already-existing row (the common case, every
     * day after the first order) makes every concurrent request take a
     * shared lock (the IGNORE's duplicate check) and then try to upgrade to
     * exclusive — a lock-upgrade cycle that MySQL reports as a deadlock
     * under real concurrency (confirmed while validating this spec).
     */
    private function allocateNextNumber(string $businessDate): string
    {
        $counter = OrderNumberCounter::where('business_date', $businessDate)->lockForUpdate()->first();

        if (!$counter) {
            // First order of a new business_date: its counter row doesn't exist
            // yet. A concurrent request racing to create the same row gets a
            // duplicate-key error here, not a deadlock (neither side holds a
            // lock on an existing row yet) — safe to ignore and re-read below.
            try {
                OrderNumberCounter::create(['business_date' => $businessDate, 'last_number' => 0]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
            $counter = OrderNumberCounter::where('business_date', $businessDate)->lockForUpdate()->first();
        }

        // A manually-typed order_number can "poison" a future auto-assigned
        // value (code review fix): without this check, if a manual entry
        // happens to match counter+1, every subsequent auto-assign attempt
        // recomputes that same doomed value, collides, and rolls back —
        // including the counter increment itself — forever. Reproduced live
        // during review: two auto-assign attempts after a colliding manual
        // entry both failed identically because the counter never advanced.
        // Skipping past any already-taken number (still under this row's
        // lock, so safe against concurrent auto-assigns) closes that.
        do {
            $counter->last_number += 1;
            $candidate = (string) $counter->last_number;
            $taken = Order::where('business_date', $businessDate)
                ->where('order_number', $candidate)
                ->exists();
        } while ($taken && $counter->last_number < 1_000_000);

        $counter->save();

        return $candidate;
    }

    /**
     * Update editable order-level fields (senha / customer name). Throws
     * OrderCancelledException if the order is cancelled — a terminal,
     * audit-preserved record (spec 020) shouldn't still be silently editable
     * (code review fix).
     */
    public function updateOrder(int $id, array $data): void
    {
        $order = Order::findOrFail($id);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new OrderCancelledException('Não é possível editar um pedido cancelado.');
        }

        if (isset($data['order_number'])) {
            $order->order_number = $data['order_number'];
        }
        if (array_key_exists('customer_name', $data)) {
            $trimmed = is_string($data['customer_name']) ? trim($data['customer_name']) : null;
            $order->customer_name = ($trimmed !== '' ? $trimmed : null);
        }

        $order->save();
    }

    /**
     * Add a new item to an existing order (kitchen-side correction, e.g. a
     * cashier forgot an add-on). Returns the item in the same shape used by
     * getOrdersByStatus(), so the frontend can append it directly.
     */
    public function addOrderItem(int $orderId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = Order::findOrFail($orderId);
            if ($order->status === Order::STATUS_CANCELLED) {
                throw new OrderCancelledException('Não é possível adicionar itens a um pedido cancelado.');
            }
            // Locked (spec 023 code review) — same TOCTOU concern as
            // createOrder(): without it, a concurrent availability toggle
            // between this check and the OrderItem insert below could let an
            // item be added right after it became unavailable.
            $menuItem = MenuItem::with('category')
                ->where('id', (int) $data['menu_item_id'])
                ->lockForUpdate()
                ->firstOrFail();
            if (!$menuItem->available) {
                throw new \DomainException("Item indisponível: {$menuItem->name}");
            }

            $quantity = (int) ($data['quantity'] ?? 1);
            $diningOption = $data['dining_option'] ?? 'local';
            $packagingCost = self::packagingCostFor($diningOption, $quantity);

            $item = OrderItem::create([
                'order_id'       => $orderId,
                'menu_item_id'   => $menuItem->id,
                'item_name'      => $menuItem->name,
                'quantity'       => $quantity,
                'notes'          => $data['notes'] ?? '',
                'dining_option'  => $diningOption,
                'unit_price'     => Money::fromReais($menuItem->price)->toReais(),
                'packaging_cost' => $packagingCost->toReais(),
            ]);

            return [
                'item_id'        => $item->id,
                'name'           => $menuItem->name,
                'description'    => $menuItem->description,
                'quantity'       => (int) $item->quantity,
                'notes'          => $item->notes ?? '',
                'dining_option'  => $item->dining_option,
                'unit_price'     => (float) $item->unit_price,
                'packaging_cost' => (float) $item->packaging_cost,
                'category_name'  => $menuItem->category->name ?? null,
            ];
        });
    }

    /**
     * Update quantity/notes of one existing order item.
     * Throws ModelNotFoundException if the item doesn't belong to $orderId.
     */
    public function updateOrderItem(int $orderId, int $itemId, array $data): void
    {
        $order = Order::findOrFail($orderId);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new OrderCancelledException('Não é possível editar itens de um pedido cancelado.');
        }
        $item = OrderItem::where('id', $itemId)->where('order_id', $orderId)->firstOrFail();

        if (isset($data['quantity'])) {
            $item->quantity = (int) $data['quantity'];
        }
        if (array_key_exists('notes', $data)) {
            $item->notes = $data['notes'] ?? '';
        }

        $item->save();
    }

    /**
     * Remove a single item from an order.
     * Returns false without deleting anything if it's the order's last item.
     */
    public function removeOrderItem(int $orderId, int $itemId): bool
    {
        $order = Order::findOrFail($orderId);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new OrderCancelledException('Não é possível remover itens de um pedido cancelado.');
        }
        $item = OrderItem::where('id', $itemId)->where('order_id', $orderId)->firstOrFail();

        $remaining = OrderItem::where('order_id', $orderId)->count();
        if ($remaining <= 1) {
            return false;
        }

        $item->delete();
        return true;
    }

    /**
     * Packaging fee for one order item, by dining option. Extracted (code
     * review fix) from two identical copies in createOrder() and
     * addOrderItem() — a future fee change only needs to happen once.
     */
    private static function packagingCostFor(string $diningOption, int $quantity): Money
    {
        return match ($diningOption) {
            'viagem_simples' => Money::fromReais(1.0)->multipliedBy($quantity),
            'viagem_vip'     => Money::fromReais(2.0)->multipliedBy($quantity),
            default          => Money::zero(),
        };
    }
}
