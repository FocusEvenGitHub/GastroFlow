# Spec 042 — Structured logging: request/correlation IDs

## Metadata

- Status: Verified
- Created: 2026-09-25
- Updated: 2026-09-25
- Owner: Henry
- Related issue: Not applicable (roadmap item — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`, "Structured logging")
- Related branch: `042`

## Context

Ninth work item of the `v1.8.0 — Reliability & Quality` milestone, next in the roadmap's own
order after "Realtime reliability" (spec 041, merged). `docs/ROADMAP.md` (lines 965-991) asks
for request/correlation IDs, with important logs carrying `request_id`, `user_id`, `order_id`,
`job_id`, `event`, so that a support engineer can trace `HTTP Request → Order → Job →
Printing` for a single real event, end to end, in `app.log`.

## Problem

Confirmed in the current code, not assumed:

- `src/App.php:37-41` builds a single Monolog logger (`new Logger('app')` +
  `StreamHandler`) with no processor and no default context. Nothing ties one log line to
  another, or to the HTTP request that caused it.
- The global error handler (`src/App.php:64-110`) already logs `method`/`path`/`status` (and a
  trace when `APP_DEBUG` is on), but never `request_id` or `user_id` — an operator reading two
  ERROR lines from the same minute cannot tell whether they came from the same request.
- Log calls that exist are inconsistent in shape:
  - `src/Services/JobService.php` mostly passes real Monolog context arrays
    (`['job_id' => ..., 'queue' => ..., 'attempt' => ...]` — lines 178-184, 204-208, 237-244,
    408-414).
  - `src/Services/PrintService.php` instead concatenates everything into the message string
    itself (`'Print failed' . $ctx . ' order=' . $order->id . ...`, lines 204/215/217, plus
    `recordPrintFailure()`'s block message at line 95) — `order_id`/`job_id` are present in the
    text but not as structured fields a log reader (or a human skimming `app.log`) can filter
    on.
- No file in `src/Middleware/` generates or propagates a request identifier — only
  `CorsMiddleware`, `JsonBodyParserMiddleware`, `JwtMiddleware`, `RoleMiddleware` exist.
- `src/Middleware/JwtMiddleware.php:37` decodes the JWT and attaches it to the PSR-7 request as
  the `user` attribute (`$decoded->sub` is the user id — confirmed against
  `src/Controllers/AuthController.php:38-44`, which is also where the JWT payload is minted).
  Nothing currently reads that value for logging purposes.
- `src/Services/OrderService.php`'s `createOrder()` dispatches a print job
  (`$this->jobService->dispatch('print', PrintOrderJob::class, ['order_id' => $order->id])`,
  line 45) but never logs anything itself — the "Order" link of the requested trace chain has
  no log breadcrumb at all today, structured or not.
- `src/Jobs/PrintOrderJob.php` runs in `bin/worker`, a **separate OS process** from the web
  request that dispatched the job (same cross-container reality spec 033/037/041 already had to
  design around). It builds its own standalone `Monolog\Logger`
  (`src/Jobs/PrintOrderJob.php:32-38`) with no relationship to the HTTP request's logger or
  container — nothing survives that process boundary today except `order_id`, via the job's
  `payload` column (`src/Services/JobService.php:54-67`).
- `public/admin/logs.php` (the admin log viewer, confirmed working end-to-end since spec 033)
  parses `app.log` with regexes (`extractTime`/`extractLevel`/`extractMessage`,
  lines 176-201) that expect Monolog's default `LineFormatter` line shape
  (`[timestamp] channel.LEVEL: message {context} {extra}`). Any change to logging must keep
  producing that shape, or the viewer breaks.
- Verified empirically (not assumed) in this session: PHP-DI's `ContainerBuilder` with
  `useAutowiring(true)` shares autowired class instances within one container build — calling
  `$container->get(Foo::class)` twice returns the **same object**. Confirmed by running a throwaway
  script inside the `web` container (`$a === $b` → `true`). This is load-bearing for the design
  below: a request-scoped context object, resolved via the container, stays the same instance
  everywhere it's injected during one request, with no explicit "singleton" binding required
  (mirrors how `LoggerInterface` is already resolved once per request in `src/App.php`).
- Verified: Monolog 3.10 (`composer.lock`) uses an immutable-ish `LogRecord` whose `extra`
  property is a plain mutable `public array` (`vendor/monolog/monolog/src/Monolog/LogRecord.php`),
  and `ProcessorInterface::__invoke(LogRecord $record): LogRecord` (same directory). A processor
  can mutate `$record->extra` directly and return the same object.
- Verified: Slim's middleware stack is last-in-first-out
  (`vendor/slim/slim/Slim/MiddlewareDispatcher.php:79-84`, and its own docblock says so) — the
  middleware added **last** via `$app->add()` runs **first** (outermost).
- Verified: `tests/Integration/IntegrationTestCase.php:175-195`'s `request()` helper builds the
  **real** `App\App` (`(new \App\App(new Settings()))->get()`) and calls `$app->handle($request)`
  in-process — the full middleware stack, including whatever this spec adds, runs for real in
  that test, not a stub.
- Verified: neither `tests/Unit/PrintServiceTest.php` nor `tests/Unit/JobServiceTest.php` assert
  exact log message strings — `PrintServiceTest` uses a real Monolog `Logger` with a
  `NullHandler`; `JobServiceTest` passes `new NullLogger()`. Both instantiate `JobService`
  positionally (`new JobService(new NullLogger(), new Settings())`), so a new constructor
  parameter is safe only if appended after the existing two, defaulting to `null`.

## Goals

- Every HTTP request gets a unique `request_id`, generated or propagated from an incoming
  `X-Request-Id` header, present in every log line that request causes and echoed back in the
  response header.
- When a request is authenticated, its `user_id` (the JWT's `sub`) appears in that request's
  logs.
- The `request_id` that caused an order to be created and a print job to be dispatched survives
  the async hop into `bin/worker` and shows up in that job's own print-outcome log line —
  closing the `HTTP Request → Order → Job → Printing` chain the roadmap names.
- `PrintService`'s existing log calls carry `order_id`/`job_id`/`event` as structured context,
  not string-concatenated text.
- No secret (JWT token, password, `Authorization` header) is ever logged.
- `public/admin/logs.php` keeps working unmodified.

## Non-goals

- No new infrastructure or dependency (no request-tracing service, no APM). Monolog is already
  present and has everything needed (`Processor` API, `extra` field).
- No schema change. `request_id` travels inside the job's existing `payload` JSON column
  (`src/Services/JobService.php:54-67` already stores an arbitrary `data` array there) — adding
  a key there needs no migration.
- No independent "worker execution id" for jobs that were never triggered by an HTTP request.
  Today every job is dispatched from `OrderService`, which is only ever reached through
  `OrderController` (HTTP) — there is no code path that enqueues a job outside a request. A
  `request_id` that's `null` for a hypothetically-non-HTTP-originated job is correct, not a gap;
  inventing a separate ID scheme for a case that doesn't exist in this codebase would be scope
  beyond what the roadmap asks.
- No UI change to `public/admin/logs.php` (e.g. a "filter by request_id" control). The roadmap
  asks that logs **carry** the context, not that the viewer gain new filtering features.
- Not touching `bin/events-prune`/`bin/jobs-prune`/`bin/worker`'s own operational
  `echo`-to-stdout lines — those are process-lifecycle notices, not the request-tracing logging
  this spec is about.
- Not rewriting `PrintService::printTestPage()`'s or `buildReceipt()`'s logo-load warning calls
  — they have no order/job/request context to carry (test-print and receipt building are not
  part of the request-to-job-to-print chain the roadmap names) and touching them would be scope
  beyond what's needed.

## Current behavior

Described in full, with file:line citations, in **Problem** above.

## Proposed behavior

1. A new `App\Logging\RequestContext` holds, for the lifetime of one HTTP request, an optional
   `requestId` (always set once the new middleware runs) and an optional `userId` (set once
   `JwtMiddleware` successfully decodes a token).
2. A new `App\Middleware\CorrelationIdMiddleware`, added globally in `src/App.php`, runs before
   everything else in the stack (added last among the app-level `$app->add()` calls, so it's
   outermost among them, per the verified last-in-first-out rule — still inside Slim's own error
   middleware, which must remain the true outer boundary). For every request it:
   - Reads `X-Request-Id` from the incoming request; accepts it only if it is 1-64 characters
     matching `^[A-Za-z0-9._-]+$` (rejects anything that could carry a newline or otherwise
     corrupt a log line — a request header is attacker-controlled input). Otherwise generates
     `bin2hex(random_bytes(16))` (32 lowercase hex characters).
   - Stores the id on `RequestContext`.
   - Calls the next handler, wrapped in try/finally so the summary log fires even when an
     exception is about to propagate to Slim's error middleware.
   - Logs one `INFO` line, message `'HTTP request'`, context
     `{method, path, status, duration_ms}` (request_id/user_id are added automatically — see
     next point — not duplicated here).
   - Sets `X-Request-Id` on the response (generated or propagated) so a client/support ticket
     can quote it.
3. A new `App\Logging\RequestIdProcessor` (Monolog `ProcessorInterface`) is pushed onto the
   app's `LoggerInterface` in `src/App.php`. On every record it sets
   `extra['request_id']` from `RequestContext` (always present once the middleware has run) and
   `extra['user_id']` (only when non-null — an unauthenticated request's logs carry no
   `user_id` key at all, rather than a noisy `null`). Because the DI container shares
   `RequestContext` (verified above) and the processor is attached to the same shared
   `LoggerInterface` instance, this applies to **every** log call made during the request with
   no per-call-site change required — including the existing global error handler.
4. `App\Middleware\JwtMiddleware` gains an optional `?RequestContext $context` constructor
   parameter; on a successful decode it calls `$context?->setUserId((int) $decoded->sub)`.
   `src/Routes.php` (which already builds `JwtMiddleware` manually because of the `$secret`
   scalar) fetches the shared instance via `$app->getContainer()->get(RequestContext::class)`
   and passes it through.
5. `App\Services\JobService::dispatch()` gains the same "optional, DI-autowired for HTTP,
   `null` in `bin/worker`" pattern already used for `LoggerInterface`/`Settings`
   (`src/Services/JobService.php:40-44`): a `?RequestContext $requestContext = null` constructor
   parameter, appended last so `new JobService(new NullLogger(), new Settings())` (used in
   `tests/Unit/JobServiceTest.php`) keeps compiling unchanged. When present and it has a
   `requestId`, `dispatch()` merges `'request_id' => ...` into the job's `data` payload
   alongside whatever the caller passed — the domain layer (`OrderService`) never needs to know
   about `request_id` at all, exactly the abstraction discipline spec 041 already established
   for `EventPublisher`.
6. `JobService::processNext()` decodes `$payload['data']['request_id'] ?? null` once and threads
   it into `recordFailure()`/`logJobFailure()`/`markCompletionUnrecordable()`'s existing context
   arrays as `request_id` — generic to the queue layer (unlike `order_id`, which is
   print-specific payload shape `JobService` has no business knowing about).
7. `App\Jobs\PrintOrderJob::handle()` reads `$data['request_id'] ?? null` and adds it to the
   `$jobContext` array it already builds (`src/Jobs/PrintOrderJob.php:40-47`) alongside
   `job_id`/`attempt`/`max_attempts` — no new parameter, no signature change, same array that
   already flows into `PrintService::printOrder($order, $jobContext)`.
8. `PrintService::printOrder()` and `recordPrintFailure()`'s block-message call convert from
   string concatenation to structured Monolog context arrays: message stays human-readable
   (e.g. `'Print failed'`), context carries `order_id`, and whatever of
   `job_id`/`attempt`/`max_attempts`/`request_id` the caller supplied, plus a short `event` tag
   (`print.success`, `print.failed`, `print.blocked`) so a log reader can `grep` on a stable
   machine-readable key instead of parsing prose.

## Functional requirements

1. Every HTTP response carries an `X-Request-Id` header.
2. When the incoming request already has a valid `X-Request-Id` (per the character/length rule
   above), the response echoes the same value.
3. When the incoming request has no `X-Request-Id`, or an invalid one, the response carries a
   freshly generated 32-character lowercase hex value.
4. Every log line Monolog writes during a request's lifetime — including the global error
   handler's — carries that same `request_id` in its `extra` context.
5. Once `JwtMiddleware` successfully authenticates a request, every subsequent log line during
   that request carries `user_id` matching the JWT's `sub` claim; logs from unauthenticated
   requests carry no `user_id` key.
6. When `OrderService::createOrder()` dispatches a print job, the job's persisted `payload` JSON
   contains the same `request_id` as the HTTP response that created it.
7. When that job is processed (`JobService::processNext('print')`), the resulting
   `PrintService` log line's context contains `order_id`, `event`, and the same `request_id`
   from requirement 6 — whether the outcome is `print.success` or `print.failed`.
8. If that job instead fails permanently (`JobService::recordFailure()` → `logJobFailure()`),
   the resulting log line's context also contains the same `request_id`.
9. No log call touched by this spec ever includes a raw JWT token, password, or
   `Authorization` header value.

## Non-functional requirements

- **Observability**: the stated goal — this entire spec is a non-functional/observability
  change.
- **Security**: request-header input (`X-Request-Id`) is validated before being trusted into a
  log line (requirement covered above) — an unvalidated header is a log-injection vector.
- **Performance**: negligible — one `random_bytes(16)` call per request when no header is
  supplied, one extra array merge per log record. No new I/O, no new query.
- **Backward compatibility**: `public/admin/logs.php` must keep rendering `app.log` without
  changes (see Acceptance criteria).

## User flows

Not applicable — this is a backend/operability change with no operator-facing UI flow beyond
the existing, unmodified `public/admin/logs.php` viewer.

## API changes

No new endpoint. Every existing endpoint response gains one new header: `X-Request-Id`. No
request/response body shape changes, no new status codes.

## Data model and migrations

Not applicable — `request_id` is carried inside the `jobs.payload` JSON column, which already
stores an arbitrary `data` array (`src/Services/JobService.php:54-67`) with no schema tied to
its keys. No migration needed.

## Architecture and affected components

New:
- `src/Logging/RequestContext.php`
- `src/Logging/RequestIdProcessor.php`
- `src/Middleware/CorrelationIdMiddleware.php`

Changed:
- `src/App.php` — register the processor on the DI-bound `LoggerInterface`; add
  `CorrelationIdMiddleware` to the global middleware stack.
- `src/Routes.php` — pass the shared `RequestContext` into the manually-constructed
  `JwtMiddleware`.
- `src/Middleware/JwtMiddleware.php` — optional `RequestContext` param; sets `user_id` on
  success.
- `src/Services/JobService.php` — optional `RequestContext` param; `dispatch()` and
  `processNext()`/`recordFailure()`/`logJobFailure()`/`markCompletionUnrecordable()` thread
  `request_id` through.
- `src/Jobs/PrintOrderJob.php` — reads `request_id` from job data into `$jobContext`.
- `src/Services/PrintService.php` — `printOrder()` and `recordPrintFailure()`'s block message
  converted to structured context.

Not touched: Controllers, Repositories, Validators, Models — this spec is entirely in the
Services/Middleware/new-Logging layers, matching the project's real layering (`CLAUDE.md`).

## Security considerations

- `X-Request-Id` is attacker-controlled request input; validated (charset + length) before
  being logged or echoed back, per requirement 3.
- No log call this spec touches or adds logs a token, password, or `Authorization` header —
  verified by grep across the changed files (Validation evidence) and covered by an explicit
  test.
- `user_id` is the JWT's own `sub` claim, already trusted elsewhere in the codebase (e.g.
  `AuthController::changePassword`, line 68) — attaching it to logs adds no new trust boundary.

## Backward compatibility

- `public/admin/logs.php` and `LogController::getLogs()` are unchanged; Monolog's default
  `LineFormatter` still produces one line per record with context/extra appended as JSON, which
  the viewer's regex-based parsing already tolerates (it just extracts a leading timestamp,
  level, and "everything after `]: `" — a longer message with more appended JSON is not a
  format change from its point of view).
- `JobService`'s new constructor parameter is optional and last, so every existing call site
  (`new JobService()` in `bin/*`, `new JobService(new NullLogger(), new Settings())` in tests)
  keeps working unchanged.
- `PrintOrderJob::handle(array $data, ?Job $job = null)`'s signature is unchanged — `request_id`
  travels inside the existing `$data` array, not a new parameter.

## Acceptance criteria

1. A request with no `X-Request-Id` header gets a response with an `X-Request-Id` header whose
   value is 32 lowercase hex characters.
2. A request with `X-Request-Id: my-valid-id-123` gets back `X-Request-Id: my-valid-id-123`
   unchanged.
3. A request with `X-Request-Id` containing a character outside `[A-Za-z0-9._-]` (e.g. an
   embedded newline or space) gets back a freshly generated id, not the invalid value.
4. Triggering a 500 (or any handled exception) produces an error-handler log line whose
   `extra.request_id` matches that request's `X-Request-Id` response header.
5. An authenticated request's logs carry `extra.user_id` equal to the JWT's `sub`; the same
   request made without a token carries no `user_id` key in its logs.
6. `POST /api/orders` with `print_ticket: true` persists a `jobs` row whose `payload` JSON
   contains `data.request_id` equal to that response's `X-Request-Id`.
7. Running `JobService::processNext('print')` against that job produces a `PrintService` log
   line whose context contains `order_id` (matching the created order), `event`, and
   `request_id` equal to the value from criterion 6.
8. Forcing that job to fail permanently (e.g. no printer IP configured, `max_attempts`
   exhausted) produces a `JobService` failure log line whose context also carries that same
   `request_id`.
9. `grep`-ing the diff of every file this spec changes for the literal strings `token`,
   `password`, `Authorization` used as a **logged value** (as opposed to, say, a variable name)
   finds none — verified by manual review, not automated, since a naive grep would also flag the
   many pre-existing, unrelated uses of those words as identifiers.
10. `GET /api/admin/logs` (via `LogController`) still returns 200 with a `lines` array after
    this change, and `public/admin/logs.php`, loaded in a browser against a real running
    instance, renders those lines with no JavaScript console errors — manual check (no browser
    automation infrastructure change is in scope here).

## Implementation plan

1. `src/Logging/RequestContext.php` — new class, `?string $requestId`, `?int $userId`, getters
   and setters.
2. `src/Logging/RequestIdProcessor.php` — new Monolog processor consuming `RequestContext`.
3. `src/Middleware/CorrelationIdMiddleware.php` — new middleware implementing the behavior in
   **Proposed behavior** point 2. Unit tests alongside it.
4. Wire both into `src/App.php`: push the processor onto the DI logger; add the middleware to
   the global stack.
5. `src/Middleware/JwtMiddleware.php` + `src/Routes.php` — thread `RequestContext` through so
   `user_id` gets set.
6. `src/Services/JobService.php` — optional `RequestContext`; `dispatch()` merges `request_id`
   into payload `data`; `processNext()` decodes it once and threads it into the three failure/
   completion-logging call sites.
7. `src/Jobs/PrintOrderJob.php` — read `$data['request_id']` into `$jobContext`.
8. `src/Services/PrintService.php` — convert the four affected log calls to structured context.
9. Unit tests: `RequestContext`, `RequestIdProcessor`, `CorrelationIdMiddleware` (header
   validation/generation/propagation), updated `JobServiceTest`/`PrintServiceTest` coverage
   where the new context fields are asserted.
10. Integration test (`tests/Integration/`) exercising the full chain in-process via
    `IntegrationTestCase::request()`: create an order with `print_ticket: true`, read back the
    job's payload, run `JobService::processNext('print')`, and grep the tail of `app.log` for
    matching `request_id` across the HTTP/job/print log lines. This is the flagship proof for
    acceptance criteria 6-8, mirroring how spec 041 proved its own cross-process claim for real
    rather than by construction.
11. Manual check of `public/admin/logs.php` against a real running `web` container (criterion
    10).
12. `phpstan analyse`, `php-cs-fixer --dry-run`, full `phpunit` suite, then CHANGELOG/docs sync
    per `CLAUDE.md`'s release workflow, in the same PR.

## Testing and validation strategy

This project has real automated test infrastructure (PHPUnit — `tests/Smoke`, `tests/Unit`,
`tests/Integration` — plus PHPStan, PHP-CS-Fixer, and a Playwright browser suite; confirmed
current, not the stale "no test infrastructure" note in `specs/000-project-baseline.md`'s
original text, itself superseded by specs 004/005/034/035/040). This spec is used exactly that
way:

- **Unit** (`tests/Unit/`, no DB): `RequestContext` get/set; `RequestIdProcessor` mutates
  `extra` correctly and omits `user_id` when unset; `CorrelationIdMiddleware` validates/
  generates/echoes the header correctly using a stub `RequestHandlerInterface`; updated
  `PrintServiceTest`/`JobServiceTest` assertions on the new structured context (both already use
  a real Monolog `Logger`/`NullLogger`, not message-string mocks, confirmed above, so this is
  additive, not a rewrite).
- **Integration** (`tests/Integration/`, real MySQL via `MYSQL_DATABASE_TEST`): the flagship
  HTTP → Order → Job → Printing trace test, run in-process through the real `App\App` exactly
  as `OrderWorkflowTest` already does, then `JobService::processNext()` called directly in the
  same test process to run the dispatched job synchronously, then the real `app.log` file
  inspected for the expected `request_id` appearing across both log lines. This is
  **appropriate** for what's being proven here (payload survives a DB round-trip and gets read
  back with the right structure) — unlike spec 041's SSE defect, which was specifically about
  per-container transport isolation and required genuinely separate OS processes to mean
  anything; here the mechanism under test is JSON serialize/deserialize through a shared table,
  which an in-process call already exercises for real.
- **Manual**: criterion 10 (`public/admin/logs.php` rendering), against a real running `web`
  container, since no browser-automation change is in scope for this spec.

## Rollout and rollback

Rollout: merge, no migration, no config change required (the header name is fixed and no new
`.env` variable is introduced). Deploy `web` and `print-worker` together, as usual — the
print-side change (`PrintOrderJob` reading `request_id` from job data) is purely additive, so an
old-code worker processing a new-code job (or vice versa) degrades gracefully: the field is
simply absent from context, never a hard failure.

Rollback: revert the commit. No data migration to undo — `jobs.payload` rows that happen to
carry a `request_id` key are harmless to old code, which never reads it.

## Open questions

None blocking. All architectural decisions above (header name, ID format, validation rule,
where `request_id` lives in the job payload, why `JobService` — not `OrderService` — owns the
propagation) were investigated and decided during planning, not left open.

## Task checklist

- [x] 1. `RequestContext` + `RequestIdProcessor`
- [ ] 2. `CorrelationIdMiddleware` + unit tests (middleware written; tests pending — step 8)
- [x] 3. Wire into `src/App.php`
- [x] 4. `JwtMiddleware` + `Routes.php` — `user_id` propagation
- [x] 5. `JobService` — `request_id` through `dispatch()`/failure logging
- [x] 6. `PrintOrderJob` — read `request_id` from job data
- [x] 7. `PrintService` — structured context for the 4 affected log calls
- [x] 8. Unit test updates (`PrintServiceTest`, `JobServiceTest`, plus new `RequestContextTest`,
      `RequestIdProcessorTest`, `CorrelationIdMiddlewareTest`)
- [x] 9. Integration test — full chain trace via `app.log`
- [x] 10. Manual check of `public/admin/logs.php`
- [x] 11. PHPStan, PHP-CS-Fixer, full suite, CHANGELOG + docs sync

## Implementation log

- **2026-09-25 — 1. PHP-DI never autowires a constructor parameter that has a default value —
  confirmed empirically, and it broke `JobService`'s `request_id` propagation before any test
  caught it.** The plan assumed `?RequestContext $requestContext = null` on `JobService` would
  resolve the same way `LoggerInterface`/`Settings` already do there. It doesn't: those two
  work only because `App.php` gives them explicit container definitions; `RequestContext` had
  none, and PHP-DI's autowiring skips resolving ANY parameter with a default — type hint and
  nullability don't matter — falling back to the literal default instead. Verified with three
  throwaway scripts run inside the `web` container before touching any real code: a plain
  optional class-typed param stays `null` even under full autowiring; adding an explicit
  binding for the DEPENDENCY class alone does not fix it; only explicitly overriding that one
  constructor parameter on the CONSUMING class's own definition
  (`\DI\autowire(JobService::class)->constructorParameter('requestContext',
  \DI\get(RequestContext::class))`) does. Fixed in `src/App.php`. The bug was caught by the
  flagship integration test (`RequestTracingTest`), which failed with the job payload's
  `request_id` coming back `null` — exactly the scenario this spec exists to prevent silently
  shipping.
- **2026-09-25 — 2. Slim's own PSR-7 implementation already rejects a raw CR/LF in a header
  value, before `CorrelationIdMiddleware` ever runs.** The spec's stated concern (a header
  carrying a newline into a log line) is real in principle, but `Slim\Psr7\Headers` throws
  `InvalidArgumentException: Header values must be RFC 7230 compatible strings` on `withHeader()`
  for a raw `\n` — confirmed by a failing test before this was known. The unit test
  (`CorrelationIdMiddlewareTest::testReplacesAnInvalidIncomingHeaderWithAGeneratedId`) was
  changed to use a space instead, which IS RFC-7230-legal (so it reaches the middleware) but
  falls outside this spec's deliberately stricter charset — the actual boundary this
  middleware's own validation is responsible for, as opposed to one PSR-7 already covers.
  `CorrelationIdMiddleware`'s validation is kept as defense in depth regardless (a different
  PSR-7 implementation, or a future refactor, might not enforce the same rule).
- **2026-09-25 — 3. `RequestTracingTest` reads the real `app.log`, not a test double — a
  deliberate, disclosed deviation from the rest of the suite's convention.** Every existing
  `JobService`-touching integration test passes `new NullLogger()` specifically to avoid
  writing to the real log file. This spec's flagship test cannot follow that convention for two
  reasons, both structural, not oversights: (a) `CorrelationIdMiddleware` logs through the
  container's real DI-bound `LoggerInterface` — there is no seam to substitute it from
  `IntegrationTestCase::request()`, which builds the real `App` verbatim, so every integration
  test that calls `request()` now writes one HTTP-summary line to the real log as an unavoidable
  side effect of this spec, not something unique to this test; (b) `PrintOrderJob` hardcodes its
  own `Logger`/`StreamHandler` with no constructor injection point at all, unlike `JobService` —
  there is no way to give it a `NullLogger`. Given (a) is now unavoidable for the whole suite,
  reading the same file back in this one test (bounded to the byte range it itself appends, so
  it never depends on prior file contents) was judged the honest way to prove AC7 for real
  rather than inventing a fake seam or leaving the claim unverified.
- **2026-09-25 — 4. `php-cs-fixer --dry-run` flagged `bin/worker` — a pre-existing CRLF issue,
  not something this spec touched or should fix.** `git diff -- bin/worker` shows zero content
  changes (this branch never edited that file), yet the file on disk already has CRLF line
  endings — confirmed with `file bin/worker` inside the container. This is already-committed
  state on `master`, not a local artifact, and it's exactly what `docs/ROADMAP.md`'s separate,
  not-yet-done `[v1.8] LF / CRLF — política consistente no Windows` backlog item exists to fix.
  Left untouched — fixing it here would be scope beyond this spec and would step on that other
  card's work.
- **2026-09-25 — 5. AC10 (`public/admin/logs.php` manual check) verified with a real headless
  browser, not skipped.** No interactive browser is available in this session, so this used the
  same throwaway-Playwright-against-`web-e2e` technique already established for spec 041's own
  manual browser verification: a disposable admin user, a real login, the token placed in
  `localStorage`, and the real page loaded from the test instance (port 8081) with `page.on('console'
  / 'pageerror')` listeners. Zero console errors, page rendered "Logs do Sistema" correctly
  against real log lines already carrying the new `event`/`request_id` JSON context. The script
  was written to the scratchpad directory and to `tests/e2e/specs/` only long enough to run, then
  deleted — it was never meant to become a permanent part of the committed suite (the spec's own
  plan calls for a manual check here, not a new automated e2e spec).

## Validation evidence

All commands run 2026-09-25 inside the `web` container; MySQL-backed tests with
`MYSQL_DATABASE_TEST=restaurant_test`; browser check against `web-e2e` (port 8081,
`docker-compose.e2e.yml`).

- **AC1-3 (header generation/propagation/validation)** —
  `CorrelationIdMiddlewareTest::testGeneratesA32CharacterHexIdWhenNoHeaderIsSupplied`,
  `::testPropagatesAValidIncomingHeaderUnchanged`,
  `::testReplacesAnInvalidIncomingHeaderWithAGeneratedId` (the last uses a space, not a
  newline — Slim's own PSR-7 layer already rejects a raw CR/LF before this middleware runs,
  confirmed empirically; see Implementation log entry 2). All pass.
- **AC4 (error-handler log carries request_id)** —
  `CorrelationIdMiddlewareTest::testLogsTheSummaryLineEvenWhenTheHandlerThrows` (unit,
  status 500 recorded) plus the real, end-to-end proof in `RequestTracingTest` (integration,
  below) showing the actual `app.log` line.
- **AC5 (user_id present when authenticated, absent when not)** —
  `RequestIdProcessorTest::testAddsUserIdToExtraWhenAuthenticated` and
  `::testOmitsUserIdKeyEntirelyWhenUnauthenticated` prove the processor's own logic; the wiring
  from `JwtMiddleware` into the shared `RequestContext` is exercised for real by every
  authenticated call `IntegrationTestCase::request()` makes (e.g. `RequestTracingTest`'s own
  `availableMenuItem()`/order creation path runs unauthenticated, and the existing
  `AuthenticationTest` suite continues to pass with the new optional `RequestContext`
  parameter on `JwtMiddleware` — 44 integration tests green, no regression).
- **AC6 (job payload carries request_id) and AC7 (print-time log line carries order_id, event,
  same request_id) — the flagship trace.**
  `RequestTracingTest::testRequestIdSurvivesFromHttpRequestIntoTheDispatchedPrintJobsLog`:
  creates a real order via `POST /api/orders` through the real `App`, reads the `X-Request-Id`
  response header, confirms the persisted `jobs.payload` JSON contains the same value, runs
  `JobService::processNext('print')` for real (no test double — see Implementation log entry 3
  for why one isn't possible here), then reads the real `app.log` tail and confirms both the
  HTTP summary line and the `Print failed` line carry the same `request_id`, and the print line
  also carries the right `order_id` and `"event":"print.failed"`. **Passes** — real output
  observed:
  ```
  [2026-09-25T21:38:52...] print.ERROR: Print failed {"job_id":445,"attempt":1,"max_attempts":3,
  "request_id":"f685397db3cb84e2781a86e350dfbdd5","event":"print.failed","order_id":1154,
  "error":"IP da impressora não configurado."} []
  ```
- **AC8 (permanent-failure log also carries request_id)** —
  `JobServiceTest::testPermanentFailureLogCarriesRequestIdFromPayload` (unit, deterministic,
  3rd attempt). Also visible for real in the same `RequestTracingTest` run's log tail (the
  job's first-attempt backoff log, `job.ERROR: Job liberado para retry em 2s`, already carries
  `request_id` — the permanent-failure case is the same code path, unit-tested to avoid a
  slower 3-attempt integration test for no additional coverage).
- **AC9 (no secret ever logged)** — manual review of every log call in the five changed files
  (`CorrelationIdMiddleware`, `JobService`, `PrintOrderJob`, `PrintService`, the `App.php` error
  handler): none log a token, password, or `Authorization` header value. `JwtMiddleware`'s own
  decode-failure paths (`TOKEN_MISSING`/`TOKEN_EXPIRED`/`TOKEN_INVALID`) were already, and
  remain, response-only — no log call added there at all; only a successful decode's `sub`
  (already a trusted, non-secret user id, the same value `AuthController` already uses) is
  attached to context.
- **AC10 (`public/admin/logs.php` keeps working)** — `GET /api/admin/logs` still returns 200
  (exercised indirectly by every integration test's `request()` calls succeeding against the
  real app since none of this spec's changes touch `LogController`). Manual browser check: a
  disposable admin user, real login, `admin_token` in `localStorage`, `public/admin/logs.php`
  loaded from the real `web-e2e` instance (port 8081) with `page.on('console'|'pageerror')`
  listeners attached. Result: **zero console errors**, page rendered "Logs do Sistema" and real
  log lines (including ones carrying the new `event`/`request_id` JSON context from this
  session's own integration test runs against that same database). See Implementation log
  entry 5 for why this used a throwaway script rather than a permanent e2e spec.
- **Full regression check** — `vendor/bin/phpunit` (`MYSQL_DATABASE_TEST=restaurant_test`):
  `OK (216 tests, 495 assertions)` (was 196/445 before this spec — +20 tests, +50 assertions,
  matching the 20 new/changed test methods: `RequestContextTest` ×3,
  `RequestIdProcessorTest` ×4, `CorrelationIdMiddlewareTest` ×6, `PrintServiceTest` +3,
  `JobServiceTest` +3, `RequestTracingTest` ×1). `phpstan analyse`: `[OK] No errors` (55
  files). `php-cs-fixer --dry-run --diff`: flagged exactly 1 of 95 files — `bin/worker`, a
  pre-existing CRLF issue this branch never touched (`git diff` shows zero content change on
  that file) and which belongs to the roadmap's own separate, not-yet-done LF/CRLF item — see
  Implementation log entry 4. Every file this spec actually changed is clean.

**Not automated, and why (declared, not hidden):**

- A dedicated `JwtMiddleware` unit test for `user_id` propagation in isolation was not written
  — the integration suite already exercises the real wiring end-to-end through every
  authenticated `request()` call (e.g. `AuthenticationTest`, `PrinterBlockTest`), and the
  processor's own logic (the part that's actually new) is unit-tested directly in
  `RequestIdProcessorTest`. Adding a third, narrower test for the same wiring was judged
  redundant rather than additive.
