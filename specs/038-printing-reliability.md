# Spec 038 — Printing reliability

## Metadata

- Status: Verified
- Created: 2026-09-24
- Updated: 2026-09-24
- Owner: Henry
- Related issue: Not applicable (roadmap item — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`, "Printing reliability")
- Related branch: `038`

## Context

Sixth work item of the `v1.8.0 — Reliability & Quality` milestone, after specs 033, 034, 035
and 037. Thermal printing is named in the roadmap as "a core product capability", with a hard
rule: **"A printer failure must never remove or invalidate the restaurant order."**

Most of this roadmap item is already done, by earlier specs. That is stated up front so this
spec is not read as re-proposing work that exists:

| Roadmap sub-item | State |
|---|---|
| Connection failure handling | **Done** (spec 008): `PrintService::printOrder()` rethrows so the queue sees the failure |
| Retries | **Done** (spec 008): `JobService` retries with `2^attempts` backoff up to `max_attempts` |
| Failed print-job visibility | **Mostly done** (spec 033): `jobs.status`/`last_error`/`failed_at`, `bin/jobs-status`, Monolog to `app.log` and the Admin log viewer. Gap: it is CLI/log only |
| Reprinting | **Done**: `POST /api/orders/{id}/print` + the kitchen button |
| Test printing | **Exists**, but reports its errors badly — see defect 2 |
| Clear printer configuration | **Done**: `Setting`-backed `printer_ip`/`printer_port`, edited in `public/admin/settings.php` |
| Duplicate-print prevention | **Partly done** (spec 037): the queue no longer re-runs a job whose handler succeeded |
| Useful operator error messages | **Not done** — defects 2 and 3 |

What remains is three concrete defects and one honest decision about duplicates.

## Problem

**Defect 1 — an unconfigured printer makes a print job "succeed".**
`src/Services/PrintService.php`'s `printOrder()` does:

```php
if (empty($config['ip'])) {
    $this->logger->warning('Impressão cancelada: IP da impressora não configurado.');
    return;
}
```

It **returns normally**, so `JobService` records the job `completed`. The order is marked
printed when nothing was printed, and `bin/jobs-status` shows nothing wrong. The same condition
in `printTestPage()` **throws** `RuntimeException('IP da impressora não configurado.')` — the
two paths answer the same state in opposite ways. Found by spec 035's investigation.

**Defect 2 — the test-print button reports the wrong error.**
`src/Controllers/PrinterController.php`'s `testPrint()` has **no `try`/`catch`**. Any failure —
unconfigured IP, unreachable printer — propagates to the error middleware and becomes a `500`.
Under `APP_ENV=production` spec 012 deliberately sanitises that to
`{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}`. So the
admin, whose JS renders `data.error`, is told "internal server error" when the real cause is
"you have not set the printer IP" or "the printer is unreachable". This is the roadmap's
"useful operator error messages" item in its most concrete form: the one screen built to
diagnose the printer cannot report what is wrong with it.

**Defect 3 — the kitchen's reprint button reports success it cannot know.**
`public/kitchen/app.js:386` shows a green `Pedido #N enviado para impressão!` on the `200` from
`POST /api/orders/{id}/print`. That endpoint only enqueues — `OrderController::print()` returns
`{"success": true, "message": "Print job queued"}` before anything reaches the printer. The API
payload is honest; the UI's rendering of it is not. If the printer is dead, the operator sees
success and nothing ever corrects it.

**Not a defect, recorded so it is not "fixed" by mistake:** printing is asynchronous by design
and must stay that way. Spec 008's FR8 requires order creation to succeed even when the printer
is offline, and the roadmap's hard rule says a printer failure must never invalidate the order.
Making the reprint endpoint wait for the printer would trade a real guarantee for a cosmetic one.

## Goals

- A print that did not happen is never recorded as a print that did.
- The test-print screen tells the operator what is actually wrong.
- The kitchen stops claiming a print succeeded when it only knows it was queued.
- The queue's two print paths (`printOrder`, `printTestPage`) treat an unconfigured printer the
  same way.

## Non-goals

- **No new Composer dependency.** `mike42/escpos-php` stays.
- **No synchronous printing.** Order creation and reprinting keep returning before the printer
  is touched (spec 008 FR8, and the roadmap's hard rule).
- **No printer status polling or heartbeat.** Knowing the printer is alive *before* sending is a
  different feature, needs its own design, and the roadmap does not ask for it here.
- **No bulk retry/discard UI for the accumulated failed jobs.** 47 of them exist in the dev
  database (found by spec 033). `bin/jobs-status` already lists them; a bulk operator action is
  worth its own spec if it is wanted, and inventing one here would expand this item well past
  what the roadmap asks.
- **No other `v1.8.0` items** — realtime/SSE, structured logging, audit history, health checks,
  migration reliability and backup/restore each get their own spec.

## Current behavior

Confirmed by reading the code on 2026-09-24.

- `src/Services/PrintService.php` — constructor takes `(LoggerInterface, Settings, PricingService,
  ?callable $connectorFactory = null)`; the factory is the existing test seam, used by
  `tests/Unit/PrintServiceTest.php` to inject a fake connector. `printOrder()` returns early on
  empty IP (defect 1), otherwise builds the receipt and rethrows on any failure after logging
  `Print failed … error="…"`. `printTestPage()` throws on empty IP.
- `src/Controllers/PrinterController.php` — `testPrint()` calls `printTestPage()` with no error
  handling and always writes `{"success": true, "message": "Teste enviado para a impressora."}`.
- `src/Controllers/OrderController.php` — `print()` calls `OrderService::printOrder($id)`, which
  dispatches the job, and returns `{"success": true, "message": "Print job queued"}`; only a
  missing order produces `404 ORDER_NOT_FOUND`.
- `public/admin/settings.js:158-172` — posts to `/api/admin/settings/test-print` and renders
  `data.error` on failure, so it will faithfully display whatever the API says, including the
  sanitised "Erro interno do servidor."
- `public/kitchen/app.js:380-392` — `reprintOrder()`, the green toast of defect 3.
- Printer configuration lives in the `settings` table (`printer_ip`, `printer_port`) and is
  edited in `public/admin/settings.php`. Nothing about that is broken.

## Proposed behavior

### 1. An unconfigured printer is a failure, not a no-op

`printOrder()` throws the same way `printTestPage()` does. The job then follows the normal path:
retried with backoff, and after `max_attempts` it lands in `failed` with the reason in
`last_error`, visible in `bin/jobs-status` and the Admin log viewer.

This is the right answer rather than "skip printing when unconfigured" because the operator
asked for a ticket and did not get one. A restaurant running deliberately without a printer sets
`print_ticket=false` at order creation, which already exists and never enqueues a job at all.

### 2. The test-print endpoint reports the real cause

`PrinterController::testPrint()` catches the failure and returns a **`503`** with the project's
standard error envelope (`ApiResponse::error`, spec 024) and a message the operator can act on:
the configured address when the printer is unreachable, or "IP da impressora não configurado"
when it is not set. `503` because the fault is a dependency being unavailable, not a bad request
or a server bug — and, unlike the current `500`, it is not swallowed by the production sanitiser,
because the message is written deliberately rather than leaked from an exception.

The message must not include a stack trace or any internal path — it carries the printer address
and the failure kind only.

### 3. The kitchen says what it actually knows

The toast becomes an honest one: the order was **queued** for printing, not printed. The exact
wording is settled during implementation, but it must not assert success at the printer. The
operator's path to the truth is already built — failures reach `app.log` and `bin/jobs-status` —
and this spec does not add a new live-status channel for it, because that is the realtime item's
job, not this one.

### 4. Duplicate-print prevention — what is worth doing, and what is not

The roadmap says "where practical". Three sources, decided rather than promised:

1. **The queue re-running a completed job.** Already fixed by spec 037. No further work.
2. **A human pressing reprint twice.** The kitchen already guards a single in-flight request
   (`this.reprinting = orderId`), but two operators on two devices defeat it. A server-side
   debounce is possible, and is **deliberately not proposed**: reprinting is a manual, intentional
   act, an operator often *wants* a second ticket, and a silent refusal would be worse than a
   duplicate. Recorded so the omission is visible.
3. **A retry after a print that actually reached the printer.** **Not practically preventable.**
   ESC/POS over a raw socket gives no application-level acknowledgement — a connection that drops
   after the bytes are written is indistinguishable from one that drops before. This is precisely
   the case "where practical" excludes, and saying so plainly is more useful than implying the
   queue guarantees exactly-once printing. It does not; it guarantees at-most-once *dispatch*
   (spec 037), which is a different thing.

## Functional requirements

1. FR1 — `PrintService::printOrder()` throws when `printer_ip` is empty, with the same message
   `printTestPage()` uses.
2. FR2 — A print job for an unconfigured printer ends `failed` after `max_attempts`, with the
   reason in `last_error`.
3. FR3 — The order row is unchanged by any print failure: same `status`, same items, same totals.
4. FR4 — `PrinterController::testPrint()` returns `503` with
   `{"success": false, "error": <actionable message>, "code": <stable code>}` when printing fails.
5. FR5 — That message names the configured printer address when one is set, and says the IP is
   not configured when it is not.
6. FR6 — That message contains no stack trace, file path or class name.
7. FR7 — `testPrint()` still returns `200` with the existing success payload when the print works.
8. FR8 — The kitchen reprint toast no longer asserts the ticket was printed.
9. FR9 — Order creation still succeeds with `print_ticket=true` and an unreachable or
   unconfigured printer (spec 008 FR8 preserved).

## Non-functional requirements

- No change to printing latency or to the asynchronous dispatch model.
- The new error path must not log or return the receipt contents or any customer data.
- `PrintService`'s existing `connectorFactory` seam is reused for tests; no new seam is added.

## User flows

- **Admin diagnosing the printer**: opens Settings, presses the test print. With no IP set, the
  screen now says the IP is not configured instead of "Erro interno do servidor". With the
  printer off, it names the address it tried.
- **Kitchen reprinting**: presses reprint, sees a message saying the ticket was queued. If the
  printer is down, the job retries and then fails, and the failure is in the Admin log viewer and
  `bin/jobs-status`.
- **Cashier creating an order with an offline printer**: unchanged — the order is created and
  returned `201`; only the print job fails.

## API changes

`POST /api/admin/settings/test-print` gains a `503` response with the standard error envelope.
Its `200` response is unchanged. No other endpoint changes. `POST /api/orders/{id}/print` keeps
its current contract exactly — the change there is in the UI's wording, not the API.

## Data model and migrations

Not applicable — no schema change. The states this spec needs (`failed`, `last_error`) were added
by migration `016` for spec 033.

## Architecture and affected components

- `src/Services/PrintService.php` — `printOrder()`'s empty-IP branch.
- `src/Controllers/PrinterController.php` — error handling and the `503`.
- `public/kitchen/app.js` — the toast wording.
- `tests/Unit/PrintServiceTest.php` — extend, reusing the connector factory.
- `tests/Integration/` — a test asserting the order survives a print failure, if that is not
  already implied by existing coverage; check before adding.
- **Not** `src/Services/JobService.php` — the queue already does the right thing once
  `printOrder()` throws.

## Security considerations

The new error message is operator-facing and deliberately more informative than the sanitised
production default, so it must be written to carry only the printer address and failure kind —
never an exception's file, line, trace or SQL. The endpoint stays behind `JwtMiddleware` +
`RoleMiddleware(['admin'])`, unchanged, so the address is disclosed only to an authenticated
administrator who can already read it in Settings.

## Backward compatibility

- **Behavior change, intended**: print jobs that previously "succeeded" with no printer
  configured will now fail. That is the point, and it may make previously-invisible
  misconfiguration suddenly visible as failed jobs — which is the correct alarm, not a regression.
- The `200` contract of `test-print` is unchanged; only the failure shape is new, and the admin JS
  already handles a non-OK response by rendering `data.error`.
- No stored data changes; no migration.

## Acceptance criteria

- AC1 — With `printer_ip` empty, `PrintService::printOrder()` throws; a unit test asserts the
  exception and its message.
- AC2 — With `printer_ip` empty, a dispatched print job reaches `status = failed` after
  `max_attempts`, with `last_error` naming the unconfigured IP.
- AC3 — After that failure, the order row's `status`, item count and total are byte-identical to
  before the print attempt.
- AC4 — `POST /api/admin/settings/test-print` with `printer_ip` empty returns `503` and a body
  whose `error` mentions the unconfigured IP — verified by a real request, not read from code.
- AC5 — The same endpoint with an unreachable printer returns `503` and names the configured
  address.
- AC6 — Neither response body contains `/var/www`, `#0 `, `Exception` or a class name.
- AC7 — With a working (faked) printer, the endpoint still returns `200` with the existing
  success payload.
- AC8 — `POST /api/orders` with `print_ticket=true` and no printer configured still returns `201`
  and creates the order.
- AC9 — `grep` of `public/kitchen/app.js` shows no message asserting the ticket was printed.
- AC10 — Full suite (Smoke + Unit + Integration), PHPStan and PHP-CS-Fixer all pass.

## Implementation plan

1. `PrintService::printOrder()` throws on empty IP; unit test for AC1.
2. `PrinterController::testPrint()` error handling, `503`, standard envelope; message builder that
   distinguishes "not configured" from "unreachable".
3. Kitchen toast wording.
4. Unit tests for the message builder (AC6's "no internals" is a string assertion).
5. Integration/API checks for AC4, AC5, AC7, AC8 — real requests through the app.
6. AC2/AC3 against the real queue: dispatch, run the worker to exhaustion, read the rows.
7. PHPStan, PHP-CS-Fixer, full suite.

## Testing and validation strategy

**Correction, for the fourth spec running:** the `/spec-plan` skill's instruction to state that
this project has no automated test infrastructure is stale. PHPUnit (spec 004), GitHub Actions CI
(spec 005), PHPStan and PHP-CS-Fixer (spec 034) and the MySQL-backed integration suite (spec 035)
all exist; `specs/000-project-baseline.md:143` was corrected on 2026-09-03. The skill file is
overdue a fix — this is the fourth spec to say so.

- **Unit**, via the existing `connectorFactory` seam: the empty-IP throw, and the message builder
  including the "no internals" assertion.
- **Integration**, real MySQL and real HTTP through the Slim app: the `503` shape and content, the
  `200` path with a faked connector, and the order surviving a print failure.
- **The queue path (AC2)** needs the worker to actually exhaust `max_attempts`; with the default
  backoff (2s, 4s, 8s) that is a slow test, so it may be driven by calling `processNext()`
  directly rather than through `bin/worker`. If the timing makes it impractical as an automated
  test, say so and verify it manually with recorded output rather than claiming coverage.
- Docker must be running. `act` is **on hold** by the owner's decision, so CI is the verification
  after push.

## Rollout and rollback

Rollout: merge and restart the `print-worker` so it runs the new code. No schema change.
Operators with an unconfigured printer will start seeing failed print jobs — expected, and the
point of the change.

Rollback: revert the commit; the silent-success behavior returns.

## Open questions

All resolved during implementation:

- **Kitchen toast wording** — settled as `Pedido #N na fila de impressão`, with type `info`
  instead of `success`. It states what the endpoint actually knows: the job was queued. The
  `.gastro-toast.info` style and the `fa-info-circle` icon already existed, so no CSS was added.
- **The stable error code for the 503** — `PRINTER_UNAVAILABLE`, matching the `SCREAMING_SNAKE`
  convention spec 024 established.
- **Whether AC2 is practical as an automated test** — yes, by driving `JobService::processNext()`
  directly and resetting `available_at` between attempts, instead of waiting out the 2s/4s/8s
  backoff through `bin/worker`. The claim path exercised is identical; only the waiting is
  skipped. Recorded in the test's own docblock so the shortcut is visible where it is taken.

## Task checklist

- [x] 1. `printOrder()` throws on empty IP + unit test
- [x] 2. `testPrint()` error handling, `503`, actionable message
- [x] 3. Kitchen toast wording
- [x] 4. Unit tests for the message builder
- [x] 5. Integration checks for AC4, AC5, AC7, AC8
- [x] 6. AC2/AC3 against the real queue
- [x] 7. PHPStan, PHP-CS-Fixer, full suite

## Implementation log

- **2026-09-24 — 1. The unconfigured-printer message became a shared constant.**
  `PrintService::ERROR_NO_IP`. The two paths did not just behave differently, they also each
  carried their own copy of the string; a constant makes "they must agree" structural rather
  than a convention someone has to remember. A unit test asserts the two paths produce the same
  message, so a future divergence fails rather than drifts.
- **2026-09-24 — 2. `getConfiguredAddress()` was added to `PrintService`.** The controller needs
  to name the address in the operator-facing error, and the alternative was echoing the
  exception message — which is exactly what must not happen, since it can carry vendor paths and
  class names. The accessor returns `ip:port` or `null`, and the controller builds the sentence.
  A small public method, not a new layer.
- **2026-09-24 — 3. AC7 is verified at the controller, not over HTTP — a deliberate deviation.**
  The DI container autowires `PrintService` with the real `NetworkPrintConnector`, so a `200`
  through the full stack would need a physical printer. `PrinterControllerTest` constructs the
  controller with the same connector-factory seam `PrintServiceTest` already uses. The endpoint's
  handler is genuinely executed; what is not exercised is Slim's routing and middleware for the
  success case. The failure cases (AC4/AC5/AC6) **are** asserted through the full stack, where it
  matters most.
- **2026-09-24 — 4. The unreachable-printer test uses loopback on a dead port.** `127.0.0.1:9101`
  is refused immediately, so the test stays fast while still exercising "the printer did not
  answer". A non-routable address would have hung until the connect timeout and made the suite
  slow for no extra coverage.
- **2026-09-24 — 5. Nothing in `JobService` was touched**, as the spec predicted: once
  `printOrder()` throws, the existing retry/backoff/failed-state machinery from specs 008, 033
  and 037 does the right thing with no change.

## Validation evidence

All commands run on 2026-09-24 inside the containers, `MYSQL_DATABASE_TEST=restaurant_test`.

- **AC1** — `PrintServiceTest::testUnconfiguredPrinterFailsInsteadOfSilentlySucceeding` passes;
  the injected connector factory throws if called, proving no connection is even attempted.
  `testTestPageAndOrderPrintAgreeOnTheUnconfiguredMessage` asserts both paths return the same
  message — they disagreed before this spec.
- **AC2** — `PrintingTest::testUnconfiguredPrinterFailsTheJobAndLeavesTheOrderIntact`: after
  three attempts the job row is `status = failed`, `attempts = 3`, and `last_error` contains
  `IP da impressora não configurado.` The run's own log output confirms it live:
  `print.ERROR: Print failed print_job=173 attempt=1/3 order=705 IP da impressora não configurado.`,
  then `attempt=2/3`, then `attempt=3/3`.
- **AC3** — the same test compares the full `orders` row before and after with `assertSame` on
  the whole array, plus the `order_items` count. Identical.
- **AC4** — real request through the app: `503`, body
  `{"success": false, "error": "IP da impressora não configurado. Defina o IP em Configurações
  antes de testar a impressão.", "code": "PRINTER_UNAVAILABLE"}`. Explicitly asserted **not** to
  contain `Erro interno do servidor`, which is what it returned before.
- **AC5** — with `printer_ip = 127.0.0.1`, `printer_port = 9101`: `503`, and the body contains
  `127.0.0.1:9101`.
- **AC6** — asserted in two places: the integration test checks the live response body contains
  none of `/var/www`, `#0 `, `Exception`, `Mike42`, `.php`; the controller test feeds an
  exception whose message deliberately contains `/var/www/html/vendor/mike42/escpos.php line 42`
  and asserts none of it survives into the response.
- **AC7** — `PrinterControllerTest::testSuccessfulTestPrintReturns200`: `200`,
  `{"success": true, "message": "Teste enviado para a impressora."}` — the payload is unchanged
  from before this spec. See Implementation log entry 3 for why this is controller-level.
- **AC8** — `PrintingTest::testOrderIsCreatedEvenWithNoPrinterConfigured`: `POST /api/orders`
  with `print_ticket: true` and no printer configured returns `201`, the order row exists with
  `status = pending`, and the print job is still enqueued — it is the job that fails, not the
  order. The roadmap's hard rule holds.
- **AC9** — `grep -n "enviado para impressão" public/kitchen/app.js` returns nothing; line 390 is
  now `Pedido #${orderId} na fila de impressão` with type `info`.
- **AC10** — full suite `OK (176 tests, 371 assertions)` (was `163/324` before this spec);
  `vendor/bin/phpstan analyse` → `[OK] No errors`; `php-cs-fixer --dry-run` →
  `Found 0 of 80 files that can be fixed`.
- **Authorization unchanged** — `PrintingTest::testTestPrintStillRequiresAnAdminToken` asserts
  `401` with no token and `403` with a `manager` token, so the more informative message is still
  only reachable by an administrator.

**Not validated, stated rather than implied:**

- **No physical printer was involved.** Every success path runs against
  `DummyPrintConnector`; every failure path against a refused socket or a thrown factory. That a
  real ESC/POS device prints the test page is unchanged by this spec and was not re-verified.
- **AC7 did not go through Slim's routing/middleware**, for the reason in log entry 3.
- **The kitchen toast was not observed in a browser.** The string, the type and the existing
  `.info` style are verified in the source; the rendered result was not watched.
- **`act` is on hold** by the owner's decision, so CI after push is the verification of the
  workflow itself.
