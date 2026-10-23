import { test, expect, type Page } from '@playwright/test';

/**
 * Desconto por item (spec 058) — só o que quebra no navegador: o preço da linha como botão,
 * o modal "Desconto no item", os bindings de habilitado/desabilitado e o que a cozinha mostra.
 * Regras e valores gravados são cobertos pela suíte de integração (ItemDiscountTest).
 *
 * Cardápio, teto e impressora fixos via page.route: o teste não depende do estado do banco.
 */

const PRINTER_OK = { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null };
const MENU = [
  {
    category_name: 'Pratos Principais',
    type: 'food',
    items: [{ id: 901, name: 'Prato Teste', description: '', price: 20, available: true, category_name: 'Pratos Principais', components: [] }],
  },
];

async function openCashier(page: Page, maxPercent = 100): Promise<void> {
  await page.route('**/api/menu', (route) => route.fulfill({ json: MENU }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: maxPercent } }));
  await page.goto('/cashier/');
}

async function addPrato(page: Page, times = 1): Promise<void> {
  for (let i = 0; i < times; i++) {
    await page.locator('.menu-item-card', { hasText: 'Prato Teste' }).getByRole('button', { name: 'Adicionar' }).click();
  }
}

const priceTag = (page: Page) => page.getByTestId('price-tag');
const applyButton = (page: Page) => page.getByTestId('discount-apply');

test('caixa: tocar no preço abre "Desconto no item"; 20% mostra preço riscado, selo e total', async ({ page }) => {
  await openCashier(page);
  await addPrato(page, 2);

  await priceTag(page).click();
  await expect(page.locator('#discountModal .modal-title')).toContainText('Desconto em 2x Prato Teste');
  await page.getByTestId('discount-value').fill('20');
  await expect(page.locator('#discountModal .discount-preview')).toContainText('por R$ 32,00');
  await applyButton(page).click();

  await expect(page.locator('.selected-item .price-old')).toHaveText('R$ 40.00');
  await expect(page.locator('.selected-item .discount-badge')).toHaveText('−20%');
  await expect(page.getByTestId('discounts-total')).toContainText('−R$ 8.00');
  await expect(page.getByTestId('order-total')).toHaveText('32.00');
});

test('caixa: valor acima do item desabilita "Aplicar" e explica', async ({ page }) => {
  await openCashier(page);
  await addPrato(page, 2);

  await priceTag(page).click();
  await page.getByTestId('discount-type-fixed').click();
  await page.getByTestId('discount-value').fill('41');

  await expect(page.getByTestId('discount-error')).toHaveText('O desconto não pode ser maior que o valor do item (R$ 40,00).');
  await expect(applyButton(page)).toBeDisabled();

  await page.getByTestId('discount-value').fill('5,50');
  await expect(applyButton(page)).toBeEnabled();
});

test('caixa: teto do Admin limita o desconto e não há botões de porcentagem prontos', async ({ page }) => {
  await openCashier(page, 30);
  await addPrato(page, 2);

  await priceTag(page).click();
  await expect(page.locator('#discountModal').getByRole('button', { name: /^\d+%$/ })).toHaveCount(0);
  await page.getByTestId('discount-value').fill('31');
  await expect(page.getByTestId('discount-error')).toHaveText('O desconto máximo permitido é de 30% (R$ 12,00 neste item).');
  await expect(applyButton(page)).toBeDisabled();
});

test('caixa: teto 0 deixa o preço como texto, sem botão', async ({ page }) => {
  await openCashier(page, 0);
  await addPrato(page);

  await expect(page.locator('.selected-item')).toContainText('R$ 20.00');
  await expect(priceTag(page)).toHaveCount(0);
});

test('caixa: diminuir a quantidade com desconto em R$ que não cabe mais bloqueia o envio', async ({ page }) => {
  await openCashier(page);
  await addPrato(page, 2);

  await priceTag(page).click();
  await page.getByTestId('discount-type-fixed').click();
  await page.getByTestId('discount-value').fill('30');
  await applyButton(page).click();
  await expect(page.getByTestId('send-order')).toBeEnabled();

  await page.locator('.selected-item .quantity-btn', { hasText: '−' }).click();

  await expect(page.locator('.selected-item .discount-badge')).toHaveText('Revise o desconto');
  await expect(page.getByTestId('send-order')).toBeDisabled();
});

test('caixa: o desconto vai no payload do pedido', async ({ page }) => {
  await openCashier(page);
  let payload: any = null;
  await page.route('**/api/orders', (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    payload = route.request().postDataJSON();
    return route.fulfill({ status: 201, json: { success: true, id: 1, message: 'Order created' } });
  });
  await addPrato(page, 2);
  await priceTag(page).click();
  await page.getByTestId('discount-value').fill('12,5');
  await applyButton(page).click();

  await page.getByTestId('send-order').click();
  await page.getByRole('button', { name: 'Confirmar e enviar' }).click();

  await expect.poll(() => payload).not.toBeNull();
  expect(payload.items[0].discount).toEqual({ type: 'percent', value: '12.50' });
});

// ── Cozinha ──

const KITCHEN_ORDER = {
  id: 77,
  order_number: '77',
  customer_name: null,
  status: 'pending',
  created_at: '2026-10-23 12:00:00',
  created_at_iso: new Date().toISOString(),
  updated_at: '2026-10-23 12:00:00',
  items: [
    {
      item_id: 501, name: 'Prato Teste', description: '', quantity: 2, notes: '', dining_option: 'local',
      unit_price: 20, packaging_cost: 0, category_name: 'Pratos Principais', components: [],
      discount: { type: 'percent', value: 20, amount: 8 },
    },
  ],
};

async function openKitchen(page: Page): Promise<void> {
  await page.route('**/api/menu', (route) => route.fulfill({ json: MENU }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: 100 } }));
  await page.route('**/api/kitchen/food-summary**', (route) => route.fulfill({ json: [] }));
  await page.route('**/api/orders?status=pending**', (route) => route.fulfill({ json: [KITCHEN_ORDER] }));
  await page.route('**/api/orders?status=done**', (route) => route.fulfill({ json: [] }));
  await page.goto('/kitchen/');
}

test('cozinha: o quadro não mostra nada de desconto', async ({ page }) => {
  await openKitchen(page);
  await expect(page.getByText('Prato Teste').first()).toBeVisible();

  const text = await page.locator('body').innerText();
  expect(text).not.toMatch(/desconto/i);
  expect(text).not.toContain('R$');
});

test('cozinha: "Editar Pedido" mostra e altera o desconto do item, e só manda o que mudou', async ({ page }) => {
  await openKitchen(page);
  const patches: any[] = [];
  await page.route('**/api/orders/77**', (route) => {
    const method = route.request().method();
    if (method === 'PATCH') patches.push({ url: route.request().url(), body: route.request().postDataJSON() });
    return route.fulfill({ json: { success: true } });
  });

  await page.getByTitle('Editar pedido').first().click();
  await expect(page.getByTestId('kitchen-discount-summary')).toHaveText('−20% (−R$ 8,00)');

  // Só a quantidade muda: o desconto não vai no PATCH (o servidor reajusta).
  await page.locator('#editOrderModal .qty-stepper button', { hasText: '−' }).click();
  await expect(page.getByTestId('kitchen-discount-summary')).toHaveText('−20% (−R$ 4,00)');
  await page.locator('#editOrderModal').getByRole('button', { name: 'Salvar' }).click();
  await expect.poll(() => patches.length).toBe(2);
  expect(patches[1].body).toEqual({ quantity: 1, notes: '', dining_option: 'local' });

  // Agora a cozinha troca o desconto: ele vai no PATCH.
  await page.getByTitle('Editar pedido').first().click();
  await page.locator('#editOrderModal').getByRole('button', { name: 'Alterar' }).click();
  await page.locator('#editOrderModal').getByRole('button', { name: 'Valor' }).click();
  await page.locator('#editOrderModal input[inputmode="decimal"]').fill('5');
  await page.locator('#editOrderModal').getByRole('button', { name: 'Aplicar' }).click();
  await expect(page.getByTestId('kitchen-discount-summary')).toHaveText('−R$ 5,00 (−R$ 5,00)');
  await page.locator('#editOrderModal').getByRole('button', { name: 'Salvar' }).click();
  await expect.poll(() => patches.length).toBe(4);
  expect(patches[3].body.discount).toEqual({ type: 'fixed', value: '5.00' });
});
