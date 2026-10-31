# CLAUDE.md — GastroFlow

Persistent instructions for working in this repository. Keep this file short; details belong in `specs/` or the code itself.

## Stack (confirmed)

PHP >=8.1 (Docker runtime: `php:8.2.35-apache`, patch version pinned on purpose — spec 059, PHP2Shell; never go back to a floating `8.2-apache` tag), Slim 4 + `php-di/slim-bridge`, Eloquent ORM (`illuminate/database ^10`, via `Illuminate\Database\Capsule\Manager`), `vlucas/valitron` for validation, `firebase/php-jwt`, `monolog/monolog`, `mike42/escpos-php` (ESC/POS thermal printing; the logo goes through `PrintService::loadLogo()`, not `EscposImage::load()`, which can't read PHP 8 GD images — spec 065; GD is in the image since then), MySQL 8.0, Docker Compose. Frontend: static PHP/HTML pages under `public/` using Alpine.js + Bootstrap 5, **no build step**; third-party libraries are vendored, pinned, in `public/vendor/` (spec 055 — never reference a CDN from an operational screen) — nothing in `public/` depends on Node. The only Node in the repository is `tests/e2e/` (Playwright, spec 040), isolated there on purpose.

## Architecture (confirmed)

- Bootstrap: `public/index.php` → `App\App::get()` (`src/App.php`) → `App\Routes::register()` (`src/Routes.php`). Routes are registered directly with Slim's fluent API, not from a config file.
- `public/.htaccess` serves any existing file directly; only non-existent paths fall through to the Slim front controller. This means `public/cashier/`, `public/kitchen/`, and `public/admin/*.php` are plain PHP view scripts running **outside** Slim — only `/api/*` and `/` go through it.
- Real layers in `src/`: `Controllers/`, `Services/`, `Repositories/` (`IngredientRepository`, `MenuRepository`, `OrderRepository` — the `Dish` controller is unreachable dead code with no registered route and still calls the Eloquent model directly), `Validators/` (`AuthValidator`, `IngredientValidator`, `MenuItemValidator`, `OrderValidator`, `SettingsValidator`, all wrapping `vlucas/valitron`), `Middleware/`, `Models/` (Eloquent). Follow this actual layering — don't invent a repository/validator/service for a domain that doesn't already have one unless a spec calls for it.
- `common/config.php`/`common/db.php` are legacy raw-PDO helpers with no callers found anywhere in `src/` or `public/` — treat as dead code, do not build on them.
- Persistence: initial schema `common/sql/001_schema.sql` (MySQL container first-init only) + incremental `common/migrations/*.sql`, applied via the custom `App\Database\MigrationRunner` through `bin/migrate`. There is no ORM migration framework. A fresh install has no restaurant data (spec 061): `001_schema.sql` seeds only the structural categories (the code matches them by name) and the two packaging items, plus a `fresh_install` marker table that makes `022_clean_fresh_install.sql` strip the client menu older migrations still insert; existing installs never have the marker and are left untouched. No default admin user is seeded; run `bin/create-admin` to create one.
- Full details, endpoints, and known gaps: `specs/000-project-baseline.md`.

## Commands actually available

Operator-facing reference, by task: `docs/commands.md`; first installation: `docs/installation.md` (spec 062); upgrading: `docs/upgrading.md` (spec 063) — keep both in sync when a command changes. `.env.example` ships production defaults (`APP_ENV=production`, `APP_DEBUG=false`) since spec 062; a development `.env` sets `development`/`true` by hand.

- **Two compose files (spec 066).** `docker-compose.yml` is **production**: `web`/`print-worker` run the built image (code + `vendor/` inside, no source bind mounts; only `.env:ro` and the `uploads`/`logs` volumes), and a one-shot `migrate` service runs `bin/migrate` after `db` is healthy and before they start. `docker-compose.dev.yml` is development (source bind-mounted, `composer install`/`bin/migrate` by hand), selected with `COMPOSE_FILE=docker-compose.dev.yml` in `.env` — **for every development command below, make sure it applies** (or pass `-f docker-compose.dev.yml`); never run `docker compose up` against the development stack with the production file. Both resolve the same `<folder>_db_data` volume.
- `docker compose up -d` — starts `db` (MySQL 8.0, published on `127.0.0.1:3306` only — spec 062), `web` (container name `restaurant_web`, port `8080:80`) and `print-worker` (+ `migrate` in production). Production build: `GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build` — the build arg becomes the image's `VERSION` file, read by `/version` (else `dev`).
- `docker compose exec web composer install|update|require|remove` — development compose only.
- `bin/migrate` — apply pending SQL migrations. Refuses to proceed if an already-applied migration's current content no longer matches what was recorded (line-ending-only differences are tolerated, not flagged). `bin/migrate --status` (spec 063) is read-only: lists pending migrations, exit 1 if any — and while any is pending `GET /health/ready` answers 503 `MIGRATIONS_PENDING`. `bin/migrate --trust-current-hashes` re-baselines the recorded hash of every already-tracked migration to its current on-disk content, for legitimate cases (e.g. a `.gitattributes` line-ending normalization) — explicit, non-default, never automatic (spec 048).
- `bin/create-admin <username>` — create an administrator (prompts for a password, minimum 8 characters; no default credentials are seeded).
- `bin/seed-demo` — on a database with no menu, first creates a fictional demo menu (spec 061); then fills a **fresh** install with fictional orders relative to today (60 completed over the previous 45 days + 5 pending today), through `OrderRepository::createOrder()`; refuses to run if the database already has any order, unless `APP_ENV=development` (then history numbers continue after each day's highest). Target another database with `docker compose exec -e MYSQL_DATABASE=<db> web php bin/seed-demo`. Added by spec 054 (it is how the README screenshots were made).
- `bin/seed-client-1` — loads `common/seeds/client-1.sql`, the full menu (items, prices, ingredients, dish components) of the pilot client used for tests and implementations — exactly what every fresh install got before spec 061, generated from that state and referenced by name, not id. Refuses to run on a database that already has a menu. Target another database the same way as `bin/seed-demo`.
- `bin/worker [--once] [queue]` — process the DB-backed job queue (e.g. print jobs).
- `bin/jobs-status [queue]` — list jobs needing attention (permanent failures + expired reservations). Read-only.
- `bin/jobs-prune [--days=N]` — delete completed jobs older than N days (default `QUEUE_RETENTION_DAYS`, 7). Never removes failed jobs.
- `bin/events-prune [--days=N]` — delete realtime events older than N days (default `EVENTS_RETENTION_DAYS`, 2). Added by spec 041.
- `bin/backup-db` — host-run (not inside the `web` container): produces `backups/gastroflow-<database>-<timestamp>.sql.gz` via `docker compose exec db mysqldump`. No new dependency anywhere — the `db` service's own `mysqldump`/`gzip` (already in the official `mysql:8.0` image) does the work; credentials are read from the `db` container's own environment, never from the host `.env`. Added by spec 045.
- `bin/restore-db [--yes] [arquivo]` — host-run companion to `bin/backup-db`. Defaults to the most recent file under `backups/` when no argument is given. Destructive (the dump's `DROP TABLE IF EXISTS` statements run for real), so it requires typing `RESTAURAR` to confirm unless `--yes` is passed. Added by spec 045.
- `composer start` — `php -S 0.0.0.0:80 -t public` (only script in `composer.json`).
- `docker compose exec web vendor/bin/phpunit` — run the PHPUnit suite (`phpunit.xml`: `tests/Smoke`, `tests/Unit`, `tests/Integration`). No `composer test` alias exists — use the `vendor/bin/phpunit` invocation directly.
- `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e` then `cd tests/e2e && npm install && npx playwright install chromium && npx playwright test` — the browser suite (spec 040). It targets **port 8081**, a second app instance pointed at `restaurant_test`; it must never target 8080, which is the development database. Only covers what breaks exclusively in a browser (Alpine/Bootstrap interaction, enabled/disabled bindings) — the HTTP-layer flows belong to the integration suite.
- `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` — the MySQL-backed integration suite (spec 035). **Without `MYSQL_DATABASE_TEST` these tests skip themselves**, and if it equals `MYSQL_DATABASE` they fail on purpose — they write real rows and must never touch the development database. One-time setup: `CREATE DATABASE restaurant_test` plus a `GRANT` for `MYSQL_USER` (the app user cannot create databases).
- GitHub Actions (`.github/workflows/ci.yml`) runs this same suite against a real MySQL 8.0 service on every push/PR to `master`.
- `docker compose exec web vendor/bin/phpstan analyse` — static analysis (PHPStan level 5, config `phpstan.neon`, pre-existing findings frozen in `phpstan-baseline.neon`). Added by spec 034.
- `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` — check code style (PSR-12, config `.php-cs-fixer.dist.php`); drop `--dry-run --diff` to apply. Added by spec 034.
- CI runs `composer validate --strict` → `composer audit` → PHPStan → PHP-CS-Fixer → PHPUnit, in that order, as separately named steps.
- Never claim a test or check passed without having actually run it and observed the result.

## Code conventions observed

- `declare(strict_types=1)` is now in **every** PHP file under `src/`, `bin/` and `tests/` (measured during spec 034), and PHP-CS-Fixer's `declare_strict_types` rule keeps it that way — a new file without it fails the style check.
- Style is PSR-12, enforced mechanically (spec 034). Don't hand-format against the fixer; run it.
- Line endings are LF everywhere, governed solely by `.gitattributes` (every tracked text path has an explicit `eol=lf` rule — spec 049); CI fails the build if a tracked file's committed blob has CRLF/mixed line endings. On Windows, also run `git config core.autocrlf false` — see `README.md`'s "Line endings (Windows)" section for the one-time remediation if a working tree was already affected before that.
- Mixed style: older files use manual constructor property assignment, newer files (e.g. `ReportController`) use PHP 8 promoted `private readonly` properties — prefer promoted properties in new code.
- Docblocks/domain comments in English; user-facing error strings and some domain comments in Portuguese — keep that split, don't translate one into the other wholesale.
- Every project-owned JS/CSS file under `public/` (not `public/vendor/`, whose directory name carries the version) is referenced with `?v=<filemtime>` — in the Admin through `$asset()` in `public/admin/_partials/head.php`, in the cashier/kitchen with an inline `filemtime()`. Without it a browser keeps the cached old file after a deploy and runs it against the new HTML (spec 070 found `style.css` and `version-badge.js` still without it). A new `<script>`/`<link>` follows the same rule.
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
- **One pull request per spec — at most.** The spec's own PR carries everything for that demand: the code, the spec file (with its implementation log and validation evidence), **its `CHANGELOG.md` entry** and **the docs pass** below. Add the changelog/docs commit to the spec branch before the PR is merged (before opening it, or as an extra commit on the open PR) — never as a separate follow-up PR after the merge. Don't open several PRs for the same purpose. (User decision, 2026-10-02, after specs 050/051 each produced a code PR plus a changelog/docs PR.)
- **`CHANGELOG.md` is updated incrementally, one entry per spec, inside that spec's PR**, under the in-progress section (the milestone's heading, or "Não lançado" for out-of-milestone client work) — not saved up until the tag.
- **Updating `CHANGELOG.md` is part of finishing a piece of work, not something to ask about.** If work reaches `master` without its entry (e.g. a direct commit, or a merge that predates this rule), add the missing entry in the next open spec PR; only if no spec PR is open does it get a PR of its own.
- **Cutting a release** (renaming "Não lançado"/the milestone heading to `vX.Y.Z (date)`, bumping the version in `docs/ROADMAP.md`/`README.md`) also rides in the last spec PR of that release once the tag is confirmed; a standalone release PR only when no spec PR is open. The tag itself is created after that PR merges.
- **A restaurant runs a release tag, never `master`** (spec 064): `master` is the next version in development; `docs/installation.md`/`docs/upgrading.md` check out tags. The pilot install follows `master` on purpose, as a test install.
- **Tags still require explicit confirmation**, and are proposed only when the milestone has no open items left, per `docs/COMMIT_CONVENTION.md` ("Release & Changelog Workflow").
- **A pushed `v*` tag publishes the release by itself** (spec 072, `.github/workflows/release.yml`): full CI on the tagged commit → the tag's CHANGELOG section must exist **and be dated** (`## vX.Y.Z (YYYY-MM-DD) — Title`, else nothing is published) → Docker image `ghcr.io/focusevengithub/gastroflow` (`X.Y.Z`, plus `X.Y`/`X` when it is the highest of its line; pre-releases only the exact tag; no `latest`) → GitHub Release with that section as notes. So the release PR dates the CHANGELOG heading *before* the tag; check it with `.github/scripts/release-notes.sh vX.Y.Z`.
- The changelog edit goes through a branch and a pull request (the spec's), like any other change — never committed straight to `master`.
- **The changelog pass and the docs pass are the same pass.** Whenever you touch `CHANGELOG.md`, check `docs/architecture.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` and `README.md` for anything the merged work made false, and fix it **in the same pull request** (the spec's PR) — don't wait to be asked and don't leave it for later. An audit on 2026-09-24 found `architecture.md` mentioned 1 of the last 7 specs and the baseline mentioned 0: the problem was never a missing document, it was documents nobody updated. When you catch a doc asserting something the code no longer does, correct it in place with a dated note rather than silently rewriting it, which is the discipline the baseline already sets for itself.

## Trello board

- The project is tracked on the Trello board **"GastroFlow Community"** (https://trello.com/b/8wJbH7PY), reached through the `trello` MCP server. Lists are the `docs/ROADMAP.md` milestones (`📌 Visão & Regras`, `✅ Concluído / Histórico`, `🚧 v1.8`, `📦 v1.9`, `🧪 v2.0`, `🧾 v2.1`, `🧹 Backlog / Follow-ups`); progress lives in each card's **`Spec / Delivery`** checklist. The repository stays the source of truth — the board mirrors it, never the other way round.
- **Updating the board is part of the work, like `CHANGELOG.md` — during development, not only at the end, and without waiting to be asked.** Tick a `Spec / Delivery` item when the step actually happens:
  - `/spec-plan` creates a spec → put the spec number/path and branch on the matching card (create the card if the work has none); tick investigation + spec items.
  - Spec approved / `/spec-implement` / tests / `/spec-review` / PR opened → tick the corresponding items as each one is really done.
  - The spec's PR (which already includes its changelog/docs) is merged → move the card to `✅ Concluído / Histórico`, record PR/commit in the description, set the merge date as due date and mark it complete. No extra PR is needed for this step.
  - A new gap or follow-up gets documented, or `docs/ROADMAP.md` changes → add/update the card in the same pass.
- The evidence rule applies to the board too: never tick an item or mark a card complete without the evidence being real (merged PR, run observed, spec status).
- Don't invent due dates (only real, documented dates such as tags/merges), don't pre-assign future spec numbers, and never put secrets, tokens or credentials in a card.

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
