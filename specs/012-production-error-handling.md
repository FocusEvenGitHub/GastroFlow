# Spec 012 — Production error handling

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Production error handling" subsection, requires that detailed application errors may remain available during development, but production API responses must not expose source paths, stack traces, SQL details, credentials, or infrastructure information — with full exceptions still available through application logs. It gives an explicit example production response:

```json
{"success": false, "error": "Internal server error", "code": "INTERNAL_ERROR"}
```

This is the exact connection point `specs/011-application-environment.md` deliberately deferred: `src/App.php:56`'s `$app->addErrorMiddleware(true, true, true)` hardcodes `$displayErrorDetails = true` unconditionally, and its Non-goals explicitly named this as "docs/ROADMAP.md's 'Production error handling' subsection, a separate future spec." Spec 011 introduced `App\Settings::isDebug()` for exactly this purpose. This spec wires it in.

## Problem

Investigation found the leak is broader than the one hardcoded `true` spec 011 flagged:

1. `src/App.php:56` — `addErrorMiddleware(true, true, true)`. Every genuinely uncaught exception returns full `$exception->getMessage()`, `file`, `line`, and `trace` in the JSON body today, in every environment, including whatever a real deployment would call "production."
2. Three controllers additionally catch exceptions **locally** and independently leak `$e->getMessage()` in a generic `catch (\Throwable $e)` fallback, bypassing `App::get()`'s error middleware entirely — confirmed by direct reads:
   - `src/Controllers/OrderController.php` — 10 generic-catch sites (`store`, `complete`, `uncomplete`, `update`, `addItem`, `updateItem`, `removeItem`, `destroy`, `print`, plus one more inside `removeItem`'s catch chain), all `catch (\Throwable $e) { ...json_encode(['error' => $e->getMessage()])...; withStatus(500)`.
   - `src/Controllers/MenuController.php` — 6 generic-catch sites (`store`, `updateItem`, `getComponents`, `updateComponents`, `reorder`, `delete`), same pattern, all status 500.
   - `src/Controllers/AdminController.php` — 1 generic-catch site (`testPrint`), same pattern, status 500.
   - None of these 17 sites currently log the caught exception anywhere — if `PrintService::printTestPage()`, `MenuService::addItem()`, or similar throws (e.g. a real `\Illuminate\Database\QueryException` with a raw SQL error message), that message goes straight to the HTTP client and is never recorded in `logs/app.log`. This directly contradicts the roadmap's "full exceptions should remain available through application logs" requirement — today they aren't available anywhere once one of these local catches fires.
3. Six other controllers (`DishController`, `IngredientController`, `KitchenController`, `AuthController`, `ReportController`) have **no** local `try`/`catch` at all — any exception they raise already flows to `App::get()`'s error middleware, so fixing item 1 covers them without any controller-level change.
4. A related latent bug in `App::get()`'s existing closure, surfaced (not introduced) by wiring `isDebug()`: the non-debug fallback (`'Erro interno do servidor.'`) is applied to **every** exception, including Slim's own `HttpSpecializedException`-driven responses (e.g. a route-not-found `404` with Slim's built-in `"Not Found"` message). Slim's built-in HTTP exception messages don't leak anything sensitive, so collapsing a `404`'s body into a generic `"Erro interno do servidor." / "INTERNAL_ERROR"` message would be a confusing regression, not a security fix — this only matters for genuine `500`s (uncaught, unrecognized exceptions).

## Goals

- No JSON error response from any endpoint exposes an exception's raw message, file path, line number, or stack trace when `App\Settings::isDebug()` is `false`.
- Every exception that is sanitized before reaching the client is still fully recorded, with its real message, in the application log (`logs/app.log` via Monolog).
- When `isDebug()` is `true` (the existing default local-dev behavior, confirmed unaffected by spec 011), responses are unchanged from today — no regression for local development.
- Curated, already-safe business error messages (`"Pedido não encontrado"`, `"Item não encontrado"`, validation messages, etc.) are untouched — this spec sanitizes leaked *internals*, not existing deliberate user-facing text.
- Slim's own `HttpSpecializedException` messages (route/method not found, etc.) are not swallowed into the generic `500` envelope — only genuine unrecognized/`500` errors get the sanitized envelope.

## Non-goals

- **Not unifying the response shape across every error type** (400/404/409 validation and not-found responses keep their current `{"error": "..."}` shape, without `success`/`code`). Full API error format standardization across all status codes is `docs/ROADMAP.md`'s separate `v1.7.0 — Domain & Architecture` subsection, "API error standardization" — this spec only applies the roadmap's example envelope to the specific case it was given for (a sanitized, otherwise-uncontrolled `500`).
- **Not fixing the pre-existing status-code gap** where `OrderController::complete()`/`uncomplete()` return `500` (via their single generic `catch (\Throwable $e)`) instead of `404` when the target order doesn't exist (no `ModelNotFoundException`-specific catch exists there today). Flagged as a found issue, not fixed — changing a status code is a different, discrete concern from sanitizing message content, and risks masking this as a "security fix" when it's actually an API-correctness one.
- **Not adding `try`/`catch` to controllers that don't have any today** (`DishController`, `IngredientController`, `KitchenController`, `AuthController`, `ReportController`). Their exceptions already reach `App::get()`'s error middleware, which this spec fixes directly.
- **Not touching `Dockerfile`'s `COPY .env ./`** or any other `v1.6.0` "Docker secret handling" concern — separate subsection, separate spec.
- **Not adding a new shared response-helper class.** Each of the three affected controllers gets its own small private `errorResponse()` method, mirroring `ReportController`'s existing private `json()` helper — matching this project's real convention (per-controller private helpers, no shared response layer) rather than inventing a new cross-controller abstraction.
- **Not changing what gets logged for exceptions that already reach `App::get()`'s error middleware** — that closure already unconditionally logs every exception today; only its client-facing payload changes.

## Current behavior

Confirmed by direct reads on the current working tree:

- `src/App.php:56-96` — `$app->addErrorMiddleware(true, true, true)`, custom `setDefaultErrorHandler` closure: builds `$statusCode` from `HttpSpecializedException::getCode()` when applicable (else `500`), unconditionally logs via `LoggerInterface` (`$logger->error($exception->getMessage(), $context)`, with `trace` added to the log context only when `$displayErrorDetails`), then returns a JSON body — `$exception->getMessage()` plus `file`/`line`/`trace` when `$displayErrorDetails` is true (always, today), else `'Erro interno do servidor.'` with no other fields.
- `src/Controllers/OrderController.php`, `MenuController.php`, `AdminController.php` — 17 total `catch (\Throwable $e)` sites as enumerated in Problem, all building `json_encode(['error' => $e->getMessage()])` and returning `withStatus(500)`, with zero logging.
- `src/Controllers/OrderController.php`'s constructor: `__construct(OrderService $orderService, OrderValidator $validator)`, manual property assignment (older style, per `CLAUDE.md`).
- `src/Controllers/MenuController.php`'s constructor: `__construct(MenuService $menuService)`, manual property assignment (older style).
- `src/Controllers/AdminController.php`'s constructor: `__construct(private readonly PrintService $printService, private readonly Settings $settings)` — promoted properties (newer style), already has `Settings` injected, no `LoggerInterface`.
- `src/Routes.php` — every controller is referenced as `[ControllerClass::class, 'method']` inside `$app->group(...)`, resolved through the PHP-DI container built in `App::get()` (`useAutowiring(true)`), confirmed by `AdminController` already receiving `Settings` this way with no explicit container definition for it beyond the one registered in `App::get()`. Adding new constructor parameters (`Settings`, `LoggerInterface`) to `OrderController`/`MenuController` will resolve automatically the same way, without touching `Routes.php`.
- `LoggerInterface` is already registered in the container (`App::get()`, `src/App.php:35-39`), writing to `$settings->getLogFile()` via a `StreamHandler`.
- `App\Settings::isDebug()` (spec 011) is already implemented and returns `false` unless `APP_DEBUG` is explicitly set to a truthy value.
- No automated test currently exercises any of these error paths (confirmed: `tests/` contains only `Smoke/ApiTest.php` and `Unit/` tests for `OrderService`/`OrderValidator`/`PrintService`/`JobService`, none of which hit a `catch (\Throwable)` fallback or the error middleware).

## Proposed behavior

After this change:

- `App::get()`'s error handler: `$displayErrorDetails = $this->settings->isDebug();`. For a genuine `500` (not an `HttpSpecializedException`) with `isDebug() === false`, the JSON body becomes `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}` (no `file`/`line`/`trace`). For an `HttpSpecializedException`-driven response (any status via that class), the body keeps showing `$exception->getMessage()` regardless of `isDebug()`, since Slim's own messages for those are already safe to display. When `isDebug() === true`, behavior is byte-for-byte unchanged from today.
- `OrderController`, `MenuController`, `AdminController` each gain a private `errorResponse(Response $response, \Throwable $e, int $status = 500): Response` method that: logs the exception via an injected `LoggerInterface` (always, regardless of `isDebug()`); returns `{"error": $e->getMessage()}` when `isDebug()` is true (today's exact shape); returns `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}` otherwise. All 17 generic `catch (\Throwable $e)` bodies are replaced with `return $this->errorResponse($response, $e);`.
- Every other existing catch clause (`ModelNotFoundException`, `DomainException`, `QueryException`) and every existing `400`/`409` validation response is untouched — same message, same status, same shape as today.

## Functional requirements

1. With `APP_DEBUG` unset (or falsy), any endpoint that throws an unrecognized exception through `App::get()`'s error middleware returns HTTP `500` with body exactly `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}` — no `file`, `line`, `trace`, or the original exception message.
2. With `APP_DEBUG=true`, the same scenario returns the exact same body shape as today: `{"error": "<original message>", "file": "...", "line": ..., "trace": [...]}`.
3. With `APP_DEBUG` unset, a request that causes Slim to throw an `HttpSpecializedException` (e.g. a request to an undefined route) still returns that exception's own status code and message (e.g. `404` / `"Not Found"`) — not the `INTERNAL_ERROR` envelope.
4. Every exception that reaches `App::get()`'s error middleware is logged via `LoggerInterface`, in both debug and non-debug mode (unchanged from today).
5. With `APP_DEBUG` unset, each of the 17 identified generic-catch sites in `OrderController`/`MenuController`/`AdminController` returns HTTP `500` with body exactly `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}` when the underlying service/repository call throws.
6. With `APP_DEBUG=true`, the same 17 sites return `{"error": "<original message>"}` — identical to current behavior.
7. Every one of the 17 sites logs the caught exception via `LoggerInterface` when it fires, in both debug and non-debug mode (new behavior — today these sites log nothing).
8. No other catch clause in these three controllers (`ModelNotFoundException`, `DomainException`, `QueryException`, or inline `400` validation responses) changes message, status code, or shape.
9. `OrderController`, `MenuController` gain `Settings` and `LoggerInterface` constructor dependencies, resolved via the existing PHP-DI autowiring with no change to `src/Routes.php`. `AdminController` gains only `LoggerInterface` (already has `Settings`).

## Non-functional requirements

Not applicable beyond Security considerations below — no performance impact (one boolean check and, on the sanitized path, a smaller response body than today).

## User flows

Not applicable — this is server-side error-response content only; no cashier/kitchen/admin UI flow changes. A cashier/kitchen/admin screen that already handles a generic `{"error": "..."}` shape continues to work, since that key is still present in every sanitized response.

## API changes

For any endpoint, when an unrecognized exception occurs and `APP_DEBUG` is not set: response body changes from `{"error": "<raw exception message>", ...}` to `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}`. HTTP status code is unchanged (`500` in all 17 controller sites and in the App-level handler's non-`HttpSpecializedException` path). No change to any `200`/`201`/`400`/`404`/`409` response.

## Data model and migrations

Not applicable — no database changes.

## Architecture and affected components

- `src/App.php` — error middleware's default handler closure.
- `src/Controllers/OrderController.php` — constructor (add `Settings`, `LoggerInterface`), new private `errorResponse()`, 10 call sites updated.
- `src/Controllers/MenuController.php` — constructor (add `Settings`, `LoggerInterface`), new private `errorResponse()`, 6 call sites updated.
- `src/Controllers/AdminController.php` — constructor (add `LoggerInterface`), new private `errorResponse()`, 1 call site updated.
- No `Services/`, `Repositories/`, `Validators/`, `Middleware/`, `Models/`, or frontend changes.

## Security considerations

This spec's entire purpose is a security fix: it directly addresses the roadmap's requirement that production responses not expose source paths, stack traces, SQL details, or infrastructure information. Two things must hold after implementation, and both are covered by Acceptance criteria: (1) sanitization actually happens when `APP_DEBUG` is unset — the safe default per spec 011; (2) sanitized exceptions are not silently lost — they must still land in `logs/app.log`, so an operator can diagnose a production `500` without needing to temporarily flip on `APP_DEBUG` (which would itself leak details to real clients while diagnosing).

## Backward compatibility

Any API consumer (today, only this repo's own `public/cashier`, `public/kitchen`, `public/admin` frontends) that parses the exact text of an error message from a `500` response would see different text once `APP_DEBUG` is unset in a given environment. A repo-wide grep for frontend code branching on specific `error` message *content* (as opposed to just displaying it) found none — all three frontends display whatever string the `error` key holds, so this is safe. No stored data or non-`500` endpoint is affected.

## Acceptance criteria

1. With the real `.env`'s current `APP_DEBUG` value (confirmed unset/false in this environment per spec 011), triggering `AdminController::testPrint` with no printer configured (or an invalid host) returns `500` with body exactly `{"success":false,"error":"Erro interno do servidor.","code":"INTERNAL_ERROR"}`, and `logs/app.log` gains a new line containing the real underlying exception message.
2. The same request with `-e APP_DEBUG=true` (container env override, not editing the real `.env`) returns `500` with `{"error":"<real message>"}` — today's exact shape.
3. `curl` to an undefined route (e.g. `GET /api/does-not-exist`) with `APP_DEBUG` unset returns Slim's own `404` with its own message (not `INTERNAL_ERROR`).
4. A request that already returns a curated error today (e.g. `DELETE /api/admin/items/999999` for a nonexistent item → `404` `{"error":"Item não encontrado"}`) returns byte-for-byte the same response after this change, with `APP_DEBUG` both unset and `true`.
5. `vendor/bin/phpunit` passes with the same count as before this spec (no regression from the new constructor dependencies).
6. `php -l` passes on all four changed files.

## Implementation plan

1. Update `src/App.php`'s error handler: `$displayErrorDetails = $this->settings->isDebug();`, and adjust the non-debug payload to only apply the `INTERNAL_ERROR` envelope when the exception is not an `HttpSpecializedException` (i.e., a genuine `500`).
2. Add `Settings`/`LoggerInterface` constructor dependencies and a private `errorResponse()` helper to `AdminController`; replace its one generic-catch site.
3. Same for `MenuController` (6 sites) and `OrderController` (10 sites), keeping each file's existing constructor-assignment style (manual for these two, since that's their current convention).
4. Manually verify via `docker compose exec` with `-e APP_DEBUG=true/unset` overrides (Acceptance criteria 1-4) — trigger real failures where practical (e.g. an invalid printer host for `testPrint`, a malformed `menu_item_id` for `MenuController`) without touching real data destructively.
5. Run `vendor/bin/phpunit` and `php -l` on all changed files (Acceptance criteria 5-6).
6. Read the full diff to confirm no curated error message/status/shape changed anywhere it shouldn't have.

## Testing and validation strategy

This project's automated test suite (`specs/004`/`005`) does not cover HTTP-level error responses (confirmed in Current behavior). Validation is: real `docker compose exec`-driven `curl` calls against the running app, toggling `APP_DEBUG` via `-e` container-env overrides (never editing the real `.env`, per `CLAUDE.md`); reading `logs/app.log` before/after each triggered failure to confirm the real exception was recorded; `vendor/bin/phpunit` for regression coverage of the untouched `OrderService`/`OrderValidator` logic; `php -l` for syntax.

## Rollout and rollback

No migration, no dependency, no container change. Rollback is a plain `git revert`. Since sanitization only activates when `APP_DEBUG` is unset, and this environment's real `.env` already has `APP_DEBUG` set per the user's own update after spec 011, the user should be aware this spec's production-path behavior will need `-e`/temporary env overrides to observe directly in this dev environment — see Open questions.

## Open questions

- **Not blocking, informational**: the user's real `.env` now sets `APP_DEBUG=true` (per spec 011's `.env.example` and the user's own confirmation of updating `.env`), so this environment will exercise the **debug** path (Functional requirements 2, 6), not the sanitized one, during normal `docker compose up -d` operation. Acceptance criteria 1, 3, 5 (the sanitized-path checks) require a temporary `-e APP_DEBUG=false`-equivalent (or unset) container override to observe directly — this does not touch the real `.env` and is reverted after the check.

## Task checklist

- [x] `src/App.php` error handler wired to `isDebug()`, `HttpSpecializedException` messages preserved regardless of debug mode
- [x] `AdminController`: `LoggerInterface` added, `errorResponse()` helper added, 1 site updated
- [x] `MenuController`: `Settings`/`LoggerInterface` added, `errorResponse()` helper added, 6 sites updated
- [x] `OrderController`: `Settings`/`LoggerInterface` added, `errorResponse()` helper added, 9 sites updated (corrected count — see Implementation log)
- [x] Acceptance criteria 1-4 verified via `docker compose exec` + `curl`, both debug and non-debug
- [x] `logs/app.log` confirmed to receive the real exception message on a sanitized response
- [x] `vendor/bin/phpunit` + `php -l` run, zero regressions

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Corrected a miscount from `/spec-plan`: `OrderController` has 9 generic `catch (\Throwable $e)` sites, not 10 (`store`, `complete`, `uncomplete`, `update`, `addItem`, `updateItem`, `removeItem`, `destroy`, `print`). Functional requirement 5's "17 identified generic-catch sites" is therefore 16 in total (1 + 6 + 9); the discrepancy doesn't change any acceptance criterion, since they're all verified by the same pattern.
- 2026-09-03 — Implemented `src/App.php`'s error handler exactly as planned: `$displayErrorDetails`/debug now gates the sanitized envelope, but only for non-`HttpSpecializedException` (genuine `500`) cases — Slim's own HTTP exception messages (404, etc.) are shown regardless of debug mode, per the spec's Proposed behavior.
- 2026-09-03 — **Bug found and fixed during implementation**: `$this->settings->isDebug()` inside the error-handler closure threw `Error: Call to a member function isDebug() on null`, because `$this` inside the closure resolved to `DI\Container`, not `App`, once the closure body referenced `$this` (previously it only used `use`-captured variables and never touched `$this`, so this had never surfaced before). Root cause not fully diagnosed (likely Slim/php-di-slim-bridge's callable-resolution strategy rebinding closures passed to `setDefaultErrorHandler`), but the fix is unambiguous: capture `$settings = $this->settings;` before defining the closure and add it to the `use (...)` clause, exactly like `$app`/`$container` already were, instead of relying on `$this` inside the closure. Verified fixed via a live `curl` request (see Validation evidence) — this would have been a production-breaking regression (every error response, including ordinary Slim 404s, would 500) had it shipped unverified.
- 2026-09-03 — `AdminController`, `MenuController`, `OrderController` implemented per plan; `MenuController`/`OrderController` kept their existing manual constructor-assignment style (not converted to promoted properties) since only new parameters were added, not the whole constructor, per `CLAUDE.md`'s "don't mass-retrofit old files as a side effect of an unrelated change." `AdminController` used promoted `private readonly` for the new `LoggerInterface`, matching its existing style.
- 2026-09-03 — `OrderController::complete()`/`uncomplete()` needed structural changes beyond a one-line swap (their catch blocks fell through to a shared `$status ?? 200` response-building tail, rather than returning directly) — restructured to return `errorResponse()` immediately from the catch block, with the success path unchanged (still implicitly `200`).
- 2026-09-03 — Verification used a real, naturally-occurring exception rather than an artificially injected one: `AdminController::testPrint()` genuinely fails in this dev environment (no thermal printer reachable — `Cannot initialise NetworkPrintConnector: Connection timed out`), which exercised the full real code path (JWT login → `/api/admin/settings/test-print` → `PrintService::printTestPage()` throws → `errorResponse()`) authentically, in both debug and non-debug mode, rather than relying on a synthetic throw.

## Validation evidence

- Acceptance criterion 1 — Real failure via `AdminController::testPrint()` (no printer reachable), `-e APP_DEBUG=false` override: `POST /api/admin/settings/test-print` (authenticated via a real login token) → `HTTP 500`, body `{"success":false,"error":"Erro interno do servidor.","code":"INTERNAL_ERROR"}`. `docker compose exec web tail -1 logs/app.log` → `app.ERROR: Cannot initialise NetworkPrintConnector: Connection timed out {"exception":"Exception"} []` — the real message was recorded. **Confirmed.**
- Acceptance criterion 2 — Same request against the real running app (`.env` has `APP_DEBUG=true`, unmodified): `HTTP 500`, body `{"error":"Cannot initialise NetworkPrintConnector: Connection timed out"}` — today's exact shape (no `success`/`code`, matches pre-change behavior for this controller). **Confirmed.**
- Acceptance criterion 3 — `GET /api/does-not-exist`: with real `.env` (`APP_DEBUG=true`) → `HTTP 404`, `{"error":"Not found.","file":...,"line":...,"trace":[...]}` (Slim's own message, full debug detail, unchanged from pre-spec behavior). With `-e APP_DEBUG=false` override → `HTTP 404`, `{"error":"Not found."}` — Slim's message shown, no `INTERNAL_ERROR` envelope substituted. **Confirmed, both modes.**
- Acceptance criterion 4 — `DELETE /api/orders/999999` (nonexistent order, hits `OrderController::destroy()`'s `ModelNotFoundException` catch, not the generic one): `HTTP 404`, `{"error":"Pedido não encontrado"}` — byte-for-byte identical with real `.env` (`APP_DEBUG=true`) and with `-e APP_DEBUG=false` override. **Confirmed, curated errors unaffected by debug mode, as required.**
- Acceptance criterion 5 — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)`, same as before this spec's changes — confirms the new constructor dependencies (`Settings`, `LoggerInterface`) resolve correctly via autowiring with no test regression (`tests/Smoke/ApiTest.php` builds a real `App` instance, exercising the exact DI path this spec changed).
- Acceptance criterion 6 — `php -l` on all four changed files (`src/App.php`, `src/Controllers/AdminController.php`, `src/Controllers/MenuController.php`, `src/Controllers/OrderController.php`) — no syntax errors, all four.
- Additional evidence beyond the stated acceptance criteria: a genuine `500` was also triggered directly through `App::get()`'s error middleware (a route handler that throws `RuntimeException` with a fake secret-looking message), confirming the App-level (not just controller-level) sanitization path independently: non-debug → `{"success":false,"error":"Erro interno do servidor.","code":"INTERNAL_ERROR"}`; the real message (including the fake "password=SUPERSECRET" text) was confirmed present in `logs/app.log`.
- **Not validated**: an automated regression test asserting these exact response shapes — this project has no HTTP/integration-level test infrastructure for controller responses (confirmed in Current behavior); all verification above is manual, against the real running container, per this project's established validation convention (`specs/004`).
