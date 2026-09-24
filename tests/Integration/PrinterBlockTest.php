<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Setting;
use App\Services\JobService;
use App\Services\PrintService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Db;
use Psr\Log\NullLogger;

/**
 * Bloqueio da impressão após falhas consecutivas (spec 039).
 *
 * Os critérios são sobre estado real e respostas reais, então tudo aqui passa pelo app.
 */
class PrinterBlockTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // settings não é limpa entre testes (é dado de referência), então o estado do
        // bloqueio é zerado explicitamente para cada caso.
        Setting::setValue('printer_consecutive_failures', '0');
        Setting::setValue('printer_blocked_at', null);
        Setting::setValue('printer_last_failed_order', null);
        Setting::setValue('printer_last_error', null);
        Setting::setValue('printer_ip', '');
    }

    /**
     * Cria um pedido e esgota o job de impressão dele até a falha permanente.
     *
     * available_at é reiniciado entre tentativas em vez de esperar o backoff de 2s/4s/8s
     * (mesma técnica do PrintingTest da spec 038) — o caminho de claim é idêntico, só a
     * espera é pulada.
     */
    private function failOnePrintJob(): int
    {
        $item = $this->availableMenuItem();

        $created = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => true,
        ]);
        $orderId = (int) $this->decode($created)['id'];

        $service = new JobService(new NullLogger());
        for ($i = 0; $i < 3; $i++) {
            Db::table('jobs')->where('queue', 'print')->update(['available_at' => Carbon::now()]);
            $service->processNext('print');
        }

        return $orderId;
    }

    /** AC1 */
    public function testThreeConsecutiveFailuresBlockPrinting(): void
    {
        $this->assertFalse($this->decode($this->request('GET', '/api/printer/status'))['blocked']);

        $lastOrderId = 0;
        for ($i = 1; $i <= 3; $i++) {
            $lastOrderId = $this->failOnePrintJob();
        }

        $status = $this->decode($this->request('GET', '/api/printer/status'));

        $this->assertTrue($status['blocked'], 'Três falhas seguidas devem bloquear');
        $this->assertSame(3, $status['consecutive_failures']);
        $this->assertSame($lastOrderId, $status['last_failed_order_id']);
        $this->assertNotNull($status['blocked_at']);
    }

    /** AC2 — uma impressão bem-sucedida no meio quebra a sequência. */
    public function testASuccessfulPrintResetsTheCounter(): void
    {
        $this->failOnePrintJob();
        $this->failOnePrintJob();

        $this->assertSame(2, $this->decode($this->request('GET', '/api/printer/status'))['consecutive_failures']);

        // Sucesso registrado pelo mesmo caminho que o PrintOrderJob usa.
        (new PrintService(new \Monolog\Logger('t'), new \App\Settings(), new \App\Services\PricingService()))
            ->recordPrintSuccess();

        $status = $this->decode($this->request('GET', '/api/printer/status'));
        $this->assertSame(0, $status['consecutive_failures']);
        $this->assertFalse($status['blocked']);
    }

    /** AC4 — o endpoint é público de propósito (spec 018). */
    public function testStatusIsReachableWithoutAToken(): void
    {
        $response = $this->request('GET', '/api/printer/status');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('blocked', $this->decode($response));
    }

    /**
     * AC5 — a regra dura do roadmap: "A printer failure must never remove or invalidate the
     * restaurant order." Com a impressão bloqueada, o pedido ainda é criado.
     */
    public function testOrderIsStillCreatedWhilePrintingIsBlocked(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->failOnePrintJob();
        }
        $this->assertTrue($this->decode($this->request('GET', '/api/printer/status'))['blocked']);

        $jobsBefore = Db::table('jobs')->where('queue', 'print')->count();
        $item = $this->availableMenuItem();

        $response = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => true,
        ]);

        $this->assertSame(201, $response->getStatusCode(), 'O pedido precisa ser criado mesmo assim');

        $orderId = (int) $this->decode($response)['id'];
        $this->assertSame(1, Db::table('orders')->where('id', $orderId)->count());

        $this->assertSame(
            $jobsBefore,
            Db::table('jobs')->where('queue', 'print')->count(),
            'Nenhum job novo pode ser enfileirado com a impressão bloqueada'
        );
    }

    /** AC6 */
    public function testReprintIsRefusedWhileBlocked(): void
    {
        $orderId = $this->failOnePrintJob();
        $this->failOnePrintJob();
        $this->failOnePrintJob();

        $jobsBefore = Db::table('jobs')->where('queue', 'print')->count();

        $response = $this->request('POST', "/api/orders/{$orderId}/print");
        $body = $this->decode($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('PRINTING_BLOCKED', $body['code']);
        $this->assertSame($jobsBefore, Db::table('jobs')->where('queue', 'print')->count());
    }

    /** AC7 + AC8 — reativar destrava e não toca em job algum. */
    public function testResetUnblocksWithoutTouchingJobs(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->failOnePrintJob();
        }

        $jobsByStatusBefore = Db::table('jobs')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $reset = $this->request('POST', '/api/printer/reset');
        $this->assertSame(200, $reset->getStatusCode());

        $status = $this->decode($this->request('GET', '/api/printer/status'));
        $this->assertFalse($status['blocked']);
        $this->assertSame(0, $status['consecutive_failures']);
        $this->assertNull($status['last_failed_order_id']);

        $jobsByStatusAfter = Db::table('jobs')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $this->assertSame(
            $jobsByStatusBefore,
            $jobsByStatusAfter,
            'Reativar não pode alterar nenhum job (spec 033 os preserva)'
        );
    }

    /** AC9 — depois de reativar, a impressão volta a ser enfileirada. */
    public function testPrintingWorksAgainAfterReset(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->failOnePrintJob();
        }
        $this->request('POST', '/api/printer/reset');

        $jobsBefore = Db::table('jobs')->where('queue', 'print')->count();
        $item = $this->availableMenuItem();

        $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => true,
        ]);

        $this->assertSame(
            $jobsBefore + 1,
            Db::table('jobs')->where('queue', 'print')->count(),
            'Após reativar, a impressão volta a ser enfileirada'
        );
    }

    /** AC10 — o erro exposto é a mensagem saneada, não a exceção crua. */
    public function testExposedErrorLeaksNoInternals(): void
    {
        $this->failOnePrintJob();

        $status = $this->decode($this->request('GET', '/api/printer/status'));

        $this->assertNotNull($status['last_error']);
        foreach (['/var/www', '#0 ', 'Exception', 'Mike42', '.php'] as $internal) {
            $this->assertStringNotContainsString($internal, $status['last_error']);
        }
    }
}
