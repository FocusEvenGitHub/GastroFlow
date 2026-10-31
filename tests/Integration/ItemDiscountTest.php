<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Setting;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Item discount end to end against real MySQL (spec 058): cashier order
 * creation, kitchen "Editar Pedido" changes, automatic adjustment, reports,
 * audit and the admin maximum.
 */
class ItemDiscountTest extends IntegrationTestCase
{
    private int $prato;
    private int $coca;
    private ?string $previousMax = null;
    /** False when parent::setUp() skipped the test: tearDown() must not touch the database then. */
    private bool $settingsSaved = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Own reference items with known prices: other tests edit seeded menu prices.
        $this->prato = $this->menuItem('Pratos Principais', 'Prato Desconto (teste)', 20.0);
        $this->coca = $this->menuItem('Bebidas', 'Bebida Desconto (teste)', 5.0);
        $this->previousMax = Setting::getValue('discount_max_percent');
        $this->settingsSaved = true;
        Setting::setValue('discount_max_percent', '100');
    }

    protected function tearDown(): void
    {
        // settings is reference data, not cleared between tests — restore it.
        // PHPUnit runs tearDown() even after a skip in setUp() (no MYSQL_DATABASE_TEST).
        if ($this->settingsSaved) {
            Setting::setValue('discount_max_percent', $this->previousMax ?? '100');
        }
        parent::tearDown();
    }

    private function menuItem(string $category, string $name, float $price): int
    {
        $categoryId = (int) Db::table('categories')->where('name', $category)->value('id');
        $id = Db::table('menu_items')->where('name', $name)->value('id');
        if ($id === null) {
            $id = Db::table('menu_items')->insertGetId([
                'category_id' => $categoryId, 'name' => $name, 'description' => '', 'price' => $price,
            ]);
        }
        Db::table('menu_items')->where('id', $id)->update(['price' => $price, 'available' => 1, 'is_customizable' => 0]);
        return (int) $id;
    }

    /** @param list<array<string, mixed>> $items */
    private function post(array $items): \Psr\Http\Message\ResponseInterface
    {
        return $this->request('POST', '/api/orders', ['items' => $items, 'print_ticket' => false]);
    }

    /** @param list<array<string, mixed>> $items */
    private function createOrder(array $items): int
    {
        $response = $this->post($items);
        $body = $this->decode($response);
        $this->assertSame(201, $response->getStatusCode(), json_encode($body));
        return (int) $body['id'];
    }

    private function line(int $orderId, int $menuItemId): object
    {
        $row = Db::table('order_items')->where('order_id', $orderId)->where('menu_item_id', $menuItemId)->first();
        $this->assertNotNull($row);
        return $row;
    }

    private function orderTotal(int $orderId): float
    {
        return round((float) Db::table('order_items')->where('order_id', $orderId)
            ->sum(Db::raw('unit_price * quantity + packaging_cost - discount_amount')), 2);
    }

    private function aliceOrder(): int
    {
        // AC1 order: 2× Prato at 20% + 1× Coca.
        return $this->createOrder([
            ['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'percent', 'value' => 20]],
            ['id' => $this->coca, 'quantity' => 1],
        ]);
    }

    public function testPercentDiscountIsStoredOnTheLine(): void
    {
        $orderId = $this->aliceOrder();

        $prato = $this->line($orderId, $this->prato);
        $this->assertSame('percent', $prato->discount_type);
        $this->assertSame('20.00', $prato->discount_value);
        $this->assertSame('8.00', $prato->discount_amount);
        $this->assertSame('0.00', $this->line($orderId, $this->coca)->discount_amount);
        $this->assertSame(37.0, $this->orderTotal($orderId));
    }

    public function testFixedDiscountAppliesToTheWholeLine(): void
    {
        $orderId = $this->createOrder([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'fixed', 'value' => 5]]]);
        $this->assertSame('5.00', $this->line($orderId, $this->prato)->discount_amount);
        $this->assertSame(35.0, $this->orderTotal($orderId));
    }

    public function testPackagingIsNotDiscounted(): void
    {
        $orderId = $this->createOrder([[
            'id' => $this->prato, 'quantity' => 1, 'dining_option' => 'viagem_vip',
            'discount' => ['type' => 'percent', 'value' => 100],
        ]]);
        $row = $this->line($orderId, $this->prato);
        $this->assertSame('20.00', $row->discount_amount);
        $this->assertSame(round((float) $row->packaging_cost, 2), $this->orderTotal($orderId));
        $this->assertGreaterThan(0, (float) $row->packaging_cost);
    }

    public function testPercentRoundsHalfUp(): void
    {
        $orderId = $this->createOrder([['id' => $this->coca, 'quantity' => 3, 'discount' => ['type' => 'percent', 'value' => 33.33]]]);
        $this->assertSame('5.00', $this->line($orderId, $this->coca)->discount_amount);
    }

    public function testDiscountAboveTheItemRejectsTheWholeOrderWithoutConsumingANumber(): void
    {
        $next = $this->decode($this->request('GET', '/api/orders/next-number'))['next'];

        $response = $this->post([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'fixed', 'value' => 40.01]]]);
        $body = $this->decode($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_DISCOUNT', $body['code']);
        $this->assertSame('Prato Desconto (teste): O desconto não pode ser maior que o valor do item (R$ 40,00).', $body['error']);
        $this->assertSame(0, Db::table('orders')->count());
        $this->assertSame($next, $this->decode($this->request('GET', '/api/orders/next-number'))['next']);
    }

    public function testAdminMaximumIsEnforced(): void
    {
        Setting::setValue('discount_max_percent', '30');

        $this->assertSame(400, $this->post([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'percent', 'value' => 31]]])->getStatusCode());
        $this->assertSame(400, $this->post([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'fixed', 'value' => 12.01]]])->getStatusCode());
        $this->assertSame(201, $this->post([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'fixed', 'value' => 12]]])->getStatusCode());
    }

    public function testMaximumZeroDisablesDiscounts(): void
    {
        Setting::setValue('discount_max_percent', '0');

        $response = $this->post([['id' => $this->prato, 'quantity' => 1, 'discount' => ['type' => 'percent', 'value' => 10]]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Prato Desconto (teste): O desconto está desativado.', $this->decode($response)['error']);
        // Without a discount the cashier keeps working normally.
        $this->assertSame(201, $this->post([['id' => $this->prato, 'quantity' => 1]])->getStatusCode());
    }

    public function testMalformedDiscountIsAValidationError(): void
    {
        foreach ([['type' => 'half', 'value' => 5], ['type' => 'percent', 'value' => -5], ['type' => 'percent', 'value' => '1e1'], ['type' => 'fixed', 'value' => 1.005], ['type' => 'percent', 'value' => 0]] as $discount) {
            $response = $this->post([['id' => $this->prato, 'quantity' => 1, 'discount' => $discount]]);
            $this->assertSame(400, $response->getStatusCode(), json_encode($discount));
            $this->assertSame('VALIDATION_FAILED', $this->decode($response)['code']);
        }
    }

    public function testKitchenCannotSendADiscountWhenAddingAnItem(): void
    {
        $orderId = $this->aliceOrder();
        $response = $this->request('POST', "/api/orders/{$orderId}/items", [
            'menu_item_id' => $this->coca, 'quantity' => 1, 'discount' => ['type' => 'percent', 'value' => 10],
        ]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', $this->decode($response)['code']);

        $added = $this->request('POST', "/api/orders/{$orderId}/items", ['menu_item_id' => $this->coca, 'quantity' => 1]);
        $this->assertSame(201, $added->getStatusCode());
        $this->assertNull($this->decode($added)['item']['discount']);
    }

    public function testKitchenQuantityChangeAdjustsTheDiscountAutomatically(): void
    {
        $orderId = $this->aliceOrder();
        $prato = $this->line($orderId, $this->prato);

        $response = $this->request('PATCH', "/api/orders/{$orderId}/items/{$prato->id}", ['quantity' => 1]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('4.00', $this->line($orderId, $this->prato)->discount_amount);

        $fixedOrder = $this->createOrder([['id' => $this->prato, 'quantity' => 2, 'discount' => ['type' => 'fixed', 'value' => 30]]]);
        $fixedLine = $this->line($fixedOrder, $this->prato);
        $this->request('PATCH', "/api/orders/{$fixedOrder}/items/{$fixedLine->id}", ['quantity' => 1]);
        $this->assertSame('20.00', $this->line($fixedOrder, $this->prato)->discount_amount);
        $this->assertSame('30.00', $this->line($fixedOrder, $this->prato)->discount_value);
    }

    public function testKitchenCanSetAndRemoveADiscount(): void
    {
        $orderId = $this->aliceOrder();
        $prato = $this->line($orderId, $this->prato);
        $path = "/api/orders/{$orderId}/items/{$prato->id}";

        $set = $this->request('PATCH', $path, ['quantity' => 2, 'discount' => ['type' => 'percent', 'value' => 10]]);
        $this->assertSame(200, $set->getStatusCode());
        $this->assertSame('4.00', $this->line($orderId, $this->prato)->discount_amount);

        $tooMuch = $this->request('PATCH', $path, ['quantity' => 1, 'discount' => ['type' => 'fixed', 'value' => 50]]);
        $this->assertSame(400, $tooMuch->getStatusCode());
        $this->assertSame('INVALID_DISCOUNT', $this->decode($tooMuch)['code']);
        $unchanged = $this->line($orderId, $this->prato);
        $this->assertSame(2, (int) $unchanged->quantity, 'a rejected discount must roll back the whole item update');
        $this->assertSame('4.00', $unchanged->discount_amount);

        Setting::setValue('discount_max_percent', '30');
        $this->assertSame(400, $this->request('PATCH', $path, ['discount' => ['type' => 'percent', 'value' => 31]])->getStatusCode());

        $removed = $this->request('PATCH', $path, ['discount' => null]);
        $this->assertSame(200, $removed->getStatusCode());
        $row = $this->line($orderId, $this->prato);
        $this->assertNull($row->discount_type);
        $this->assertNull($row->discount_value);
        $this->assertSame('0.00', $row->discount_amount);
    }

    public function testOrdersListShowsTheItemDiscount(): void
    {
        $orderId = $this->aliceOrder();

        $orders = $this->decode($this->request('GET', '/api/orders?status=pending'));
        $order = array_values(array_filter($orders, fn ($o) => $o['id'] === $orderId))[0];
        $byName = array_column($order['items'], null, 'name');

        $this->assertSame(['type' => 'percent', 'value' => 20, 'amount' => 8], $byName['Prato Desconto (teste)']['discount']);
        $this->assertNull($byName['Bebida Desconto (teste)']['discount']);
    }

    public function testAuditRecordsOnlyPeopleChoosingADiscount(): void
    {
        $orderId = $this->aliceOrder();
        $rows = Db::table('audit_log')->where('action', 'order.discount_applied')->get();
        $this->assertCount(1, $rows);
        $details = json_decode((string) $rows[0]->details, true);
        $this->assertSame('cashier', $details['source']);
        $this->assertSame(['Prato Desconto (teste)'], array_column($details['items'], 'name'));

        $prato = $this->line($orderId, $this->prato);
        $path = "/api/orders/{$orderId}/items/{$prato->id}";
        $this->request('PATCH', $path, ['quantity' => 1]); // automatic adjustment: not audited
        $this->assertSame(1, Db::table('audit_log')->where('action', 'like', 'order.discount%')->count());

        $this->request('PATCH', $path, ['discount' => ['type' => 'fixed', 'value' => 3]]);
        $kitchen = Db::table('audit_log')->where('action', 'order.discount_applied')->orderByDesc('id')->first();
        $this->assertSame('kitchen', json_decode((string) $kitchen->details, true)['source']);

        $this->request('PATCH', $path, ['discount' => null]);
        $this->assertSame(1, Db::table('audit_log')->where('action', 'order.discount_removed')->count());

        $this->request('PATCH', $path, ['discount' => null]); // nothing to remove: no new row
        $this->assertSame(1, Db::table('audit_log')->where('action', 'order.discount_removed')->count());
    }

    public function testReportsAreNetOfDiscounts(): void
    {
        $orderId = $this->aliceOrder();
        $this->request('POST', "/api/orders/{$orderId}/complete");
        $date = (string) Db::table('orders')->where('id', $orderId)->value('business_date');
        $auth = $this->authHeader('manager');
        $range = "date_from={$date}&date_to={$date}";

        $summary = $this->decode($this->request('GET', "/api/admin/reports/summary?date={$date}", null, $auth));
        $this->assertSame(37.0, (float) $summary['data']['revenue']);
        $this->assertSame(8.0, (float) $summary['data']['discount_total']);

        $top = $this->decode($this->request('GET', "/api/admin/reports/top-items?{$range}", null, $auth));
        $byName = array_column($top['data'], 'total_revenue', 'name');
        $this->assertSame(32.0, (float) $byName['Prato Desconto (teste)']);
        $this->assertSame(5.0, (float) $byName['Bebida Desconto (teste)']);

        $sales = $this->decode($this->request('GET', "/api/admin/reports/sales?{$range}", null, $auth));
        $this->assertSame(37.0, (float) $sales['data'][0]['revenue']);

        $discounts = $this->decode($this->request('GET', "/api/admin/reports/discounts?{$range}", null, $auth));
        $this->assertSame(8.0, (float) $discounts['data']['total_discount']);
        $this->assertSame(1, $discounts['data']['orders_with_discount']);
        $this->assertSame(45.0, (float) $discounts['data']['gross_products']);
        // JSON carries 8.0 as 8, hence assertEquals.
        $this->assertEquals([['name' => 'Prato Desconto (teste)', 'lines' => 1, 'total' => 8]], $discounts['data']['top_items']);
    }

    public function testDiscountReportRequiresAdminOrManager(): void
    {
        $this->assertSame(401, $this->request('GET', '/api/admin/reports/discounts')->getStatusCode());
        $this->assertSame(403, $this->request('GET', '/api/admin/reports/discounts', null, $this->authHeader('cashier'))->getStatusCode());
    }

    public function testDiscountMaximumSettingIsValidatedAndPublic(): void
    {
        $admin = $this->authHeader('admin');

        $bad = $this->request('PUT', '/api/admin/settings', ['settings' => ['discount_max_percent' => '101']], $admin);
        $this->assertSame(400, $bad->getStatusCode());

        $ok = $this->request('PUT', '/api/admin/settings', ['settings' => ['discount_max_percent' => '30']], $admin);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(['discount_max_percent' => 30], $this->decode($this->request('GET', '/api/settings/discount')));

        $manager = $this->request('PUT', '/api/admin/settings', ['settings' => ['discount_max_percent' => '50']], $this->authHeader('manager'));
        $this->assertSame(403, $manager->getStatusCode());
        $this->assertSame('30', Setting::getValue('discount_max_percent'));
    }
}
