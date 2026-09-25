<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Carbon\Carbon;

/**
 * MySQL-backed EventPublisher (spec 041).
 *
 * Chosen over a per-container temp file — the defect this spec fixes — because MySQL is the
 * one thing every container already shares (the same fact specs 033/037 already exploited for
 * the job queue, and spec 040 re-confirmed via variables_order=EGPCS: MySQL, not the
 * filesystem, is what every container agrees on). No new dependency, no Redis/RabbitMQ,
 * per docs/ROADMAP.md's explicit "do not introduce additional infrastructure unless required".
 */
class DatabaseEventPublisher implements EventPublisher
{
    public function publish(string $type, array $data = []): void
    {
        Event::create([
            'type'     => $type,
            'order_id' => $data['order_id'] ?? null,
            'payload'  => $data,
        ]);
    }

    /**
     * Delete events older than $days. These rows exist so a reconnecting client can catch up
     * on what it missed — not as an audit trail — so the retention window is deliberately
     * short (default set by Settings/env, spec 041).
     */
    public function pruneOlderThan(int $days): int
    {
        return Event::where('created_at', '<', Carbon::now()->subDays($days))->delete();
    }
}
