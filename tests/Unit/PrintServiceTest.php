<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\PricingService;
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

    /** @return array{0: Logger, 1: \Monolog\Handler\TestHandler} */
    private function makeLoggerWithTestHandler(): array
    {
        $handler = new \Monolog\Handler\TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);
        return [$logger, $handler];
    }

    /**
     * Spec 038, AC1 — an unconfigured printer used to return normally here, so JobService
     * marked the job completed and the order was recorded as printed with nothing printed.
     */
    public function testUnconfiguredPrinterFailsInsteadOfSilentlySucceeding(): void
    {
        Setting::setValue('printer_ip', '');

        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function () {
            throw new \LogicException('O conector não deve ser criado sem IP configurado');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(PrintService::ERROR_NO_IP);

        $service->printOrder($this->makeOrder());
    }

    /** Spec 038 — both print paths must answer an unconfigured printer the same way. */
    public function testTestPageAndOrderPrintAgreeOnTheUnconfiguredMessage(): void
    {
        Setting::setValue('printer_ip', '');

        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());

        $orderMessage = null;
        try {
            $service->printOrder($this->makeOrder());
        } catch (\Throwable $e) {
            $orderMessage = $e->getMessage();
        }

        $testMessage = null;
        try {
            $service->printTestPage();
        } catch (\Throwable $e) {
            $testMessage = $e->getMessage();
        }

        $this->assertSame(PrintService::ERROR_NO_IP, $orderMessage);
        $this->assertSame($orderMessage, $testMessage, 'The two paths disagreed before spec 038');
    }

    /** Spec 039 — o contador só bloqueia ao atingir o limite. */
    public function testThreeFailuresBlockPrintingAndSuccessClearsIt(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());

        $this->assertFalse($service->isPrintingBlocked());

        $this->assertSame(1, $service->recordPrintFailure(11, 'erro'));
        $this->assertFalse($service->isPrintingBlocked(), 'Uma falha não pode bloquear');

        $this->assertSame(2, $service->recordPrintFailure(12, 'erro'));
        $this->assertFalse($service->isPrintingBlocked(), 'Duas falhas não podem bloquear');

        $this->assertSame(3, $service->recordPrintFailure(13, 'erro'));
        $this->assertTrue($service->isPrintingBlocked(), 'Três falhas bloqueiam');

        $service->recordPrintSuccess();

        $this->assertFalse($service->isPrintingBlocked());
        $this->assertSame(0, $service->getPrinterStatus()['consecutive_failures']);
    }

    /** Spec 039 — o status carrega o pedido afetado, que é o que a UI notifica. */
    public function testStatusReportsTheLastFailedOrder(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());
        $service->recordPrintFailure(77, 'impressora fora');

        $status = $service->getPrinterStatus();

        $this->assertSame(77, $status['last_failed_order_id']);
        $this->assertSame('impressora fora', $status['last_error']);
        $this->assertSame(3, $status['max_failures']);
    }

    /** Spec 039 — reativar destrava e limpa o estado. */
    public function testResetClearsTheBlock(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());
        $service->recordPrintFailure(1, 'e');
        $service->recordPrintFailure(2, 'e');
        $service->recordPrintFailure(3, 'e');
        $this->assertTrue($service->isPrintingBlocked());

        $service->resetPrinterFailures();

        $status = $service->getPrinterStatus();
        $this->assertFalse($status['blocked']);
        $this->assertSame(0, $status['consecutive_failures']);
        $this->assertNull($status['last_failed_order_id']);
        $this->assertNull($status['last_error']);
    }

    public function testConfiguredAddressIsNullWhenNoIpIsSet(): void
    {
        Setting::setValue('printer_ip', '');

        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());

        $this->assertNull($service->getConfiguredAddress());
    }

    public function testConfiguredAddressCombinesIpAndPort(): void
    {
        Setting::setValue('printer_ip', '10.0.0.5');
        Setting::setValue('printer_port', '9100');

        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService());

        $this->assertSame('10.0.0.5:9100', $service->getConfiguredAddress());
    }

    public function testConnectionFailurePropagatesToCaller(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function () {
            throw new \RuntimeException('Cannot initialise NetworkPrintConnector: Connection timed out');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timed out');

        $service->printOrder($this->makeOrder());
    }

    public function testSuccessfulPrintDoesNotThrow(): void
    {
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function ($ip, $port) {
            return new \Mike42\Escpos\PrintConnectors\DummyPrintConnector();
        });

        $service->printOrder($this->makeOrder());

        $this->addToAssertionCount(1);
    }

    /** Spec 042 — a successful print's log carries structured, not string-concatenated, context. */
    public function testSuccessfulPrintLogsStructuredContext(): void
    {
        [$logger, $handler] = $this->makeLoggerWithTestHandler();
        $service = new PrintService($logger, new Settings(), new PricingService(), function () {
            return new \Mike42\Escpos\PrintConnectors\DummyPrintConnector();
        });

        $service->printOrder($this->makeOrder(), [
            'job_id'       => 55,
            'attempt'      => 1,
            'max_attempts' => 3,
            'request_id'   => 'req-abc123',
        ]);

        // Filtered, not assumed to be the only record: a real logo.png happening to exist in
        // this dev environment can add an unrelated "logo failed to load" warning from
        // buildReceipt() — pre-existing behavior, nothing to do with this spec's change.
        $successRecords = array_values(array_filter(
            $handler->getRecords(),
            fn ($r) => $r->message === 'Print success'
        ));

        $this->assertCount(1, $successRecords);
        $this->assertSame([
            'job_id'       => 55,
            'attempt'      => 1,
            'max_attempts' => 3,
            'request_id'   => 'req-abc123',
            'event'        => 'print.success',
            'order_id'     => 1,
            'printer'      => '192.168.0.136:9100',
        ], $successRecords[0]->context);
    }

    /** Spec 042 — the unconfigured-printer failure path also carries structured context. */
    public function testUnconfiguredPrinterLogsStructuredContext(): void
    {
        Setting::setValue('printer_ip', '');
        [$logger, $handler] = $this->makeLoggerWithTestHandler();
        $service = new PrintService($logger, new Settings(), new PricingService());

        try {
            $service->printOrder($this->makeOrder(), ['job_id' => 9]);
        } catch (\RuntimeException $e) {
            // Expected — assertions are on the log record, not the exception here.
        }

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('Print failed', $records[0]->message);
        $this->assertSame('print.failed', $records[0]->context['event']);
        $this->assertSame(1, $records[0]->context['order_id']);
        $this->assertSame(9, $records[0]->context['job_id']);
        $this->assertSame(PrintService::ERROR_NO_IP, $records[0]->context['error']);
    }

    /** Spec 042 — the block-message log (3 consecutive failures) also carries structured context. */
    public function testBlockLogCarriesEventAndCallerContext(): void
    {
        [$logger, $handler] = $this->makeLoggerWithTestHandler();
        $service = new PrintService($logger, new Settings(), new PricingService());

        $service->recordPrintFailure(1, 'e', ['job_id' => 10, 'request_id' => 'req-x']);
        $service->recordPrintFailure(2, 'e', ['job_id' => 11, 'request_id' => 'req-y']);
        $service->recordPrintFailure(3, 'e', ['job_id' => 12, 'request_id' => 'req-z']);

        $blockRecords = array_values(array_filter(
            $handler->getRecords(),
            fn ($r) => ($r->context['event'] ?? null) === 'print.blocked'
        ));

        $this->assertCount(1, $blockRecords, 'Exactly one block log, on the third failure');
        $this->assertSame('req-z', $blockRecords[0]->context['request_id'], 'Carries the LAST call\'s context');
        $this->assertSame(12, $blockRecords[0]->context['job_id']);
        $this->assertSame(3, $blockRecords[0]->context['failures']);
        $this->assertSame(3, $blockRecords[0]->context['order_id']);
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
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function () use ($connector) {
            return $connector;
        });

        $service->printOrder($order);

        $this->assertStringContainsString('TOTAL: R$ 60,70', $connector->data);
    }

    public function testBuildYourOwnDishPrintsItsAddOnsUnderTheItem(): void
    {
        // Spec 030: unit_price is already the composed price (base + add-ons),
        // so the line total is unchanged; add-ons are listed for the kitchen/customer.
        $dish = $this->makeOrderItem('Monte Seu Prato', 30.0, 1);
        $dish->setRelation('components', collect([
            new \App\Models\OrderItemComponent(['item_name' => 'Filé de Frango', 'quantity' => 2, 'unit_price' => 13.0]),
            new \App\Models\OrderItemComponent(['item_name' => 'Arroz Branco', 'quantity' => 1, 'unit_price' => 4.0]),
        ]));
        $order = $this->makeOrder();
        $order->setRelation('items', collect([$dish]));

        $connector = new CapturingPrintConnector();
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function () use ($connector) {
            return $connector;
        });

        $service->printOrder($order);

        // Assert on ASCII-only fragments: escpos-php re-encodes accented
        // characters to the printer's code page (e.g. "é" -> CP850 0x82).
        $this->assertStringContainsString('+ 2x Fil', $connector->data);
        $this->assertStringContainsString(' de Frango', $connector->data);
        $this->assertStringContainsString('+ 1x Arroz Branco', $connector->data);
        $this->assertStringContainsString('TOTAL: R$ 30,00', $connector->data);
    }

    private function printToString(Order $order): string
    {
        $connector = new CapturingPrintConnector();
        $service = new PrintService($this->makeLogger(), new Settings(), new PricingService(), function () use ($connector) {
            return $connector;
        });
        $service->printOrder($order);
        return $connector->data;
    }

    /** Spec 058, AC13 — net line, a discount label under it, and a net TOTAL. */
    public function testDiscountedLineIsPrintedNetWithItsDiscountLabel(): void
    {
        $prato = $this->makeOrderItem('Prato do Dia', 20.0, 2);
        $prato->discount_type = 'percent';
        $prato->discount_value = 20.0;
        $prato->discount_amount = 8.0;
        $coca = $this->makeOrderItem('Coca-Cola', 5.0, 1);
        $order = $this->makeOrder();
        $order->setRelation('items', collect([$prato, $coca]));

        $data = $this->printToString($order);

        $this->assertMatchesRegularExpression('/2x Prato do Dia +R\$ 32,00/', $data);
        $this->assertStringContainsString('   Desconto 20%: -R$ 8,00', $data);
        $this->assertStringContainsString('TOTAL: R$ 37,00', $data);
    }

    public function testFixedDiscountLabelHasNoRate(): void
    {
        $prato = $this->makeOrderItem('Prato do Dia', 20.0, 2);
        $prato->discount_type = 'fixed';
        $prato->discount_value = 5.0;
        $prato->discount_amount = 5.0;
        $order = $this->makeOrder();
        $order->setRelation('items', collect([$prato]));

        $data = $this->printToString($order);

        $this->assertStringContainsString('   Desconto: -R$ 5,00', $data);
        $this->assertStringContainsString('TOTAL: R$ 35,00', $data);
    }

    /** Spec 058, AC13 — no discount: identical bytes to an item that never had the fields. */
    public function testTicketWithoutDiscountIsUnchanged(): void
    {
        $plain = $this->makeOrder();
        $plain->setRelation('items', collect([$this->makeOrderItem('Prato do Dia', 20.0, 2)]));

        $zeroed = $this->makeOrder();
        $item = $this->makeOrderItem('Prato do Dia', 20.0, 2);
        $item->discount_type = null;
        $item->discount_value = null;
        $item->discount_amount = 0.0;
        $zeroed->setRelation('items', collect([$item]));

        $plainData = $this->printToString($plain);
        $this->assertStringNotContainsString('Desconto', $plainData);
        $this->assertSame($plainData, $this->printToString($zeroed));
    }

    /** A Settings whose logo.png holds $bytes, under a throwaway base path (spec 065). */
    private function settingsWithLogo(string $bytes): Settings
    {
        $base = sys_get_temp_dir() . '/gf-logo-' . bin2hex(random_bytes(4));
        mkdir($base . '/public/assets/img', 0777, true);
        file_put_contents($base . '/public/assets/img/logo.png', $bytes);
        return new Settings($base);
    }

    private function imageBytes(string $encoder, int $width = 40): string
    {
        $im = imagecreatetruecolor($width, 20);
        imagefilledrectangle($im, 0, 0, 9, 19, imagecolorallocate($im, 0, 0, 0));
        ob_start();
        $encoder($im);
        return (string) ob_get_clean();
    }

    private function printWith(Settings $settings): string
    {
        $connector = new CapturingPrintConnector();
        $service = new PrintService($this->makeLogger(), $settings, new PricingService(), fn () => $connector);
        $service->printOrder($this->makeOrder());
        return $connector->data;
    }

    /**
     * Spec 065, AC3 — the logo reaches the ticket as a raster image (GS v 0). Also covers a
     * JPEG saved as logo.png, which is what uploads before spec 065 left on disk.
     */
    public function testLogoIsPrintedAsARasterImage(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not loaded.');
        }
        foreach (['imagepng', 'imagejpeg'] as $encoder) {
            $settings = $this->settingsWithLogo($this->imageBytes($encoder));
            $this->assertStringContainsString("\x1dv0", $this->printWith($settings), $encoder);
            $service = new PrintService($this->makeLogger(), $settings, new PricingService());
            $this->assertNull($service->logoProblem(), $encoder);
        }
    }

    /** Spec 065 — a logo wider than 58 mm paper is scaled down to 384 dots (48 bytes a row). */
    public function testWideLogoIsScaledToThePaperWidth(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not loaded.');
        }
        $data = $this->printWith($this->settingsWithLogo($this->imageBytes('imagepng', 1024)));
        // GS v 0 m xL xH: width in bytes, little-endian.
        $this->assertStringContainsString("\x1dv0\x00\x30\x00", $data);
    }

    /** Spec 065, AC5 — an unreadable logo is reported, and the ticket still prints without it. */
    public function testUnreadableLogoIsReportedAndTheTicketStillPrints(): void
    {
        $settings = $this->settingsWithLogo('not an image');
        $data = $this->printWith($settings);

        $this->assertStringContainsString('Senha: 5', $data);
        $this->assertStringNotContainsString("\x1dv0", $data);
        $service = new PrintService($this->makeLogger(), $settings, new PricingService());
        $this->assertNotNull($service->logoProblem());
    }
}
