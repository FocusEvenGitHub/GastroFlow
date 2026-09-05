<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\OrderNumberCounter;
use Illuminate\Database\Capsule\Manager as DB;

class OrderRepository
{
    /**
     * Return orders with their items (name, description from menu).
     * If $date is provided, filter by that date; otherwise use current day.
     */
    public function getOrdersByStatus(string $status, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $query = Order::with(['items.menuItem.category'])->whereDate('created_at', $date);
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
                    'name'           => $item->menuItem->name ?? 'Unknown',
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
     */
    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
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

            foreach ($data['items'] as $item) {
                $menuItem = MenuItem::find($item['id']);
                $unitPrice = $menuItem ? (float) $menuItem->price : 0.0;
                $diningOption = $item['dining_option'] ?? 'local';
                $packagingCost = match ($diningOption) {
                    'viagem_simples' => 1.0 * (int) $item['quantity'],
                    'viagem_vip'     => 2.0 * (int) $item['quantity'],
                    default          => 0.0,
                };

                OrderItem::create([
                    'order_id'       => $order->id,
                    'menu_item_id'   => $item['id'],
                    'quantity'       => $item['quantity'],
                    'notes'          => $item['notes'] ?? '',
                    'dining_option'  => $diningOption,
                    'unit_price'     => $unitPrice,
                    'packaging_cost' => $packagingCost,
                ]);
            }

            return $order;
        });
    }

    /**
     * Mark order as done. Throws DomainException if the order is cancelled
     * (terminal — spec 020).
     */
    public function completeOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \DomainException('Não é possível concluir um pedido cancelado.');
        }
        $order->status = Order::STATUS_DONE;
        $order->save();
    }

    /**
     * Reopen a completed order (undo "dar baixa"). Throws DomainException if
     * the order is cancelled (terminal — spec 020).
     */
    public function uncompleteOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \DomainException('Não é possível reabrir um pedido cancelado.');
        }
        $order->status = Order::STATUS_PENDING;
        $order->save();
    }

    /**
     * Cancel an order (replaces the old hard-delete-as-cancellation
     * behavior — spec 020). Preserves the row for history/audit. Throws
     * DomainException if already cancelled.
     */
    public function cancelOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        if ($order->status === Order::STATUS_CANCELLED) {
            throw new \DomainException('Este pedido já está cancelado.');
        }
        $order->status = Order::STATUS_CANCELLED;
        $order->save();
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

        $counter->last_number += 1;
        $counter->save();

        return (string) $counter->last_number;
    }

    /**
     * Update editable order-level fields (senha / customer name).
     */
    public function updateOrder(int $id, array $data): void
    {
        $order = Order::findOrFail($id);

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
        Order::findOrFail($orderId);
        $menuItem = MenuItem::with('category')->findOrFail((int) $data['menu_item_id']);

        $quantity = (int) ($data['quantity'] ?? 1);
        $diningOption = $data['dining_option'] ?? 'local';
        $packagingCost = match ($diningOption) {
            'viagem_simples' => 1.0 * $quantity,
            'viagem_vip'     => 2.0 * $quantity,
            default          => 0.0,
        };

        $item = OrderItem::create([
            'order_id'       => $orderId,
            'menu_item_id'   => $menuItem->id,
            'quantity'       => $quantity,
            'notes'          => $data['notes'] ?? '',
            'dining_option'  => $diningOption,
            'unit_price'     => (float) $menuItem->price,
            'packaging_cost' => $packagingCost,
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
    }

    /**
     * Update quantity/notes of one existing order item.
     * Throws ModelNotFoundException if the item doesn't belong to $orderId.
     */
    public function updateOrderItem(int $orderId, int $itemId, array $data): void
    {
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
        $item = OrderItem::where('id', $itemId)->where('order_id', $orderId)->firstOrFail();

        $remaining = OrderItem::where('order_id', $orderId)->count();
        if ($remaining <= 1) {
            return false;
        }

        $item->delete();
        return true;
    }
}
