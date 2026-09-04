<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
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
                'table_number'  => $order->table_number,
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

            $order = Order::create([
                'table_number'  => $data['table_number'],
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
     * Mark order as done.
     */
    public function completeOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->status = Order::STATUS_DONE;
        $order->save();
    }

    /**
     * Reopen a completed order (undo "dar baixa").
     */
    public function uncompleteOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->status = Order::STATUS_PENDING;
        $order->save();
    }

    /**
     * table_number is a customer-facing pickup ticket ("Senha"), not a physical restaurant
     * table; this MAX()+1 is not concurrency-safe — see docs/ROADMAP.md's v1.7.0 "Order number
     * integrity" for the planned order_number rework.
     */
    public function getNextNumber(): int
    {
        $result = DB::select('SELECT COALESCE(MAX(CAST(table_number AS UNSIGNED)), 0) + 1 AS next FROM orders');
        return (int) $result[0]->next;
    }

    /**
     * Update editable order-level fields (senha / customer name).
     */
    public function updateOrder(int $id, array $data): void
    {
        $order = Order::findOrFail($id);

        if (isset($data['table_number'])) {
            $order->table_number = $data['table_number'];
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

    /**
     * Hard-delete an order (order_items cascade via FK).
     */
    public function deleteOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->delete();
    }
}
