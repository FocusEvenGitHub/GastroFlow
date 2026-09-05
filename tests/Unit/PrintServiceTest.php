<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Setting;
use App\Services\PrintService;
use App\Settings;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

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
}
