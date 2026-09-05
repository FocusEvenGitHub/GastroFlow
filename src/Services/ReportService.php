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
        // business_date, not DATE(orders.created_at)/whereDate (spec 025):
        // sargable, uses the existing index instead of a full scan.
        $rows = Order::selectRaw(
            "orders.business_date as date,
             COUNT(DISTINCT orders.id) as orders,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost), 0) as revenue,
             COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost) / NULLIF(COUNT(DISTINCT orders.id), 0), 0) as avg_ticket,
             COALESCE(SUM(order_items.quantity), 0) as items_sold"
        )
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', Order::STATUS_DONE)
            ->where('orders.business_date', '>=', $dateFrom)
            ->where('orders.business_date', '<=', $dateTo)
            ->groupBy('orders.business_date')
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
        // Grouped by menu_item_id (the stable identity), using the order-time
        // name snapshot (spec 023) — no menu_items join needed, and a rename
        // partway through the range doesn't split one item into two rows.
        //
        // The displayed name is the *most recently used* snapshot, not an
        // alphabetical MAX() (code review fix, spec 023: MAX() picked
        // whichever name sorted last lexicographically, which is unrelated to
        // recency — e.g. a rename to an alphabetically-earlier name would
        // have kept showing the stale one forever). GROUP_CONCAT orders by
        // id DESC (most recent order_items row first) and SUBSTRING_INDEX
        // takes just that first entry; this is safe even if the concatenated
        // list is truncated by group_concat_max_len, since MySQL truncates
        // from the end, never the beginning.
        $rows = OrderItem::selectRaw(
            "SUBSTRING_INDEX(
                 GROUP_CONCAT(order_items.item_name ORDER BY order_items.id DESC SEPARATOR '\u{7}'),
                 '\u{7}', 1
             ) as name,
             SUM(order_items.quantity) as total_qty,
             SUM(order_items.unit_price * order_items.quantity) as total_revenue"
        )
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_DONE)
            ->where('orders.business_date', '>=', $dateFrom)
            ->where('orders.business_date', '<=', $dateTo)
            ->groupBy('order_items.menu_item_id')
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
            ->where('orders.business_date', '>=', $dateFrom)
            ->where('orders.business_date', '<=', $dateTo)
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
     * Orders grouped by hour of day within a date range.
     * Returns array of { hour, orders }.
     */
    public function getOrdersByHour(string $dateFrom, string $dateTo): array
    {
        $rows = Order::selectRaw(
            "HOUR(orders.created_at) as hour,
             COUNT(*) as orders"
        )
            ->where('orders.business_date', '>=', $dateFrom)
            ->where('orders.business_date', '<=', $dateTo)
            ->groupBy(DB::raw('HOUR(orders.created_at)'))
            ->orderBy('hour')
            ->get()
            ->toArray();

        // Fill missing hours with 0
        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(int) $r['hour']] = (int) $r['orders'];
        }
        $result = [];
        for ($h = 0; $h <= 23; $h++) {
            $result[] = [
                'hour'   => $h,
                'orders' => $byHour[$h] ?? 0,
            ];
        }
        return $result;
    }

    /**
     * Average preparation time (in minutes) grouped by day.
     * Uses updated_at - created_at for completed (done) orders.
     * Returns { avg_minutes: float, by_day: array }.
     */
    public function getAvgPrepTime(string $dateFrom, string $dateTo): array
    {
        // Overall average for the period
        $avg = Order::selectRaw(
            "COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)), 0) as avg_minutes"
        )
            ->where('status', Order::STATUS_DONE)
            ->where('business_date', '>=', $dateFrom)
            ->where('business_date', '<=', $dateTo)
            ->first()
            ->toArray();

        $overallAvg = round((float) ($avg['avg_minutes'] ?? 0), 1);

        // By day within the period
        $byDay = Order::selectRaw(
            "business_date as date,
             COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)), 0) as avg_minutes,
             COUNT(*) as orders"
        )
            ->where('status', Order::STATUS_DONE)
            ->where('business_date', '>=', $dateFrom)
            ->where('business_date', '<=', $dateTo)
            ->groupBy('business_date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'avg_minutes' => $overallAvg,
            'by_day'      => array_map(fn($r) => [
                'date'        => $r['date'],
                'avg_minutes' => round((float) $r['avg_minutes'], 1),
                'orders'      => (int) $r['orders'],
            ], $byDay),
        ];
    }

    /**
     * Compare current period with the previous period of same length.
     * Returns { current, previous, change }.
     */
    public function getMonthlyComparison(string $dateFrom, string $dateTo): array
    {
        $daysDiff = (new \DateTime($dateFrom))->diff(new \DateTime($dateTo))->days + 1;
        $prevTo   = (new \DateTime($dateFrom))->modify('-1 day')->format('Y-m-d');
        $prevFrom = (new \DateTime($prevTo))->modify("-{$daysDiff} days")->modify('+1 day')->format('Y-m-d');

        $periods = [
            'current'  => [$dateFrom, $dateTo],
            'previous' => [$prevFrom, $prevTo],
        ];

        $result = [];
        foreach ($periods as $key => [$from, $to]) {
            $data = Order::selectRaw(
                "COUNT(DISTINCT orders.id) as orders,
                 COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost), 0) as revenue,
                 COALESCE(SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost) / NULLIF(COUNT(DISTINCT orders.id), 0), 0) as avg_ticket,
                 COALESCE(SUM(order_items.quantity), 0) as items_sold"
            )
                ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.status', Order::STATUS_DONE)
                ->where('orders.business_date', '>=', $from)
                ->where('orders.business_date', '<=', $to)
                ->first()
                ->toArray();

            $result[$key] = [
                'date_from'  => $from,
                'date_to'    => $to,
                'orders'     => (int) ($data['orders'] ?? 0),
                'revenue'    => (float) ($data['revenue'] ?? 0),
                'avg_ticket' => round((float) ($data['avg_ticket'] ?? 0), 2),
                'items_sold' => (int) ($data['items_sold'] ?? 0),
            ];
        }

        // Calculate changes
        $result['change'] = [
            'orders'     => $result['previous']['orders'] > 0
                ? round(($result['current']['orders'] - $result['previous']['orders']) / $result['previous']['orders'] * 100, 1)
                : 0,
            'revenue'    => $result['previous']['revenue'] > 0
                ? round(($result['current']['revenue'] - $result['previous']['revenue']) / $result['previous']['revenue'] * 100, 1)
                : 0,
            'avg_ticket' => $result['previous']['avg_ticket'] > 0
                ? round(($result['current']['avg_ticket'] - $result['previous']['avg_ticket']) / $result['previous']['avg_ticket'] * 100, 1)
                : 0,
            'items_sold' => $result['previous']['items_sold'] > 0
                ? round(($result['current']['items_sold'] - $result['previous']['items_sold']) / $result['previous']['items_sold'] * 100, 1)
                : 0,
        ];

        return $result;
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
            ->where('orders.business_date', $date)
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
