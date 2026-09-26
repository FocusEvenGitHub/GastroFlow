<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Setting;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Audit history for sensitive administrative operations (spec 043).
 *
 * Exercised through the real HTTP layer, same as the rest of the integration suite (spec 035),
 * asserting the resulting `audit_log` row rather than reading off the code.
 */
class AuditLogTest extends IntegrationTestCase
{
    /** AC1 */
    public function testUpdatingMenuItemPriceWritesAuditRow(): void
    {
        $item = $this->availableMenuItem();

        $response = $this->request('PATCH', "/api/admin/items/{$item->id}", [
            'price' => 29.90,
        ], $this->authHeader('admin'));

        $this->assertSame(200, $response->getStatusCode(), json_encode($this->decode($response)));

        $row = Db::table('audit_log')->where('action', 'menu_item.updated')->first();
        $this->assertNotNull($row);
        $this->assertSame($item->id, (int) $row->entity_id);
        $this->assertSame('menu_item', $row->entity_type);
        $details = json_decode($row->details, true);
        $this->assertSame(29.9, $details['price']);
    }

    /** AC2, AC3 — printer_ip is just another settings key, same endpoint, same audit action. */
    public function testUpdatingSettingsWritesAuditRowWithOldAndNewValues(): void
    {
        Setting::setValue('printer_ip', '10.0.0.1');

        $response = $this->request('PUT', '/api/admin/settings', [
            'settings' => ['printer_ip' => '10.0.0.5'],
        ], $this->authHeader('admin'));

        $this->assertSame(200, $response->getStatusCode(), json_encode($this->decode($response)));

        $row = Db::table('audit_log')->where('action', 'settings.updated')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->entity_type);
        $this->assertNull($row->entity_id);
        $details = json_decode($row->details, true);
        $this->assertSame('10.0.0.1', $details['printer_ip']['old']);
        $this->assertSame('10.0.0.5', $details['printer_ip']['new']);
    }

    /** AC4 */
    public function testReopeningOrderWritesAuditRow(): void
    {
        $item = $this->availableMenuItem();
        $order = $this->decode($this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => false,
        ]));
        $orderId = (int) $order['id'];

        $this->request('POST', "/api/orders/{$orderId}/complete");
        $response = $this->request('POST', "/api/orders/{$orderId}/uncomplete");

        $this->assertSame(200, $response->getStatusCode(), json_encode($this->decode($response)));

        $row = Db::table('audit_log')->where('action', 'order.reopened')->first();
        $this->assertNotNull($row);
        $this->assertSame($orderId, (int) $row->entity_id);
        $this->assertSame('order', $row->entity_type);
    }

    /** AC5 — a failed request must never write an audit row. */
    public function testFailedMenuUpdateWritesNoAuditRow(): void
    {
        // A price of -1 fails MenuItemValidator's validation.
        $item = $this->availableMenuItem();

        $response = $this->request('PATCH', "/api/admin/items/{$item->id}", [
            'price' => -1,
        ], $this->authHeader('admin'));

        $this->assertSame(400, $response->getStatusCode(), 'Expected validation to reject a negative price');
        $this->assertSame(0, Db::table('audit_log')->count());
    }

    /** AC5 — a not-found order must never write an audit row either. */
    public function testReopeningNonexistentOrderWritesNoAuditRow(): void
    {
        $response = $this->request('POST', '/api/orders/999999/uncomplete');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, Db::table('audit_log')->count());
    }

    /** AC6 */
    public function testAuditLogEndpointRequiresAdminRole(): void
    {
        $this->assertSame(
            200,
            $this->request('GET', '/api/admin/audit-log', null, $this->authHeader('admin'))->getStatusCode()
        );
        $this->assertSame(
            403,
            $this->request('GET', '/api/admin/audit-log', null, $this->authHeader('manager'))->getStatusCode()
        );
        $this->assertSame(401, $this->request('GET', '/api/admin/audit-log')->getStatusCode());
    }
}
