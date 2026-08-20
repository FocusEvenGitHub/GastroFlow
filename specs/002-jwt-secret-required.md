# Spec 002 — JWT secret must be explicitly configured

## Metadata

- Status: Approved
- Created: 2026-08-19
- Updated: 2026-08-19
- Owner: Henry (via Claude Code)
- Related issue: #9
- Related branch: 013

## Context

`docs/ROADMAP.md` v2.0 — Foundation item #9. `CLAUDE.md` explicitly flags `src/Routes.php:19` (now line 22) as a known hardcoded JWT-secret fallback and says not to "silently fix" it outside an explicit spec/request. The user explicitly requested implementation of roadmap items #8, #9, #10 in this conversation — this spec documents that explicit request per the reduced-spec allowance for small, obvious fixes ("must still document the problem, expected result, and validation").

## Problem

`src/Routes.php:22` — `$secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me';`. If `JWT_SECRET` is ever unset in an environment (e.g. a misconfigured deployment), the app silently signs and verifies JWTs with a publicly-known secret string instead of failing loudly — a security risk.

## Expected result

If `JWT_SECRET` is not set in the environment, the app throws a `\RuntimeException` at route-registration time instead of falling back to a hardcoded secret. `src/App.php`'s existing global error middleware already catches any `\Throwable` and returns a JSON 500, so this fails safely rather than crashing ungracefully.

Both consumers of the secret (`JwtMiddleware`, used to verify tokens on `/api/admin`; `AuthController`, used to sign tokens on `/api/login`) take `string $secret` purely via constructor injection and read it from nowhere else — so this is the only code location that needs to change.

## Change

```php
// before
$secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me';

// after
$secret = $_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET environment variable is not set.');
```

`\RuntimeException` matches the existing convention for this class of misconfiguration error elsewhere in the codebase (`src/Services/PrintService.php:69`, `src/Jobs/PrintOrderJob.php:26`).

`.env` and `.env.example` already define `JWT_SECRET` — no env-file change needed.

## Validation

No automated test suite exists in this project. The live app was not tested with `JWT_SECRET` actually unset in `.env`, since editing `.env` is blocked by this session's sandbox and temporarily breaking the running app is undesirable. Instead, the exact expression used in `Routes.php` was exercised in isolation inside the running container (`docker compose exec web php -r ...`), simulating both the unset and set cases — see Validation evidence.

## Task checklist

- [x] Replace the hardcoded fallback in `src/Routes.php` with a thrown `\RuntimeException`

## Implementation log

- 2026-08-19: One-line change in `src/Routes.php`. No other files touched — confirmed both `JwtMiddleware` and `AuthController` receive the secret only via constructor injection.

## Validation evidence

- `docker compose exec web php -l src/Routes.php` → "No syntax errors detected."
- `docker compose exec web php -r '...'` running the exact `$_ENV["JWT_SECRET"] ?? throw new \RuntimeException(...)` expression:
  - With `$_ENV["JWT_SECRET"]` unset: `OK threw RuntimeException: JWT_SECRET environment variable is not set.`
  - With `$_ENV["JWT_SECRET"] = "abc123"`: `OK with set var: abc123`
- Live end-to-end request against the running app (real `.env` has `JWT_SECRET` set): `curl http://localhost:8080/api/menu` → `200 OK`, confirming the changed line doesn't break the normal (secret-present) path.
- The genuinely-missing-env-var path through the full `App::get()` bootstrap and Slim's error middleware was not exercised live (would require unsetting `JWT_SECRET` in the real `.env`, which is blocked). The isolated expression test above exercises the identical code, and `App.php`'s global error handler (unconditionally catches `\Throwable` → JSON 500) was confirmed by reading `src/App.php:53-93` during planning, not re-tested here since it's pre-existing, unmodified infrastructure.
