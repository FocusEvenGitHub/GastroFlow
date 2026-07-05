<?php
namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class KitchenService
{
    public function getIngredientsSummary(): array
    {
        $pendingOrders = Order::where('status', 'pending')
            ->with(['items.menuItem.ingredients.category'])
            ->get();

        $summary = [];

        foreach ($pendingOrders as $order) {
            foreach ($order->items as $orderItem) {
                $menuItem = $orderItem->menuItem;
                if (!$menuItem) continue;

                foreach ($menuItem->ingredients as $ingredient) {
                    $key = $ingredient->id;
                    if (!isset($summary[$key])) {
                        $summary[$key] = [
                            'id'            => $ingredient->id,
                            'name'          => $ingredient->name,
                            'unit'          => $ingredient->unit,
                            'category'      => $ingredient->category ? $ingredient->category->name : 'Outros',
                            'total_quantity' => 0,
                        ];
                    }

                    $summary[$key]['total_quantity'] += $orderItem->quantity;
                }
            }
        }

        return array_values($summary);
    }
}