<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\MenuItem;

class KitchenService
{
    public function getFoodCategorySummary(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        // business_date, not whereDate('created_at', ...) — sargable (spec 025).
        $pendingOrders = Order::where('status', 'pending')
            ->where('business_date', $date)
            ->with(['items.menuItem.components', 'items.components.menuItem'])
            ->get();

        $summary = [];

        foreach ($pendingOrders as $order) {
            foreach ($order->items as $orderItem) {
                $menuItem = $orderItem->menuItem;
                if (!$menuItem) continue;

                $qty = (int) $orderItem->quantity;

                if ($orderItem->components->isNotEmpty()) {
                    // Build-your-own dish (spec 030): count the add-ons chosen
                    // for this order item, not the menu item's fixed recipe.
                    foreach ($orderItem->components as $chosen) {
                        $component = $chosen->menuItem;
                        if (!$component || !$component->food_category) continue;
                        $key = $component->food_category . '::' . $component->id;
                        if (!isset($summary[$key])) {
                            $summary[$key] = [
                                'id'             => $component->id,
                                'name'           => $component->name,
                                'food_category'  => $component->food_category,
                                'total_quantity' => 0,
                            ];
                        }
                        $summary[$key]['total_quantity'] += $qty * (int) $chosen->quantity;
                    }
                } elseif ($menuItem->components->isNotEmpty()) {
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
