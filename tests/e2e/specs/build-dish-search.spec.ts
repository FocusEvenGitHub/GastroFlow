import { test, expect, type Page } from '@playwright/test';

/**
 * Busca de adicionais no Monte Seu Prato (spec 070) — só existe no navegador: o Tom Select
 * criado a cada abertura do modal, a soma de +1 ao escolher e o campo que volta vazio.
 * Preço e componentes gravados continuam cobertos pela integração (spec 030).
 *
 * Cardápio e impressora fixos via page.route: o teste não depende do estado do banco.
 */

const PRINTER_OK = { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null };

const addon = (id: number, name: string, price: number, food_category: string) => ({
  id, name, description: '', price, available: true, category_name: 'Adicionais', food_category, components: [],
});

const DISH = {
  category_name: 'Pratos Principais',
  type: 'food',
  items: [{ id: 950, name: 'Monte Seu Prato', description: '', price: 15, available: true, is_customizable: true, category_name: 'Pratos Principais', components: [] }],
};

const MENU = [
  DISH,
  {
    category_name: 'Adicionais',
    type: 'food',
    items: [
      addon(951, 'Frango Grelhado', 8, 'protein'),
      addon(952, 'Arroz Branco', 3, 'grain'),
      addon(953, 'Molho Pesto', 2, 'sauce'),
    ],
  },
];

async function openCashier(page: Page, menu: unknown[] = MENU): Promise<string[]> {
  const errors: string[] = [];
  page.on('pageerror', (err) => errors.push(err.message));
  await page.route('**/api/menu', (route) => route.fulfill({ json: menu }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.route('**/api/settings/discount', (route) => route.fulfill({ json: { discount_max_percent: 100 } }));
  await page.goto('/cashier/');
  return errors;
}

const modal = (page: Page) => page.locator('#buildDishModal');
const searchInput = (page: Page) => modal(page).locator('.ts-control input');
const addonQty = (page: Page, name: string) =>
  modal(page).locator('.builder-addon', { hasText: name }).locator('.btn-group span');

async function openBuilder(page: Page): Promise<void> {
  await page.locator('.menu-item-card', { hasText: 'Monte Seu Prato' }).getByRole('button', { name: 'Montar' }).click();
  await expect(modal(page)).toBeVisible();
}

async function pick(page: Page, query: string, name: string): Promise<void> {
  await searchInput(page).click();
  await searchInput(page).fill(query);
  await page.locator('.ts-dropdown .option', { hasText: name }).click();
}

test('caixa: Tom Select carregado do próprio servidor', async ({ page }) => {
  await openCashier(page);
  expect(await page.evaluate(() => typeof (window as unknown as { TomSelect?: unknown }).TomSelect)).toBe('function');
});

test('Monte Seu Prato: campo de busca acima de "Proteínas"', async ({ page }) => {
  await openCashier(page);
  await openBuilder(page);

  await expect(searchInput(page)).toHaveAttribute('placeholder', 'Buscar adicional…');
  const searchTop = (await modal(page).locator('.ts-wrapper').boundingBox())!.y;
  const groupTop = (await modal(page).locator('.builder-group-title', { hasText: 'Proteínas' }).boundingBox())!.y;
  expect(searchTop).toBeLessThan(groupTop);
});

test('Monte Seu Prato: "fran" acha o Frango sob Proteínas; escolher soma 1 e limpa o campo', async ({ page }) => {
  await openCashier(page);
  await openBuilder(page);

  await searchInput(page).click();
  await searchInput(page).fill('fran');
  const options = page.locator('.ts-dropdown .option');
  await expect(options).toHaveCount(1);
  await expect(options.first()).toContainText('Frango Grelhado');
  await expect(page.locator('.ts-dropdown .optgroup-header')).toHaveText(['Proteínas']);

  await options.first().click();
  await expect(addonQty(page, 'Frango Grelhado')).toHaveText('1');
  await expect(modal(page).locator('.builder-chip')).toHaveText(['1x Frango Grelhado']);
  await expect(modal(page).locator('.modal-footer .h4')).toHaveText('R$ 23.00');
  await expect(searchInput(page)).toHaveValue('');
  await expect(modal(page).locator('.ts-control .item')).toHaveCount(0);
});

test('Monte Seu Prato: escolher 11 vezes para em 10', async ({ page }) => {
  await openCashier(page);
  await openBuilder(page);

  for (let i = 0; i < 11; i++) await pick(page, 'fran', 'Frango Grelhado');
  await expect(addonQty(page, 'Frango Grelhado')).toHaveText('10');
});

test('Monte Seu Prato: fechar e reabrir não duplica o campo nem gera erro', async ({ page }) => {
  const errors = await openCashier(page);
  for (let i = 0; i < 3; i++) {
    await openBuilder(page);
    await modal(page).getByRole('button', { name: 'Cancelar' }).click();
    await expect(modal(page)).toBeHidden();
  }
  await openBuilder(page);
  await expect(modal(page).locator('.ts-wrapper')).toHaveCount(1);
  expect(errors).toEqual([]);
});

test('Monte Seu Prato: sem adicionais, sem campo de busca', async ({ page }) => {
  await openCashier(page, [DISH, { category_name: 'Adicionais', type: 'food', items: [] }]);
  await openBuilder(page);

  await expect(modal(page).getByText('Nenhum adicional disponível.')).toBeVisible();
  await expect(modal(page).locator('#addon-search')).toBeHidden();
  await expect(modal(page).locator('.ts-wrapper')).toHaveCount(0);
});

test('Monte Seu Prato: montagem feita pela busca vai ao pedido com os mesmos adicionais', async ({ page }) => {
  await openCashier(page);
  await openBuilder(page);

  await pick(page, 'fran', 'Frango Grelhado');
  await pick(page, 'pes', 'Molho Pesto');
  await modal(page).getByRole('button', { name: 'Adicionar ao pedido' }).click();
  await expect(modal(page)).toBeHidden();

  await expect(page.locator('.selected-item .build-components li')).toHaveText(['+ 1x Frango Grelhado', '+ 1x Molho Pesto']);

  // Editar a montagem reabre com as quantidades salvas; a busca soma sobre elas
  await page.getByRole('button', { name: 'Editar montagem' }).click();
  await expect(addonQty(page, 'Frango Grelhado')).toHaveText('1');
  await pick(page, 'fran', 'Frango Grelhado');
  await expect(addonQty(page, 'Frango Grelhado')).toHaveText('2');
});
