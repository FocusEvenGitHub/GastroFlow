import { test, expect, type Page } from '@playwright/test';

/**
 * "Aparece na Cozinha" por item (spec 087) — só o que quebra no navegador: o filtro dos
 * cartões da cozinha, a recarga ao chegar menu.updated e o switch do Admin seguindo a
 * categoria. A regra do servidor é coberta por tests/Integration/ShowInKitchenTest.php.
 *
 * Tudo via page.route: o teste não depende do estado do banco.
 */

const PRINTER_OK = { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null };

const item = (item_id: number, name: string, category_name: string, show_in_kitchen: boolean) => ({
  item_id, name, description: '', quantity: 1, notes: '', dining_option: 'viagem_simples',
  unit_price: 18, packaging_cost: 0, category_name, show_in_kitchen, components: [], discount: null,
});

const order = (ovosVisible: boolean) => ({
  id: 87,
  order_number: '87',
  customer_name: null,
  status: 'pending',
  created_at: '2026-11-24 12:00:00',
  created_at_iso: new Date().toISOString(),
  updated_at: '2026-11-24 12:00:00',
  items: [
    item(1, 'Barça de Carne', 'Pratos Principais', true),
    item(2, 'Cartela de Ovos', 'Viagem', ovosVisible),
  ],
});

async function openKitchen(page: Page, state: { ovosVisible: boolean }) {
  await page.route('**/api/menu', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: 100 } }));
  await page.route('**/api/kitchen/food-summary**', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/orders?status=pending**', (route) => route.fulfill({ json: [order(state.ovosVisible)] }));
  await page.route('**/api/orders?status=done**', (route) => route.fulfill({ json: [] }));
  await page.goto('/kitchen/');
}

test('cozinha: o cartão mostra só itens marcados e acompanha menu.updated sem recarregar', async ({ page }) => {
  const state = { ovosVisible: false };
  await openKitchen(page, state);

  const card = page.locator('.card').filter({ hasText: 'Barça de Carne' }).first();
  await expect(card).toBeVisible();
  await expect(card).not.toContainText('Cartela de Ovos');

  // O Admin ligou o item: o servidor publicaria menu.updated no stream.
  state.ovosVisible = true;
  await page.evaluate(() => {
    const app = (window as any).Alpine.$data(document.querySelector('[x-data]'));
    app.eventSource.dispatchEvent(new MessageEvent('menu.updated', { data: '{}' }));
  });

  await expect(card).toContainText('Cartela de Ovos');
});

const ADMIN_MENU = [
  { category_name: 'Pratos Principais', type: 'food', items: [] },
  {
    category_name: 'Bebidas',
    type: 'drink',
    items: [{ id: 5, name: 'Água', description: '', price: 3, available: true, show_in_kitchen: false, category_name: 'Bebidas', components: [] }],
  },
];

async function openAdmin(page: Page): Promise<any[]> {
  await page.addInitScript(() => {
    localStorage.setItem('admin_token', 'e2e');
    localStorage.setItem('admin_username', 'e2e');
    localStorage.setItem('admin_role', 'admin');
  });
  await page.route('**/api/admin/menu', (route) => route.fulfill({ json: ADMIN_MENU }));
  const patches: any[] = [];
  await page.route('**/api/admin/items/5', (route) => {
    if (route.request().method() === 'PATCH') patches.push(route.request().postDataJSON());
    return route.fulfill({ json: { success: true } });
  });
  await page.goto('/admin/');
  return patches;
}

test('admin: o switch do item novo segue a categoria até o usuário mexer nele', async ({ page }) => {
  await openAdmin(page);
  await page.getByRole('button', { name: 'Novo item' }).click();

  const category = page.locator('#newCategory');
  const kitchen = page.locator('#newShowInKitchen');

  await category.selectOption('Pratos Principais');
  await expect(kitchen).toBeChecked();
  await category.selectOption('Bebidas');
  await expect(kitchen).not.toBeChecked();

  await kitchen.check();
  await category.selectOption('Pratos Principais');
  await category.selectOption('Bebidas');
  await expect(kitchen).toBeChecked();
});

test('admin: o botão do card liga o item na cozinha', async ({ page }) => {
  const patches = await openAdmin(page);

  const card = page.locator('.gf-item-card').filter({ hasText: 'Água' });
  // x-show só esconde: o texto continua no DOM, então a checagem é de visibilidade.
  const badge = card.getByText('Fora da cozinha');
  await expect(badge).toBeVisible();
  await card.getByRole('button', { name: 'Mostrar na tela da cozinha: Água' }).click();

  await expect.poll(() => patches).toEqual([{ show_in_kitchen: true }]);
  await expect(badge).toBeHidden();
});
