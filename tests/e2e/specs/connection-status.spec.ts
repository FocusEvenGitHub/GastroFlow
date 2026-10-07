import { test, expect as baseExpect, type Page } from '@playwright/test';

/**
 * Indicador de conexão do Caixa e da Cozinha (spec 057).
 *
 * Só o navegador mostra isto: o estado vive no Alpine (GF.ui() em public/assets/js/gf.js),
 * alimentado por um heartbeat em /health/ready e, na cozinha, pelo EventSource. As quedas
 * são simuladas com page.route — nada muda no servidor.
 *
 * Para não esperar o relógio de verdade: o evento `online` dispara um heartbeat na hora
 * (FR5), e GF.LOST_AFTER_MS é lido a cada atualização, então zerá-lo leva direto a
 * "Conexão perdida" no heartbeat seguinte. Os 15 s em si são uma subtração — não precisam
 * de navegador para serem provados.
 */

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8081';

// Esperas de 20 s, não os 5 s padrão: as transições dependem do heartbeat e do stream da
// cozinha, que só abre depois do primeiro carregamento — e no Docker Desktop/Windows, com o
// banco de teste acumulando dados das outras suítes, cada requisição leva segundos (medido em
// 2026-10-07: /health/live de 0,6 a 6 s com o container ocioso). Espera por condição, não
// sleep: num servidor rápido o teste termina tão rápido quanto antes.
const expect = baseExpect.configure({ timeout: 20_000 });
const PHP_DIRECT = process.env.E2E_PHP_DIRECT === '1';

function pill(page: Page) {
  return page.locator('.gf-conn');
}

function lostBanner(page: Page) {
  return page.locator('.alert', { hasText: 'Sem conexão com o servidor desde' });
}

async function beatNow(page: Page): Promise<void> {
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
}

async function failHealth(page: Page, how: 'abort' | '503' = 'abort'): Promise<void> {
  await page.route('**/health/ready', (route) =>
    how === 'abort'
      ? route.abort('internetdisconnected')
      : route.fulfill({ status: 503, json: { success: false, error: 'Banco de dados indisponível.' } })
  );
}

test('caixa: servidor saudável mostra "Conectado" e nenhum aviso', async ({ page }) => {
  await page.goto('/cashier/');

  await expect(pill(page)).toHaveText('Conectado');
  await expect(lostBanner(page)).toHaveCount(0);
});

test('caixa: queda → Reconectando → Conexão perdida → Conectado, sem toast', async ({ page }) => {
  // Impressora fixa: no CI o banco de teste guarda uma falha de impressão dos testes de
  // integração, que viraria um toast alheio a este teste (mesmo motivo de api-errors.spec.ts).
  await page.route('**/api/printer/status', (route) =>
    route.fulfill({ json: { success: true, blocked: false, consecutive_failures: 0, last_failed_order_id: null } })
  );
  await page.goto('/cashier/');
  await expect(pill(page)).toHaveText('Conectado');

  await failHealth(page);
  await beatNow(page);
  await expect(pill(page)).toHaveText('Reconectando…');
  await expect(lostBanner(page)).toHaveCount(0);

  await page.evaluate('GF.LOST_AFTER_MS = 0');
  await beatNow(page);
  await expect(pill(page)).toHaveText('Conexão perdida');
  await expect(lostBanner(page)).toHaveText(/desde \d{2}:\d{2}\.\s*Pedidos não poderão ser enviados/);

  await page.unroute('**/health/ready');
  await beatNow(page);
  await expect(pill(page)).toHaveText('Conectado');
  await expect(lostBanner(page)).toHaveCount(0);

  // AC7: o heartbeat nunca gera toast.
  await expect(page.locator('.gastro-toast')).toHaveCount(0);
});

test('caixa: banco fora do ar (503 em /health/ready) também sai de "Conectado"', async ({ page }) => {
  await page.goto('/cashier/');
  await expect(pill(page)).toHaveText('Conectado');

  await failHealth(page, '503');
  await beatNow(page);

  await expect(pill(page)).toHaveText('Reconectando…');
});

test('caixa: aberto sem servidor, carrega o cardápio sozinho quando a conexão volta', async ({ page }) => {
  // Cardápio fixo quando o servidor "volta": o do banco de teste acumula categorias e itens
  // das outras suítes (510 categorias medidas em 2026-10-07) e nem o caixa saudável chega a
  // desenhar os cards — o teste é sobre a reconexão, não sobre o volume do banco.
  const MENU = [
    {
      category_name: 'Bebidas',
      type: 'drink',
      items: [{ id: 1, name: 'Água', description: '', price: 3, available: true, category_name: 'Bebidas' }],
    },
  ];
  let serverUp = false;
  await page.route('**/health/ready', (route) => (serverUp ? route.continue() : route.abort('internetdisconnected')));
  await page.route('**/api/menu', (route) => (serverUp ? route.fulfill({ json: MENU }) : route.abort('internetdisconnected')));

  await page.goto('/cashier/');
  await expect(pill(page)).toHaveText('Reconectando…');
  await expect(page.locator('.menu-item-card')).toHaveCount(0);

  serverUp = true;
  await beatNow(page);

  await expect(pill(page)).toHaveText('Conectado');
  await expect(page.locator('.menu-item-card', { hasText: 'Água' })).toBeVisible();
});

test('cozinha: stream de tempo real caído nunca aparece como "Conectado"', async ({ page }) => {
  // /health/ready fica saudável — só o stream falha.
  await page.route('**/api/events/stream.php', (route) => route.abort('internetdisconnected'));

  await page.goto('/kitchen/');

  await expect(pill(page)).toHaveText('Reconectando…');
  await beatNow(page);
  await expect(pill(page)).not.toHaveText('Conectado');
});

test.describe('cozinha com SSE real', () => {
  test.beforeEach(() => {
    test.skip(
      PHP_DIRECT,
      'SSE desabilitado de propósito no servidor embutido do CI (tests/e2e/router.php, spec 040) — só roda contra Apache real (web-e2e).'
    );
  });

  test('servidor saudável mostra "Conectado"', async ({ page }) => {
    await page.goto('/kitchen/');

    await expect(pill(page)).toHaveText('Conectado');
    await expect(lostBanner(page)).toHaveCount(0);
  });

  test('pedido criado durante a queda aparece ao reconectar', async ({ page }) => {
    test.setTimeout(90_000);
    await page.route('**/api/events/stream.php', (route) => route.abort('internetdisconnected'));
    await page.goto('/kitchen/');
    await expect(pill(page)).toHaveText('Reconectando…');

    // Pedido criado enquanto a cozinha está sem stream. page.request não passa pelo
    // page.route — vai direto ao servidor.
    const menu = await (await page.request.get(`${BASE}/api/menu`, { timeout: 30_000 })).json();
    const item = menu.flatMap((c: any) => c.items ?? []).find((i: any) => !i.is_customizable);
    expect(item, 'o banco de teste precisa ter ao menos um item não customizável').toBeTruthy();
    const res = await page.request.post(`${BASE}/api/orders`, {
      data: { items: [{ id: item.id, quantity: 1 }], customer_name: 'E2E conexão', print_ticket: false },
    });
    expect(res.status()).toBe(201);
    const orderId = (await res.json()).id;

    // O stream novo começa de "agora" (sem Last-Event-ID): só o recarregamento da
    // reconexão traz este pedido.
    const refetch = page.waitForResponse((r) => r.url().includes('/api/orders?status=pending'));
    await page.unroute('**/api/events/stream.php');

    await expect(pill(page)).toHaveText('Conectado', { timeout: 30_000 });
    const pending = await (await refetch).json();
    expect(pending.map((o: any) => o.id)).toContain(orderId);
  });
});
