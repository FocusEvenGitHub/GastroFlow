<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MenuItem;
use App\OrderCancelledException;
use App\Repositories\OrderRepository;
use App\Services\PricingService;
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
            $table->boolean('is_customizable')->default(false);
            $table->string('food_category', 30)->nullable();
            $table->string('packaging_option', 20)->nullable()->unique();
        });

        Db::schema()->create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('food');
        });

        Db::schema()->create('order_item_components', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedInteger('menu_item_id');
            $table->string('item_name', 100)->default('');
            $table->unsignedInteger('quantity')->default(1);
            $table->float('unit_price')->default(0);
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

        $this->repo = new OrderRepository(new PricingService());
    }

    private function orderData(?string $orderNumber = null): array
    {
        $data = ['items' => [['id' => $this->menuItemId, 'quantity' => 1]]];
        if ($orderNumber !== null) {
            $data['order_number'] = $orderNumber;
        }
        return $data;
    }

    private function linkPackaging(string $option, float $price): void
    {
        Db::table('menu_items')->insert(['name' => 'Embalagem ' . $option, 'price' => $price, 'packaging_option' => $option]);
    }

    private function lastItem(int $orderId): object
    {
        return Db::table('order_items')->where('order_id', $orderId)->orderByDesc('id')->first();
    }

    /** Spec 050 — the fee follows the linked menu item's current price. */
    public function testPackagingFeeUsesTheLinkedMenuItemPrice(): void
    {
        $this->linkPackaging('viagem_vip', 3.0);

        $order = $this->repo->createOrder(['items' => [['id' => $this->menuItemId, 'quantity' => 2, 'dining_option' => 'viagem_vip']]]);

        $this->assertEqualsWithDelta(6.0, (float) $this->lastItem($order->id)->packaging_cost, 0.001);
    }

    public function testPackagingFeeFallsBackToTheDefaultWhenNoItemIsLinked(): void
    {
        $order = $this->repo->createOrder(['items' => [['id' => $this->menuItemId, 'quantity' => 3, 'dining_option' => 'viagem_simples']]]);

        $this->assertEqualsWithDelta(3.0, (float) $this->lastItem($order->id)->packaging_cost, 0.001);
    }

    public function testKitchenEditsUseTheLinkedMenuItemPrice(): void
    {
        $this->linkPackaging('viagem_simples', 1.5);
        $order = $this->repo->createOrder(['items' => [['id' => $this->menuItemId, 'quantity' => 2]]]);
        $itemId = (int) $this->lastItem($order->id)->id;

        $this->repo->updateOrderItem($order->id, $itemId, ['dining_option' => 'viagem_simples']);
        $this->assertEqualsWithDelta(3.0, (float) $this->lastItem($order->id)->packaging_cost, 0.001);

        $this->repo->addOrderItem($order->id, ['menu_item_id' => $this->menuItemId, 'quantity' => 1, 'dining_option' => 'viagem_simples']);
        $this->assertEqualsWithDelta(1.5, (float) $this->lastItem($order->id)->packaging_cost, 0.001);
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

    public function testManualOverrideMatchingTheNextAutoNumberDoesNotJamAllocation(): void
    {
        // Code review regression (reproduced live against the real dev DB
        // before this fix): a manual order_number equal to counter+1 used to
        // make every subsequent auto-assign recompute the same doomed value
        // forever, since the whole transaction (including the counter
        // increment) rolled back on the collision.
        $this->repo->createOrder($this->orderData('1')); // manually "poison" the next auto value

        $first = $this->repo->createOrder($this->orderData());
        $second = $this->repo->createOrder($this->orderData());

        $this->assertSame('2', $first->order_number);
        $this->assertSame('3', $second->order_number);
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

    public function testGetOrdersByStatusFiltersByBusinessDateNotAdjacentDay(): void
    {
        // Spec 025: filtering by business_date (sargable), not
        // whereDate('created_at', ...) — this proves the filter logic itself
        // is still correct after that switch, not the query plan (which
        // needs a real MySQL EXPLAIN — see specs/025-*.md's Validation evidence).
        $today = $this->repo->createOrder($this->orderData());

        $yesterday = (new \DateTime('yesterday'))->format('Y-m-d');
        Db::table('orders')->insert([
            'order_number'  => '1',
            'business_date' => $yesterday,
            'customer_name' => null,
            'status'        => 'pending',
            'created_at'    => $yesterday . ' 12:00:00',
            'updated_at'    => $yesterday . ' 12:00:00',
        ]);

        $todayResults = $this->repo->getOrdersByStatus('pending', date('Y-m-d'));
        $this->assertCount(1, $todayResults);
        $this->assertSame($today->id, $todayResults[0]['id']);

        $yesterdayResults = $this->repo->getOrdersByStatus('pending', $yesterday);
        $this->assertCount(1, $yesterdayResults);
        $this->assertNotSame($today->id, $yesterdayResults[0]['id']);
    }

    public function testCancelledOrderCannotBeUpdated(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($order->id);

        $this->expectException(OrderCancelledException::class);
        $this->repo->updateOrder($order->id, ['customer_name' => 'Alguém']);
    }

    public function testItemCannotBeAddedToACancelledOrder(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $this->repo->cancelOrder($order->id);

        $this->expectException(OrderCancelledException::class);
        $this->repo->addOrderItem($order->id, ['menu_item_id' => $this->menuItemId]);
    }

    public function testItemCannotBeUpdatedOnACancelledOrder(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $itemId = Db::table('order_items')->where('order_id', $order->id)->value('id');
        $this->repo->cancelOrder($order->id);

        $this->expectException(OrderCancelledException::class);
        $this->repo->updateOrderItem($order->id, $itemId, ['quantity' => 2]);
    }

    public function testItemCannotBeRemovedFromACancelledOrder(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $itemId = Db::table('order_items')->where('order_id', $order->id)->value('id');
        $this->repo->cancelOrder($order->id);

        $this->expectException(OrderCancelledException::class);
        $this->repo->removeOrderItem($order->id, $itemId);
    }

    /**
     * Spec 030 fixture: a customizable "Monte Seu Prato" (base R$5,00) plus
     * two Adicionais and one non-Adicional item.
     *
     * @return array{dish: int, frango: int, arroz: int, bebida: int}
     */
    private function seedBuildYourOwnDish(): array
    {
        $pratos = (int) Db::table('categories')->insertGetId(['name' => 'Pratos Principais']);
        $adicionais = (int) Db::table('categories')->insertGetId(['name' => 'Adicionais']);
        $bebidas = (int) Db::table('categories')->insertGetId(['name' => 'Bebidas']);

        return [
            'dish'   => (int) Db::table('menu_items')->insertGetId(['category_id' => $pratos, 'name' => 'Monte Seu Prato', 'price' => 5.0, 'available' => true, 'is_customizable' => true]),
            'frango' => (int) Db::table('menu_items')->insertGetId(['category_id' => $adicionais, 'name' => 'Filé de Frango', 'price' => 13.0, 'available' => true, 'food_category' => 'protein']),
            'arroz'  => (int) Db::table('menu_items')->insertGetId(['category_id' => $adicionais, 'name' => 'Arroz Branco', 'price' => 4.0, 'available' => true, 'food_category' => 'grain']),
            'bebida' => (int) Db::table('menu_items')->insertGetId(['category_id' => $bebidas, 'name' => 'Coca-Cola', 'price' => 5.0, 'available' => true]),
        ];
    }

    public function testBuildYourOwnDishIsPricedFromBasePlusAddOnsAndSnapshotsThem(): void
    {
        $ids = $this->seedBuildYourOwnDish();

        // Repeated add-on ids are merged: frango 1 + 1 = 2.
        $order = $this->repo->createOrder(['items' => [[
            'id' => $ids['dish'], 'quantity' => 2, 'dining_option' => 'viagem_simples',
            'components' => [
                ['id' => $ids['frango'], 'quantity' => 1],
                ['id' => $ids['arroz'], 'quantity' => 1],
                ['id' => $ids['frango'], 'quantity' => 1],
            ],
        ]]]);

        // Unit price: 5,00 + 2 x 13,00 + 1 x 4,00 = 35,00; packaging stays per plate (2 x 1,00).
        $item = Db::table('order_items')->where('order_id', $order->id)->first();
        $this->assertEqualsWithDelta(35.0, (float) $item->unit_price, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $item->packaging_cost, 0.001);

        $components = Db::table('order_item_components')->where('order_item_id', $item->id)->orderBy('menu_item_id')->get();
        $this->assertCount(2, $components);
        $this->assertSame('Filé de Frango', $components[0]->item_name);
        $this->assertSame(2, (int) $components[0]->quantity);
        $this->assertEqualsWithDelta(13.0, (float) $components[0]->unit_price, 0.001);
        $this->assertSame('Arroz Branco', $components[1]->item_name);

        // Renaming/repricing the add-on afterward must not change the snapshot or the listing.
        Db::table('menu_items')->where('id', $ids['frango'])->update(['name' => 'Outro Nome', 'price' => 99.0]);
        $listed = $this->repo->getOrdersByStatus('pending', date('Y-m-d'));
        $this->assertSame(
            [
                ['menu_item_id' => $ids['frango'], 'name' => 'Filé de Frango', 'quantity' => 2, 'unit_price' => 13.0],
                ['menu_item_id' => $ids['arroz'], 'name' => 'Arroz Branco', 'quantity' => 1, 'unit_price' => 4.0],
            ],
            $listed[0]['items'][0]['components']
        );
        $this->assertSame(35.0, $listed[0]['items'][0]['unit_price']);
    }

    /** Spec 052 — a kitchen save re-prices the item from the current menu. */
    public function testItemUpdateRepricesFromCurrentMenuButKeepsTheName(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $itemId = (int) $this->lastItem($order->id)->id;
        Db::table('menu_items')->where('id', $this->menuItemId)->update(['price' => 12.0, 'name' => 'Outro Nome']);

        $this->repo->updateOrderItem($order->id, $itemId, ['notes' => 'x']);

        $item = $this->lastItem($order->id);
        $this->assertEqualsWithDelta(12.0, (float) $item->unit_price, 0.001);
        $this->assertSame('Prato Teste', $item->item_name);
    }

    public function testItemUpdateRepricesABuildYourOwnDishAndItsAddOns(): void
    {
        $ids = $this->seedBuildYourOwnDish();
        $order = $this->repo->createOrder(['items' => [[
            'id' => $ids['dish'], 'quantity' => 1,
            'components' => [['id' => $ids['frango'], 'quantity' => 2]],
        ]]]);
        $item = $this->lastItem($order->id);
        $this->assertEqualsWithDelta(31.0, (float) $item->unit_price, 0.001); // 5 + 2 x 13

        Db::table('menu_items')->where('id', $ids['frango'])->update(['price' => 14.0]);
        Db::table('menu_items')->where('id', $ids['dish'])->update(['price' => 6.0]);
        $this->repo->updateOrderItem($order->id, (int) $item->id, ['quantity' => 1]);

        $component = Db::table('order_item_components')->where('order_item_id', $item->id)->first();
        $this->assertEqualsWithDelta(14.0, (float) $component->unit_price, 0.001);
        $this->assertSame(2, (int) $component->quantity);
        $this->assertSame('Filé de Frango', $component->item_name);
        $this->assertEqualsWithDelta(34.0, (float) $this->lastItem($order->id)->unit_price, 0.001); // 6 + 2 x 14
    }

    public function testItemUpdateKeepsStoredPricesForItemsGoneFromTheMenu(): void
    {
        $order = $this->repo->createOrder($this->orderData());
        $itemId = (int) $this->lastItem($order->id)->id;
        Db::table('menu_items')->where('id', $this->menuItemId)->delete();

        $this->repo->updateOrderItem($order->id, $itemId, ['notes' => 'x']);
        $this->assertEqualsWithDelta(10.0, (float) $this->lastItem($order->id)->unit_price, 0.001);

        // A deleted add-on keeps its stored price inside the recomputed sum.
        $ids = $this->seedBuildYourOwnDish();
        $order2 = $this->repo->createOrder(['items' => [[
            'id' => $ids['dish'], 'quantity' => 1,
            'components' => [['id' => $ids['frango'], 'quantity' => 1], ['id' => $ids['arroz'], 'quantity' => 1]],
        ]]]);
        $dishItemId = (int) $this->lastItem($order2->id)->id;
        Db::table('menu_items')->where('id', $ids['frango'])->delete();
        Db::table('menu_items')->where('id', $ids['arroz'])->update(['price' => 5.0]);

        $this->repo->updateOrderItem($order2->id, $dishItemId, ['notes' => 'x']);
        $this->assertEqualsWithDelta(23.0, (float) $this->lastItem($order2->id)->unit_price, 0.001); // 5 + 13 (stored) + 5
    }

    public function testNotesOnlyUpdateStillRecomputesPackaging(): void
    {
        $order = $this->repo->createOrder(['items' => [['id' => $this->menuItemId, 'quantity' => 2, 'dining_option' => 'viagem_vip']]]);
        $itemId = (int) $this->lastItem($order->id)->id;
        $this->assertEqualsWithDelta(4.0, (float) $this->lastItem($order->id)->packaging_cost, 0.001); // fallback 2,00
        $this->linkPackaging('viagem_vip', 3.0);

        $this->repo->updateOrderItem($order->id, $itemId, ['notes' => 'só a observação']);
        $this->assertEqualsWithDelta(6.0, (float) $this->lastItem($order->id)->packaging_cost, 0.001);
    }

    public function testRepricingAppliesToDoneOrdersButNotCancelledOnes(): void
    {
        $done = $this->repo->createOrder($this->orderData());
        $doneItemId = (int) $this->lastItem($done->id)->id;
        $this->repo->completeOrder($done->id);
        $cancelled = $this->repo->createOrder($this->orderData());
        $cancelledItemId = (int) $this->lastItem($cancelled->id)->id;
        $this->repo->cancelOrder($cancelled->id);
        Db::table('menu_items')->where('id', $this->menuItemId)->update(['price' => 15.0]);

        $this->repo->updateOrderItem($done->id, $doneItemId, ['notes' => 'x']);
        $this->assertEqualsWithDelta(15.0, (float) $this->lastItem($done->id)->unit_price, 0.001);

        try {
            $this->repo->updateOrderItem($cancelled->id, $cancelledItemId, ['notes' => 'x']);
            $this->fail('Cancelled order must not be editable');
        } catch (OrderCancelledException) {
        }
        $this->assertEqualsWithDelta(10.0, (float) $this->lastItem($cancelled->id)->unit_price, 0.001);
    }

    public function testListedOrdersExposeCreatedAtWithTimezoneOffset(): void
    {
        // Spec 031: created_at has no offset (local time); created_at_iso must
        // carry one so clients don't misread local time as UTC.
        $order = $this->repo->createOrder($this->orderData());

        $listed = $this->repo->getOrdersByStatus('pending', date('Y-m-d'));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $listed[0]['created_at_iso']);
        $this->assertSame(
            $order->fresh()->created_at->getTimestamp(),
            (new \DateTimeImmutable($listed[0]['created_at_iso']))->getTimestamp()
        );
    }

    public function testRegularItemsListAnEmptyComponentsArray(): void
    {
        $this->repo->createOrder($this->orderData());

        $listed = $this->repo->getOrdersByStatus('pending', date('Y-m-d'));
        $this->assertSame([], $listed[0]['items'][0]['components']);
    }

    public function testBuildYourOwnDishWithoutAddOnsIsRejected(): void
    {
        $ids = $this->seedBuildYourOwnDish();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Escolha ao menos um adicional');
        $this->repo->createOrder(['items' => [['id' => $ids['dish'], 'quantity' => 1]]]);
    }

    public function testAddOnsOnARegularDishAreRejected(): void
    {
        $ids = $this->seedBuildYourOwnDish();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não aceita adicionais');
        $this->repo->createOrder(['items' => [[
            'id' => $this->menuItemId, 'quantity' => 1,
            'components' => [['id' => $ids['arroz'], 'quantity' => 1]],
        ]]]);
    }

    public function testNonAdicionalComponentIsRejectedAndPersistsNothing(): void
    {
        $ids = $this->seedBuildYourOwnDish();

        try {
            $this->repo->createOrder(['items' => [[
                'id' => $ids['dish'], 'quantity' => 1,
                'components' => [['id' => $ids['bebida'], 'quantity' => 1]],
            ]]]);
            $this->fail('Expected DomainException was not thrown.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('não é um adicional', $e->getMessage());
        }

        $this->assertSame(0, Db::table('orders')->count());
        $this->assertSame(0, Db::table('order_item_components')->count());
    }

    public function testUnavailableOrMissingAddOnIsRejected(): void
    {
        $ids = $this->seedBuildYourOwnDish();
        Db::table('menu_items')->where('id', $ids['arroz'])->update(['available' => false]);

        foreach ([$ids['arroz'], $ids['arroz'] + 999] as $componentId) {
            try {
                $this->repo->createOrder(['items' => [[
                    'id' => $ids['dish'], 'quantity' => 1,
                    'components' => [['id' => $componentId, 'quantity' => 1]],
                ]]]);
                $this->fail('Expected DomainException was not thrown.');
            } catch (\DomainException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBuildYourOwnDishCannotBeAddedThroughAddOrderItem(): void
    {
        $ids = $this->seedBuildYourOwnDish();
        $order = $this->repo->createOrder($this->orderData());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('só pode ser montado pelo Caixa');
        $this->repo->addOrderItem($order->id, ['menu_item_id' => $ids['dish']]);
    }
}
