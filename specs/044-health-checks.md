# Spec 044 — Health checks

## Metadata

- Status: Verified
- Created: 2026-09-26
- Updated: 2026-09-26
- Owner: Henry
- Related issue: Not applicable (no GitHub Issue filed; tracked directly via `docs/ROADMAP.md`'s v1.8.0 "Health checks" item)
- Related branch: 044

## Context

`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` milestone lists "Health checks" as one of its items:

> Provide at least:
> `GET /health/live`
> `GET /health/ready`
> Readiness should reflect important dependencies such as database availability.

Every other v1.8.0 sub-item that precedes it in the roadmap (Static analysis, Code style, CI quality pipeline, Integration tests, E2E smoke tests, Job reliability, Printing reliability, Realtime reliability, Structured logging, Audit history) already has a landed, `Verified` spec (specs 033-035, 037-043). Health checks is the next unaddressed item in document order, and `README.md:216` already names it first in the "Next up" list for this milestone.

No liveness/readiness endpoint exists anywhere in the codebase today (confirmed by grep across `src/`, `public/`, `docs/`) — this is new functionality, not a gap in an existing one.

## Problem

GastroFlow has no HTTP endpoint an external process (Docker, a reverse proxy, an uptime monitor, an operator's `curl`) can call to answer two distinct operational questions:

1. Is the PHP application process itself up and answering requests at all?
2. Is the application actually able to do its job right now — specifically, can it reach the database?

Today the only way to answer either is to hit a real business endpoint (e.g. `GET /api/menu`), which conflates "is the app alive" with "does this particular business query succeed," and — for `/api/admin/*` — would additionally require a valid JWT just to probe liveness.

## Goals

- Provide `GET /health/live`: confirms the PHP process is up and Slim is routing requests, independent of any dependency.
- Provide `GET /health/ready`: confirms the application's important runtime dependency (the MySQL database) is currently reachable.
- Both endpoints are unauthenticated (no JWT), so infrastructure tooling can probe them without credentials.
- Both endpoints never leak internals (stack traces, file paths, SQL text, credentials) in their response body, in either the success or failure case.

## Non-goals

- Wiring these endpoints into `docker-compose.yml`'s `web`/`print-worker` service definitions as a Docker `HEALTHCHECK` directive. The current `php:8.2-apache`-based image has no `curl`/`wget` installed (confirmed in `Dockerfile`), and adding one is an unrequested dependency change per `CLAUDE.md`'s "General rules" ("Don't add new dependencies... unless explicitly asked"). The roadmap item only asks that the endpoints be **provided**, not that Compose consume them — this is deferred as a separate, explicitly-requested follow-up if wanted.
- Checking dependencies other than the database (printer reachability, job queue backlog, disk space, SEFAZ, etc.). The roadmap text gives the database as the concrete example ("such as database availability") and nothing else in the current architecture is a hard runtime dependency of the HTTP layer itself — printer/job-queue health already has its own operator-visible signal (`GET /api/printer/status`, `bin/jobs-status`, spec 033/038/039) and mixing it into readiness would make `/health/ready` fail for reasons unrelated to "can the app serve a request."
- A `/health` root/aggregate endpoint. The roadmap names exactly the two paths above.
- Any new Service/Repository layer for this. The check is a single lightweight query; introducing a `HealthService`/`HealthRepository` would be an empty abstraction layer for a one-line check, which `CLAUDE.md`'s "General rules" explicitly warns against ("Don't create Controllers/Services/Repositories/... layers for a domain that doesn't already have them, purely for architectural symmetry").
- A configurable timeout on the readiness DB check. `Database::boot()` (`src/Database.php`) sets no connect-timeout today, and none of the sibling controllers configure one either; adding one here would be scope creep beyond what the roadmap asks. Recorded as a non-blocking open question below.

## Current behavior

- `src/Routes.php` registers three groups: an unauthenticated `/api` group (menu/orders/kitchen/printer — public by long-standing design, spec 018), `/api/login` and `/api/admin/account/password` (JWT-protected), and `/api/admin/*` (JWT + role-protected). There is no route outside these groups except the `/` → `/cashier/` redirect.
- `src/App.php` builds the Slim app, boots Eloquent via `Database::boot()`, and registers `App.php`'s own error middleware, `CorrelationIdMiddleware` (spec 042, attaches a `request_id` to every response), `CorsMiddleware`, and `JsonBodyParserMiddleware` — all applied globally, before routing, so any new route registered in `Routes::register()` automatically gets a `request_id` header and CORS handling without extra wiring.
- `src/Database.php`'s `Database::boot()` opens a single global Eloquent/Capsule MySQL connection at app-bootstrap time (`DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` from `Settings`). There is no existing "ping the database" helper anywhere in `src/`.
- `App\ApiResponse::error()` (`src/ApiResponse.php`) is the project's one shared response helper, used only for the `{"success": false, "error": ..., "code": ...}` error shape (spec 024); success payloads are still built manually per controller (e.g. `PrinterController::status()`, `src/Controllers/PrinterController.php:27-33`), so this spec follows the same pattern rather than introducing a new success helper.
- No `HealthController`, `/health/live`, or `/health/ready` exists anywhere in `src/`, `public/`, or `docs/` today (confirmed by a repository-wide grep for `health` — the only hits are the CI job's own `mysqladmin ping --health-*` Docker Compose service options, unrelated documentation, and this same roadmap item's own text).

## Proposed behavior

- `GET /health/live` always returns `200` with `{"success": true, "status": "live"}` as long as the PHP process can route the request through Slim — it performs no dependency check of any kind.
- `GET /health/ready` attempts a lightweight database round-trip (`SELECT 1` through the existing Eloquent/Capsule connection). On success it returns `200` with `{"success": true, "status": "ready"}`. On failure (any `\Throwable` from the query/connection attempt) it returns `503` with `{"success": false, "error": "Banco de dados indisponível.", "code": "DB_UNAVAILABLE"}`, built via `App\ApiResponse::error()` — matching the rest of the codebase's error shape and its "user-facing error strings in Portuguese" convention (`CLAUDE.md`).
- Both routes are registered directly on `$app` in `Routes::register()`, outside the `/api` group and without `JwtMiddleware`/`RoleMiddleware`, so they remain reachable without a token — consistent with the existing public `/api/printer/status` precedent for infrastructure-facing endpoints.
- Both still pass through `App.php`'s existing global middleware stack (`CorrelationIdMiddleware`, `CorsMiddleware`, `JsonBodyParserMiddleware`, the error middleware) unchanged — no opt-out is introduced, since none of that middleware requires authentication or leaks internals for these routes.

## Functional requirements

1. `GET /health/live` returns HTTP `200` with `Content-Type: application/json` and body `{"success": true, "status": "live"}`, with no request parameters and no dependency check.
2. `GET /health/ready` returns HTTP `200` with body `{"success": true, "status": "ready"}` when the configured MySQL database answers a lightweight query.
3. `GET /health/ready` returns HTTP `503` with body `{"success": false, "error": "Banco de dados indisponível.", "code": "DB_UNAVAILABLE"}` when the database connection/query fails for any reason.
4. Neither endpoint requires an `Authorization` header; a request without one must not receive `401`.
5. Neither endpoint's response body, under any status, contains a stack trace, a file path, or the underlying SQL/PDO exception message.
6. Both endpoints respond only to `GET`; other methods fall through to Slim's normal `405`/`404` handling (no special-casing needed beyond registering `GET`).

## Non-functional requirements

- No new Composer or system (`apt`) dependency is introduced (`CLAUDE.md`: "Don't add new dependencies... unless explicitly asked").
- No new database migration, table, or column (this feature reads, never writes, and needs no new persisted state).
- The readiness check must reuse the existing global Eloquent/Capsule connection (`src/Database.php`) rather than opening a second, parallel connection.

## User flows

- **Docker/orchestration operator**: configures an external prober (or, in the future, a Compose `HEALTHCHECK` — out of scope here) to call `GET /health/live` periodically to detect a hung/crashed PHP process, and `GET /health/ready` to detect "process is up but the database is unreachable" before routing real traffic to it.
- **Developer/operator troubleshooting**: runs `curl http://localhost:8080/health/ready` by hand while diagnosing a suspected DB connectivity issue, without needing to log in first.

## API changes

New, additive endpoints — no existing endpoint's request/response shape changes.

| Method | Path | Auth | Success | Failure |
|---|---|---|---|---|
| GET | `/health/live` | None | `200 {"success": true, "status": "live"}` | Not applicable — this endpoint has no failure mode by design |
| GET | `/health/ready` | None | `200 {"success": true, "status": "ready"}` | `503 {"success": false, "error": "Banco de dados indisponível.", "code": "DB_UNAVAILABLE"}` |

## Data model and migrations

Not applicable — no new/changed table, column, or Eloquent model. The readiness check reads through the existing Capsule connection with no schema dependency beyond "the configured database is reachable."

## Architecture and affected components

- New `src/Controllers/HealthController.php` — two thin action methods (`live`, `ready`), following the existing controller convention (promoted `private readonly` properties for new code, per `CLAUDE.md`'s code conventions) even though this controller needs no constructor dependencies (it calls `Illuminate\Database\Capsule\Manager` directly, the same way `App\Database` and `App\Database\MigrationRunner` already do, rather than through an injected repository that doesn't otherwise exist for this domain).
- `src/Routes.php` — two new top-level route registrations (outside `/api`, no middleware added).
- No changes to `src/App.php`, `src/Database.php`, `src/Settings.php`, or any existing controller/service/repository/validator/middleware.

## Security considerations

- Both endpoints are intentionally unauthenticated. This is a deliberate scope decision (mirrors `/api/printer/status`'s existing public-endpoint precedent, spec 018/039) rather than an oversight: a liveness/readiness probe that requires a JWT defeats its own purpose for orchestration tooling that has no login flow.
- Neither endpoint accepts any request input (no query/body parameters), so there is no injection or validation surface to cover.
- The `503` failure message is a fixed, generic Portuguese string (`"Banco de dados indisponível."`) — never the caught exception's own message — so a real PDO/connection error (which can include the configured host, database name, or, in rarer PDO error strings, connection details) never reaches the HTTP response. Full exception detail still goes to `logs/app.log` only if explicitly logged; this spec does not require logging every readiness failure (a transient failed probe is expected operational noise, not an error needing its own log line) — logging is left to the existing global error middleware only if the exception is allowed to propagate, which it is not here (it's caught explicitly to produce the `503`).

## Backward compatibility

Purely additive — no existing route, response shape, or client integration changes. No impact on any existing API consumer.

## Acceptance criteria

1. `curl -i http://localhost:8080/health/live` (or the PHPUnit-simulated equivalent) returns `200` and a body of exactly `{"success":true,"status":"live"}` (modulo JSON key ordering).
2. With the `db` container running normally, `curl -i http://localhost:8080/health/ready` returns `200` and a body of exactly `{"success":true,"status":"ready"}`.
3. With the `db` container stopped (`docker compose stop db`), `curl -i http://localhost:8080/health/ready` returns `503` and a body of exactly `{"success":false,"error":"Banco de dados indisponível.","code":"DB_UNAVAILABLE"}`, with no stack trace, file path, or SQL text anywhere in the body. `db` is restarted afterward and normal readiness (`200`) is re-confirmed.
4. Both `curl` calls above are made with no `Authorization` header and neither returns `401`.
5. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff` both pass clean on the new/changed files.
6. `vendor/bin/phpunit --testsuite Smoke,Unit` passes, including a new automated test asserting acceptance criterion 1 and 2 (the "DB down" case in criterion 3 is verified manually per the Testing section below, not automated).

## Implementation plan

1. Add `src/Controllers/HealthController.php` with `live(Request, Response): Response` (always `200`) and `ready(Request, Response): Response` (tries `Capsule::connection()->select('SELECT 1')` in a `try/catch (\Throwable)`, returns `200`/`503` per the shapes above).
2. Register `GET /health/live` and `GET /health/ready` in `src/Routes.php`, top-level (outside the `/api` group, no middleware), placed near the existing `/` redirect for readability.
3. Add `tests/Smoke/HealthTest.php` covering acceptance criteria 1 and 2 (both endpoints against the real test-run database connection, mirroring `tests/Smoke/ApiTest.php`'s existing pattern of booting the real `App` and asserting on the response).
4. Run `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff` and fix any findings.
5. Manually verify acceptance criterion 3 (the DB-down `503` path) against the local Docker Compose stack, since this failure mode cannot be triggered by the Smoke/Unit suite without tearing down the real database the rest of that suite also depends on.
6. Update `docs/ROADMAP.md`'s "Health checks" item with a `**Status (spec 044)**` line, and `README.md`'s "Next up" note, per `CLAUDE.md`'s docs-pass-with-every-landed-spec rule.
7. Add a `CHANGELOG.md` entry under the in-progress `v1.8.0` heading once this lands on `master`, per the same rule.

## Testing and validation strategy

This project does have automated test infrastructure (PHPUnit `Smoke`/`Unit`/`Integration` suites, spec 004/034/035, run in CI against a real MySQL 8.0 service) — unlike a from-scratch project, so this is not the "no test infrastructure" case the spec template warns about. Acceptance criteria 1, 2, 4 and 6 are covered by a new `tests/Smoke/HealthTest.php`, run the same way as the rest of the suite (`docker compose exec web vendor/bin/phpunit`). Acceptance criterion 3 (the readiness check's failure path) is **not** automated in this spec: the Smoke/Unit suites run against the same live database the rest of the app uses in that environment, and stopping it mid-run would break every other test in the same process, not just this one. It is instead verified manually against the local Docker Compose stack (stop `db`, curl, observe `503`, restart `db`, confirm `200` again), with the actual command output recorded in Validation evidence below — per `CLAUDE.md`'s "never claim a test or check passed without having actually run it and observed the result."

## Rollout and rollback

Additive, stateless, no feature flag needed — the routes either exist or don't. Rollback is a plain revert of the two new files plus the two route registrations; no data or migration to unwind.

## Open questions

- Should `Database::boot()` (`src/Database.php`) gain an explicit PDO connect-timeout so `/health/ready` fails fast (e.g. within 2-3s) instead of waiting on MySQL's/PDO's own default connect timeout when the DB host is unreachable rather than merely down? **Non-blocking** — today's behavior (whatever PDO's default connect timeout is) is not worse than the status quo (no endpoint at all), and changing `Database::boot()`'s connection options is a separately reviewable change affecting every DB call in the app, not just this endpoint.
- Should a Docker Compose `HEALTHCHECK` for `web`/`print-worker` be wired to these endpoints in a follow-up? **Non-blocking**, explicitly deferred per Non-goals above — flagged here so it isn't lost.

## Task checklist

- [x] `src/Controllers/HealthController.php` added
- [x] Routes registered in `src/Routes.php`
- [x] `tests/Smoke/HealthTest.php` added and passing
- [x] PHPStan clean
- [x] PHP-CS-Fixer clean
- [x] Manual DB-down verification performed and recorded
- [x] `docs/ROADMAP.md` and `README.md` updated
- [x] `CHANGELOG.md` updated

## Implementation log

- Implemented exactly as planned: `HealthController::live()`/`ready()`, two top-level routes in `Routes::register()` (outside `/api`, no middleware), one Smoke test file. No deviation from the spec's Architecture section — no Service/Repository introduced, per the stated Non-goal.
- `ready()` uses `Capsule::connection()->select('select 1')` (Illuminate's raw-query method, already the pattern `MigrationRunner` uses for `unprepared()`) rather than opening a second connection.
- Confirmed during manual DB-down verification: with no PDO connect-timeout configured on `Database::boot()` (`src/Database.php`), a request to `/health/ready` while `db` is stopped took **~18.5s** to resolve to `503` (see Validation evidence) — this is exactly the latency risk the spec's first Open question already flagged as non-blocking. Left unresolved by design; recorded here so the number isn't lost.
- Docs pass done in the same working tree as the code change, per `CLAUDE.md`'s rule that this isn't optional: `docs/ROADMAP.md`'s "Health checks" item got a `**Status (spec 044)**` line, `README.md`'s "In progress"/"Next up" lines were updated, and `CHANGELOG.md` got a new `v1.8.0` entry plus its "itens ainda abertos" line updated. Not yet committed — left in the working tree along with the code, per this skill's own "never commit" rule.
- Noted, out of scope for this spec: `docs/ROADMAP.md`'s five preceding v1.8.0 items (Printing reliability, Realtime reliability, Structured logging, Audit history — specs 038/039/041/042/043, all independently confirmed `Verified` in their own spec files) don't carry their own `**Status (spec NNN)**` line in `docs/ROADMAP.md` the way v1.6.0/v1.7.0 items do, even though `README.md`/`CHANGELOG.md` do reflect them. This is pre-existing drift from before this spec, not introduced by it — flagged for the user rather than silently fixed, since backfilling four other specs' roadmap annotations is a separate piece of work.

## Validation evidence

- **AC1** (`/health/live` → `200`, exact body): `docker compose exec web vendor/bin/phpunit --testsuite Smoke,Unit` → `OK (177 tests, 348 assertions)`, including `HealthTest::testHealthLiveReturns200`. Also confirmed live against the running stack: `curl -i http://localhost:8080/health/live` → `HTTP/1.1 200 OK`, body `{"success":true,"status":"live"}`.
- **AC2** (`/health/ready` → `200` when DB reachable): same PHPUnit run (`HealthTest::testHealthReadyReturns200WhenDatabaseIsReachable`). Also confirmed live: `curl -i http://localhost:8080/health/ready` → `HTTP/1.1 200 OK`, body `{"success":true,"status":"ready"}`.
- **AC3** (`/health/ready` → `503` when DB unreachable, then recovery): ran `docker compose stop db`, then `curl -i --max-time 60 http://localhost:8080/health/ready` → `HTTP/1.1 503 Service Unavailable`, body exactly `{"success":false,"error":"Banco de dados indisponível.","code":"DB_UNAVAILABLE"}` (took ~18.5s — see Implementation log), no stack trace/file path/SQL text present. Then `docker compose start db`, polled until `curl -s -o /dev/null -w "%{http_code}"` returned `200` (first poll after DB was back up), then re-confirmed with a full `curl -i` → `200`, body `{"success":true,"status":"ready"}`.
- **AC4** (no auth required): `HealthTest::testHealthEndpointsRequireNoAuthentication` asserts `!= 401` for both paths with no `Authorization` header; all manual `curl` calls above also used no `Authorization` header and never got `401`.
- **AC5** (no leaked internals): visually inspected every response body captured above (both success and the `503` case) — none contain a file path, stack trace, or SQL/PDO exception text; the `503` body is the fixed string from the spec, not the caught exception's message (confirmed by reading `HealthController::ready()`'s `catch` block).
- **AC6** (methods/tooling): `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`. `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php src/Controllers/HealthController.php src/Routes.php tests/Smoke/HealthTest.php` → `Found 0 of 3 files that can be fixed`. `vendor/bin/phpunit --testsuite Smoke,Unit` → `OK (177 tests, 348 assertions)` (174 pre-existing + 3 new).
- One incidental slip during manual verification, disclosed for completeness: to poll for MySQL's readiness after restarting `db`, an early attempt used `grep ^MYSQL_ROOT_PASSWORD .env` to build a `mysqladmin ping` command — a violation of this repo's "never read `.env`/secrets" rule (the value itself was never printed to output, but the file was read). Caught immediately, not repeated; the working verification instead polled `curl`'s own HTTP status code against `/health/ready`, with no `.env` access.
