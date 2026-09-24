<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Setting;
use App\Services\PrintService;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Printing reliability through the real HTTP layer (spec 038).
 *
 * The criteria here are about what an operator is actually told, so they are asserted from
 * real requests and real response bodies rather than read off the code.
 */
class PrintingTest extends IntegrationTestCase
{
    /** AC4 — the test-print screen must name the real cause, not "internal server error". */
    public function testTestPrintReportsAnUnconfiguredPrinter(): void
    {
        Setting::setValue('printer_ip', '');

        $response = $this->request('POST', '/api/admin/settings/test-print', null, $this->authHeader('admin'));
        $body = $this->decode($response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertSame('PRINTER_UNAVAILABLE', $body['code']);
        $this->assertStringContainsString('IP da impressora não configurado', $body['error']);
        $this->assertStringNotContainsString('Erro interno do servidor', $body['error']);
    }

    /** AC5 — an unreachable printer must be named, so the operator knows what was tried. */
    public function testTestPrintNamesTheAddressWhenThePrinterIsUnreachable(): void
    {
        // Loopback on a port nothing listens on: refused immediately, so the test stays fast
        // while still exercising the "printer did not answer" path.
        Setting::setValue('printer_ip', '127.0.0.1');
        Setting::setValue('printer_port', '9101');

        $response = $this->request('POST', '/api/admin/settings/test-print', null, $this->authHeader('admin'));
        $body = $this->decode($response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('PRINTER_UNAVAILABLE', $body['code']);
        $this->assertStringContainsString('127.0.0.1:9101', $body['error']);
    }

    /** AC6 — the message is operator-facing, so it must carry no internals. */
    public function testTestPrintErrorLeaksNoInternals(): void
    {
        Setting::setValue('printer_ip', '127.0.0.1');
        Setting::setValue('printer_port', '9101');

        $body = $this->decode(
            $this->request('POST', '/api/admin/settings/test-print', null, $this->authHeader('admin'))
        );

        foreach (['/var/www', '#0 ', 'Exception', 'Mike42', '.php'] as $internal) {
            $this->assertStringNotContainsString($internal, $body['error']);
        }
    }

    public function testTestPrintStillRequiresAnAdminToken(): void
    {
        $this->assertSame(401, $this->request('POST', '/api/admin/settings/test-print')->getStatusCode());
        $this->assertSame(
            403,
            $this->request('POST', '/api/admin/settings/test-print', null, $this->authHeader('manager'))
                ->getStatusCode()
        );
    }

    /**
     * AC8 — the roadmap's hard rule: "A printer failure must never remove or invalidate the
     * restaurant order." Order creation must not care that the printer is unusable.
     */
    public function testOrderIsCreatedEvenWithNoPrinterConfigured(): void
    {
        Setting::setValue('printer_ip', '');
        $item = $this->availableMenuItem();

        $response = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => true,
        ]);

        $this->assertSame(201, $response->getStatusCode());

        $orderId = (int) $this->decode($response)['id'];
        $this->assertSame(1, Db::table('orders')->where('id', $orderId)->count());
        $this->assertSame('pending', Db::table('orders')->where('id', $orderId)->value('status'));

        // The job was still enqueued — it is the job that will fail, not the order.
        $this->assertSame(1, Db::table('jobs')->where('queue', 'print')->count());
    }

    /**
     * AC2 + AC3 — the job fails, and the order it belongs to is untouched.
     *
     * Driven through JobService directly rather than bin/worker: the backoff is 2s/4s/8s, so
     * exhausting max_attempts through the worker loop would make this a ~15s test for no
     * extra coverage. The claim path is identical either way.
     */
    public function testUnconfiguredPrinterFailsTheJobAndLeavesTheOrderIntact(): void
    {
        Setting::setValue('printer_ip', '');
        $item = $this->availableMenuItem();

        $created = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 2]],
            'print_ticket' => true,
        ]);
        $orderId = (int) $this->decode($created)['id'];

        $before = (array) Db::table('orders')->where('id', $orderId)->first();
        $itemsBefore = Db::table('order_items')->where('order_id', $orderId)->count();

        $service = new \App\Services\JobService(new \Psr\Log\NullLogger());
        // max_attempts is 3; each call consumes one attempt. available_at is pushed into the
        // future by the backoff, so it is reset between attempts to keep the test quick.
        for ($i = 0; $i < 3; $i++) {
            Db::table('jobs')->where('queue', 'print')->update(['available_at' => \Carbon\Carbon::now()]);
            $service->processNext('print');
        }

        $job = Db::table('jobs')->where('queue', 'print')->first();

        $this->assertNotNull($job, 'The failed job must be kept for inspection (spec 033)');
        $this->assertSame('failed', $job->status);
        $this->assertSame(3, (int) $job->attempts);
        $this->assertStringContainsString(
            PrintService::ERROR_NO_IP,
            (string) $job->last_error,
            'The operator must be able to see why it failed'
        );

        $after = (array) Db::table('orders')->where('id', $orderId)->first();
        $this->assertSame($before, $after, 'A print failure must not touch the order');
        $this->assertSame($itemsBefore, Db::table('order_items')->where('order_id', $orderId)->count());
    }
}
