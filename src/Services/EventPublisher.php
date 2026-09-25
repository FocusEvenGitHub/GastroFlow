<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The abstraction docs/ROADMAP.md's "Realtime reliability" item names explicitly:
 * OrderService -> EventPublisher -> Community event implementation -> SSE.
 *
 * OrderService depends only on this interface, never on how an event actually reaches the
 * kitchen — that used to be a private file_put_contents() call inside OrderService itself
 * (spec 041), which is precisely what this interface exists to remove.
 */
interface EventPublisher
{
    /**
     * Publish one realtime event.
     *
     * @param array<string, mixed> $data Small, non-secret payload (e.g. ['order_id' => 42]).
     *                                    Never a credential or full order contents — the same
     *                                    discipline already applied to job/print logging.
     */
    public function publish(string $type, array $data = []): void;
}
