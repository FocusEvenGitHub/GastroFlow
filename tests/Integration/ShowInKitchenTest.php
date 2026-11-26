<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\MenuItem;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Spec 087 (issue #80): each menu item carries show_in_kitchen, set in the Admin, and
 * the kitchen's order cards list only the items that have it on.
 */
class ShowInKitchenTest extends IntegrationTestCase
{
    /** @var list<int> menu_items is reference data, not cleared between tests */
    private array $createdItemIds = [];

    protected function tearDown(): void
    {
        if ($this->createdItemIds !== []) {
            // The order created in testOrderPayloadFollowsTheMenuFlag references the item (FK).
            Db::table('order_items')->whereIn('menu_item_id', $this->createdItemIds)->delete();
            Db::table('menu_items')->whereIn('id', $this->createdItemIds)->delete();
        }
        parent::tearDown();
    }

    private function createItem(string $category, ?bool $showInKitchen = null): int
    {
        $body = [
            'category_name' => $category,
            'name'          => 'Spec 087 ' . bin2hex(random_bytes(3)),
            'price'         => 10.0,
        ];
        if ($showInKitchen !== null) {
            $body['show_in_kitchen'] = $showInKitchen;
        }
        $response = $this->request('POST', '/api/admin/items', $body, $this->authHeader('admin'));
        $this->assertSame(201, $response->getStatusCode(), json_encode($this->decode($response)));

        $id = (int) MenuItem::where('name', $body['name'])->value('id');
        $this->createdItemIds[] = $id;
        return $id;
    }

    /** @return array<string, mixed>|null */
    private function adminMenuItem(int $id): ?array
    {
        $menu = $this->decode($this->request('GET', '/api/admin/menu', null, $this->authHeader('admin')));
        foreach ($menu as $category) {
            foreach ($category['items'] ?? [] as $item) {
                if ($item['id'] === $id) {
                    return $item;
                }
            }
        }
        return null;
    }

    /** AC4 */
    public function testNewItemDefaultsToVisibleOnlyForMainDishes(): void
    {
        $main = $this->createItem('Pratos Principais');
        $drink = $this->createItem('Bebidas');

        $this->assertTrue($this->adminMenuItem($main)['show_in_kitchen'] ?? null);
        $this->assertFalse($this->adminMenuItem($drink)['show_in_kitchen'] ?? null);

        $explicit = $this->createItem('Bebidas', true);
        $this->assertTrue($this->adminMenuItem($explicit)['show_in_kitchen'] ?? null);
    }

    /** AC2, AC7 */
    public function testPatchTogglesTheFlagAuditsItAndPublishesMenuUpdated(): void
    {
        $id = $this->createItem('Bebidas');
        Db::table('events')->delete();

        $response = $this->request('PATCH', "/api/admin/items/{$id}", ['show_in_kitchen' => true], $this->authHeader('admin'));

        $this->assertSame(200, $response->getStatusCode(), json_encode($this->decode($response)));
        $this->assertTrue($this->adminMenuItem($id)['show_in_kitchen'] ?? null);

        $audit = Db::table('audit_log')->where('action', 'menu_item.updated')->where('entity_id', $id)->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('show_in_kitchen', (string) $audit->details);

        $this->assertSame(1, Db::table('events')->where('type', 'menu.updated')->count());
    }

    /** AC7 — create and delete also notify the kitchen */
    public function testCreateAndDeletePublishMenuUpdated(): void
    {
        $id = $this->createItem('Bebidas');
        $this->assertSame(200, $this->request('DELETE', "/api/admin/items/{$id}", null, $this->authHeader('admin'))->getStatusCode());

        $this->assertSame(2, Db::table('events')->where('type', 'menu.updated')->count());
    }

    /** AC3 */
    public function testNonBooleanIsRejected(): void
    {
        $id = $this->createItem('Bebidas');

        $response = $this->request('PATCH', "/api/admin/items/{$id}", ['show_in_kitchen' => 'sim'], $this->authHeader('admin'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('show_in_kitchen', $this->decode($response)['messages'] ?? []);
    }

    /** AC5 — the order payload reads the flag live from the menu */
    public function testOrderPayloadFollowsTheMenuFlag(): void
    {
        $id = $this->createItem('Bebidas');
        $create = $this->request('POST', '/api/orders', ['items' => [['id' => $id, 'quantity' => 1]]]);
        $this->assertSame(201, $create->getStatusCode(), json_encode($this->decode($create)));

        $flag = function () use ($id): ?bool {
            foreach ($this->decode($this->request('GET', '/api/orders?status=pending')) as $order) {
                foreach ($order['items'] as $item) {
                    if ($item['name'] === MenuItem::find($id)->name) {
                        return $item['show_in_kitchen'];
                    }
                }
            }
            return null;
        };

        $this->assertFalse($flag());
        $this->request('PATCH', "/api/admin/items/{$id}", ['show_in_kitchen' => true], $this->authHeader('admin'));
        $this->assertTrue($flag());
    }
}
