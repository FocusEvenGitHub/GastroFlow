import { test, expect } from '@playwright/test';

/**
 * As telas de operação funcionam sem internet (spec 055).
 *
 * Bloqueia toda requisição para fora do próprio servidor — o que um restaurante com a rede
 * local de pé e a internet caída vê — e confere que cada tela ainda tem o que precisa:
 * Alpine iniciado, CSS do Bootstrap, ícones e fonte. Antes da spec 055 tudo isso vinha de
 * CDN, e sem internet o Alpine nem iniciava; este teste falhava no master daquela época.
 *
 * Só entra na suíte de navegador porque só um navegador mostra o defeito: na camada HTTP
 * as páginas respondem 200 do mesmo jeito, com ou sem CDN.
 */

// A biblioteca própria de cada tela do Admin é conferida pela resposta HTTP, não por
// window.TomSelect/window.Chart: sem login, o auth.js redireciona para /admin/ logo depois
// de carregar, e a variável global seria lida já na página de login.
const PAGES: { path: string; vendorFile?: string }[] = [
  { path: '/cashier/' },
  { path: '/kitchen/' },
  { path: '/admin/index.php', vendorFile: '/vendor/tom-select-2.6.2/js/tom-select.complete.min.js' },
  { path: '/admin/reports.php', vendorFile: '/vendor/chartjs-4.4.0/chart.umd.min.js' },
];

for (const { path, vendorFile } of PAGES) {
  test(`${path} carrega sem internet`, async ({ page, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const blocked: string[] = [];
    const vendorResponse = vendorFile
      ? page.waitForResponse((r) => new URL(r.url()).pathname === vendorFile)
      : null;
    await page.route('**/*', (route) => {
      const url = route.request().url();
      if (url.startsWith(origin) || url.startsWith('data:')) {
        return route.continue();
      }
      blocked.push(url);
      return route.abort('internetdisconnected');
    });

    await page.goto(path);
    await page.waitForFunction(() => (window as any).Alpine !== undefined);

    const state = await page.evaluate(async () => {
      // Elementos de prova, criados aqui para não depender do conteúdo de cada tela.
      const probe = document.createElement('div');
      probe.className = 'd-none';
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-check';
      document.body.append(probe, icon);

      const [fa, inter] = await Promise.all([
        document.fonts.load('900 16px "Font Awesome 6 Free"'),
        document.fonts.load('400 16px Inter'),
      ]);
      return {
        bootstrap: getComputedStyle(probe).display === 'none',
        iconFont: fa.length > 0 && fa.every((f) => f.status === 'loaded'),
        interFont: inter.length > 0 && inter.every((f) => f.status === 'loaded'),
      };
    });

    expect(blocked, 'requisições para fora do servidor').toEqual([]);
    expect(state).toEqual({ bootstrap: true, iconFont: true, interFont: true });
    if (vendorResponse) {
      expect((await vendorResponse).status()).toBe(200);
    }
  });
}
