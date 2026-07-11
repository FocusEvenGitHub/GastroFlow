<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use Illuminate\Database\Capsule\Manager as DB;

class ReportService
{
    /**
     * Sales grouped by day within a date range.
     * Returns array of { date, orders, revenue, avg_ticket }.
     */
    public function getSalesByDay(string $dateFrom, string $dateTo): array
    {
        $rows = Order::selectRaw(
            "DATE(orders.created_at) as date,
             COUNT(DISTINCT orders.id) as orders,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost), 0) as revenue,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost) / NULLIF(COUNT(DISTINCT orders.id), 0), 0) as avg_ticket,
             COALESCE(SUM(order_items.quantity), 0) as items_sold"
        )
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', Order::STATUS_DONE)
            ->whereDate('orders.created_at', '>=', $dateFrom)
            ->whereDate('orders.created_at', '<=', $dateTo)
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->orderBy('date')
            ->get()
            ->toArray();

        // Cast numeric fields
        return array_map(fn($r) => [
            'date'       => $r['date'],
            'orders'     => (int) $r['orders'],
            'revenue'    => (float) $r['revenue'],
            'avg_ticket' => round((float) $r['avg_ticket'], 2),
            'items_sold' => (int) ($r['items_sold'] ?? 0),
        ], $rows);
    }

    /**
     * Most sold items within a date range.
     * Returns array of { name, total_qty, total_revenue }.
     */
    public function getTopItems(string $dateFrom, string $dateTo, int $limit = 10): array
    {
        $rows = OrderItem::selectRaw(
            "menu_items.name,
             SUM(order_items.quantity) as total_qty,
             SUM(order_items.unit_price * order_items.quantity) as total_revenue"
        )
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_DONE)
            ->whereDate('orders.created_at', '>=', $dateFrom)
            ->whereDate('orders.created_at', '<=', $dateTo)
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get()
            ->toArray();

        return array_map(fn($r) => [
            'name'          => $r['name'],
            'total_qty'     => (int) $r['total_qty'],
            'total_revenue' => (float) $r['total_revenue'],
        ], $rows);
    }

    /**
     * Distribution by dining option within a date range.
     * Returns array of { dining_option, total_qty, total_packaging }.
     */
    public function getDiningOptionDistribution(string $dateFrom, string $dateTo): array
    {
        $rows = OrderItem::selectRaw(
            "order_items.dining_option,
             SUM(order_items.quantity) as total_qty,
             SUM(order_items.packaging_cost) as total_packaging"
        )
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_DONE)
            ->whereDate('orders.created_at', '>=', $dateFrom)
            ->whereDate('orders.created_at', '<=', $dateTo)
            ->groupBy('order_items.dining_option')
            ->orderByDesc('total_qty')
            ->get()
            ->toArray();

        return array_map(fn($r) => [
            'dining_option'  => $r['dining_option'],
            'total_qty'      => (int) $r['total_qty'],
            'total_packaging' => (float) $r['total_packaging'],
        ], $rows);
    }

    /**
     * Quick summary for a single date (default: today).
     * Returns { date, orders, revenue, avg_ticket, items_sold }.
     */
    public function getDailySummary(string $date): array
    {
        $data = Order::selectRaw(
            "COUNT(DISTINCT orders.id) as orders,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost), 0) as revenue,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost) / NULLIF(COUNT(DISTINCT orders.id), 0), 0) as avg_ticket,
             COALESCE(SUM(order_items.quantity), 0) as items_sold"
        )
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', Order::STATUS_DONE)
            ->whereDate('orders.created_at', $date)
            ->first()
            ->toArray();

        return [
            'date'       => $date,
            'orders'     => (int) ($data['orders'] ?? 0),
            'revenue'    => (float) ($data['revenue'] ?? 0),
            'avg_ticket' => round((float) ($data['avg_ticket'] ?? 0), 2),
            'items_sold' => (int) ($data['items_sold'] ?? 0),
        ];
    }
}
