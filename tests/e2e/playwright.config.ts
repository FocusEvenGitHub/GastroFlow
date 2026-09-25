import { defineConfig, devices } from '@playwright/test';

/**
 * Testes de navegador do GastroFlow (spec 040).
 *
 * baseURL aponta para a porta 8081 — a instância do docker-compose.e2e.yml, que roda contra
 * o banco `restaurant_test`. NUNCA apontar para 8080: lá está o banco de desenvolvimento, e
 * estes testes escrevem dados.
 */
export default defineConfig({
  testDir: './specs',
  // Um worker: estes testes compartilham estado GLOBAL — o bloqueio da impressora vive na
  // tabela `settings`, não por sessão — então o afterEach de um limparia o bloqueio que
  // outro ainda verifica. (Hoje o Playwright só paraleliza entre ARQUIVOS e há um só, então
  // isto é proteção para quando um segundo arquivo existir, não correção de bug observado.)
  workers: 1,
  fullyParallel: false,
  // Sem retries: um teste que só passa na segunda tentativa está escondendo instabilidade,
  // e a suíte é pequena o bastante para não precisar disso.
  retries: 0,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8081',
    // Esperas por condição são a regra (spec 040 FR6); este é o teto por ação.
    actionTimeout: 15_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
