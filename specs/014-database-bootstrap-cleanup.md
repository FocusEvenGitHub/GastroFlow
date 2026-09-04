# Spec 014 — Database bootstrap cleanup

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Database bootstrap cleanup" subsection, requires removing globally known default database credentials from schema/bootstrap logic — database users and passwords should come from environment/deployment configuration, and schema migrations should only manage application database structures and data required by GastroFlow itself.

Investigation found two distinct, concrete violations — one in the schema file, one in **live application code** (not dead code, unlike prior findings in this milestone):

1. `common/sql/001_schema.sql:8-10` creates an actual MySQL user with a hardcoded password, inside the application's own schema file.
2. `src/Database.php:18-19` — the real, active `Database::boot()` used by every entry point (`App::get()`, `bin/migrate`, `bin/worker`, `public/api/events/stream.php`) — falls back to hardcoded `'restuser'`/`'restpass'` when `MYSQL_USER`/`MYSQL_PASSWORD` are unset. This is the exact same vulnerable pattern spec 002 already fixed for `JWT_SECRET` (`$_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me'` → throw if unset) — just not yet applied here.

## Problem

Confirmed by direct reads:

1. `common/sql/001_schema.sql:6-10`:
   ```sql
   -- Garantir acesso do usuário da aplicação vindo de qualquer host
   CREATE USER IF NOT EXISTS 'restuser'@'%' IDENTIFIED BY 'restpass';
   GRANT ALL PRIVILEGES ON restaurant.* TO 'restuser'@'%';
   FLUSH PRIVILEGES;
   ```
   This runs against **every** fresh installation, regardless of what `MYSQL_USER`/`MYSQL_PASSWORD` the deployer configures in their own `.env`. It is also **entirely redundant**: the official `mysql:8.0` Docker image already creates a user matching `MYSQL_USER`/`MYSQL_PASSWORD` with full privileges on `MYSQL_DATABASE`, automatically, via its own entrypoint script, *before* any `docker-entrypoint-initdb.d/*.sql` file (including this one) runs — confirmed by `docker-compose.yml`'s `db` service already setting `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` as container environment variables. The practical effect of this schema block is: **every GastroFlow database, everywhere, running with any credentials, additionally gets a `restuser`@`%` account with the publicly-known password `restpass` and full privileges on the `restaurant` database** — a real, exploitable backdoor baked into version control, independent of the deployer's actual configuration.
2. `src/Database.php:16-19`:
   ```php
   'host'      => $settings->get('DB_HOST', 'db'),
   'database'  => $settings->get('MYSQL_DATABASE', 'restaurant'),
   'username'  => $settings->get('MYSQL_USER', 'restuser'),
   'password'  => $settings->get('MYSQL_PASSWORD', 'restpass'),
   ```
   If `MYSQL_USER`/`MYSQL_PASSWORD` are ever unset in a real deployment's environment, the app silently attempts to authenticate as `restuser`/`restpass` instead of failing loudly — the same class of bug `specs/002-jwt-secret-required.md` already fixed for `JWT_SECRET`, left unaddressed here.
3. `common/config.php:5-6` has the identical hardcoded fallback pattern (`getenv('MYSQL_USER') ?: 'restuser'`, `getenv('MYSQL_PASSWORD') ?: 'restpass'`), but this file is confirmed dead code (no callers anywhere in `src/`/`public/`, per `specs/000-project-baseline.md`) — its fate (delete vs. keep) is an existing open question in that baseline, not something this spec should decide unilaterally.
4. `.github/workflows/ci.yml` also uses the literal string `restpass` (lines 19, 32) — but only as an explicit, real environment variable for its own ephemeral CI-only MySQL service container, not as a code-level fallback default. This is a normal, harmless CI convention (the same pattern `specs/004`/`005` already established), not a "globally known default" reachable by any real deployment.

## Goals

- No file that runs against a real database (`common/sql/001_schema.sql`, `common/migrations/*.sql`) creates a database user or grants privileges with a hardcoded password — that responsibility belongs entirely to the deployment configuration (the `mysql:8.0` image's own `MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_DATABASE` env-var-driven bootstrap, already in place).
- `src/Database.php` (live code) never silently falls back to a hardcoded database username or password — an unset `MYSQL_USER`/`MYSQL_PASSWORD` fails loudly with a clear error, matching the established `JWT_SECRET` pattern.
- A fresh `docker compose up -d` installation continues to work identically to today, with the same effective database user/privileges (now created exactly once, by the official image's own bootstrap, instead of twice).

## Non-goals

- **Not deleting `common/config.php`/`common/db.php`.** Confirmed dead code, but their removal is an existing open question in `specs/000-project-baseline.md`, not decided here. This spec only addresses `src/Database.php` (the live path) and `common/sql/001_schema.sql` (the file that actually runs against a real database).
- **Not changing `DB_HOST`'s (`'db'`) or `MYSQL_DATABASE`'s (`'restaurant'`) default fallbacks in `src/Database.php`.** The roadmap specifically calls out "database users and passwords" — a hostname and a database name are not credentials, and defaulting them is a normal, low-risk development convenience (matches `docker-compose.yml`'s own service name `db`).
- **Not changing `.github/workflows/ci.yml`.** Its use of `restpass` is for a real, explicitly-configured, ephemeral CI database — not a code-level fallback reachable in any real deployment. Confirmed CI already sets `MYSQL_USER`/`MYSQL_PASSWORD` as real environment variables (job-level `env:`), so removing `src/Database.php`'s hardcoded fallback does not affect CI.
- **Not touching `bin/create-admin`-style administrator bootstrap** (default `admin`/`admin123` seed user in `common/sql/001_schema.sql:148-150`) — that is `docs/ROADMAP.md`'s separate "Administrator bootstrap" subsection, a distinct future spec.
- **Not adding a new migration to alter an already-applied schema.** `common/sql/001_schema.sql` only runs on a genuinely fresh database volume (`docker-entrypoint-initdb.d` semantics) — an existing, already-initialized database (like this project's own dev database) is entirely unaffected by this change, since the file won't re-run against it. No migration is needed to "undo" the extra `restuser`/`restpass` account on existing databases (see Backward compatibility).

## Current behavior

Confirmed by direct reads on the current working tree:

- `common/sql/001_schema.sql:1-10` — creates the `restaurant` database, then unconditionally creates `'restuser'@'%' IDENTIFIED BY 'restpass'` and grants it all privileges, before any table definitions.
- `docker-compose.yml`'s `db` service already sets `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` as container environment variables, interpolated from the real `.env` (`${VAR}` syntax) — the official `mysql:8.0` image's entrypoint uses these to create the database and a matching non-root user automatically, before mounted `docker-entrypoint-initdb.d/*.sql` files run.
- `src/Database.php::boot()` — the only place `Illuminate\Database\Capsule\Manager` is configured; called from `App::get()`, `bin/migrate`, `bin/worker`, `public/api/events/stream.php`. Falls back to `'restuser'`/`'restpass'` for `MYSQL_USER`/`MYSQL_PASSWORD` when unset.
- `src/Settings.php` — has no "required, throw if unset" accessor today; `Settings::get($key, $default)` always returns a value or `null`, never throws. `src/Routes.php:22` implements the required-value pattern inline (`$_ENV['JWT_SECRET'] ?? throw new \RuntimeException(...)`) rather than through `Settings`.
- `.github/workflows/ci.yml` — sets `MYSQL_ROOT_PASSWORD: root`, `MYSQL_DATABASE: restaurant`, `MYSQL_USER: restuser`, `MYSQL_PASSWORD: restpass` as the CI MySQL service container's own env (lines 15-19) *and* as job-level `env:` (lines 28-33) *and* writes them into a `.env` file for `bin/migrate` (lines 56-64) — real, explicit values at every step, never relying on any PHP-level default.
- No automated test currently exercises `Database::boot()`'s credential-resolution logic directly (confirmed: no `tests/Unit/DatabaseTest.php` or similar exists).

## Proposed behavior

After this change:

- `common/sql/001_schema.sql` no longer contains any `CREATE USER`/`GRANT`/`FLUSH PRIVILEGES` statement. A fresh `docker compose up -d` still ends up with exactly one application database user (`MYSQL_USER`/`MYSQL_PASSWORD`, whatever the deployer configured), created solely by the official MySQL image's own bootstrap — no extra `restuser`/`restpass` backdoor account.
- `App\Settings` gains a `getRequired(string $key): string` method that returns the env value or throws `\RuntimeException("$key environment variable is not set.")`, matching `src/Routes.php:22`'s existing message convention for `JWT_SECRET`.
- `src/Database.php::boot()` uses `$settings->getRequired('MYSQL_USER')` and `$settings->getRequired('MYSQL_PASSWORD')` instead of hardcoded-fallback `get()` calls. `DB_HOST`/`MYSQL_DATABASE` keep their existing non-secret defaults, unchanged.
- Any deployment missing `MYSQL_USER`/`MYSQL_PASSWORD` in its environment now fails at `Database::boot()` with a clear `RuntimeException` message, instead of silently attempting a well-known default credential pair.

## Functional requirements

1. `common/sql/001_schema.sql` contains no `CREATE USER`, `GRANT`, or `FLUSH PRIVILEGES` statement.
2. `App\Settings::getRequired('SOME_KEY')` returns the value of `$_ENV['SOME_KEY']` when set and non-empty.
3. `App\Settings::getRequired('SOME_KEY')` throws `\RuntimeException` with message `"SOME_KEY environment variable is not set."` when `$_ENV['SOME_KEY']` is unset or empty.
4. `src/Database.php::boot()` calls `$settings->getRequired('MYSQL_USER')` and `$settings->getRequired('MYSQL_PASSWORD')` — no hardcoded `'restuser'`/`'restpass'` string appears anywhere in the file.
5. `src/Database.php::boot()`'s `DB_HOST`/`MYSQL_DATABASE` resolution is unchanged (`get('DB_HOST', 'db')`, `get('MYSQL_DATABASE', 'restaurant')`).
6. With the real `.env`'s current, correct `MYSQL_USER`/`MYSQL_PASSWORD` values (unchanged), the app connects to the database exactly as before — no behavior change for a correctly configured environment.
7. `docker compose exec web vendor/bin/phpunit` passes with the same count as before this spec (no regression).

## Non-functional requirements

Not applicable beyond the security goal already stated — no performance impact.

## User flows

Not applicable — no user-facing behavior change.

## API changes

Not applicable.

## Data model and migrations

Not applicable — `common/sql/001_schema.sql` is edited directly (per `CLAUDE.md`'s convention that this file, unlike `common/migrations/*.sql`, is the one-time initial schema mounted only on a genuinely fresh database volume — it is not itself a tracked, already-applied migration, so editing it does not violate the "never change the DB schema outside a migration file" rule, since no existing database re-executes it). No new migration is added, since there is no new table/column — this removes bootstrap logic, not schema.

## Architecture and affected components

- `common/sql/001_schema.sql` — remove the `CREATE USER`/`GRANT`/`FLUSH PRIVILEGES` block (3 lines + 1 comment).
- `src/Settings.php` — add `getRequired(string $key): string`.
- `src/Database.php` — use `getRequired()` for `MYSQL_USER`/`MYSQL_PASSWORD`.
- No `Controllers/`, `Services/`, `Repositories/`, `Models/`, or frontend changes.

## Security considerations

This spec's entire purpose is removing a real, exploitable hardcoded-credential backdoor from the schema file, and closing the same silent-fallback vulnerability class already fixed for `JWT_SECRET` (spec 002) for database credentials too. Both fixes are subtractive (remove a redundant `CREATE USER`, remove a fallback default) rather than additive, minimizing the chance of introducing a new issue. This spec does not attempt to revoke the `restuser`/`restpass` account from any *already-initialized* database (including this project's own dev database) — see Backward compatibility.

## Backward compatibility

- **Existing, already-initialized databases** (including this project's own local dev database) are **not** affected by the `common/sql/001_schema.sql` change — that file only runs via `docker-entrypoint-initdb.d` on a genuinely empty MySQL data volume, confirmed by `docker-compose.yml`'s mount (`./common/sql/001_schema.sql:/docker-entrypoint-initdb.d/01-schema.sql`). Any database initialized before this change keeps whatever `restuser`@`%`/`restpass` account was already created — this spec does not retroactively revoke it. A follow-up manual step (documented in Open questions) is available for anyone who wants to remove that account from an already-running database, but it's not part of this spec's automated changes.
- **`src/Database.php`'s new `getRequired()` calls** only change behavior for a deployment that has never had `MYSQL_USER`/`MYSQL_PASSWORD` set — such a deployment could never have successfully connected to a real, correctly-configured database anyway (the old hardcoded fallback would only "work" against a database that happened to still have the `restuser`/`restpass` backdoor account, i.e., the exact vulnerability this spec closes). This project's own `.env` already has both values set (confirmed working per spec 013's validation), so no impact here.

## Acceptance criteria

1. `grep -n "CREATE USER\|GRANT \|FLUSH PRIVILEGES" common/sql/001_schema.sql` returns no matches.
2. `grep -n "restuser\|restpass" src/Database.php` returns no matches.
3. A real PHP snippet calling `(new App\Settings())->getRequired('DEFINITELY_NOT_SET_XYZ')` throws `RuntimeException` with message `"DEFINITELY_NOT_SET_XYZ environment variable is not set."`.
4. The same snippet with an env var that **is** set (e.g. `getRequired('MYSQL_USER')` against the real `.env`) returns its value without throwing.
5. `docker compose exec web vendor/bin/phpunit` passes with the same test count as before this spec.
6. `curl http://localhost:8080/api/menu` (real running stack, unchanged `.env`) still returns `200` with menu data after this change — confirms `Database::boot()` still connects correctly with the existing, correctly-configured credentials.
7. `php -l` passes on both changed PHP files.

## Implementation plan

1. Remove the `CREATE USER`/`GRANT`/`FLUSH PRIVILEGES` block (and its preceding comment) from `common/sql/001_schema.sql`.
2. Add `Settings::getRequired(string $key): string`.
3. Update `src/Database.php::boot()` to use `getRequired()` for `MYSQL_USER`/`MYSQL_PASSWORD`.
4. Verify via a real PHP snippet that `getRequired()` throws correctly for an unset key and returns correctly for a set one (Acceptance criteria 3-4).
5. Run `vendor/bin/phpunit` and a real `curl` against the running app to confirm no regression (Acceptance criteria 5-6) — this project's dev database is already initialized, so the schema-file change cannot be validated by re-running `docker compose up -d` against it (see Testing and validation strategy for how the schema change itself is validated instead).
6. `php -l` on both changed files; read the diff to confirm scope.

## Testing and validation strategy

No automated test infrastructure covers SQL schema files or `Database::boot()`'s credential resolution directly. Validation is:
- The `common/sql/001_schema.sql` change is validated by direct inspection (Acceptance criterion 1) plus reasoning already confirmed in Current behavior (the official `mysql:8.0` image's own bootstrap already creates the equivalent, correctly-configured user/grant — confirmed by reading `docker-compose.yml`'s `db` service `environment:` block). It is **not** validated by initializing a fresh database volume in this session, since doing so would require tearing down this project's own populated `db_data` volume (a destructive operation on real dev data, out of scope and unnecessary — see Open questions for an optional, explicitly-opt-in way to verify this later without risk).
- `src/Database.php`/`Settings::getRequired()` changes are validated with real PHP execution against the live container (Acceptance criteria 3, 4, 6) and the existing PHPUnit suite (Acceptance criterion 5).

## Rollout and rollback

No migration, no dependency, no container change. Rollback is a plain `git revert`. The schema-file change only affects databases initialized *after* this change ships — no impact on any already-running database either way.

## Open questions

- **Not blocking**: this project's own local dev database was very likely initialized while `common/sql/001_schema.sql` still had the `CREATE USER 'restuser'@'%' IDENTIFIED BY 'restpass'` block, so it likely still has that account. Removing it from the live database (e.g., `DROP USER 'restuser'@'%'` — note this is the *same* username as the legitimate, intentionally-configured application user, so this would need careful verification that it doesn't refer to the same account currently in active use, or a `SHOW GRANTS`/`SELECT user,host FROM mysql.user` check first) is a manual, explicit, destructive database operation this spec does not perform automatically, per `CLAUDE.md`'s "never run destructive database operations" rule. Left for the user to decide and execute if desired.

## Task checklist

- [x] `common/sql/001_schema.sql`'s `CREATE USER`/`GRANT`/`FLUSH PRIVILEGES` block removed
- [x] `Settings::getRequired()` added
- [x] `src/Database.php` uses `getRequired()` for `MYSQL_USER`/`MYSQL_PASSWORD`
- [x] Acceptance criteria 3-4 verified via real PHP execution
- [x] `vendor/bin/phpunit` + real `curl` verify no regression
- [x] `php -l` run, diff reviewed for scope

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Re-verified `common/sql/001_schema.sql` lines 1-14 and `src/Database.php`/`src/Settings.php` immediately before editing — unchanged since `/spec-plan`, no conflict to report.
- 2026-09-03 — Implemented exactly per plan: removed the comment + 3-line `CREATE USER`/`GRANT`/`FLUSH PRIVILEGES` block from `common/sql/001_schema.sql`; added `Settings::getRequired()`; switched `Database::boot()`'s `MYSQL_USER`/`MYSQL_PASSWORD` resolution to it. No deviations.
- 2026-09-03 — Did not attempt to validate the schema-file change by initializing a fresh database volume, per the spec's own Testing and validation strategy (would require tearing down this project's populated `db_data` volume — destructive, unnecessary, and explicitly out of scope). Validated instead by direct inspection plus the already-confirmed reasoning about the official `mysql:8.0` image's own env-driven bootstrap (from `/spec-plan`'s investigation of `docker-compose.yml`).

## Validation evidence

- Acceptance criterion 1 — `grep -n "CREATE USER\|GRANT \|FLUSH PRIVILEGES" common/sql/001_schema.sql` → no matches. **Confirmed.**
- Acceptance criterion 2 — `grep -n "restuser\|restpass" src/Database.php` → no matches. **Confirmed.**
- Acceptance criterion 3 — Real PHP execution in the running container: `(new App\Settings())->getRequired('DEFINITELY_NOT_SET_XYZ')` → threw `RuntimeException`, message `"DEFINITELY_NOT_SET_XYZ environment variable is not set."` — exact match to spec. **Confirmed.**
- Acceptance criterion 4 — Same pattern with `getRequired('MYSQL_USER')` against the real `.env` → returned `"restuser"` without throwing. **Confirmed.**
- Acceptance criterion 5 — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)` — same count as before this spec. **Confirmed.**
- Acceptance criterion 6 — `curl http://localhost:8080/api/menu` → `HTTP 200`, `15742` bytes — identical to the pre-change response size, confirming `Database::boot()` connects correctly with the existing credentials via `getRequired()`. **Confirmed.**
- Acceptance criterion 7 — `docker compose exec web php -l src/Settings.php` and `php -l src/Database.php` → `No syntax errors detected`, both files. **Confirmed.**
- `git diff -- common/sql/001_schema.sql src/Settings.php src/Database.php` reviewed — scope confirmed minimal and exact (schema file: 5 lines removed; `Database.php`: 2 lines changed; `Settings.php`: one new method added, alongside spec 011's pre-existing, unrelated additions in the same file).
