<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Money;
use App\Services\PricingService;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Menu mutations, reports and the end-to-end journey (spec 035: AC11, AC12).
 *
 * The journey is exercised through the application's HTTP layer, not a browser —
 * the roadmap says explicitly not to drive the frontend through automation.
 */
class MenuReportsAndJourneyTest extends IntegrationTestCase
{
    /** AC12 */
    public function testAdminCanCreateAMenuItemAndItAppearsOnThePublicMenu(): void
    {
        $headers = $this->authHeader('admin');
        // The endpoint takes category_name, not category_id (MenuItemValidator:27).
        $categoryName = (string) Db::table('categories')->orderBy('id')->value('name');
        $name = 'Teste Integração ' . bin2hex(random_bytes(3));

        $create = $this->request('POST', '/api/admin/items', [
            'category_name' => $categoryName,
            'name'          => $name,
            'description'   => 'criado pelo teste de integração',
            'price'         => 12.34,
            'available'     => true,
        ], $headers);

        $this->assertSame(201, $create->getStatusCode(), json_encode($this->decode($create)));

        $menu = $this->decode($this->request('GET', '/api/menu'));
        $names = [];
        foreach ($menu['categories'] ?? $menu as $category) {
            foreach ($category['items'] ?? [] as $item) {
                $names[] = $item['name'];
            }
        }

        $this->assertContains($name, $names, 'Newly created item must appear on the public menu');

        // Clean up: menu_items is reference data and is not cleared between tests.
        Db::table('menu_items')->where('name', $name)->delete();
    }

    public function testMenuMutationIsRefusedWithoutAToken(): void
    {
        $response = $this->request('POST', '/api/admin/items', [
            'category_id' => 1,
            'name'        => 'não deve existir',
            'price'       => 1.0,
        ]);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testSalesReportIsEmptyWhenNoOrderIsDone(): void
    {
        $response = $this->request('GET', '/api/admin/reports/sales', null, $this->authHeader('admin'));
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame([], $body['data'], 'No completed orders yet, so no rows');
    }

    /**
     * AC11 — the whole journey: cashier creates, kitchen sees it, it is completed, and the
     * report reflects the money. This is the path the product exists to run.
     */
    public function testEndToEndJourneyFromOrderToReport(): void
    {
        $item = $this->availableMenuItem();
        $quantity = 2;

        // 1. Cashier creates the order.
        $create = $this->request('POST', '/api/orders', [
            'items' => [['id' => $item->id, 'quantity' => $quantity]],
        ]);
        $this->assertSame(201, $create->getStatusCode());
        $orderId = (int) $this->decode($create)['id'];

        // 2. Kitchen sees it among the pending orders.
        // OrderController::index() writes the list directly, with no envelope.
        $pending = $this->decode($this->request('GET', '/api/orders?status=pending'));
        $listed = array_map(static fn (array $o): int => (int) $o['id'], $pending);
        $this->assertContains($orderId, $listed, 'Kitchen must see the new order as pending');

        // 3. It is completed.
        $this->assertSame(200, $this->request('POST', "/api/orders/{$orderId}/complete")->getStatusCode());

        // 4. The report reflects it, for that order's own business date.
        $businessDate = (string) Db::table('orders')->where('id', $orderId)->value('business_date');

        $report = $this->decode($this->request(
            'GET',
            "/api/admin/reports/sales?date_from={$businessDate}&date_to={$businessDate}",
            null,
            $this->authHeader('admin')
        ));

        $this->assertCount(1, $report['data'], 'Exactly one business day should appear');
        $row = $report['data'][0];

        $this->assertSame(1, (int) $row['orders']);
        $this->assertSame($quantity, (int) $row['items_sold']);

        // The revenue must equal what PricingService says the line is worth, to the cent.
        // The order was created without a dining_option, so the default applies.
        $pricing = new PricingService();
        $diningOption = (string) Db::table('order_items')
            ->where('order_id', $orderId)
            ->value('dining_option');

        $unit = $pricing->unitPriceFor($item);
        $packaging = $pricing->packagingFeeFor($diningOption, $quantity);
        $expectedCents = ($unit->getCents() * $quantity) + $packaging->getCents();

        $this->assertSame(
            $expectedCents,
            Money::fromReais($row['revenue'])->getCents(),
            'Reported revenue must match exact pricing, in cents'
        );
    }
}
