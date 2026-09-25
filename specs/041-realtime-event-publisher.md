# Spec 041 — Realtime reliability: database-backed event publisher

## Metadata

- Status: Verified
- Created: 2026-09-25
- Updated: 2026-09-25
- Owner: Henry
- Related issue: Not applicable (roadmap item — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`, "Realtime reliability")
- Related branch: `041`

## Context

Eighth work item of the `v1.8.0 — Reliability & Quality` milestone, next in the roadmap's own
order after "Printing reliability" (specs 038/039). The mechanism this spec replaces was already
found fragile three times in this session without being the direct subject of any of those specs:

- **Spec 038's investigation** noted the kitchen's realtime signal and the print worker live in
  different containers.
- **Spec 039** had to poll `GET /api/printer/status` instead of using SSE for the printer-block
  state, specifically because the existing SSE mechanism cannot carry an event from the
  `print-worker` container to the `web` container that serves the stream.
- **Spec 040** had to make its E2E test server **disable SSE entirely** (`tests/e2e/router.php`
  returns `204` for `/api/events/*`) because the long-lived connection exhausted the test
  server's worker pool — a symptom of how the mechanism is built, not just of where it runs.

This spec is the direct fix: `docs/ROADMAP.md`'s "Realtime reliability" section names the target
shape explicitly (`OrderService → EventPublisher → Community event implementation → SSE`) and
this spec builds it.

## Problem

Confirmed by reading `public/api/events/stream.php` and
`OrderService::triggerKitchenEvent()` (`src/Services/OrderService.php:125-134`) on 2026-09-25.

1. **One file holds "the latest event", not a queue.** `triggerKitchenEvent()` does
   `@file_put_contents($eventFile, json_encode($data, ...))` — each call **overwrites** the
   previous one. A burst of events (e.g. several `addOrderItem` calls in quick succession) can
   have an earlier event replaced by a later one before the stream's 2-second poll
   (`stream.php`'s `sleep(2)` loop) ever reads it.
2. **`sys_get_temp_dir()` is per-container.** Confirmed broken in production topology: the print
   worker runs as `restaurant_print_worker`, separate from `restaurant_web`
   (`docker-compose.yml`), so an event written by one is invisible to the other. Confirmed again,
   differently, in spec 040 — the mechanism was fragile enough under a single-threaded test
   server that it had to be disabled outright rather than merely worked around.
3. **No event ID.** `stream.php` never emits an SSE `id:` line, so the browser's native
   `EventSource` reconnection (`Last-Event-ID` header) is never exercised. A disconnect —
   network blip, the 30-second manual-retry fallback in `public/kitchen/app.js:161-168` — loses
   whatever happened while offline, with no way to ask "what did I miss?".
4. **`OrderService` writes directly to the transport.** There is no `EventPublisher` abstraction
   at all — `triggerKitchenEvent()` is a private method that `file_put_contents`s straight to a
   path in `sys_get_temp_dir()`. This is precisely what the roadmap's own sentence forbids: "The
   business domain should not depend directly on the event transport mechanism."
5. **No retention, because there is no history.** One file, overwritten each time — nothing to
   prune, but also nothing for a reconnecting client to catch up on.
6. **No locking.** `file_put_contents` without `LOCK_EX` — concurrent writes are not safely
   ordered.

**Confirmed, and relevant to scope:** every listener in `public/kitchen/app.js:126-168`
(`order.created`, `order.completed`, `order.uncompleted`, `order.updated`, `order.cancelled`)
does the same thing on receipt — calls `this.fetchAll()`. The client does not consume per-event
payload data today; it only reacts to "something changed" by re-fetching everything. This bounds
the fix: the client does not need richer event content, only a transport that reliably tells it
*that* something happened, in order, without silently dropping events across a container boundary
or a reconnect.

## Goals

- An event published from any container (worker or web) reaches the kitchen, regardless of which
  container serves the SSE stream.
- A burst of events is not silently collapsed to just the last one.
- A reconnecting client resumes from where it left off (`Last-Event-ID`), not from a blank slate.
- `OrderService` depends only on an `EventPublisher` interface — no controller, service, or test
  outside the concrete publisher touches the transport.
- Storage is bounded by retention, mirroring the pattern `bin/jobs-prune` already established.

## Non-goals

- **No Redis, no RabbitMQ, no message broker.** The roadmap says so explicitly: "Do not introduce
  additional infrastructure unless required." MySQL is already shared by every container — the
  same fact spec 033/037 already exploited for the job queue, and spec 040 re-confirmed
  (`variables_order=EGPCS`, the one thing every container agreed on).
- **No client-side incremental state application.** The kitchen keeps doing exactly what it does
  today on any event: call `fetchAll()`. Changing that would be unrelated scope creep — the
  problem here is transport reliability, not UI architecture.
- **No horizontal scaling / multi-instance support.** Community is explicitly single-location
  (`CLAUDE.md`, `docs/ROADMAP.md` "Single-location first"). The fix targets "reliable across this
  product's actual containers", not "reliable across an arbitrary number of app instances".
- **No change to the printer-status polling from spec 039.** `GET /api/printer/status` is a
  different, already-working mechanism for a different concern; out of scope here.
- **No change to which event types exist.** `order.created/completed/uncompleted/updated/
  cancelled` stay exactly as they are — only the transport moves.

## Current behavior

- `public/api/events/stream.php` — boots `Settings`/`Database` standalone (not through the Slim
  app), sends `Content-Type: text/event-stream`, disables output buffering, then loops forever:
  reads `sys_get_temp_dir() . '/gastroflow-events.json'`, compares its content to the last seen
  string, emits an SSE frame if different, sends a `: keepalive` comment every 30s
  (15 × 2s), sleeps 2s, repeats. Exits the loop on `connection_aborted()`.
- `OrderService::triggerKitchenEvent(string $type, int $orderId)` — private, called after every
  mutating operation (`createOrder`, `completeOrder`, `uncompleteOrder`, `cancelOrder`,
  `updateOrder`, `addOrderItem`, `updateOrderItem`, `removeOrderItem`). Writes
  `{"type":..., "order_id":..., "timestamp":...}` to the same temp-dir path.
- `public/kitchen/app.js:126-168` — `connectSSE()` builds one `EventSource`, registers a listener
  per event type (all calling `fetchAll()` when `selectedDate` is today), and on `onerror` waits
  30s before manually reconnecting if the browser's own automatic reconnect hasn't already
  recovered.
- The `jobs` table (migration `007_jobs.sql`, extended by `016_job_reliability.sql`) is the
  existing, proven precedent for "container-shared state lives in MySQL, not in a per-container
  temp file" in this codebase.
- `bin/worker`'s continuous loop already calls a prune routine at most once an hour
  (`JobService::pruneCompleted()`, spec 033) — an existing, working pattern for periodic
  retention without adding a cron dependency.

## Proposed behavior

### An `events` table replaces the temp file

New migration `017_realtime_events.sql`, styled like `007_jobs.sql`:

```sql
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    order_id INT UNSIGNED NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`id` is the auto-increment primary key **and** doubles as the SSE event ID / reconnection
cursor — the same role `jobs.id` plays for FIFO ordering, with the same guarantee (MySQL
serializes concurrent inserts; no application-level locking needed, closing defect #6 for free).

### `EventPublisher` — the one abstraction the roadmap names

```php
interface EventPublisher
{
    public function publish(string $type, array $data = []): void;
}
```

`DatabaseEventPublisher implements EventPublisher` inserts one row. `OrderService`'s constructor
gains `EventPublisher $events` (autowired by the existing DI container, the same pattern as
`PrintService`/`JobService`); `triggerKitchenEvent()` is replaced by a call to
`$this->events->publish($type, ['order_id' => $orderId])`. No new layer beyond what the roadmap
itself names — `EventPublisher` lives in `src/Services/`, alongside `PrintService`/`JobService`,
not in a new namespace.

This is what makes the non-functional requirement testable: `OrderServiceTest` can inject a fake
`EventPublisher` and assert *that* an event was published, never touching a database row or a
file.

### The stream reads the table, and uses real SSE event IDs

`stream.php` polls `events` instead of the temp file:

```sql
SELECT id, type, order_id, payload, created_at FROM events
WHERE id > :lastId ORDER BY id LIMIT 50
```

Each row is emitted as `id: <id>\nevent: <type>\ndata: <json>\n\n` — the `id:` line is what lets
the browser's native reconnection work. `:lastId` starts from the `Last-Event-ID` HTTP header
when the browser supplies one (automatic on reconnect), or from the current max `id` on a fresh
connection — a new client does not replay all history, only a *reconnecting* one catches up.
Poll interval stays 2s; nothing in this spec's evidence says it needs to change, and the roadmap
asks for "appropriate" latency, not minimal latency.

### Retention mirrors `bin/jobs-prune`

`EventPublisher::pruneOlderThan(int $days)` (or a free function alongside it) deletes rows past
retention, default `EVENTS_RETENTION_DAYS` (exact default decided in Open questions — these rows
exist to let a *recently* reconnected client catch up, not as an audit trail, so the right window
is short, but "how short" needs a number rather than a guess). `bin/worker`'s existing hourly
prune hook (spec 033) gains a second call to this, reusing the mechanism already running in the
`print-worker` container rather than adding a new one. A `bin/events-prune` command mirrors
`bin/jobs-prune` for manual/on-demand pruning.

## Functional requirements

1. FR1 — `OrderService`'s constructor takes an `EventPublisher`; no direct file or transport
   access remains in `src/Services/OrderService.php`.
2. FR2 — Every call site that previously called `triggerKitchenEvent()` now calls
   `EventPublisher::publish()` with the same `type` and `order_id`.
3. FR3 — `DatabaseEventPublisher::publish()` inserts exactly one row per call; concurrent calls
   from different connections never collide or overwrite each other's row.
4. FR4 — `stream.php` emits SSE frames with an `id:` line equal to the row's `id`.
5. FR5 — A request carrying `Last-Event-ID` resumes from `id > <that value>`; a request without
   it starts from the current max `id` (no history replay on a fresh connection).
6. FR6 — An event published by a process using a different DB connection than the one serving the
   stream (simulating the worker/web container split) is delivered.
7. FR7 — A burst of N events published in quick succession all appear in the stream, in order,
   none skipped.
8. FR8 — `pruneOlderThan()` deletes only rows past the retention window; recent rows are
   untouched.
9. FR9 — `bin/events-prune` and the `bin/worker` hourly hook both call the same prune path.

## Non-functional requirements

- No new Composer dependency.
- No schema change outside `common/migrations/017_realtime_events.sql`.
- `payload` never carries a secret or credential — same discipline as job/print logging
  (specs 033/037/038).
- The stream endpoint's DB polling query must stay index-backed (`id` is the primary key; the
  `WHERE id > :lastId ORDER BY id` shape is a direct index scan, not a table scan).

## User flows

Not applicable as a new user-facing flow — the kitchen's behavior on receiving an event is
unchanged (it still just refetches). What changes is reliability: an event from the print worker
now reaches the kitchen, and a kitchen screen that loses its connection for a minute catches up
instead of silently going stale until the next full page load.

## API changes

`GET /api/events/stream.php` keeps its path and SSE content type. It gains standards-based
`id:` lines (additive — `EventSource` already supports this natively; no client code has to
change to benefit from it, though `public/kitchen/app.js` may still want minor updates to make
the resumption behavior visible for debugging). No other endpoint changes.

## Data model and migrations

New `common/migrations/017_realtime_events.sql`, the `events` table as shown above. No existing
table is altered.

## Architecture and affected components

- `src/Services/EventPublisher.php` (new interface) — matches the roadmap's own named
  abstraction; no new layer beyond it.
- `src/Services/DatabaseEventPublisher.php` (new) — the concrete, MySQL-backed implementation.
- `src/Services/OrderService.php` — constructor + `triggerKitchenEvent()` call sites.
- `public/api/events/stream.php` — reads `events` instead of the temp file; emits `id:`.
- `bin/worker` — extends the existing hourly prune hook.
- `bin/events-prune` (new) — mirrors `bin/jobs-prune`.
- `common/migrations/017_realtime_events.sql` (new).
- `tests/Unit/` — `OrderServiceTest` gets a fake `EventPublisher` (replacing whatever, if
  anything, it does today around the file-based mechanism — to be confirmed during
  implementation); a new unit test for `DatabaseEventPublisher` against SQLite.
- `tests/Integration/` — the cross-connection delivery test (FR6), the burst-ordering test (FR7),
  and the prune test (FR8), all needing real MySQL for the same reason spec 037's concurrency
  tests did.
- **Not** `public/kitchen/app.js`'s event-type listeners or their `fetchAll()` behavior — that
  stays as-is per the stated non-goal.

## Security considerations

`events.payload` is a small JSON blob (`order_id` today); the same rule that already governs job
and print logging applies — never a secret, never a credential, never full order contents. The
stream endpoint is public, unchanged from today (cozinha has no login, per spec 018's
established, deliberate model) — this spec does not add or remove authentication anywhere.

## Backward compatibility

- **Behavior change, intentional**: the kitchen now receives events the old mechanism silently
  dropped across the container boundary. That is the fix, not a regression.
- `sys_get_temp_dir() . '/gastroflow-events.json'` stops being written or read; nothing else in
  the codebase references that path (confirmed by grep during investigation — only
  `OrderService.php` and `stream.php` touch it).
- No stored data migrates; `events` starts empty.
- `EventSource`'s `id:` support is additive and part of the web standard the browser already
  implements — no client-side change is required for the reconnection benefit to apply, though
  `app.js` may be adjusted for clarity/debugging during implementation.

## Acceptance criteria

- AC1 — `OrderServiceTest` (or equivalent) asserts `EventPublisher::publish()` is called with the
  right `type`/`order_id` for each mutating operation, via a fake implementation — no database or
  file touched by that test.
- AC2 — `DatabaseEventPublisher::publish()` against real MySQL inserts exactly one row per call.
- AC3 — **FR6, proven with two separate OS processes** (mirroring
  `tests/Integration/bin/claim-one-job.php`'s technique from spec 037): process A publishes an
  event on one connection; process B, polling with `WHERE id > :lastId`, sees it. This is the
  test that actually proves the cross-container defect is fixed — two objects in one PHP process
  sharing a connection would prove nothing.
- AC4 — A burst of 10 events published in a tight loop all appear via a single poll (or a small
  number of polls), in ascending `id` order, none missing.
- AC5 — A request to `stream.php` with `Last-Event-ID: <N>` receives only events with `id > N`; a
  request with no header receives none of the pre-existing history (starts from "now").
- AC6 — `pruneOlderThan()` deletes a row older than the retention window and keeps one inside it,
  verified by direct row counts before/after — same technique as `JobService::pruneCompleted()`'s
  test in spec 033.
- AC7 — `grep -rn "gastroflow-events.json"` after implementation returns nothing outside this
  spec's own history/comments.
- AC8 — Full suite (Smoke + Unit + Integration), PHPStan and PHP-CS-Fixer all pass.
- AC9 — **Browser-observed, not assumed**: using the `tests/e2e/` suite (spec 040) or manual
  verification, an `EventSource` connection that receives an `id:`-bearing event and then
  reconnects sends `Last-Event-ID` and does not re-receive events it already saw. State explicitly
  if this specific behavior is only verifiable in the browser suite or manually, and which was
  actually done.

## Implementation plan

1. `common/migrations/017_realtime_events.sql`; confirm idempotent (`bin/migrate` twice).
2. `EventPublisher` interface + `DatabaseEventPublisher`; unit tests against SQLite.
3. `OrderService` constructor + call-site migration; `OrderServiceTest` updated to the fake
   publisher (AC1).
4. `stream.php` rewritten to poll `events`, emit `id:`, honor `Last-Event-ID`.
5. **AC3 first**, before anything else is declared working: the two-process cross-connection test,
   because it is the direct fix for the defect that motivated this spec.
6. Burst test (AC4), reconnection test (AC5).
7. `pruneOlderThan()` + `bin/events-prune` + the `bin/worker` hook extension; prune test (AC6).
8. Remove the old file-based code path entirely; confirm AC7.
9. Browser verification (AC9) via `tests/e2e/` if practical, else manual with recorded evidence.
10. PHPStan, PHP-CS-Fixer, full suite; `CHANGELOG.md`/`docs/architecture.md` in the same pass
    (project rule).

## Testing and validation strategy

**Correction, for the seventh spec running:** the `/spec-plan` skill's instruction to state that
this project has no automated test infrastructure is stale. PHPUnit (spec 004), CI (spec 005),
PHPStan/PHP-CS-Fixer (spec 034), the MySQL-backed integration suite (spec 035), and now a
Playwright browser suite (spec 040) all exist. The skill file itself is overdue a fix.

- **Unit (SQLite)**: `EventPublisher` fake for `OrderServiceTest`; `DatabaseEventPublisher`'s own
  basic insert/read behavior.
- **Integration (real MySQL, separate OS processes)**: AC3 is the acceptance criterion this whole
  spec exists for, and it is deliberately the hardest to fake — reusing the exact
  separate-process technique spec 037 used for job-claim concurrency, because two objects sharing
  one connection inside one PHPUnit process would prove nothing about the container-boundary
  defect.
- **Browser (Playwright, spec 040)**: `Last-Event-ID` resumption is genuine browser networking
  behavior (the browser's own `EventSource` implementation decides when and how to send that
  header) that PHPUnit cannot drive without a real `EventSource` client. If it turns out
  impractical even there, this must be stated plainly rather than claimed.
- Docker must be running. `act` remains on hold by the owner's 2026-09-24 decision; CI after push
  is the verification path for the workflow itself.

## Rollout and rollback

Rollout: merge, run `bin/migrate`, restart `web` and `print-worker` so both run the new code (an
event published by the old worker code would never reach a new-code stream, and vice versa —
both containers must move together).

Rollback: revert the commit; `events` can be left in place harmlessly, since old code never reads
or writes it.

## Open questions

Todas resolvidas na implementação:

- **`EVENTS_RETENTION_DAYS` default** — **2 dias**. Estas linhas existem para o cliente
  reconectando recuperar o que perdeu, não como histórico — longo o bastante para cobrir uma
  desconexão de uma noite, curto o bastante para ninguém confundir `events` com `orders`.
- **Intervalo de poll** — mantido em 2s, sem evidência de que precisasse mudar. O `SELECT ...
  WHERE id > :lastId ORDER BY id LIMIT 50` é indexado (chave primária), então a troca de leitura
  de arquivo para consulta não introduziu lentidão perceptível nos testes.
- **Se `app.js` precisava de mudança** — não foi necessária. O benefício de reconexão é nativo
  do `EventSource` e aditivo; nenhum código do cliente muda para se beneficiar dele.

## Task checklist

- [x] 1. Migração `017_realtime_events.sql`
- [x] 2. `EventPublisher` + `DatabaseEventPublisher` + testes unitários
- [x] 3. `OrderService` migrado para a interface; `OrderServiceTest` atualizado
- [x] 4. `stream.php` reescrito (consulta a tabela, emite `id:`, honra `Last-Event-ID`)
- [x] 5. AC3 — teste de entrega entre processos, provado primeiro
- [x] 6. AC4/AC5 — testes de rajada e reconexão
- [x] 7. Retenção: `pruneOlderThan()`, `bin/events-prune`, gancho no `bin/worker`, AC6
- [x] 8. Caminho antigo por arquivo totalmente removido, AC7 confirmado
- [x] 9. Verificação no navegador (AC9)
- [x] 10. PHPStan, PHP-CS-Fixer, suíte completa, CHANGELOG + docs

## Implementation log

- **2026-09-25 — 1. `EventPublisher` precisou de binding explícito no container de DI.**
  `App::get()` já usa `useAutowiring(true)`, mas isso resolve classes concretas, não
  interfaces — sem `EventPublisher::class => \DI\autowire(DatabaseEventPublisher::class)` em
  `src/App.php`, o PHP-DI não saberia qual implementação instanciar para o `OrderService`.
  Confirmado lendo `App.php` antes de escrever, não assumido.
- **2026-09-25 — 2. Uma suposição minha sobre o mock foi verificada e estava errada — corrigida
  antes de virar registro.** Ao atualizar `testCreateOrderWithValidDataDispatchesPrintJob`
  adicionei `$printService->method('isPrintingBlocked')->willReturn(false)` supondo que, sem
  isso, o mock retornaria `null`. Testado isoladamente antes de escrever qualquer conclusão:
  como `PrintService::isPrintingBlocked()` declara `: bool`, o PHPUnit já gera `false` como
  padrão para um método não configurado — confirmado com `var_dump`, não `null`. O stub
  explícito ficou no teste por clareza (deixa a pré-condição visível), não porque corrigia uma
  lacuna real.
- **2026-09-25 — 3. `const UPDATED_AT = null` copiado de memória, não do arquivo — CS-Fixer
  pegou.** Escrevi `Event.php` espelhando `Job.php`, mas usei `const` sem `public` — o
  `Job.php` real já usa `public const`. O PHP-CS-Fixer acusou a única violação de estilo da
  spec inteira; corrigido conferindo o arquivo real em vez de confiar na memória.
- **2026-09-25 — 4. Meu primeiro teste de `pruneOlderThan()` estava errado, não o código.**
  `Event::create(['created_at' => Carbon::now()->subDays(8)])` gravava a data **atual**, não a
  passada — confirmado isolando o comportamento antes de mudar qualquer coisa: o Eloquent
  sobrescreve `created_at` com "agora" no `create()` sempre que o model usa timestamps
  automáticos (`Event` não declara `$timestamps = false`). Corrigido usando
  `Db::table('events')->update(['created_at' => ...])` via query builder, que contorna o
  auto-timestamp — a mesma técnica que os testes de integração já usavam para `available_at`/
  `reserved_until` (specs 033/037).
- **2026-09-25 — 5. AC5 tinha uma lacuna até eu notar.** O primeiro teste de navegador só
  exercitava o ramo `if` de `stream.php` (`Last-Event-ID` presente). O ramo `else` — conexão
  sem o header começa de "agora", não repete histórico — só estava confirmado por leitura de
  código. Adicionado `sem Last-Event-ID, uma conexão nova não repete eventos já existentes`,
  criando um pedido **antes** de conectar e confirmando que ele não aparece no stream.
- **2026-09-25 — 6. AC9 (reconexão nativa do `EventSource`) provado parcialmente, e a lacuna
  é honesta, não contornada.** Reconexão automática com `Last-Event-ID` só acontece quando o
  PRÓPRIO objeto `EventSource` perde a conexão e se reconecta sozinho — não quando o teste
  cria um `new EventSource()` novo. Forçar isso exigiria o servidor derrubar a conexão de
  propósito, mudando `stream.php` só para viabilizar teste, fora do escopo. Em vez disso: (a)
  um `EventSource` real confirma que a linha `id:` chega — a peça que habilita a reconexão
  nativa a funcionar sem código no cliente; (b) `fetch()` cru com e sem o header confirma que
  o servidor honra `Last-Event-ID` nos dois ramos, na fiação real do protocolo, contra Apache
  de verdade (não o servidor embutido `php -S` do CI, que nem roda SSE — spec 040). O que
  genuinamente não foi observado: o navegador decidindo sozinho reconectar e reenviar o
  header. Registrado como não coberto, não alegado como coberto.
- **2026-09-25 — 7. `docker-compose.e2e.yml` roda Apache, não `php -S`.** Só o workflow do CI
  usa o servidor embutido do PHP com `tests/e2e/router.php` (que desabilita SSE de propósito,
  spec 040). A instância local (`web-e2e`, porta 8081) usa a mesma imagem/Dockerfile do
  serviço `web` — Apache real, SSE funcionando sem stub nenhum. Foi contra ela que a
  verificação de navegador desta spec rodou.
- **2026-09-25 — 8. `realtime-events.spec.ts` quebrou no CI (PR #16) por rodar contra o
  servidor embutido, não contra Apache.** Eu havia registrado o fato certo na entrada 7 mas não
  apliquei a consequência ao escrever os testes: `tests/e2e/router.php` devolve 204 de
  propósito para qualquer `/api/events/*`, então os 3 testes desta suíte falharam no CI real
  (`page.evaluate: Nenhum evento order.created chegou em 15s`; os dois `fetch()` receberam
  corpo nulo). Corrigido com `test.beforeEach(() => test.skip(process.env.E2E_PHP_DIRECT ===
  '1', ...))`, o mesmo sinal de ambiente que `printer-block.spec.ts` já usa (spec 040) para
  diferenciar CI de Docker local — confirmado localmente rodando a suíte duas vezes: com
  `E2E_PHP_DIRECT=1` os 3 testes aparecem como `skipped`, sem rede nenhuma; contra a instância
  Apache real (porta 8081) os mesmos 3 seguem `passed`, junto com os 5 de printer-block.
  Comentário de `router.php` que dizia "nenhum teste desta suíte exercita SSE" também corrigido
  — ficaria falso sem a ressalva.

## Validation evidence

Todos os comandos rodados em 2026-09-25 dentro dos containers,
`MYSQL_DATABASE_TEST=restaurant_test` para a suíte PHP, porta 8081 (`docker-compose.e2e.yml`,
Apache real) para a suíte de navegador.

- **AC1** — `OrderServiceTest::testCreateOrderWithValidDataDispatchesPrintJob` e
  `::testEachMutatingOperationPublishesItsEventType` passam; o fake de `EventPublisher` nunca
  toca banco ou arquivo, e o segundo teste confirma o tipo de evento certo para as 7 operações
  mutantes (`completeOrder`, `uncompleteOrder`, `cancelOrder`, `updateOrder`, `addOrderItem`,
  `updateOrderItem`, `removeOrderItem`).
- **AC2** — `DatabaseEventPublisherTest::testPublishInsertsExactlyOneRow` (SQLite): uma
  chamada, uma linha, `type`/`order_id`/`payload` corretos.
- **AC3 — o critério que a spec inteira existe para provar.**
  `RealtimeEventTest::testEventPublishedFromAnotherProcessIsVisibleHere`: um processo de SO
  separado (`tests/Integration/bin/publish-one-event.php`, conexão própria) publica; **este**
  processo, com a mesma consulta `WHERE id > :lastId` que `stream.php` usa, enxerga o evento.
  Passa.
- **AC4** — `testBurstOfEventsAllArriveInOrder`: 10 eventos publicados em sequência rápida,
  todos os 10 aparecem, em ordem ascendente de `id`, nenhum faltando.
- **AC5 — dos dois lados.** Nível de consulta:
  `testQueryHonorsLastEventIdCursor` (integração). Nível de protocolo, navegador real: `o
  servidor honra o header Last-Event-ID quando enviado diretamente` (com o header, recebe o
  pedido recém-criado) e `sem Last-Event-ID, uma conexão nova não repete eventos já
  existentes` (sem o header, um pedido criado **antes** de conectar não aparece).
- **AC6** — unitário (`DatabaseEventPublisherTest::testPruneOlderThanDeletesOnlyOldRows`,
  SQLite) e integração (`RealtimeEventTest::testPruneOlderThanAgainstRealDatabase`, MySQL
  real) — ambos deletam só a linha antiga.
- **AC7** — `Grep` escopado a `src/`, `public/`, `tests/` por `gastroflow-events` → zero
  ocorrências.
- **AC8** — suíte completa `OK (196 tests, 445 assertions)` (era 187/417 antes desta spec);
  `phpstan analyse` → `[OK] No errors`; `php-cs-fixer --dry-run` → `Found 0 of 88 files that
  can be fixed` (depois de corrigir `public const` em `Event.php`, o único desvio de estilo
  encontrado).
- **AC9** — três testes Playwright contra a instância Apache real (porta 8081), `3 passed`:
  evento real chega com `id:`; servidor honra `Last-Event-ID` presente; servidor não repete
  histórico sem o header. A suíte de navegador inteira (8 testes, incluindo os da spec 039)
  segue verde: `8 passed (52.8s)` localmente contra Apache. Também confirmado localmente sob
  `E2E_PHP_DIRECT=1` (o mesmo sinal usado pelo workflow do CI): os 3 testes de
  `realtime-events.spec.ts` aparecem como `skipped`, não `failed` — ver entrada 8 do log de
  implementação. Confirmado no CI real (PR #16, GitHub Actions run 36199903587): `3 skipped`
  / `5 passed (5.9s)`.
- **Migração** — `bin/migrate` aplicou `017_realtime_events.sql` com `[OK]`; segunda execução
  → `✔ Nenhuma migração pendente.` `SHOW COLUMNS FROM events` confirma o schema no MySQL de
  desenvolvimento.
- **`bin/events-prune`** — rodado contra o banco de desenvolvimento real:
  `Retenção: 2 dia(s) / Eventos removidos: 0` (nada acumulado ainda, esperado).

**Não validado, declarado em vez de subentendido:**

- **Reconexão automática genuína do `EventSource`** (o navegador decidindo sozinho reconectar
  e reenviar `Last-Event-ID`) não foi forçada — ver log de implementação, entrada 6.
  O que a habilita (a linha `id:` chegando) e o que o servidor faz quando o header chega estão
  provados; o gatilho do próprio navegador, não.
- **Nenhum teste mede latência real de ponta a ponta** sob carga — o poll de 2s foi mantido
  sem medição formal de throughput, por não haver evidência de que precisasse mudar.
- **Multi-instância/escala horizontal** permanece fora de escopo, como o não-objetivo já
  declarava — a tabela `events` resolve o cross-container do Community, não um cenário de
  múltiplas instâncias `web`.
