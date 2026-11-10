import { test, expect, type Page } from '@playwright/test';

/**
 * Senha só com números (spec 076) — só o que quebra no navegador: o filtro de dígitos nos
 * campos do caixa e da cozinha, e a cozinha deixar de mandar a senha quando não mudou.
 * A regra do servidor é coberta por OrderValidatorTest e OrderWorkflowTest.
 *
 * Tudo via page.route: o teste não depende do estado do banco.
 */

const PRINTER_OK = { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null };

test('caixa: digitar "4a2" na senha deixa "42"', async ({ page }) => {
  await page.route('**/api/menu', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: 100 } }));
  await page.route('**/api/orders/next-number**', (route) => route.fulfill({ json: { next: 5 } }));
  await page.goto('/cashier/');

  const senha = page.locator('#orderNumber');
  await expect(senha).toHaveAttribute('inputmode', 'numeric');
  await senha.fill('');
  await senha.pressSequentially('4a2');
  await expect(senha).toHaveValue('42');
});

const LEGACY_ORDER = {
  id: 88,
  order_number: 'HIST-1',
  customer_name: null,
  status: 'pending',
  created_at: '2026-10-23 12:00:00',
  created_at_iso: new Date().toISOString(),
  updated_at: '2026-10-23 12:00:00',
  items: [
    {
      item_id: 601, name: 'Prato Teste', description: '', quantity: 1, notes: '', dining_option: 'local',
      unit_price: 20, packaging_cost: 0, category_name: 'Pratos Principais', components: [], discount: null,
    },
  ],
};

async function openKitchen(page: Page): Promise<any[]> {
  await page.route('**/api/menu', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: 100 } }));
  await page.route('**/api/kitchen/food-summary**', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/orders?status=pending**', (route) => route.fulfill({ json: [LEGACY_ORDER] }));
  await page.route('**/api/orders?status=done**', (route) => route.fulfill({ json: [] }));
  const orderPatches: any[] = [];
  await page.route('**/api/orders/88**', (route) => {
    const request = route.request();
    if (request.method() === 'PATCH' && !request.url().includes('/items/')) orderPatches.push(request.postDataJSON());
    return route.fulfill({ json: { success: true } });
  });
  await page.goto('/kitchen/');
  await page.getByTitle('Editar pedido').first().click();
  return orderPatches;
}

const senhaInput = (page: Page) => page.locator('#editOrderModal .edit-order-fields input').nth(0);
const clienteInput = (page: Page) => page.locator('#editOrderModal .edit-order-fields input').nth(1);

test('cozinha: pedido antigo com senha "HIST-1" — mudar só o cliente não manda a senha', async ({ page }) => {
  const orderPatches = await openKitchen(page);

  await expect(senhaInput(page)).toHaveValue('HIST-1');
  await clienteInput(page).fill('Ana');
  await page.locator('#editOrderModal').getByRole('button', { name: 'Salvar' }).click();

  await expect.poll(() => orderPatches.length).toBe(1);
  expect(orderPatches[0]).toEqual({ customer_name: 'Ana' });
});

test('cozinha: a senha aceita só dígitos e vai no PATCH quando muda', async ({ page }) => {
  const orderPatches = await openKitchen(page);

  await senhaInput(page).fill('');
  await senhaInput(page).pressSequentially('1x2');
  await expect(senhaInput(page)).toHaveValue('12');
  await page.locator('#editOrderModal').getByRole('button', { name: 'Salvar' }).click();

  await expect.poll(() => orderPatches.length).toBe(1);
  expect(orderPatches[0]).toEqual({ customer_name: null, order_number: '12' });
});
