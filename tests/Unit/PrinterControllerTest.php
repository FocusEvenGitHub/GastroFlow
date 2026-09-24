<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\PrinterController;
use App\Models\Setting;
use App\Services\PricingService;
use App\Services\PrintService;
use App\Settings;
use Illuminate\Database\Capsule\Manager as Db;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The test-print endpoint's success path (spec 038, AC7).
 *
 * Exercised at the controller rather than over HTTP: the DI container builds a PrintService
 * with the real NetworkPrintConnector, so a 200 through the app would need an actual printer.
 * Injecting the connector factory here is the same seam PrintServiceTest uses.
 */
class PrinterControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Db();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Db::schema()->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });

        Setting::setValue('printer_ip', '192.168.0.50');
        Setting::setValue('printer_port', '9100');
        Setting::setValue('restaurant_name', 'GastroFlow Teste');
    }

    private function makeService(?callable $connectorFactory): PrintService
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        return new PrintService($logger, new Settings(), new PricingService(), $connectorFactory);
    }

    private function call(PrintService $service): \Psr\Http\Message\ResponseInterface
    {
        $controller = new PrinterController($service);

        return $controller->testPrint(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/settings/test-print'),
            (new ResponseFactory())->createResponse()
        );
    }

    /** @return array<string, mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody(), true);
    }

    /** AC7 — a working printer still returns the original success payload. */
    public function testSuccessfulTestPrintReturns200(): void
    {
        $response = $this->call($this->makeService(fn () => new DummyPrintConnector()));
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('Teste enviado para a impressora.', $body['message']);
    }

    /** AC4 at the controller level: the unconfigured case is told apart from a dead printer. */
    public function testUnconfiguredPrinterReturns503WithAnActionableMessage(): void
    {
        Setting::setValue('printer_ip', '');

        $response = $this->call($this->makeService(fn () => new DummyPrintConnector()));
        $body = $this->decode($response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('PRINTER_UNAVAILABLE', $body['code']);
        $this->assertStringContainsString(PrintService::ERROR_NO_IP, $body['error']);
        $this->assertStringContainsString('Configurações', $body['error']);
    }

    /** AC5/AC6 at the controller level: names the address, leaks nothing internal. */
    public function testUnreachablePrinterNamesTheAddressWithoutLeakingInternals(): void
    {
        $service = $this->makeService(function () {
            throw new \RuntimeException(
                'Cannot initialise NetworkPrintConnector: /var/www/html/vendor/mike42/escpos.php line 42'
            );
        });

        $body = $this->decode($this->call($service));

        $this->assertStringContainsString('192.168.0.50:9100', $body['error']);
        foreach (['/var/www', 'Mike42', '.php', 'Exception'] as $internal) {
            $this->assertStringNotContainsString($internal, $body['error']);
        }
    }
}
