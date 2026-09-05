<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\PrintService;
use App\Settings;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Captures everything written to it as plain text, ignoring
 * finalize()/read() -- Printer::close() finalizes the connector before
 * PrintService::printOrder() returns, which would null out
 * DummyPrintConnector's own buffer before a test could read it back.
 */
class CapturingPrintConnector implements PrintConnector
{
    public string $data = '';

    public function __destruct()
    {
    }

    public function finalize()
    {
    }

    public function read($len)
    {
        return '';
    }

    public function write($data)
    {
        $this->data .= $data;
    }
}

class PrintServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('settings', function ($table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Setting::setValue('printer_ip', '192.168.0.136');
        Setting::setValue('printer_port', '9100');
    }

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->id = 1;
        $order->order_number = '5';
        $order->created_at = Carbon::now();
        $order->setRelation('items', collect());
        return $order;
    }

    private function makeOrderItem(string $name, float $unitPrice, int $quantity, float $packagingCost = 0.0): OrderItem
    {
        // item_name is the order-time snapshot (spec 023) — PrintService reads
        // it directly and no longer touches the menuItem relation at all.
        $item = new OrderItem();
        $item->item_name = $name;
        $item->quantity = $quantity;
        $item->unit_price = $unitPrice;
        $item->packaging_cost = $packagingCost;
        $item->dining_option = 'local';
        $item->notes = '';

        return $item;
    }

    private function makeLogger(): Logger
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        return $logger;
    }

    public function testConnectionFailurePropagatesToCaller(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), function () {
            throw new \RuntimeException('Cannot initialise NetworkPrintConnector: Connection timed out');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timed out');

        $service->printOrder($this->makeOrder());
    }

    public function testSuccessfulPrintDoesNotThrow(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), function ($ip, $port) {
            return new \Mike42\Escpos\PrintConnectors\DummyPrintConnector();
        });

        $service->printOrder($this->makeOrder());

        $this->addToAssertionCount(1);
    }

    public function testReceiptTotalIsExactAcrossManyItems(): void
    {
        // 10 items at R$0,10 each is the canonical float trap
        // (0.1 + 0.1 + ... ten times is not exactly 1.0 in binary float),
        // plus a fractional-price item to also exercise multiplication.
        // Independently hand-computed in integer cents: 10*10 + 3*1990 = 100 + 5970 = 6070 -> "60,70".
        $order = $this->makeOrder();
        $items = collect();
        for ($i = 0; $i < 10; $i++) {
            $items->push($this->makeOrderItem('Item ' . $i, 0.10, 1));
        }
        $items->push($this->makeOrderItem('Prato do Dia', 19.90, 3));
        $order->setRelation('items', $items);

        $connector = new CapturingPrintConnector();
        $service = new PrintService($this->makeLogger(), new Settings(), function () use ($connector) {
            return $connector;
        });

        $service->printOrder($order);

        $this->assertStringContainsString('TOTAL: R$ 60,70', $connector->data);
    }
}
