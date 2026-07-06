<?php

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use Illuminate\Database\Capsule\Manager as DB;

class OrderRepository
{
    /**
     * Return orders with their items (name, description from menu).
     */
    public function getOrdersByStatus(string $status): array
    {
        $query = Order::with(['items.menuItem']);
        if ($status === 'all') {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->status($status)->orderBy('created_at', 'asc');
        }

        $orders = $query->get();

        return $orders->map(function ($order) {
            $items = $order->items->map(function ($item) {
                return [
                    'name'        => $item->menuItem->name ?? 'Unknown',
                    'description' => $item->menuItem->description ?? '',
                    'quantity'    => (int)$item->quantity,
                    'notes'       => $item->notes ?? '',
                ];
            })->all();

            return [
                'id'           => $order->id,
                'table_number' => $order->table_number,
                'status'       => $order->status,
                'created_at'   => $order->created_at->toDateTimeString(),
                'updated_at'   => $order->updated_at->toDateTimeString(),
                'items'        => $items,
            ];
        })->all();
    }

    /**
     * Create an order and its items inside a transaction.
     */
    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = Order::create([
                'table_number' => $data['table'],
                'status'       => Order::STATUS_PENDING,
            ]);

            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id'    => $order->id,
                    'menu_item_id' => $item['id'],
                    'quantity'    => $item['quantity'],
                    'notes'       => $item['notes'] ?? '',
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

    public function getNextNumber(): int
    {
        $result = DB::select('SELECT COALESCE(MAX(CAST(table_number AS UNSIGNED)), 0) + 1 AS next FROM orders');
        return (int) $result[0]->next;
    }
}