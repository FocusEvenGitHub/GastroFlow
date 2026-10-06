import { test, expect, type Page } from '@playwright/test';

/**
 * Mensagens de erro de rede do cliente compartilhado GF.api (spec 056).
 *
 * Só o navegador mostra estes defeitos: sem servidor, o fetch rejeita com um texto que
 * depende do navegador ("Failed to fetch" no Chrome), e um 502 em HTML quebrava o
 * res.json() com "Unexpected token '<'". As falhas são simuladas com page.route — nada
 * muda no servidor nem no banco.
 */

// Cardápio e impressora fixos: o teste é sobre o tratamento de erro, não sobre o estado do
// banco de teste — que acumula itens das outras suítes (tela lenta) e, no CI, uma falha de
// impressão deixada pelos testes de integração, que vira um toast "Erro ao imprimir o
// pedido #N" a mais na tela.
const PRINTER_OK = { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null };
const MENU = [
  {
    category_name: 'Bebidas',
    type: 'drink',
    items: [{ id: 1, name: 'Água', description: '', price: 3, available: true, category_name: 'Bebidas' }],
  },
];

async function submitOneItem(page: Page): Promise<void> {
  await page.route('**/api/menu', (route) => route.fulfill({ json: MENU }));
  await page.route('**/api/printer/status', (route) => route.fulfill({ json: PRINTER_OK }));
  await page.goto('/cashier/');
  await page.locator('.menu-item-card', { hasText: 'Água' }).click();
  await page.getByRole('button', { name: 'Enviar Pedido' }).click();
  await page.getByRole('button', { name: 'Confirmar e enviar' }).click();
}

function toast(page: Page) {
  return page.locator('.gastro-toast-text');
}

test('caixa: servidor fora do ar mostra "Sem conexão com o servidor."', async ({ page }) => {
  await page.route('**/api/orders', (route) =>
    route.request().method() === 'POST' ? route.abort('internetdisconnected') : route.continue()
  );

  await submitOneItem(page);

  await expect(toast(page)).toHaveText('Sem conexão com o servidor.');
});

test('caixa: 502 em HTML mostra o status, não erro de JSON', async ({ page }) => {
  await page.route('**/api/orders', (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({ status: 502, contentType: 'text/html', body: '<html><body>Bad Gateway</body></html>' })
      : route.continue()
  );

  await submitOneItem(page);

  await expect(toast(page)).toHaveText('Erro do servidor (HTTP 502).');
});

test('cozinha: falha de rede na consulta da impressora não gera toast', async ({ page }) => {
  await page.route('**/api/printer/status', (route) => route.abort('internetdisconnected'));
  const failed = page.waitForEvent('requestfailed', (r) => r.url().includes('/api/printer/status'));

  await page.goto('/kitchen/');
  await failed;
  // O catch roda logo depois da rejeição; um quadro de animação basta para o Alpine
  // ter desenhado um toast, se algum tivesse sido criado.
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => r(null))));

  await expect(toast(page)).toHaveCount(0);
});
