<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Money;
use App\Services\PricingService;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Order creation, pricing, lifecycle and job dispatch against real MySQL
 * (spec 035: AC6, AC7, AC10).
 */
class OrderWorkflowTest extends IntegrationTestCase
{
    /** @return array{id: int, body: array<string, mixed>} */
    private function createOrder(int $menuItemId, int $quantity = 2, bool $printTicket = false): array
    {
        $response = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $menuItemId, 'quantity' => $quantity]],
            'print_ticket' => $printTicket,
        ]);

        $body = $this->decode($response);
        $this->assertSame(201, $response->getStatusCode(), 'Order creation failed: ' . json_encode($body));

        return ['id' => (int) $body['id'], 'body' => $body];
    }

    public function testOrderCreationPersistsOrderAndItems(): void
    {
        $item = $this->availableMenuItem();
        $order = $this->createOrder($item->id, 3);

        $this->assertSame(1, Db::table('orders')->where('id', $order['id'])->count());
        $this->assertSame(1, Db::table('order_items')->where('order_id', $order['id'])->count());

        $row = Db::table('order_items')->where('order_id', $order['id'])->first();
        $this->assertSame(3, (int) $row->quantity);
        // Snapshot taken at sale time (spec 023), not a join to the live menu.
        $this->assertSame($item->name, $row->item_name);
    }

    /** AC6 — persisted money must match PricingService exactly, in cents. */
    public function testPersistedTotalMatchesPricingService(): void
    {
        $item = $this->availableMenuItem();
        $quantity = 4;
        $order = $this->createOrder($item->id, $quantity);

        $row = Db::table('order_items')->where('order_id', $order['id'])->first();

        $expectedUnit = (new PricingService())->unitPriceFor($item);
        $persistedUnit = Money::fromReais($row->unit_price);

        $this->assertSame(
            $expectedUnit->getCents(),
            $persistedUnit->getCents(),
            'unit_price persisted does not match PricingService'
        );

        $expectedLine = $expectedUnit->getCents() * $quantity;
        $this->assertSame($expectedLine, $persistedUnit->getCents() * (int) $row->quantity);
    }

    public function testOrderNumberIsAssignedPerBusinessDate(): void
    {
        $item = $this->availableMenuItem();

        $first = $this->createOrder($item->id);
        $second = $this->createOrder($item->id);

        $numbers = Db::table('orders')
            ->whereIn('id', [$first['id'], $second['id']])
            ->pluck('order_number')
            ->all();

        $this->assertCount(2, array_unique($numbers), 'Sequential orders must get distinct numbers');
    }

    /** AC7 */
    public function testCompleteThenReopenMovesStatusBothWays(): void
    {
        $item = $this->availableMenuItem();
        $order = $this->createOrder($item->id);

        $complete = $this->request('POST', "/api/orders/{$order['id']}/complete");
        $this->assertSame(200, $complete->getStatusCode());
        $this->assertSame('done', Db::table('orders')->where('id', $order['id'])->value('status'));

        $reopen = $this->request('POST', "/api/orders/{$order['id']}/uncomplete");
        $this->assertSame(200, $reopen->getStatusCode());
        $this->assertSame('pending', Db::table('orders')->where('id', $order['id'])->value('status'));
    }

    /** AC7 — cancelled is terminal (spec 020); reopening it must be refused with 409. */
    public function testCancelledOrderCannotBeReopened(): void
    {
        $item = $this->availableMenuItem();
        $order = $this->createOrder($item->id);

        $cancel = $this->request('POST', "/api/orders/{$order['id']}/cancel");
        $this->assertSame(200, $cancel->getStatusCode());
        $this->assertSame('cancelled', Db::table('orders')->where('id', $order['id'])->value('status'));

        $reopen = $this->request('POST', "/api/orders/{$order['id']}/uncomplete");
        $this->assertSame(409, $reopen->getStatusCode(), 'cancelled is terminal');

        // The row survives cancellation — soft cancel, for history/reporting (spec 020).
        $this->assertSame(1, Db::table('orders')->where('id', $order['id'])->count());
    }

    public function testUnknownOrderReturns404(): void
    {
        $response = $this->request('POST', '/api/orders/99999999/complete');

        $this->assertSame(404, $response->getStatusCode());
    }

    /** Spec 022 — an invalid menu item is rejected before anything is persisted. */
    public function testOrderWithNonexistentMenuItemIsRejectedAndPersistsNothing(): void
    {
        $before = Db::table('orders')->count();

        $response = $this->request('POST', '/api/orders', [
            'items' => [['id' => 99999999, 'quantity' => 1]],
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($before, Db::table('orders')->count(), 'Nothing may be persisted on rejection');
    }

    /** AC10 — job creation is asserted at the jobs table, where the roadmap item lives. */
    public function testPrintTicketEnqueuesExactlyOnePendingPrintJob(): void
    {
        $item = $this->availableMenuItem();
        $this->createOrder($item->id, 1, true);

        $jobs = Db::table('jobs')->where('queue', 'print')->get();

        $this->assertCount(1, $jobs);
        $this->assertSame('pending', $jobs[0]->status);
        $this->assertSame(0, (int) $jobs[0]->attempts);
    }

    public function testWithoutPrintTicketNoJobIsEnqueued(): void
    {
        $item = $this->availableMenuItem();
        $this->createOrder($item->id, 1, false);

        $this->assertSame(0, Db::table('jobs')->where('queue', 'print')->count());
    }
}
