# Spec 053 — Login throttling

## Metadata

- Status: Verified
- Created: 2026-10-02
- Updated: 2026-10-02
- Owner: Henry
- Related issue: Trello card #62 "Login throttling" (Backlog, label Security)
- Related branch: `053`

## Context

Spec 016 ("Authentication hardening", `v1.6.0`) closed every item of the roadmap's authentication checklist except one: **login throttling**. It was deferred on purpose to its own spec because it needed design decisions. `docs/ROADMAP.md` › "Authentication hardening" and `docs/architecture.md:53` both record it as a known, tracked gap.

The user asked for it on 2026-10-02 and made the open decisions:
- **Key:** count failures per **IP + username**. A higher **per-IP** limit (across usernames) also applies, for one machine trying many usernames.
- **Limit:** **10 failures within 15 minutes** blocks that key for **15 minutes**, answered with **429** and `Retry-After`.
- **Reset:** a successful login clears that IP + username's failures.
- **Storage:** there's no Redis/cache in the project, so attempts go into a new table created by a migration.

This work belongs to no milestone (the original item was in `v1.6.0`, already closed), so it's a patch like the client work (`v1.8.x`).

## Problem

`POST /api/login` (`src/Routes.php:71-74` → `AuthController::login`, `src/Controllers/AuthController.php:25-58`) accepts unlimited attempts. Nothing in `src/` or `common/` counts failed logins or locks anyone out. Since the admin API controls prices, settings and users' data, an attacker on the restaurant network can brute-force an admin password at full speed.

## Goals

- **Limit per IP + username:** after 10 failed logins within 15 minutes, further attempts get `429 TOO_MANY_LOGIN_ATTEMPTS` with `Retry-After` until the block expires (15 minutes after the failure that crossed the limit).
- **Limit per IP:** a higher limit across usernames (default 30 failures / 15 min).
- **Reset:** a successful login clears that IP + username's failures.
- **Visibility:** the admin login screen shows the server's message.

## Non-goals

- No CAPTCHA, no permanent account lockout, no admin UI to unlock (the block expires by itself).
- No trust of `X-Forwarded-For`/`X-Real-IP`. The app is served directly by Apache with no reverse proxy, so the client IP is `REMOTE_ADDR` (see Open questions).
- No change to the JWT, password hashing or the password-change endpoint (`PATCH /api/admin/account/password` already requires a token plus the current password).
- No throttling on other endpoints.
- No audit log entries for blocks (the throttle logs to `app.log` instead, see FR8).

## Current behavior

- **Login flow:** `AuthController::login()`:
  - validates the body (`AuthValidator::validateLogin`, `400 VALIDATION_FAILED` on bad input);
  - looks up the user by username and checks the password with `password_verify()` (`401 INVALID_CREDENTIALS` on failure);
  - on success, issues a JWT valid for 8 hours.
- **Route wiring:** the route builds the controller inline (`new AuthController($secret, new AuthValidator())`), outside the DI container used by other controllers.
- **Error shape:** `App\ApiResponse::error()` returns `{"success":false,"error":"…","code":"…"}`.
- **Admin screen:** the only admin login form (spec 051, `public/admin/auth.js` `GFAdmin.login`) already shows `data.error` from any failure.
- **Integration tests:** `tests/Integration/AuthenticationTest.php` covers login success and failure against real MySQL. The `request()` helper (`tests/Integration/IntegrationTestCase.php:176`) builds requests **without `REMOTE_ADDR`**. Volatile tables are cleared between tests via `VOLATILE_TABLES`.

## Proposed behavior

- **Migration `020_login_attempts.sql`:**
  - Table `login_attempts (id BIGINT PK, ip VARCHAR(45) NOT NULL, username VARCHAR(100) NOT NULL, attempted_at DATETIME NOT NULL)`.
  - Indexes `(ip, username, attempted_at)` and `(ip, attempted_at)`.
  - `CREATE TABLE IF NOT EXISTS`, so it's idempotent.
- **`App\Services\LoginThrottleService`** (new, justified: stateful policy plus DB access that doesn't belong in the controller). It takes a clock so tests can control time.
  - `check(string $ip, string $username): ?int`: `null` if allowed, otherwise seconds until unblocked.
    - Blocked if failures for (ip, username) in the last 15 min ≥ 10, or failures for ip in the last 15 min ≥ 30.
    - Unblocked at the most recent counted failure + 15 min (`Retry-After`, rounded up, minimum 1).
  - `recordFailure(string $ip, string $username): void`, which also deletes rows older than the window (keeps the table small, no cron needed).
  - `clear(string $ip, string $username): void`.
  - Limits and window are class constants (10 / 30 / 900 s).
- **`AuthController::login()`**, in order:
  1. Validate the body (400, as today).
  2. `check()`. If blocked → `429 TOO_MANY_LOGIN_ATTEMPTS`, message `"Muitas tentativas de login. Tente novamente em N minuto(s)."`, header `Retry-After: <s>`. The password is **not** checked and the attempt is **not** recorded, so a blocked client can't extend its own block.
  3. Wrong user or password → `recordFailure()` → 401, as today.
  4. Success → `clear()` → token, as today.
- **Inputs:**
  - Client IP is `getServerParams()['REMOTE_ADDR']`, falling back to `'unknown'` when absent (PHPUnit requests).
  - Username is the submitted string, trimmed and lowercased, so `Admin` and `admin` share a counter. Lookup in `users` is unchanged.
- **Logging:** a blocked attempt is logged at `warning` with `event: auth.login_throttled`, `ip` and `username` (no password), via the existing Monolog logger and the request correlation id from spec 042.

## Functional requirements

1. FR1 — Ten failed `POST /api/login` for the same IP and username within 15 min → the 11th request returns `429`, `code: TOO_MANY_LOGIN_ATTEMPTS`, with an integer `Retry-After` header, even when the password is correct.
2. FR2 — While blocked, attempts are not recorded. The block ends 15 min after the failure that reached the limit, and then that IP + username can log in.
3. FR3 — The same IP with 30 failed logins across different usernames within 15 min is blocked for any username.
4. FR4 — Failures for user A from IP X don't affect user A from IP Y, or user B from IP X while under the per-IP limit.
5. FR5 — A successful login clears that IP + username's failures. Earlier failures don't carry over toward the next block.
6. FR6 — Validation errors (`400`) don't count as failures. Unknown usernames and wrong passwords do (both are `401`).
7. FR7 — Rows older than the 15-minute window are deleted whenever a failure is recorded.
8. FR8 — Every `429` writes one `warning` log line with `event: auth.login_throttled`, `ip`, `username` and no password.
9. FR9 — The admin login form shows the 429 message (no frontend change expected: it already shows `data.error`).

## Non-functional requirements

- **Cost:** a few small indexed queries per login: up to two counts (key, IP) and two `MAX(attempted_at)` lookups when a limit is reached, plus an insert and a prune on failure, or one delete on success. Negligible. *(Corrected 2026-10-02 after `/spec-review`: the earlier wording said "at most 3".)*
- **Concurrency:** check-then-record is not atomic. Concurrent requests can each pass `check()` and push a key slightly past 10 failures before the block applies. That's acceptable for brute-force limiting (an attacker gains at most a few extra tries per burst). Recorded after `/spec-review`.
- **Timing:** the server never sleeps or delays a response.
- **Clock:** times use the app's clock (PHP timezone `America/Sao_Paulo`, `docker/php-timezone.ini`), consistently on write and read.

## User flows

- **Admin mistypes the password 3 times:** 401 each time, then logs in normally and the counter clears.
- **Brute force from one PC:** after 10 wrong passwords for `admin` the screen shows "Muitas tentativas de login. Tente novamente em 15 minutos." For 15 minutes, even the right password gets 429. From another PC, `admin` can still log in.

## API changes

`POST /api/login` gains a response: `429` with `{"success":false,"error":"Muitas tentativas de login. Tente novamente em N minuto(s).","code":"TOO_MANY_LOGIN_ATTEMPTS"}` and a `Retry-After` header. Existing 200/400/401 are unchanged. The OpenAPI file (`public/api/docs/openapi.yaml`) documents the new response.

## Data model and migrations

`common/migrations/020_login_attempts.sql` creates `login_attempts` with the columns and indexes above. No change to existing tables. No model is required (query builder through `Illuminate\Database\Capsule\Manager`, as `MigrationRunner` and other plain-table code already do).

## Architecture and affected components

- **New:**
  - `common/migrations/020_login_attempts.sql`
  - `src/Services/LoginThrottleService.php`
  - `tests/Unit/LoginThrottleServiceTest.php` (in-memory SQLite, controlled clock)
- **Changed:**
  - `src/Controllers/AuthController.php`: takes the throttle service and a logger.
  - `src/Routes.php`: the `/api/login` closure builds those dependencies (or resolves them from the container).
  - `tests/Integration/AuthenticationTest.php`: 429 end to end.
  - `tests/Integration/IntegrationTestCase.php`: `login_attempts` added to `VOLATILE_TABLES`, so tests don't block each other.
  - `public/api/docs/openapi.yaml`
- **Docs (same PR):** `docs/architecture.md` (Authentication › Login throttling), `docs/ROADMAP.md` (Authentication hardening status), `docs/technical-decisions.md`, `CHANGELOG.md` ("Não lançado").
- No new Controller, Repository or Validator. `RoleMiddleware`/`JwtMiddleware` are untouched.

## Security considerations

- **Gap closed:** this closes the brute-force gap left open by spec 016.
- **Source of the IP:** `REMOTE_ADDR` is set by the server, not the client, so it can't be spoofed through headers. Proxy headers are ignored on purpose.
- **Lockout of the real admin:** the IP + username key means an attacker can only lock out an admin on **their own** IP. The per-IP limit (30) stops one machine spraying usernames.
- **What's stored:** no password or hash, only IP, normalized username and time. Rows expire after 15 minutes.
- **No user enumeration:** the 429 is answered the same way for existing and unknown usernames.

## Backward compatibility

- Normal logins behave the same. Clients that ignore 429 just see a failed login with a clear message.
- The migration is additive. Old code with the new table present keeps working (rollback-safe).
- `bin/migrate` must run before the new code, or every login fails with a SQL error (same pattern as spec 050). This goes in the changelog.

## Acceptance criteria

1. AC1 — Unit test: 10 `recordFailure('1.1.1.1','admin')` within the window → `check()` returns a value in `(0, 900]`. With 9 failures it returns `null`.
2. AC2 — Unit test (controlled clock): blocked at t; at t + 15 min + 1 s `check()` returns `null`. Rows older than the window are gone after the next `recordFailure()`.
3. AC3 — Unit test: 30 failures from one IP across 30 usernames → `check()` blocks a 31st username from that IP. A different IP is not blocked. 10 failures for `admin` on IP X don't block `admin` on IP Y.
4. AC4 — Unit test: `clear()` after 9 failures → one more failure doesn't block. Usernames are normalized (`Admin` and `admin` share a counter).
5. AC5 — Integration test (real MySQL, `/api/login` through the Slim app): 10 wrong passwords → 401 each; the 11th with the **correct** password → `429`, `code` `TOO_MANY_LOGIN_ATTEMPTS`, `Retry-After` > 0. A user with no failures logs in normally (200). A 400 (missing password) doesn't count.
6. AC6 — Manual: 11 wrong-password `curl` calls against the 8081 test instance → the last answers 429 with `Retry-After`. The `app.log` line `auth.login_throttled` is present. The admin login screen at 8081 shows the message.
7. AC7 — `bin/migrate` applies `020` on the dev database. Re-running is a no-op.
8. AC8 — PHPUnit (Unit + Integration), PHPStan and PHP-CS-Fixer pass.

## Implementation plan

1. Migration 020.
2. `LoginThrottleService` + unit tests (AC1–AC4).
3. `AuthController` + route wiring + log line.
4. Integration test (AC5) + `VOLATILE_TABLES`.
5. OpenAPI 429 response.
6. Docs + `CHANGELOG.md` in the same branch (single PR rule).
7. Checks, migration and manual verification on 8081 (AC6–AC8).

## Testing and validation strategy

- **Automated:** PHPUnit exists (specs 004/035). AC1–AC4 are unit tests on in-memory SQLite with an injected clock (no sleeping). AC5 is an integration test against `restaurant_test`; its `request()` helper has no `REMOTE_ADDR`, so the IP falls back to `'unknown'`, and `login_attempts` is cleared between tests.
- **Manual:** AC6 runs on the 8081 test instance only (never the dev database), with a throwaway user removed afterwards and the throttle rows left to expire. AC7 runs on the dev database: an additive migration, no data change.

## Rollout and rollback

- **Deploy:** run `bin/migrate` **before** the code (otherwise every login fails with a SQL error).
- **Rollback:** revert the code. The extra table is harmless to old code; no drop needed.

## Open questions

- Non-blocking: **Docker Desktop on Windows/Mac** NATs host-forwarded traffic, so `REMOTE_ADDR` can be the same gateway IP for every client. Then the per-IP limit (30) becomes effectively global, and IP + username degrades to per-username. On Linux Docker with published ports (iptables DNAT) the real client IP is kept. To be checked during implementation (the value seen on 8080/8081) and documented. If it matters for the client's deployment, a follow-up could trust a proxy header behind an explicit setting.
- Non-blocking: the per-IP limit (30) is a default the user didn't set explicitly (they chose "higher than per-user"). Adjustable later through the constant.

## Task checklist

- [x] Migration 020
- [x] `LoginThrottleService` + unit tests (AC1–AC4)
- [x] `AuthController` + route + log line
- [x] Integration test (AC5) + `VOLATILE_TABLES`
- [x] OpenAPI 429
- [x] Docs + CHANGELOG (same PR)
- [x] Checks + migrate + manual verification (AC6–AC8)

## Implementation log

- 2026-10-02 — Approved by `/spec-implement` invocation (Draft, no blocking open questions) → In Progress.
- 2026-10-02 — Window counting uses `attempted_at > now − 900`, strictly. Because blocked attempts are never recorded, the most recent recorded failure *is* the one that reached the limit, so `Retry-After = latest + 900 − now` ends the block exactly 15 minutes after it, and at that instant every counted failure has left the window. Consistent by construction.
- 2026-10-02 — `AuthController` takes the throttle and logger as optional constructor parameters (promoted, `?LoginThrottleService = null`, `?LoggerInterface = null`). Only `/api/login` wires them in `src/Routes.php`; `PATCH /api/admin/account/password` builds the controller without them, unchanged (it needs a JWT plus the current password, and throttling it is out of scope).
- 2026-10-02 — Unit-test helper first named `fail()` clashed with PHPUnit's final `Assert::fail()`; renamed `failLogins()`.
- 2026-10-02 — **Docker Desktop caveat confirmed** (Open question 1): every request from the host to 8081 arrived as `REMOTE_ADDR = 172.18.0.1`, the Docker bridge gateway. Under Docker Desktop, per-IP keys collapse to one IP: the per-IP cap (30) becomes global and IP + username behaves as per-username. Documented in `CHANGELOG.md`, `docs/architecture.md` and `docs/technical-decisions.md`. No code change: trusting a proxy header would let any client spoof its IP, and Linux Docker keeps real client IPs.
- 2026-10-02 — Changelog and docs pass written on this same branch (one-PR-per-spec rule, `CLAUDE.md`): `CHANGELOG.md` "Não lançado (próximo patch: `v1.8.3`)", `docs/architecture.md` (Login throttling bullet corrected with a dated note), `docs/ROADMAP.md` (Authentication hardening status + after-tag line in `v1.8.2`), `docs/technical-decisions.md` (new row). `README.md` and `specs/000-project-baseline.md` were checked; they don't mention throttling, so nothing became false.

## Validation evidence

All run on 2026-10-02.

- **AC1–AC4** — `vendor/bin/phpunit --filter LoginThrottleServiceTest --testdox` → `OK (4 tests, 15 assertions)`:
  - `✔ Ten failures block and nine do not` (AC1)
  - `✔ Block expires after the window and old rows are pruned` (AC2)
  - `✔ Per ip limit across usernames and keys are isolated` (AC3)
  - `✔ Clear resets and usernames are normalized` (AC4)
- **AC5** — `MYSQL_DATABASE_TEST=restaurant_test vendor/bin/phpunit --filter AuthenticationTest --testdox` → `OK (9 tests, 29 assertions)`, including `✔ Login is throttled after ten failures`: one 400 not counted, 10 × 401, then the correct password gets 429 + `TOO_MANY_LOGIN_ATTEMPTS` + `Retry-After` > 0, and another user from the same IP gets 200. The existing login and role tests still pass.
- **AC6** — 8081 (`restaurant_test`), throwaway admin with a random password:
  - 10 wrong-password `curl` calls → `401` ×10.
  - 11th with the **correct** password → `HTTP/1.1 429 Too Many Requests`, `Retry-After: 898`, body `{"success":false,"error":"Muitas tentativas de login. Tente novamente em 15 minutos.","code":"TOO_MANY_LOGIN_ATTEMPTS"}`.
  - `app.log` in `restaurant_web_e2e`: `app.WARNING: Login bloqueado por excesso de tentativas {"event":"auth.login_throttled","ip":"172.18.0.1","username":"s053_…"} {"request_id":"52bd…"}`.
  - Admin login screen (Playwright): the login answers `429`, and the alert reads "Muitas tentativas de login. Tente novamente em 15 minutos."
  - Cleanup: user and its `login_attempts` rows deleted (leftover 0 / 0); `restaurant_web_e2e` removed.
- **AC7** — dev database: `bin/migrate` → `▶ Executando 020_login_attempts.sql ... [OK]`; a second run → `✔ Nenhuma migração pendente.`
- **AC8**
  - `vendor/bin/phpunit` → `Tests: 250, Assertions: 395, Skipped: 53`.
  - Integration suite → `Tests: 53, Assertions: 208, Skipped: 1`.
  - `vendor/bin/phpstan analyse` → `[OK] No errors`.
  - `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 109 files that can be fixed`.
  - `public/api/docs/openapi.yaml` parses (`yaml.safe_load`).
