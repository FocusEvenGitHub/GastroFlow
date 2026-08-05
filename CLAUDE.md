# CLAUDE.md — GastroFlow

Persistent instructions for working in this repository. Keep this file short; details belong in `specs/` or the code itself.

## Stack (confirmed)

PHP >=8.1 (Docker runtime: `php:8.2-apache`), Slim 4 + `php-di/slim-bridge`, Eloquent ORM (`illuminate/database ^10`, via `Illuminate\Database\Capsule\Manager`), `vlucas/valitron` for validation, `firebase/php-jwt`, `monolog/monolog`, `mike42/escpos-php` (ESC/POS thermal printing), MySQL 8.0, Docker Compose. Frontend: static PHP/HTML pages under `public/` using Alpine.js + Bootstrap 5, no build step.

## Architecture (confirmed)

- Bootstrap: `public/index.php` → `App\App::get()` (`src/App.php`) → `App\Routes::register()` (`src/Routes.php`). Routes are registered directly with Slim's fluent API, not from a config file.
- `public/.htaccess` serves any existing file directly; only non-existent paths fall through to the Slim front controller. This means `public/cashier/`, `public/kitchen/`, and `public/admin/*.php` are plain PHP view scripts running **outside** Slim — only `/api/*` and `/` go through it.
- Real layers in `src/`: `Controllers/`, `Services/`, `Repositories/` (only `MenuRepository` and `OrderRepository` — several controllers call Eloquent models directly), `Validators/` (only `OrderValidator` exists), `Middleware/`, `Models/` (Eloquent). Follow this actual layering — don't invent a repository/validator/service for a domain that doesn't already have one unless a spec calls for it.
- `common/config.php`/`common/db.php` are legacy raw-PDO helpers with no callers found anywhere in `src/` or `public/` — treat as dead code, do not build on them.
- Persistence: initial schema `common/sql/001_schema.sql` (MySQL container first-init only) + incremental `common/migrations/*.sql`, applied via the custom `App\Database\MigrationRunner` through `bin/migrate`. There is no ORM migration framework and no formal seeding beyond the one admin row in `001_schema.sql`.
- Full details, endpoints, and known gaps: `specs/000-project-baseline.md`.

## Commands actually available

- `docker compose up -d` — starts `db` (MySQL 8.0) and `web` (container name `restaurant_web`, port `8080:80`).
- `docker compose exec web composer install|update|require|remove`
- `bin/migrate` — apply pending SQL migrations.
- `bin/worker [--once] [queue]` — process the DB-backed job queue (e.g. print jobs).
- `composer start` — `php -S 0.0.0.0:80 -t public` (only script in `composer.json`).
- **There is no test command and no lint/static-analysis command in this project.** Do not invent one, and never claim tests passed if none were run — say plainly that no test infrastructure exists when that's the case.

## Code conventions observed

- `declare(strict_types=1)` is used in newer files but not universally — prefer it in new/edited files, don't mass-retrofit old ones as a side effect of an unrelated change.
- Mixed style: older files use manual constructor property assignment, newer files (e.g. `ReportController`) use PHP 8 promoted `private readonly` properties — prefer promoted properties in new code.
- Docblocks/domain comments in English; user-facing error strings and some domain comments in Portuguese — keep that split, don't translate one into the other wholesale.
- Controllers catch exceptions and return JSON manually (`json_encode` + `Content-Type` header); there's no shared response helper except in `ReportController`. Don't introduce a new one unless a spec calls for it.
- Commit convention (types, scopes, emoji) is defined in `COMMIT_CONVENTION.md` — follow it, don't restate it here.

## Security rules

- Never read, print, or modify `.env`, credentials, tokens, or other secrets.
- Never commit or push without an explicit request.
- Never run destructive database operations (drops, truncates, irreversible data changes).
- Never change the DB schema outside a migration file in `common/migrations/` (or an explicitly agreed equivalent) — no ad hoc `ALTER TABLE` outside that mechanism.
- `src/Routes.php:19` has a known hardcoded JWT-secret fallback; don't add similar hardcoded-secret fallbacks elsewhere, and don't silently "fix" this one outside of an explicit spec.

## General rules

- Don't add new dependencies, don't bump Composer/Docker versions, unless explicitly asked.
- Don't create Controllers/Services/Repositories/Validators/Middleware layers for a domain that doesn't already have them, purely for architectural symmetry — match the real layering found in `src/`.
- Don't claim a test passed, a command ran successfully, or a criterion is met without having actually run it and observed the result.

## Spec workflow

- Before implementing a non-trivial feature, read the relevant file in `specs/`.
- If no spec exists, create one from `specs/_template.md` (or use `/spec-plan`).
- Do not silently change requirements during implementation.
- Record relevant implementation decisions in the spec's `Implementation log`.
- Keep the `Task checklist` synchronized with the actual implementation.
- Do not mark a spec as `Verified` without validation evidence.
- Small and obvious fixes may use a reduced spec, but must still document the problem, expected result, and validation.
- An approved spec is the source of truth for the requested behavior.
- Existing tests and code must still be considered for backward compatibility.
- When code and spec conflict, report the conflict instead of silently choosing one.
- See `specs/README.md` for the full lifecycle, naming convention, and the `/spec-plan` / `/spec-implement` skills.
