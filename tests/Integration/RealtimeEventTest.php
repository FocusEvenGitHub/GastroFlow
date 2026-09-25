<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Event;
use App\Services\DatabaseEventPublisher;

/**
 * Realtime events across real MySQL (spec 041).
 *
 * AC3 is the criterion this whole spec exists for: an event published from one connection
 * (simulating the print worker's container) must be visible to a different connection
 * (simulating the web container serving the SSE stream). The old sys_get_temp_dir()
 * mechanism failed exactly this — the two containers never shared a filesystem.
 */
class RealtimeEventTest extends IntegrationTestCase
{
    /**
     * @return array{0: string, 1: string} [stdout, stderr]
     */
    private function runPublisherProcess(string $type, ?int $orderId = null): array
    {
        $script = __DIR__ . '/bin/publish-one-event.php';
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $command = 'php ' . escapeshellarg($script)
            . ' ' . escapeshellarg($type)
            . ' ' . escapeshellarg((string) ($orderId ?? ''))
            . ' ' . escapeshellarg($this->testDatabase());

        $proc = proc_open($command, $descriptor, $pipes);
        $this->assertIsResource($proc, 'Could not start the publisher process');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [trim($stdout), trim($stderr)];
    }

    /**
     * AC3 — the criterion the spec exists for. Publish from a genuinely separate OS process
     * (a different connection, simulating the print-worker container) and read it back from
     * *this* process (simulating the stream, served by the web container) — the same query
     * shape stream.php uses (`id > :lastId`).
     */
    public function testEventPublishedFromAnotherProcessIsVisibleHere(): void
    {
        $before = (int) (Event::max('id') ?? 0);

        [$stdout, $stderr] = $this->runPublisherProcess('order.created', 123);

        $this->assertSame('', $stderr, "The publisher process failed: {$stderr}");
        $this->assertNotSame('', $stdout, 'The publisher process printed no event id');

        $publishedId = (int) $stdout;
        $this->assertGreaterThan($before, $publishedId);

        // Exactly the query stream.php runs: WHERE id > :lastId.
        $visible = Event::where('id', '>', $before)->orderBy('id')->get();

        $this->assertCount(1, $visible, 'The cross-process event must be visible to this connection');
        $this->assertSame('order.created', $visible->first()->type);
        $this->assertSame(123, $visible->first()->order_id);
        $this->assertSame($publishedId, $visible->first()->id);
    }

    /** AC4 — a burst of events published in quick succession, none skipped, order preserved. */
    public function testBurstOfEventsAllArriveInOrder(): void
    {
        $before = (int) (Event::max('id') ?? 0);
        $publisher = new DatabaseEventPublisher();

        for ($i = 1; $i <= 10; $i++) {
            $publisher->publish('order.updated', ['order_id' => $i]);
        }

        $visible = Event::where('id', '>', $before)->orderBy('id')->get();

        $this->assertCount(10, $visible, 'A burst must not collapse to fewer than every event published');
        $this->assertSame(range(1, 10), $visible->pluck('order_id')->all());
    }

    /** AC5 — the Last-Event-ID query shape: only events strictly after the given id. */
    public function testQueryHonorsLastEventIdCursor(): void
    {
        $publisher = new DatabaseEventPublisher();
        $publisher->publish('order.created', ['order_id' => 1]);
        $cursor = (int) Event::max('id');
        $publisher->publish('order.updated', ['order_id' => 1]);
        $publisher->publish('order.completed', ['order_id' => 1]);

        $resumed = Event::where('id', '>', $cursor)->orderBy('id')->get();

        $this->assertCount(2, $resumed);
        $this->assertSame(['order.updated', 'order.completed'], $resumed->pluck('type')->all());
    }

    /** AC6 — pruning against real MySQL, mirroring JobService::pruneCompleted()'s test shape. */
    public function testPruneOlderThanAgainstRealDatabase(): void
    {
        $publisher = new DatabaseEventPublisher();
        $publisher->publish('order.created', ['order_id' => 1]);
        $old = Event::latest('id')->first();

        \Illuminate\Database\Capsule\Manager::table('events')
            ->where('id', $old->id)
            ->update(['created_at' => \Carbon\Carbon::now()->subDays(8)]);

        $publisher->publish('order.created', ['order_id' => 2]);

        $deleted = $publisher->pruneOlderThan(7);

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull(Event::find($old->id));
    }
}
