<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

class KitchenService
{
    /**
     * Returns total quantity of each ingredient needed for all pending orders.
     */
    public function getIngredientsSummary(): array
    {
        // Fetch pending orders with their items and menu items' ingredients
        $pendingOrders = Order::where('status', 'pending')
            ->with(['items.menuItem.ingredients'])
            ->get();

        $summary = [];

        foreach ($pendingOrders as $order) {
            foreach ($order->items as $orderItem) {
                $menuItem = $orderItem->menuItem;
                if (!$menuItem) continue;

                foreach ($menuItem->ingredients as $ingredient) {
                    $key = $ingredient->id;
                    $totalQty = $orderItem->quantity;

                    if (!isset($summary[$key])) {
                        $summary[$key] = [
                            'id'       => $ingredient->id,
                            'name'     => $ingredient->name,
                            'unit'     => $ingredient->unit,
                            'category' => $ingredient->category,
                            'total_quantity' => 0.0,
                        ];
                    }
                    $summary[$key]['total_quantity'] += $totalQty;
                }
            }
        }

        return array_values($summary); // re-index array
    }
}