<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\MenuItem;

class KitchenService
{
    public function getFoodCategorySummary(): array
    {
        $today = date('Y-m-d');
        $pendingOrders = Order::where('status', 'pending')
            ->whereDate('created_at', $today)
            ->with(['items.menuItem.components'])
            ->get();

        $summary = [];

        foreach ($pendingOrders as $order) {
            foreach ($order->items as $orderItem) {
                $menuItem = $orderItem->menuItem;
                if (!$menuItem) continue;

                $qty = (int) $orderItem->quantity;

                if ($menuItem->components->isNotEmpty()) {
                    foreach ($menuItem->components as $component) {
                        if (!$component->food_category) continue;
                        $compQty = $qty * ($component->pivot->quantity ?? 1);
                        $key = $component->food_category . '::' . $component->id;
                        if (!isset($summary[$key])) {
                            $summary[$key] = [
                                'id'             => $component->id,
                                'name'           => $component->name,
                                'food_category'  => $component->food_category,
                                'total_quantity' => 0,
                            ];
                        }
                        $summary[$key]['total_quantity'] += $compQty;
                    }
                } else {
                    if (!$menuItem->food_category) continue;
                    $key = $menuItem->food_category . '::' . $menuItem->id;
                    if (!isset($summary[$key])) {
                        $summary[$key] = [
                            'id'             => $menuItem->id,
                            'name'           => $menuItem->name,
                            'food_category'  => $menuItem->food_category,
                            'total_quantity' => 0,
                        ];
                    }
                    $summary[$key]['total_quantity'] += $qty;
                }
            }
        }

        return array_values($summary);
    }
}
