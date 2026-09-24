# CLAUDE.md — GastroFlow

Persistent instructions for working in this repository. Keep this file short; details belong in `specs/` or the code itself.

## Stack (confirmed)

PHP >=8.1 (Docker runtime: `php:8.2-apache`), Slim 4 + `php-di/slim-bridge`, Eloquent ORM (`illuminate/database ^10`, via `Illuminate\Database\Capsule\Manager`), `vlucas/valitron` for validation, `firebase/php-jwt`, `monolog/monolog`, `mike42/escpos-php` (ESC/POS thermal printing), MySQL 8.0, Docker Compose. Frontend: static PHP/HTML pages under `public/` using Alpine.js + Bootstrap 5, no build step.

## Architecture (confirmed)

- Bootstrap: `public/index.php` → `App\App::get()` (`src/App.php`) → `App\Routes::register()` (`src/Routes.php`). Routes are registered directly with Slim's fluent API, not from a config file.
- `public/.htaccess` serves any existing file directly; only non-existent paths fall through to the Slim front controller. This means `public/cashier/`, `public/kitchen/`, and `public/admin/*.php` are plain PHP view scripts running **outside** Slim — only `/api/*` and `/` go through it.
- Real layers in `src/`: `Controllers/`, `Services/`, `Repositories/` (`IngredientRepository`, `MenuRepository`, `OrderRepository` — the `Dish` controller is unreachable dead code with no registered route and still calls the Eloquent model directly), `Validators/` (`AuthValidator`, `IngredientValidator`, `MenuItemValidator`, `OrderValidator`, `SettingsValidator`, all wrapping `vlucas/valitron`), `Middleware/`, `Models/` (Eloquent). Follow this actual layering — don't invent a repository/validator/service for a domain that doesn't already have one unless a spec calls for it.
- `common/config.php`/`common/db.php` are legacy raw-PDO helpers with no callers found anywhere in `src/` or `public/` — treat as dead code, do not build on them.
- Persistence: initial schema `common/sql/001_schema.sql` (MySQL container first-init only) + incremental `common/migrations/*.sql`, applied via the custom `App\Database\MigrationRunner` through `bin/migrate`. There is no ORM migration framework and no seeded data beyond the menu/category rows in `001_schema.sql` — no default admin user is seeded; run `bin/create-admin` to create one.
- Full details, endpoints, and known gaps: `specs/000-project-baseline.md`.

## Commands actually available

- `docker compose up -d` — starts `db` (MySQL 8.0) and `web` (container name `restaurant_web`, port `8080:80`).
- `docker compose exec web composer install|update|require|remove`
- `bin/migrate` — apply pending SQL migrations.
- `bin/create-admin <username>` — create an administrator (prompts for a password, minimum 8 characters; no default credentials are seeded).
- `bin/worker [--once] [queue]` — process the DB-backed job queue (e.g. print jobs).
- `bin/jobs-status [queue]` — list jobs needing attention (permanent failures + expired reservations). Read-only.
- `bin/jobs-prune [--days=N]` — delete completed jobs older than N days (default `QUEUE_RETENTION_DAYS`, 7). Never removes failed jobs.
- `composer start` — `php -S 0.0.0.0:80 -t public` (only script in `composer.json`).
- `docker compose exec web vendor/bin/phpunit` — run the PHPUnit suite (`phpunit.xml`: `tests/Smoke`, `tests/Unit`, `tests/Integration`). No `composer test` alias exists — use the `vendor/bin/phpunit` invocation directly.
- `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` — the MySQL-backed integration suite (spec 035). **Without `MYSQL_DATABASE_TEST` these tests skip themselves**, and if it equals `MYSQL_DATABASE` they fail on purpose — they write real rows and must never touch the development database. One-time setup: `CREATE DATABASE restaurant_test` plus a `GRANT` for `MYSQL_USER` (the app user cannot create databases).
- GitHub Actions (`.github/workflows/ci.yml`) runs this same suite against a real MySQL 8.0 service on every push/PR to `master`.
- `docker compose exec web vendor/bin/phpstan analyse` — static analysis (PHPStan level 5, config `phpstan.neon`, pre-existing findings frozen in `phpstan-baseline.neon`). Added by spec 034.
- `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` — check code style (PSR-12, config `.php-cs-fixer.dist.php`); drop `--dry-run --diff` to apply. Added by spec 034.
- CI runs `composer validate --strict` → `composer audit` → PHPStan → PHP-CS-Fixer → PHPUnit, in that order, as separately named steps.
- Never claim a test or check passed without having actually run it and observed the result.

## Code conventions observed

- `declare(strict_types=1)` is now in **every** PHP file under `src/`, `bin/` and `tests/` (measured during spec 034), and PHP-CS-Fixer's `declare_strict_types` rule keeps it that way — a new file without it fails the style check.
- Style is PSR-12, enforced mechanically (spec 034). Don't hand-format against the fixer; run it.
- Mixed style: older files use manual constructor property assignment, newer files (e.g. `ReportController`) use PHP 8 promoted `private readonly` properties — prefer promoted properties in new code.
- Docblocks/domain comments in English; user-facing error strings and some domain comments in Portuguese — keep that split, don't translate one into the other wholesale.
- Controllers catch exceptions and return JSON manually (`json_encode` + `Content-Type` header); there's no shared response helper except in `ReportController`. Don't introduce a new one unless a spec calls for it.
- Commit convention (types, scopes, emoji) is defined in `COMMIT_CONVENTION.md` — follow it, don't restate it here.

## Security rules

- Never read, print, or modify `.env`, credentials, tokens, or other secrets.
- Never commit or push without an explicit request.
- Never run destructive database operations (drops, truncates, irreversible data changes).
- Never change the DB schema outside a migration file in `common/migrations/` (or an explicitly agreed equivalent) — no ad hoc `ALTER TABLE` outside that mechanism.
- The hardcoded JWT-secret fallback formerly at `src/Routes.php:19` was removed by spec 002 (`v1.5.6`); `src/Routes.php:22` now throws a `RuntimeException` when `JWT_SECRET` is unset. Don't reintroduce a hardcoded-secret fallback there or elsewhere, and don't silently "fix" security-relevant code like this outside of an explicit spec.

## Release workflow

- **The tag number comes from `docs/ROADMAP.md`'s milestone, not from SemVer arithmetic over the commits since the last tag.** A milestone's tag (`v1.8.0`, `v1.9.0`, …) is cut **only when every item of that milestone is complete** — never mid-milestone, because that would name a release as if the milestone had closed. Work that belongs to no milestone (client requests) is the exception: it gets a patch tag kept deliberately below the next reserved milestone number, as `v1.7.1` did.
- **`CHANGELOG.md` is updated incrementally, as each stage/spec lands on `master`**, under the in-progress milestone's heading — not saved up until the tag.
- **Updating `CHANGELOG.md` is part of finishing a piece of work, not something to ask about.** Whenever new work lands on `master` — a merge commit, a direct commit, or a `git push` that puts commits on `master` that weren't there before (detected via `git log`/`git status`, or observed directly when you perform the commit/push yourself) — add that work to the in-progress milestone's section, using `git log <last-tag>..HEAD` and `docs/ROADMAP.md`'s current milestone to draft the entry. Do it as the next step, without waiting to be asked. This applies the same way whether the user asked for the commit/push/merge or you performed it as part of a task.
- **Tags still require explicit confirmation**, and are proposed only when the milestone has no open items left, per `docs/COMMIT_CONVENTION.md` ("Release & Changelog Workflow").
- The changelog edit goes through a branch and a pull request, like any other change — never committed straight to `master`.
- **Syncing the docs is part of finishing too, for the same reason.** When a spec lands, update whatever it made false in `docs/architecture.md`, `docs/technical-decisions.md` and `specs/000-project-baseline.md` — don't wait to be asked. An audit on 2026-09-24 found `architecture.md` mentioned 1 of the last 7 specs and the baseline mentioned 0: the problem was never a missing document, it was documents nobody updated. When you catch a doc asserting something the code no longer does, correct it in place with a dated note rather than silently rewriting it, which is the discipline the baseline already sets for itself.

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
- See `specs/README.md` for the full lifecycle, naming convention, and the `/spec-plan` / `/spec-implement` / `/spec-review` skills.
