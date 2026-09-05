<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MenuItem;
use App\Repositories\OrderRepository;
use Illuminate\Database\Capsule\Manager as Db;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * Exercises OrderRepository's order_number allocation (spec 019) and status
 * transition guards (spec 020) against a real in-memory SQLite database —
 * sequential/single-process only, so the allocation tests prove correctness,
 * not true concurrency (see specs/019-*.md's Testing and validation strategy
 * for the separate manual concurrency check).
 */
class OrderRepositoryTest extends TestCase
{
    private OrderRepository $repo;
    private int $menuItemId;

    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('order_number_counters', function ($table) {
            $table->date('business_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Db::schema()->create('menu_items', function ($table) {
            $table->id();
            $table->unsignedInteger('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->float('price')->default(0);
            $table->boolean('available')->default(true);
        });

        Db::schema()->create('orders', function ($table) {
            $table->id();
            $table->string('order_number', 50);
            $table->date('business_date');
            $table->string('customer_name', 100)->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['business_date', 'order_number']);
        });

        Db::schema()->create('order_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('menu_item_id');
            $table->string('item_name', 100)->default('');
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->string('dining_option')->nullable();
            $table->float('unit_price')->default(0);
            $table->float('packaging_cost')->default(0);
        });

        $this->menuItemId = (int) MenuItem::create([
            'name' => 'Prato Teste', 'price' => 10.0, 'available' => true,
        ])->id;

        $this->repo = new OrderRepository();
    }

    private function orderData(?string $orderNumber = null): array
    {
        $data = ['items' => [['id' => $this->menuItemId, 'quantity' => 1]]];
        if ($orderNumber !== null) {
            $data['order_number'] = $orderNumber;
        }
        return $data;
    }

    public function testItemNameIsSnapshottedAtOrderCreationTime(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $storedItem = Db::table('order_items')->where('order_id', $order->id)->first();
        $this->assertSame('Prato Teste', $storedItem->item_name);

        // Renaming the menu item afterward must not change the snapshot.
        Db::table('menu_items')->where('id', $this->menuItemId)->update(['name' => 'Novo Nome']);
        $storedItem = Db::table('order_items')->where('order_id', $order->id)->first();
        $this->assertSame('Prato Teste', $storedItem->item_name);
    }

    public function testItemNameIsSnapshottedWhenAddedToAnExistingOrder(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->addOrderItem($order->id, ['menu_item_id' => $this->menuItemId]);

        $storedItem = Db::table('order_items')->where('order_id', $order->id)->orderByDesc('id')->first();
        $this->assertSame('Prato Teste', $storedItem->item_name);
    }

    public function testAutoAllocationIsSequentialForTheSameDay(): void
    {
        $first = $this->repo->createOrder($this->orderData());
        $second = $this->repo->createOrder($this->orderData());

        $this->assertSame('1', $first->order_number);
        $this->assertSame('2', $second->order_number);
        $this->assertSame($first->business_date, $second->business_date);
    }

    public function testManualOverrideNeverAdvancesTheAutoCounter(): void
    {
        // A large manual value must not push the auto-assign counter forward.
        $this->repo->createOrder($this->orderData('99'));
        $auto = $this->repo->createOrder($this->orderData());

        $this->assertSame('1', $auto->order_number);
    }

    public function testDuplicateManualOrderNumberOnSameDayFails(): void
    {
        $this->repo->createOrder($this->orderData('7'));

        $this->expectException(QueryException::class);
        $this->repo->createOrder($this->orderData('7'));
    }

    public function testGetNextNumberPreviewDoesNotConsumeAnything(): void
    {
        $first = $this->repo->getNextNumber();
        $second = $this->repo->getNextNumber();

        $this->assertSame($first, $second);

        $order = $this->repo->createOrder($this->orderData());
        $this->assertSame((string) $first, $order->order_number);
    }

    public function testCancelOrderFromPendingOrDone(): void
    {
        $pending = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($pending->id);
        $this->assertSame('cancelled', $pending->fresh()->status);

        $done = $this->repo->createOrder($this->orderData());
        $this->repo->completeOrder($done->id);
        $this->repo->cancelOrder($done->id);
        $this->assertSame('cancelled', $done->fresh()->status);
    }

    public function testCancellingAnAlreadyCancelledOrderThrows(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($order->id);

        $this->expectException(\DomainException::class);
        $this->repo->cancelOrder($order->id);
    }

    public function testCompletingACancelledOrderThrows(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($order->id);

        $this->expectException(\DomainException::class);
        $this->repo->completeOrder($order->id);
    }

    public function testUncompletingACancelledOrderThrows(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($order->id);

        $this->expectException(\DomainException::class);
        $this->repo->uncompleteOrder($order->id);
    }

    public function testCreateOrderWithNonexistentMenuItemThrowsAndPersistsNothing(): void
    {
        $nonexistentId = $this->menuItemId + 999;

        try {
            $this->repo->createOrder(['items' => [['id' => $nonexistentId, 'quantity' => 1]]]);
            $this->fail('Expected DomainException was not thrown.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame(0, Db::table('orders')->count());
        $this->assertSame(0, Db::table('order_items')->count());
        $this->assertSame(1, $this->repo->getNextNumber(), 'no order_number should have been consumed');
    }

    public function testCreateOrderWithUnavailableMenuItemThrows(): void
    {
        $unavailableId = (int) MenuItem::create([
            'name' => 'Fora de estoque', 'price' => 5.0, 'available' => false,
        ])->id;

        $this->expectException(\DomainException::class);
        $this->repo->createOrder(['items' => [['id' => $unavailableId, 'quantity' => 1]]]);
    }

    public function testAddOrderItemWithUnavailableMenuItemThrows(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $unavailableId = (int) MenuItem::create([
            'name' => 'Fora de estoque', 'price' => 5.0, 'available' => false,
        ])->id;

        $this->expectException(\DomainException::class);
        $this->repo->addOrderItem($order->id, ['menu_item_id' => $unavailableId]);
    }
}
