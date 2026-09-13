<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReportService;
use Illuminate\Database\Capsule\Manager as Db;
use PHPUnit\Framework\TestCase;

/**
 * ReportService::getMainDishSales() (spec 032) against an in-memory SQLite
 * database — the query is deliberately portable (no GROUP_CONCAT) so it can
 * be exercised here; the other report queries are MySQL-specific.
 */
class ReportServiceTest extends TestCase
{
    private ReportService $service;
    private int $orderSeq = 0;

    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('food');
        });

        Db::schema()->create('menu_items', function ($table) {
            $table->id();
            $table->unsignedInteger('category_id');
            $table->string('name');
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
        });

        Db::schema()->create('order_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedInteger('menu_item_id');
            $table->string('item_name', 100)->default('');
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->string('dining_option')->default('local');
            $table->float('unit_price')->default(0);
            $table->float('packaging_cost')->default(0);
        });

        $this->service = new ReportService();
    }

    private function menuItem(string $category, string $name, float $price): int
    {
        $categoryId = Db::table('categories')->where('name', $category)->value('id')
            ?? Db::table('categories')->insertGetId(['name' => $category]);

        return (int) Db::table('menu_items')->insertGetId([
            'category_id' => $categoryId, 'name' => $name, 'price' => $price,
        ]);
    }

    /** @param list<array{0:int,1:string,2:int,3:float,4?:float}> $lines [menu_item_id, name, qty, unit_price, packaging] */
    private function order(string $date, string $status, array $lines): void
    {
        $orderId = Db::table('orders')->insertGetId([
            'order_number' => (string) ++$this->orderSeq, 'business_date' => $date, 'status' => $status,
            'created_at' => $date . ' 12:00:00', 'updated_at' => $date . ' 12:30:00',
        ]);
        foreach ($lines as $line) {
            Db::table('order_items')->insert([
                'order_id' => $orderId, 'menu_item_id' => $line[0], 'item_name' => $line[1],
                'quantity' => $line[2], 'unit_price' => $line[3], 'packaging_cost' => $line[4] ?? 0,
            ]);
        }
    }

    public function testCountsOnlyCompletedMainDishesWithinTheRange(): void
    {
        $parmegiana = $this->menuItem('Pratos Principais', 'Parmegiana de Frango', 25.0);
        $monte = $this->menuItem('Pratos Principais', 'Monte Seu Prato', 0.0);
        $coca = $this->menuItem('Bebidas', 'Coca-Cola', 5.0);
        $arroz = $this->menuItem('Adicionais', 'Arroz Branco', 4.0);

        $this->order('2026-09-10', 'done', [[$parmegiana, 'Parmegiana de Frango', 2, 25.0, 2.0], [$coca, 'Coca-Cola', 3, 5.0]]);
        $this->order('2026-09-11', 'done', [[$parmegiana, 'Parmegiana de Frango', 1, 25.0], [$monte, 'Monte Seu Prato', 1, 30.0], [$arroz, 'Arroz Branco', 1, 4.0]]);
        // Excluded: pending, cancelled, and outside the date range.
        $this->order('2026-09-11', 'pending', [[$parmegiana, 'Parmegiana de Frango', 5, 25.0]]);
        $this->order('2026-09-11', 'cancelled', [[$monte, 'Monte Seu Prato', 4, 30.0]]);
        $this->order('2026-09-12', 'done', [[$parmegiana, 'Parmegiana de Frango', 7, 25.0]]);

        $result = $this->service->getMainDishSales('2026-09-10', '2026-09-11');

        $this->assertSame(4, $result['total_qty']);
        // Revenue excludes packaging, like the top-items report: 3 x 25 + 1 x 30 = 105.
        $this->assertSame(105.0, $result['total_revenue']);
        $this->assertSame([
            ['menu_item_id' => $parmegiana, 'name' => 'Parmegiana de Frango', 'total_qty' => 3, 'total_revenue' => 75.0, 'share' => 75.0],
            ['menu_item_id' => $monte, 'name' => 'Monte Seu Prato', 'total_qty' => 1, 'total_revenue' => 30.0, 'share' => 25.0],
        ], $result['items']);
    }

    public function testNameIsTheMostRecentSnapshotAndTiesAreSortedByName(): void
    {
        $luis = $this->menuItem('Pratos Principais', 'Luís de Carne', 28.0);
        $barca = $this->menuItem('Pratos Principais', 'Barça de Frango', 30.0);

        $this->order('2026-09-10', 'done', [[$luis, 'Luís Carne (antigo)', 1, 26.0]]);
        $this->order('2026-09-11', 'done', [[$luis, 'Luís de Carne', 1, 28.0], [$barca, 'Barça de Frango', 2, 30.0]]);

        $result = $this->service->getMainDishSales('2026-09-10', '2026-09-11');

        $this->assertSame(['Barça de Frango', 'Luís de Carne'], array_column($result['items'], 'name'));
        $this->assertSame([2, 2], array_column($result['items'], 'total_qty'));
    }

    public function testEmptyPeriodReturnsZeroTotals(): void
    {
        $result = $this->service->getMainDishSales('2026-01-01', '2026-01-31');

        $this->assertSame(['total_qty' => 0, 'total_revenue' => 0.0, 'items' => []], $result);
    }
}
