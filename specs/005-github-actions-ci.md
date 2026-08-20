# Spec 005 — GitHub Actions CI/CD

## Metadata

- Status: Verified
- Created: 2026-08-19
- Updated: 2026-08-20
- Owner: Henry (via Claude Code)
- Related issue: #15
- Related branch: 013

## Context

`docs/ROADMAP.md` v2.1 — Tests & Quality, item #15, which the roadmap's "Recommended execution order" places right after item #1 (PHPUnit) because it depends on it. The user explicitly requested implementation of roadmap items #1 and #15 together, and explicitly chose to implement them **in parallel** rather than gate 005 on 004 finishing first — the workflow's shape (spec 004's exact file layout: `phpunit.xml`, `tests/bootstrap.php`, `tests/Smoke/ApiTest.php`, `tests/Unit/*`) is already fully specified in `specs/004-phpunit-smoke-tests.md`, so both specs' file changes are written and applied together in the same implementation pass. The logical dependency (this workflow needs those files to exist and be correct to actually pass) is unchanged — only the sequencing of *doing the work* is parallel, not the runtime dependency itself.

## Problem

There is no automated check on push/PR. A regression can be merged to `master` with nothing but a human noticing.

## Goals

- Run `composer install` then `vendor/bin/phpunit` automatically on every push and pull request, per the roadmap's stated pipeline.
- Give the smoke test (`tests/Smoke/ApiTest.php`, spec 004) a real, ephemeral MySQL database to hit `GET /api/menu` against, with the current schema and migrations applied — otherwise it cannot pass in CI.
- Add a status badge to `README.md`.

## Non-goals

- No deployment step — "CI/CD" in the roadmap's own description is scoped to "minimal pipeline: `composer install` → `phpunit`"; there is no deploy target defined anywhere in this repo (no cloud config, no `Dockerfile` registry push, no hosting docs) to deploy to.
- No lint/static-analysis step — `CLAUDE.md` states plainly "there is no test command and no lint/static-analysis command in this project"; adding one is out of scope for this item.
- No matrix testing across multiple PHP/MySQL versions — the project targets one confirmed runtime (`php:8.2-apache`, MySQL 8.0, per `docker-compose.yml`/`Dockerfile`); a single-version job matches that.
- No change to `docker-compose.yml`, `Dockerfile`, or any application code — CI reuses the existing schema/migrations/composer setup as-is.

## Current behavior

- No `.github/workflows/` directory exists (confirmed: `Glob .github/workflows/*` → no files found).
- `composer.json` has no `require-dev`/test script today; after spec 004 is implemented it will have `phpunit/phpunit ^11.0` under `require-dev`, plus `phpunit.xml` (bootstrap `tests/bootstrap.php`) and `tests/Smoke/ApiTest.php` + `tests/Unit/*.php`.
- `composer.lock` is confirmed gitignored (`specs/000-project-baseline.md`, via `git check-ignore -v composer.lock`) — not tracked, so CI's `composer install` resolves fresh against `composer.json`'s version constraints each run rather than a committed lockfile.
- Schema bootstrap is two-step and file-based, not a single command: `common/sql/001_schema.sql` (base schema, includes the seed `admin`/bcrypt-hash row per `specs/000-project-baseline.md`) must be loaded into a fresh MySQL database first, then `bin/migrate` (`src/Database/MigrationRunner.php`) applies the 7 files under `common/migrations/*.sql` (`001_ingredients.sql`, `002_food_category_and_components.sql`, `003_dish_components_data.sql`, `005_dining_option.sql`, `006_settings.sql`, `007_jobs.sql`, `008_order_items_price.sql`) in filename order, tracking applied files in a `migrations` table it creates itself. There is no single "create schema" script that does both steps.
- `bin/migrate` and `public/index.php` both load configuration via `Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load()` (`->load()`, not `->safeLoad()` — throws if no `.env` file is present) before constructing `Settings`. **This repo has no `.env.example`** (confirmed: `Glob .env.example` → no files found) — a CI job cannot rely on copying one; it must supply configuration another way.
- `Settings::get()` (`src/Settings.php:13-17`) reads only `$_ENV`, never `getenv()`. Per spec 004's design, `tests/bootstrap.php` bridges `getenv()` into `$_ENV` for any key not already set — this is the intended path for CI to supply DB/JWT configuration as real environment variables, without needing a `.env` file at all, provided `bin/migrate` is *not* used as-is in CI (since it unconditionally calls `Dotenv::load()`, which throws without a `.env` file).
- `src/Routes.php:19` throws `\RuntimeException('JWT_SECRET environment variable is not set.')` if `$_ENV['JWT_SECRET']` is unset (spec 002) — `App::get()` → `Routes::register()` runs unconditionally, so the smoke test's `App::get()` call requires `JWT_SECRET` present in `$_ENV` (bridged from a real CI environment variable per spec 004's bootstrap).
- `Database::boot()` (`src/Database.php:8-27`) defaults to `DB_HOST=db`, `MYSQL_DATABASE=restaurant`, `MYSQL_USER=restuser`, `MYSQL_PASSWORD=restpass` when the corresponding `$_ENV` keys are absent — a CI MySQL service exposed on `127.0.0.1` needs `DB_HOST` (and, if different, the other three) supplied explicitly, since `db` only resolves as a hostname inside the Docker Compose network, not on a GitHub-hosted runner.
- `README.md`'s badge row (lines 12-17) already includes an explicit placeholder: `<img src="https://img.shields.io/badge/tests-not%20yet-lightgrey?...">`, which names exactly the thing this spec replaces.
- Repository's default branch is confirmed **`master`** (`git remote -v` → `origin` is `github.com/FocusEvenGitHub/GastroFlow`; `remotes/origin/HEAD -> origin/master`), not `main` as the roadmap text says ("Workflow runs on `push` (main) and `pull_request`"). This is a discrepancy between the roadmap's wording and the actual repository — resolved here by triggering on `master`, the real default branch, not by renaming the branch or silently keeping the roadmap's literal (wrong) name.

## Proposed behavior

A single workflow file, `.github/workflows/ci.yml`, triggered on `push` to `master` and on `pull_request` (any base branch), running one job on `ubuntu-latest` with a `mysql:8.0` service container. The job: checks out the code, sets up PHP 8.2 with Composer, runs `composer install`, loads `common/sql/001_schema.sql` into the service database with the MySQL client, runs `bin/migrate` against it (with `.env` supplied as a generated file so `Dotenv::load()` doesn't throw — see step 5 below), then runs `vendor/bin/phpunit` with the same DB/`JWT_SECRET` configuration exposed as job-level environment variables (picked up by `tests/bootstrap.php`'s `getenv()`-to-`$_ENV` bridge from spec 004). `README.md`'s existing "tests: not yet" badge is replaced with a GitHub Actions workflow-status badge.

## Functional requirements

1. `.github/workflows/ci.yml` exists and is valid GitHub Actions YAML.
2. The workflow triggers on `push` to the `master` branch and on every `pull_request`.
3. The job sets up PHP `8.2` (matching `Dockerfile`'s `php:8.2-apache` and `composer.json`'s `"php": ">=8.1"`).
4. The job runs `composer install` and it completes successfully (exit `0`).
5. The job provisions a MySQL 8.0 service, loads `common/sql/001_schema.sql`, then runs `bin/migrate` (or equivalent direct application of `common/migrations/*.sql` in filename order) against it before running tests.
6. The job runs `vendor/bin/phpunit` and the workflow fails (non-zero) if any test fails; it passes (exit `0`, green) when spec 004's tests all pass.
7. `README.md` displays a badge that reflects the live status of this workflow (e.g. `https://github.com/FocusEvenGitHub/GastroFlow/actions/workflows/ci.yml/badge.svg`), replacing the current static "tests: not yet" badge.

## Non-functional requirements

- The workflow must not require any secret beyond what GitHub Actions provides by default (`GITHUB_TOKEN` is not even needed here) — all DB/JWT values are non-sensitive, CI-only placeholder values defined directly in the workflow file, not GitHub encrypted secrets, since no real credential is at risk (ephemeral, isolated CI database).
- Runtime budget: a single job, no matrix — keep total CI time low (target: a few minutes), consistent with the roadmap's own "minimal pipeline" framing.

## User flows

Not applicable — this is a repository/CI-maintainer-facing change, not an end-user flow.

## API changes

Not applicable — no application code changes.

## Data model and migrations

Not applicable — no new migration files. The workflow *applies* the existing `common/sql/001_schema.sql` + `common/migrations/*.sql` to a fresh, ephemeral CI database; it does not add or change any of them.

## Architecture and affected components

- `.github/workflows/ci.yml` — new file.
- `README.md` — badge row (lines 12-17) edited to replace the "tests: not yet" badge with a live CI badge.
- `bin/migrate` — **added during implementation**: added the same `getenv()`→`$_ENV` bridge `tests/bootstrap.php` already has, fixing the actual cause of both CI run failures (`DB_HOST` never reaching `Settings::get()`). See Validation evidence.
- `common/migrations/006_settings.sql` — **added during implementation**, not part of the original plan: fixed an independently-discovered, real (but not CI-blocking) bug (see Validation evidence). Editing an already-shipped migration file's SQL is normally sensitive; justified here because `MigrationRunner` tracks applied migrations by filename only (not content hash), so this only changes behavior for fresh installs, never for a database where `006_settings.sql` is already recorded as applied.
- No other `src/`, `public/`, or `composer.json` changes beyond what spec 004 already introduces.

## Security considerations

- CI database credentials are throwaway values scoped to an ephemeral GitHub-hosted runner's service container, torn down at the end of every run — not the same values as any real deployment's `.env` (which this workflow never reads, writes, or has access to).
- `JWT_SECRET` for CI is likewise a fixed, non-sensitive placeholder string defined in the workflow file — it never signs a real token seen outside the test run.
- No production credentials, deployment targets, or infrastructure access are introduced by this spec (reinforced by the Non-goals section: no deploy step exists).

## Backward compatibility

Fully additive: a new workflow file and a badge swap in `README.md`. No existing behavior, endpoint, or file consumed by the running application changes.

## Acceptance criteria

1. `.github/workflows/ci.yml` exists, triggers on `push` (branch `master`) and `pull_request` (functional requirement 2).
2. The workflow's PHP setup step targets version `8.2`.
3. A workflow run's job log shows `composer install` completing successfully.
4. A workflow run's job log shows the schema load + `bin/migrate` step completing successfully before the test step runs.
5. A workflow run's job log shows `vendor/bin/phpunit` executing all of spec 004's tests and exiting `0`.
6. `README.md`'s badge row shows a live GitHub Actions status badge for this workflow instead of the static "tests: not yet" badge.

## Implementation plan

1. Implement alongside spec 004 (`specs/004-phpunit-smoke-tests.md`) in the same pass, not gated on it reaching `Implemented`/`Verified` first — but this workflow's steps still assume `phpunit.xml`, `tests/bootstrap.php`, and the three test files 004 defines exist and are correct by the time this workflow actually runs (a real CI job execution, not just the YAML being written, is what proves the two specs work together).
2. Create `.github/workflows/ci.yml`:
   - `on: push: branches: [master]` and `on: pull_request:`.
   - `services.mysql` using `mysql:8.0`, with health-check options, exposing `3306` on the runner, and fixed env (`MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`) matching what the job step below will pass to the app.
   - Steps: `actions/checkout@v4` → `shivammathur/setup-php@v2` (`php-version: '8.2'`) → `composer install --no-interaction --prefer-dist` → a step that waits for MySQL to be ready and pipes `common/sql/001_schema.sql` into it via the `mysql` client (`mysql -h127.0.0.1 -uroot -p... $MYSQL_DATABASE < common/sql/001_schema.sql`) → a step that writes a minimal `.env` file (`DB_HOST=127.0.0.1`, the matching `MYSQL_*` values, `JWT_SECRET=<ci-placeholder>`) so `bin/migrate`'s unconditional `Dotenv::load()` doesn't throw, then runs `php bin/migrate` → `vendor/bin/phpunit`, with `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `JWT_SECRET` set as job/step-level `env:` (picked up by `tests/bootstrap.php`'s `getenv()`→`$_ENV` bridge, so the generated `.env` file and the job `env:` stay consistent with each other).
3. Update `README.md`'s badge row: replace the `tests-not%20yet-lightgrey` badge with `https://github.com/FocusEvenGitHub/GastroFlow/actions/workflows/ci.yml/badge.svg`, linked to the Actions run list.
4. Push the branch and open/verify a PR (or push directly, per whatever the user directs at implementation time) so the workflow actually executes at least once, and capture that it's green as validation evidence — a CI workflow cannot be verified by static reading alone; it must be observed to run.

## Testing and validation strategy

No automated test infrastructure exists for *workflow files themselves* (GitHub Actions has no local unit-test tool in this project's toolchain). Validation is: push the branch (or open a PR) so GitHub Actions actually executes the workflow, then inspect the real run's logs/conclusion via `gh run list` / `gh run view` (or the Actions UI) to confirm each step succeeded and the overall run is green. A workflow YAML that merely "looks correct" is not sufficient evidence — this spec cannot move to `Verified` without an observed real run.

## Rollout and rollback

Additive change on branch `013`. Rollback is a plain revert of `.github/workflows/ci.yml` and the `README.md` badge line; no data/schema/deployment impact — this workflow does not deploy anything.

## Open questions

- ~~Whether pushing to trigger a live run is authorized~~ — resolved: the user explicitly requested the merge-and-push, which triggered run #1. See below for what it found.
- **Blocking `Verified` status, needs a user decision**: run #1 failed at the migration step due to a pre-existing bug in `common/migrations/006_settings.sql` (unconditional `ALTER TABLE orders ADD COLUMN customer_name ...`, which collides with `common/sql/001_schema.sql:39` already declaring that column) — see Validation evidence for the full root-cause analysis and local reproduction. This is a real defect independent of this spec's own scope (the workflow YAML itself is correctly configured per AC 1/2/3/6). Options for the user to choose between:
  1. Fix `006_settings.sql` to guard the `ALTER TABLE` the same way `002_food_category_and_components.sql` already does (`information_schema.COLUMNS` existence check) — safe for already-migrated databases, since `MigrationRunner` tracks applied migrations by filename only, not content hash.
  2. Leave it as-is and accept that CI will keep failing until it's fixed some other way.
  3. Something else the user prefers.
  **Resolved**: user chose option 1 (fix now). `common/migrations/006_settings.sql` was patched to guard the `ALTER TABLE` with the same `information_schema.COLUMNS` existence check `002_food_category_and_components.sql` already uses. Re-verified locally (via a bypass script, not the real `bin/migrate` — see Validation evidence's correction note) — all 7 migrations apply cleanly end-to-end against that reproduction.
- **This did not actually fix run #2** — run #2 (commit `cda0d36`, the 006 fix) failed again, at the same "Run migrations" step, with a *different* error this time (`getaddrinfo for db failed`), which the user surfaced by pasting the real log after a WebFetch attempt was declined. This revealed the actual root cause: `bin/migrate` never populates `$_ENV['DB_HOST']` from the workflow's real environment variable (see Validation evidence for the full explanation) — a separate, unrelated bug from the 006 one, coincidentally hit first. Fixed in `bin/migrate` (same `getenv()` bridge `tests/bootstrap.php` already had), pushed as commit `494e003`, and re-verified locally against the *real* `bin/migrate` script beforehand. **Run #3 (commit `494e003`) succeeded** — confirmed directly by the user in the GitHub Actions UI. Spec moved to `Verified`.

## Task checklist

- [x] `.github/workflows/ci.yml` created
- [x] `README.md` badge swapped for a live CI badge
- [x] `common/migrations/006_settings.sql` fixed (independently-found bug, not the actual CI blocker) and re-verified locally end-to-end
- [x] `bin/migrate` fixed (actual root cause of both run #1 and run #2's failures: `$_ENV['DB_HOST']` never populated) and re-verified locally against the real script
- [x] Workflow observed to run green at least once — run #3 (commit `494e003`) succeeded, confirmed by the user directly in the GitHub Actions UI

## Implementation log

- 2026-08-19: While confirming how `common/sql/001_schema.sql` bootstraps a fresh database, discovered it's more self-sufficient than the plan assumed: it does its own `CREATE DATABASE IF NOT EXISTS restaurant` + `USE restaurant;`, **and** hardcodes `CREATE USER IF NOT EXISTS 'restuser'@'%' IDENTIFIED BY 'restpass'` + `GRANT ALL PRIVILEGES` itself (`common/sql/001_schema.sql:1-10`). This means the CI service's `MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_DATABASE` values were deliberately chosen to exactly match these hardcoded values (`restaurant`/`restuser`/`restpass` — also `Database::boot()`'s own defaults in `src/Database.php:13-16`), so the official `mysql:8.0` image's own env-var-driven user/db auto-creation and the schema file's redundant `CREATE USER IF NOT EXISTS` don't conflict (both idempotent, same values). Not a deviation from the plan, but a fact worth recording since it explains why these specific values were used verbatim rather than arbitrary ones.
- 2026-08-19: `.github/workflows/ci.yml` written per the Implementation plan: `mysql:8.0` service with a health check, PHP 8.2 via `shivammathur/setup-php@v2`, `composer install`, a schema-load step (waits on `mysqladmin ping`, then pipes `common/sql/001_schema.sql` through the `mysql` client), a step that writes a job-local `.env` (from the job's own `env:` block, not from any real secret) so `bin/migrate`'s unconditional `Dotenv::load()` doesn't throw, `php bin/migrate`, then `vendor/bin/phpunit`.
- 2026-08-19: `README.md`'s badge row: replaced the static `tests-not%20yet-lightgrey` badge with a live `actions/workflows/ci.yml/badge.svg`, linked to the workflow's Actions page.
- 2026-08-19: **Not done, and explicitly not attempted**: a full local dry-run of the schema-load + `bin/migrate` steps against a scratch database. `bin/migrate` hardcodes its `Dotenv` path to the project's real `.env` (`bin/migrate:23-24`, `Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load()`) with no override mechanism, so pointing it at a temporary/scratch database without modifying the real `.env` file isn't possible — and modifying `.env` is against this repo's explicit rules (`CLAUDE.md`: "Never read, print, or modify `.env`, credentials, tokens, or other secrets"). This is why the workflow's correctness could only be checked by static review + YAML validation, not by executing the exact CI steps locally end-to-end. See Validation evidence and Open questions.

## Validation evidence

- AC 1 (trigger config — `push` to `master`, `pull_request`): confirmed by reading the committed `.github/workflows/ci.yml` — `on.push.branches: [master]`, `on.pull_request:` (no branch filter, so it matches every PR). `git remote -v` / `git branch -a` confirmed `master` is this repo's real default branch, so the trigger branch is correct for this repo.
- AC 2 (PHP 8.2): confirmed by reading the file — `shivammathur/setup-php@v2` step has `php-version: '8.2'`.
- YAML validity: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML OK')"` → `YAML OK`.
- **A real workflow run happened** (branches 012/013 were merged into `master` and pushed, per the user's explicit request, which triggered this workflow for the first time): run #1 (id `32324769737`, commit `34875b9`). Steps, per the GitHub Actions Jobs API:
  1. Set up job — success
  2. Initialize containers (the `mysql:8.0` service) — success
  3. `actions/checkout@v4` — success
  4. Set up PHP 8.2 — success
  5. `composer install` — success (AC 3 met)
  6. Wait for MySQL and load base schema — success
  7. Write `.env` for `bin/migrate` — success
  8. **Run migrations — failure**
  9. Run PHPUnit — **skipped** (never reached; AC 5 not met)
  10. Stop containers / complete job — success
  - **Overall run conclusion: failure.** AC 4 (schema+migrate) and AC 5 (phpunit passing in CI) are **not met**.
- **Correction**: an earlier version of this section claimed run #1's failure was the `006_settings.sql` duplicate-column bug below, based on a local reproduction that used explicit `$_ENV` values set directly in-process — not on run #1's actual log text (a WebFetch attempt to read the real job log was declined). That was a real, independently-reproducible bug (kept fixed, see below), but **it was not actually what failed run #1 or run #2** — the real cause (confirmed from the user pasting run #2's actual raw log) was different and is described next. Recorded here so the spec's history doesn't quietly imply the first diagnosis was verified when it wasn't.
- **Actual root cause of both run #1 and run #2's failures** (confirmed from run #2's real log, pasted directly by the user after a WebFetch attempt was declined): `PDOException: ... getaddrinfo for db failed: Temporary failure in name resolution`, i.e. the app tried to connect to a host literally named `db` — `Database::boot()`'s default fallback (`src/Database.php:13`, `$settings->get('DB_HOST', 'db')`) — meaning `$_ENV['DB_HOST']` was never actually set when `bin/migrate` ran, despite the workflow's "Write .env for bin/migrate" step (which does set `DB_HOST=127.0.0.1` in the file) reporting success.
  - Why: `bin/migrate` calls `Dotenv::createImmutable(...)->load()` with no bridge from `getenv()` into `$_ENV`. Two PHP/CI specifics combine: (1) the runner's PHP `variables_order` doesn't include `E`, so `$_ENV` isn't auto-populated from real OS environment variables; (2) GitHub Actions' job-level `env:` block **does** set `DB_HOST` as a real OS process environment variable, visible via `getenv()` — and phpdotenv's default "immutable" mode refuses to let a `.env` **file** value override a key that `getenv()` already reports as set, so it skips writing `DB_HOST` into `$_ENV` from the file too. Net result: `$_ENV['DB_HOST']` stays unset from both sources, and `Settings::get()` falls back to its hardcoded default `'db'`, which doesn't resolve on a GitHub-hosted runner. This never showed up in normal Docker use because `docker-compose.yml`'s `environment: DB_HOST: db` happens to equal that same default.
  - **Fix**: `bin/migrate` now includes the identical `getenv()`→`$_ENV` bridge `tests/bootstrap.php` already had (added for spec 004) — anything `getenv()` reports that `$_ENV` doesn't already have gets copied in, after `Dotenv::load()`.
  - **Fix verified locally against the real `bin/migrate` script** (not a bypass), reproducing CI's exact condition — `DB_HOST` supplied only as a bare process environment variable, nothing pre-set in `$_ENV`: a third throwaway, isolated `mysql:8.0` container was loaded with the schema, then `docker compose exec -e DB_HOST=ci-dryrun-mysql3 -T web php bin/migrate` ran successfully end-to-end:
    ```
    === GastroFlow Migrations ===
    ▶ Executando 001_ingredients.sql ... [OK]
    ▶ Executando 002_food_category_and_components.sql ... [OK]
    ▶ Executando 003_dish_components_data.sql ... [OK]
    ▶ Executando 005_dining_option.sql ... [OK]
    ▶ Executando 006_settings.sql ... [OK]
    ▶ Executando 007_jobs.sql ... [OK]
    ▶ Executando 008_order_items_price.sql ... [OK]
    ✓ Concluído.
    ```
    Container torn down afterward. `docker compose exec web php -l bin/migrate` → "No syntax errors detected".
  - **Confirmed the actual, complete fix**: run #3 (commit `494e003`) succeeded on real GitHub Actions, confirmed by the user directly in the Actions UI (2026-08-20). AC 3, 4, and 5 are now met.

### Independently-found bug (real, but not the CI blocker): `common/migrations/006_settings.sql`

Reproduced locally (a *different* throwaway, isolated `mysql:8.0` container, using a one-off script that bypassed `bin/migrate`/`.env` entirely by setting `$_ENV` directly in-process — this is what let the connection succeed despite the `DB_HOST` bug above, which is why this SQL-level error was reachable in that specific reproduction even though it wasn't what actually failed in CI). Result:
  ```
  ▶ Executando 001_ingredients.sql ... [OK]
  ▶ Executando 002_food_category_and_components.sql ... [OK]
  ▶ Executando 003_dish_components_data.sql ... [OK]
  ▶ Executando 005_dining_option.sql ... [OK]
  ▶ Executando 006_settings.sql ... [ERRO] SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'customer_name'
  ```
  **This is a pre-existing bug in `common/migrations/006_settings.sql`, not a defect in this spec's CI workflow.** `006_settings.sql` unconditionally runs `ALTER TABLE orders ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL AFTER table_number;` — but `common/sql/001_schema.sql:39` already declares `customer_name VARCHAR(100) DEFAULT NULL` directly on the `orders` table. On a genuinely fresh install (schema + all migrations from zero, exactly what CI does and what no environment before this had ever actually exercised), the column already exists by the time migration 006 runs, and the unconditional `ALTER TABLE` fails. `common/migrations/002_food_category_and_components.sql` already has the correct defensive pattern for this exact situation (an `information_schema.COLUMNS` existence check before the `ALTER TABLE`, via `PREPARE`/`EXECUTE`) — `006_settings.sql` never got the same treatment. This latent bug was invisible until now because every existing database (the long-running local dev DB included) had `006_settings.sql` recorded as already-applied from before `001_schema.sql` was updated to include `customer_name` directly, so it was never re-run against a fresh schema until this CI workflow did exactly that.
  - `MigrationRunner`'s `getPendingFiles()` only checks by filename, not by content hash, to decide whether to (re-)run a migration — so fixing `006_settings.sql`'s content would not cause it to re-run against any database (including the real dev one) where it's already recorded as applied; it would only change behavior for genuinely fresh installs (i.e. CI, and any future fresh deployment).
  - Reported to the user rather than patched unilaterally, since spec 005's own `Non-goals` originally said this workflow wouldn't touch migration files. The user then explicitly chose to fix it (see Open questions) — the fix itself uses the sanctioned mechanism (`CLAUDE.md`: schema changes go through `common/migrations/`), and is safe for already-migrated databases since `MigrationRunner` tracks by filename, not content hash.
  - **Fix verified locally**: a second isolated throwaway `mysql:8.0` container was loaded with the base schema and all 7 migrations re-run end-to-end via the same in-process technique (no `.env` touched):
    ```
    ▶ Executando 001_ingredients.sql ... [OK]
    ▶ Executando 002_food_category_and_components.sql ... [OK]
    ▶ Executando 003_dish_components_data.sql ... [OK]
    ▶ Executando 005_dining_option.sql ... [OK]
    ▶ Executando 006_settings.sql ... [OK]
    ▶ Executando 007_jobs.sql ... [OK]
    ▶ Executando 008_order_items_price.sql ... [OK]
    MIGRATE_OK
    ```
    Container torn down afterward (`docker rm -f`). `docker compose exec web php -l common/migrations/006_settings.sql` also ran clean (a weak check for a `.sql` file, but confirms no stray PHP-incompatible characters).
- AC 6 (README badge): `git diff README.md` (pre-merge) showed the static badge replaced with a live workflow-status badge, now on `master`. Reflects the real status of the workflow, currently passing (run #3).
- Diff scope reviewed: `.github/workflows/ci.yml` and the `README.md` badge line (commit `8129928`, merged via `34875b9`); the two follow-up fixes, `common/migrations/006_settings.sql` (commit `cda0d36`) and `bin/migrate` (commit `494e003`), both pushed directly to `master`.
