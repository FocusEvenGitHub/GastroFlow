<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Event;
use App\Services\DatabaseEventPublisher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

class DatabaseEventPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Mirrors common/migrations/017_realtime_events.sql. Keep in sync, or writes fail
        // with "no such column".
        Db::schema()->create('events', function ($table) {
            $table->bigIncrements('id');
            $table->string('type', 50);
            $table->unsignedInteger('order_id')->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /** AC2 */
    public function testPublishInsertsExactlyOneRow(): void
    {
        $publisher = new DatabaseEventPublisher();

        $publisher->publish('order.created', ['order_id' => 42]);

        $this->assertSame(1, Event::count());
        $event = Event::first();
        $this->assertSame('order.created', $event->type);
        $this->assertSame(42, $event->order_id);
        $this->assertSame(['order_id' => 42], $event->payload);
    }

    public function testPublishWithoutOrderIdLeavesItNull(): void
    {
        (new DatabaseEventPublisher())->publish('connected');

        $this->assertNull(Event::first()->order_id);
    }

    public function testIdsIncreaseInPublishOrder(): void
    {
        $publisher = new DatabaseEventPublisher();

        $publisher->publish('order.created', ['order_id' => 1]);
        $publisher->publish('order.updated', ['order_id' => 1]);
        $publisher->publish('order.completed', ['order_id' => 1]);

        $ids = Event::orderBy('id')->pluck('type', 'id')->all();
        $this->assertSame(
            ['order.created', 'order.updated', 'order.completed'],
            array_values($ids)
        );
    }

    /**
     * AC6 — the backdating uses the query builder, not Event::create(): Eloquent's own
     * auto-timestamp behavior overwrites an explicit created_at passed to create() with
     * "now" (confirmed before writing this test), which would silently make the test
     * meaningless. Db::table()->update() bypasses that, the same technique the integration
     * suite already uses to backdate available_at/reserved_until (specs 033/037).
     */
    public function testPruneOlderThanDeletesOnlyOldRows(): void
    {
        $old = Event::create(['type' => 'order.created', 'order_id' => 1]);
        $recent = Event::create(['type' => 'order.created', 'order_id' => 2]);

        Db::table('events')->where('id', $old->id)
            ->update(['created_at' => Carbon::now()->subDays(8)]);
        Db::table('events')->where('id', $recent->id)
            ->update(['created_at' => Carbon::now()->subDays(1)]);

        $deleted = (new DatabaseEventPublisher())->pruneOlderThan(7);

        $this->assertSame(1, $deleted);
        $this->assertNull(Event::find($old->id));
        $this->assertNotNull(Event::find($recent->id));
    }
}
