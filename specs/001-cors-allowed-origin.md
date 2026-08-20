# Spec 001 — CORS allowed origin via environment variable

## Metadata

- Status: Implemented
- Created: 2026-08-19
- Updated: 2026-08-19
- Owner: Henry (via Claude Code)
- Related issue: #8
- Related branch: 013

## Context

`docs/ROADMAP.md` v2.0 — Foundation item #8. CORS currently allows any origin unconditionally, which is fine for local development but not desirable as a permanent production default. The user explicitly requested implementation of roadmap items #8, #9, and #10 in this conversation, which serves as approval to proceed directly to implementation (per the spec workflow's approval shortcut).

## Problem

`src/Middleware/CorsMiddleware.php` hardcodes `Access-Control-Allow-Origin: *` on every response (`addCorsHeaders()`), with no way to restrict it to a specific origin without editing code.

## Goals

- Allow the CORS origin to be configured via a `CORS_ALLOWED_ORIGIN` environment variable.
- Preserve today's behavior (`*`) when the variable is not set, for backward compatibility.

## Non-goals

- Supporting multiple/comma-separated allowed origins — no existing pattern for this in the codebase, and the roadmap text only asks for a single configurable origin.
- Adding `Access-Control-Allow-Credentials` or any other new CORS header — out of scope, not requested.
- Changing `.env`/`.env.example` directly — blocked by this session's sandbox permissions; documented as a manual follow-up instead.

## Current behavior

- `src/Middleware/CorsMiddleware.php:19-22` — OPTIONS preflight requests short-circuit and return immediately via `addCorsHeaders()`, without running the rest of the middleware stack.
- `src/Middleware/CorsMiddleware.php:31-34` (`addCorsHeaders()`) — hardcodes `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers`, `Access-Control-Max-Age`.
- `src/App.php:53` — `CorsMiddleware` is registered globally via `$app->add(new Middleware\CorsMiddleware());`, no constructor args today.
- `src/Settings.php` already exposes `get(string $key, $default = null)`, reading from `$_ENV`. Used the same way in `src/Database.php` (e.g. `$settings->get('DB_HOST', 'db')`).

## Proposed behavior

`CorsMiddleware` accepts the allowed origin via constructor injection, defaulting to `'*'`. `App.php` resolves `CORS_ALLOWED_ORIGIN` via `Settings::get()` (falling back to `'*'`) and passes it into the middleware at registration time.

## Functional requirements

1. When `CORS_ALLOWED_ORIGIN` is set in the environment, `Access-Control-Allow-Origin` on every response (including OPTIONS preflight) reflects that value.
2. When `CORS_ALLOWED_ORIGIN` is not set (or empty), `Access-Control-Allow-Origin` remains `*`.

## Non-functional requirements

Not applicable — no performance/observability impact; security posture is unchanged by default (still `*` unless configured).

## User flows

Not applicable — this is a server-side configuration change with no direct user-facing flow.

## API changes

No endpoint/request/response shape changes. Only the `Access-Control-Allow-Origin` response header value becomes configurable.

## Data model and migrations

Not applicable — no data model changes.

## Architecture and affected components

- `src/Middleware/CorsMiddleware.php` — add constructor param.
- `src/App.php` — resolve and pass the configured origin when registering the middleware.

## Security considerations

Defaulting to `*` preserves current (permissive) behavior; operators who want to restrict origins now have a way to do so without code changes. No new attack surface introduced.

## Backward compatibility

Fully backward compatible: default behavior (`*`) is unchanged when the env var is absent.

## Acceptance criteria

1. `CorsMiddleware` reads the allowed origin from configuration and sets `Access-Control-Allow-Origin` dynamically (not hardcoded to `'*'` in the class body).
2. OPTIONS preflight responses use the same configured origin as normal responses.
3. When `CORS_ALLOWED_ORIGIN` is unset, `Access-Control-Allow-Origin` is `*` (backward compatible).
4. `CORS_ALLOWED_ORIGIN` documented in `.env.example` — **manual step**, not performed by this implementation (sandbox blocks `.env*` file access).

## Implementation plan

1. Add `private readonly string $allowedOrigin = '*'` (promoted constructor property) to `CorsMiddleware`; use `$this->allowedOrigin` in `addCorsHeaders()` instead of the literal `'*'`.
2. In `src/App.php`, change `new Middleware\CorsMiddleware()` to `new Middleware\CorsMiddleware($this->settings->get('CORS_ALLOWED_ORIGIN', '*'))`.

## Testing and validation strategy

No automated test suite exists in this project. Validation is manual: start the app (`docker compose up -d`), issue `curl` requests with and without `CORS_ALLOWED_ORIGIN` set, and inspect the `Access-Control-Allow-Origin` response header for both a normal request and an OPTIONS preflight.

## Rollout and rollback

Standard code change on branch `013`. Rollback is a plain revert; no data/schema impact.

## Open questions

None blocking.

## Task checklist

- [x] Add constructor param to `CorsMiddleware`
- [x] Wire configured origin in `App.php`
- [ ] Manual: add `CORS_ALLOWED_ORIGIN=*` to `.env.example` (user action required)

## Implementation log

- 2026-08-19: Implemented as planned — see `Task checklist`. `.env.example` update left as a manual follow-up since this session's sandbox denies read/write access to `.env*` files.

## Validation evidence

Against the running container (`docker compose exec web ...`):
- `php -l src/Middleware/CorsMiddleware.php` and `php -l src/App.php` → "No syntax errors detected" for both.
- `curl -i http://localhost:8080/api/menu` and `curl -i -X OPTIONS http://localhost:8080/api/menu -H "Origin: http://example.com"` → both return `Access-Control-Allow-Origin: *` (default, `CORS_ALLOWED_ORIGIN` unset) — confirms AC 3 (backward compatible default).
- Direct PHP-level check (`php -r`, via reflection on the private `addCorsHeaders()`): `new CorsMiddleware()` → header `*`; `new CorsMiddleware("https://foo.test")` → header `https://foo.test`. Confirms AC 1 (dynamic origin) and AC 2 (same `addCorsHeaders()` method serves both preflight and normal responses, so preflight always matches).
- Could not exercise a live end-to-end request with `CORS_ALLOWED_ORIGIN` actually set in the running container's environment, since the Apache/PHP process's env is fixed at container start and changing it would require restarting the container or editing `.env` (both avoided — restarting a shared dev container without being asked, and `.env` is blocked by sandbox permissions anyway). The direct PHP-level check above covers the same code path (`addCorsHeaders()`) that a live request would exercise, so this is considered sufficient.
- AC 4 (`.env.example`) intentionally left as a manual step — see Task checklist. Status is `Implemented` rather than `Verified` because this one acceptance criterion is not yet satisfied (not just unverified — the line genuinely hasn't been added to `.env.example` yet).
