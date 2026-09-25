import { test, expect } from '@playwright/test';

/**
 * Testes de navegador do stream SSE de eventos em tempo real (spec 041).
 *
 * Critério de admissão desta suíte (spec 040): só o que quebra exclusivamente no navegador.
 * Os testes de integração (tests/Integration/RealtimeEventTest.php) já provam, contra MySQL
 * real e processos de SO separados, que um evento publicado em uma conexão chega a outra —
 * o defeito que a spec 041 existe para corrigir. O que só um EventSource de verdade prova é
 * o formato do protocolo SSE na fiação: se a linha `id:` chega ao cliente do jeito que o
 * padrão exige para a reconexão nativa funcionar.
 *
 * Pulada inteira sob E2E_PHP_DIRECT=1 (servidor embutido do CI): tests/e2e/router.php recusa
 * de propósito qualquer requisição a /api/events/* com 204, para não esgotar o pool de um
 * worker por conexão do `php -S` (spec 040) — SSE nunca vai funcionar ali, e cada teste aqui
 * dependeria de um EventSource que nunca recebe nada. Só a instância Apache real
 * (docker-compose.e2e.yml, porta 8081) exercita este arquivo; foi contra ela que a evidência
 * de validação da spec 041 foi coletada.
 */

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8081';

test.beforeEach(() => {
  test.skip(
    process.env.E2E_PHP_DIRECT === '1',
    'SSE desabilitado de propósito no servidor embutido do CI (tests/e2e/router.php, spec 040) — esta suíte só roda contra Apache real (web-e2e).'
  );
});

/** Cria um pedido pela API real — isso é o que faz OrderService publicar order.created. */
async function createOrder(page: import('@playwright/test').Page): Promise<number> {
  const menu = await (await page.request.get(`${BASE}/api/menu`)).json();
  const item = menu.flatMap((c: any) => c.items ?? []).find((i: any) => !i.is_customizable);
  expect(item, 'o banco de teste precisa ter ao menos um item não customizável').toBeTruthy();

  const res = await page.request.post(`${BASE}/api/orders`, {
    data: { items: [{ id: item.id, quantity: 1 }], customer_name: 'E2E realtime', print_ticket: false },
  });
  expect(res.status()).toBe(201);

  return (await res.json()).id;
}

/**
 * FR4 — um evento real, publicado pelo caminho real (OrderService -> EventPublisher -> banco
 * -> stream.php), chega ao navegador com uma linha `id:`. É essa linha que habilita a
 * reconexão nativa do EventSource (Last-Event-ID) — sem ela, o navegador não tem o que
 * reenviar ao reconectar. Nenhum teste de integração faz o parsing do protocolo SSE de
 * verdade; só um EventSource real prova isto.
 */
test('um evento real chega ao navegador com um id de evento SSE', async ({ page }) => {
  await page.goto('/kitchen/');

  const received = page.evaluate(() => {
    return new Promise<{ id: string | null; type: string }>((resolve, reject) => {
      const es = new EventSource('/api/events/stream.php');
      const timeout = setTimeout(() => {
        es.close();
        reject(new Error('Nenhum evento order.created chegou em 15s'));
      }, 15000);

      es.addEventListener('order.created', (evt: MessageEvent) => {
        clearTimeout(timeout);
        es.close();
        resolve({ id: (evt as MessageEvent).lastEventId, type: 'order.created' });
      });
    });
  });

  // Dá tempo do EventSource conectar antes de disparar o evento.
  await page.waitForTimeout(500);
  await createOrder(page);

  const event = await received;

  expect(event.type).toBe('order.created');
  expect(event.id, 'o evento precisa carregar um id — é o que habilita Last-Event-ID').not.toBeNull();
  expect(Number(event.id)).toBeGreaterThan(0);
});

/**
 * AC9 — o que este teste NÃO prova, declarado em vez de forçado.
 *
 * A reconexão automática do EventSource com Last-Event-ID só acontece quando o PRÓPRIO
 * objeto EventSource perde a conexão (rede caindo, servidor fechando) e se reconecta
 * sozinho — não quando o teste cria um `new EventSource()` novo. Forçar isso exigiria o
 * servidor derrubar a conexão de propósito, o que mudaria stream.php só para viabilizar o
 * teste — fora do escopo da spec. A garantia de QUE o servidor honra Last-Event-ID quando
 * recebido está provada em RealtimeEventTest::testQueryHonorsLastEventIdCursor (a mesma
 * consulta que stream.php roda) e é o que este comentário registra como não coberto aqui.
 */
test('o servidor honra o header Last-Event-ID quando enviado diretamente', async ({ page }) => {
  await createOrder(page);

  // Last-Event-ID: 0 deve reportar tudo que existe, incluindo o pedido recém-criado — prova,
  // pela fiação real do protocolo, o mesmo que RealtimeEventTest::testQueryHonorsLastEventIdCursor
  // prova ao nível de query (WHERE id > :lastId). Lemos só o começo do corpo e encerramos.
  const response = await fetch(`${BASE}/api/events/stream.php`, {
    headers: { 'Last-Event-ID': '0' },
  });
  const reader = response.body!.getReader();
  const decoder = new TextDecoder();
  let text = '';
  const deadline = Date.now() + 5000;

  while (!text.includes('order.created') && Date.now() < deadline) {
    const { value, done } = await reader.read();
    if (done) break;
    text += decoder.decode(value, { stream: true });
  }
  reader.cancel();

  expect(text).toContain('event: order.created');
  expect(text).toMatch(/id: \d+/);
});

/**
 * AC5, a segunda metade: SEM Last-Event-ID, o endpoint começa de "agora" — uma conexão nova
 * não repete o histórico inteiro. stream.php lê Event::max('id') exatamente para isto
 * (público/api/events/stream.php); sem este teste, só a leitura do código confirmava o
 * ramo `else`, nunca uma requisição HTTP real batendo nele.
 */
test('sem Last-Event-ID, uma conexão nova não repete eventos já existentes', async ({ page }) => {
  // Pedido criado ANTES de conectar — se o servidor repetisse histórico, isto apareceria.
  const staleOrderId = await createOrder(page);

  const controller = new AbortController();
  const response = await fetch(`${BASE}/api/events/stream.php`, { signal: controller.signal });
  const reader = response.body!.getReader();
  const decoder = new TextDecoder();
  let text = '';
  const deadline = Date.now() + 3000; // sem novo evento chegando, só confirma ausência

  while (Date.now() < deadline) {
    const readPromise = reader.read();
    const timeoutPromise = new Promise<{ done: true; value: undefined }>((resolve) =>
      setTimeout(() => resolve({ done: true, value: undefined }), 500)
    );
    const { value, done } = await Promise.race([readPromise, timeoutPromise]);
    if (value) text += decoder.decode(value, { stream: true });
    if (done && Date.now() >= deadline) break;
  }
  controller.abort();

  expect(
    text,
    `uma conexão sem Last-Event-ID não deve repetir o pedido #${staleOrderId}, criado antes de conectar`
  ).not.toContain(`"order_id":${staleOrderId}`);
});
