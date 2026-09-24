import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Testes de navegador do bloqueio de impressão (spec 040).
 *
 * Critério de admissão desta suíte: só entra o que QUEBRA APENAS NO NAVEGADOR. Tudo aqui
 * depende de binding do Alpine, de CSS do Bootstrap ou de polling — nada disso existe na
 * camada HTTP, que a suíte de integração (spec 035) já cobre.
 *
 * O defeito que motivou a suíte: o aviso de bloqueio nunca sumia, porque
 * `x-show` aplica `display: none` inline e `.d-flex` do Bootstrap é `!important`. A API
 * respondia "desbloqueado", o botão voltava a funcionar, e só o texto na tela mentia —
 * invisível para os 187 testes de backend da época.
 */

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8081';

/**
 * Prepara o estado da impressora executando PHP na instância de teste.
 *
 * DESVIO declarado do FR5 da spec, que pedia "todo dado de teste criado pela API": não existe
 * endpoint que ATIVE o bloqueio — só `POST /api/printer/reset`, que o limpa. Chegar lá pela
 * API exigiria três jobs falhando de verdade, ~42s de backoff, e um worker rodando contra o
 * banco de teste. Criar um endpoint só para testar seria mudar a aplicação para facilitar
 * teste, que a spec proíbe. Pedidos e cardápio continuam vindo da API.
 */
function setPrinterState(sql: 'blocked' | 'clear', orderId?: number): void {
  const php =
    sql === 'blocked'
      ? `App\\Models\\Setting::setValue('printer_consecutive_failures','3');
         App\\Models\\Setting::setValue('printer_blocked_at', date('Y-m-d H:i:s'));
         App\\Models\\Setting::setValue('printer_last_failed_order','${orderId ?? 0}');
         App\\Models\\Setting::setValue('printer_last_error','IP da impressora não configurado.');`
      : `App\\Models\\Setting::setValue('printer_consecutive_failures','0');
         App\\Models\\Setting::setValue('printer_blocked_at', null);
         App\\Models\\Setting::setValue('printer_last_failed_order', null);
         App\\Models\\Setting::setValue('printer_last_error', null);`;

  const script = `require "vendor/autoload.php";
     Dotenv\\Dotenv::createImmutable(getcwd())->load();
     App\\Database::boot(new App\\Settings());
     ${php}`;

  // No CI não há Docker: o workflow roda PHP direto, com MYSQL_DATABASE=restaurant_test na
  // própria variável de ambiente — que é o mesmo mecanismo de isolamento que a instância
  // 8081 usa localmente.
  if (process.env.E2E_PHP_DIRECT === '1') {
    execFileSync('php', ['-r', script.replace(/vendor\/autoload\.php/, 'vendor/autoload.php')], {
      cwd: '../..',
      stdio: 'pipe',
      env: { ...process.env, MYSQL_DATABASE: 'restaurant_test' },
    });
    return;
  }

  execFileSync(
    'docker',
    [
      'compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml',
      'exec', '-T', 'web-e2e', 'php', '-r',
      script.replace(/getcwd\(\)/, '"/var/www/html"').replace(/"vendor\//, '"/var/www/html/vendor/'),
    ],
    { cwd: '../..', stdio: 'pipe' }
  );
}

/** Cria um pedido pela API, para a cozinha ter um card com botão de reimprimir. */
async function createOrder(page: Page): Promise<number> {
  const menu = await (await page.request.get(`${BASE}/api/menu`)).json();
  const item = menu.flatMap((c: any) => c.items ?? []).find((i: any) => !i.is_customizable);
  expect(item, 'o banco de teste precisa ter ao menos um item não customizável').toBeTruthy();

  const res = await page.request.post(`${BASE}/api/orders`, {
    data: { items: [{ id: item.id, quantity: 1 }], customer_name: 'E2E', print_ticket: false },
  });
  expect(res.status()).toBe(201);

  return (await res.json()).id;
}

test.afterEach(() => setPrinterState('clear'));

test('cozinha: o aviso de bloqueio SOME ao reativar', async ({ page }) => {
  const orderId = await createOrder(page);
  setPrinterState('blocked', orderId);

  await page.goto('/kitchen/');

  const aviso = page.locator('.alert-danger', { hasText: 'Impressão bloqueada' });
  await expect(aviso).toBeVisible();

  await page.getByRole('button', { name: 'Reativar impressão' }).click();

  // Espera por condição, nunca por tempo: o ciclo reset+refresh leva ~2,4s, e um
  // waitForTimeout fixo já produziu um falso positivo durante a spec 039 (FR6).
  await expect(aviso).toHaveCount(0);
});

test('cozinha: o botão de reimprimir desabilita e reabilita', async ({ page }) => {
  const orderId = await createOrder(page);
  setPrinterState('blocked', orderId);

  await page.goto('/kitchen/');

  const bloqueado = page.locator('button[title*="Impressão bloqueada"]').first();
  await expect(bloqueado).toBeVisible();
  await expect(bloqueado).toBeDisabled();

  await page.getByRole('button', { name: 'Reativar impressão' }).click();

  const liberado = page.locator('button[title="Reimprimir nota"]').first();
  await expect(liberado).toBeEnabled();
});

test('caixa: o toggle de imprimir desabilita e fica visivelmente esmaecido', async ({ page }) => {
  setPrinterState('blocked', 1);

  await page.goto('/cashier/');

  const toggle = page.locator('#printToggle');
  await expect(toggle).toBeDisabled();

  // Desabilitado sem parecer desabilitado foi o segundo defeito achado na spec 039: o
  // operador não entende por que clicar não faz nada.
  await expect(page.locator('.print-switch')).toHaveClass(/opacity-50/);
});

test('as duas telas avisam qual pedido falhou', async ({ page }) => {
  const orderId = await createOrder(page);
  setPrinterState('blocked', orderId);

  for (const tela of ['/kitchen/', '/cashier/']) {
    await page.goto(tela);
    await expect(
      page.locator('.gastro-toast', { hasText: `Erro ao imprimir o pedido #${orderId}` })
    ).toBeVisible();
  }
});

test('nenhum erro de JavaScript nas duas telas', async ({ page }) => {
  const erros: string[] = [];
  page.on('pageerror', (e) => erros.push(String(e)));
  page.on('console', (m) => {
    // O aviso de status da impressora usa console.error quando a rede falha; isso é
    // tratamento previsto, não erro de página.
    if (m.type() === 'error' && !m.text().includes('status da impressora')) {
      erros.push(`console: ${m.text()}`);
    }
  });

  for (const tela of ['/kitchen/', '/cashier/']) {
    await page.goto(tela);
    await expect(page.locator('nav')).toBeVisible();
  }

  expect(erros, `erros de JS: ${erros.join(' | ')}`).toHaveLength(0);
});
