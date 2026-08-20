# Spec 005 — GitHub Actions CI/CD

## Metadata

- Status: Implemented
- Created: 2026-08-19
- Updated: 2026-08-19
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
- No `src/`, `public/`, `common/`, or `composer.json` changes beyond what spec 004 already introduces.

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

- **Not blocking implementation, blocking `Verified` status**: whether pushing a branch / opening a PR to actually trigger and observe a live workflow run is authorized — **asked, not yet granted as of this update**. Per `CLAUDE.md` ("Never commit or push without an explicit request"), this was left undone; the spec stays `Implemented` until an actual run is observed and its logs recorded here.

## Task checklist

- [x] `.github/workflows/ci.yml` created
- [x] `README.md` badge swapped for a live CI badge
- [ ] Workflow observed to run green at least once (push/PR, pending user authorization — see Open questions)

## Implementation log

- 2026-08-19: While confirming how `common/sql/001_schema.sql` bootstraps a fresh database, discovered it's more self-sufficient than the plan assumed: it does its own `CREATE DATABASE IF NOT EXISTS restaurant` + `USE restaurant;`, **and** hardcodes `CREATE USER IF NOT EXISTS 'restuser'@'%' IDENTIFIED BY 'restpass'` + `GRANT ALL PRIVILEGES` itself (`common/sql/001_schema.sql:1-10`). This means the CI service's `MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_DATABASE` values were deliberately chosen to exactly match these hardcoded values (`restaurant`/`restuser`/`restpass` — also `Database::boot()`'s own defaults in `src/Database.php:13-16`), so the official `mysql:8.0` image's own env-var-driven user/db auto-creation and the schema file's redundant `CREATE USER IF NOT EXISTS` don't conflict (both idempotent, same values). Not a deviation from the plan, but a fact worth recording since it explains why these specific values were used verbatim rather than arbitrary ones.
- 2026-08-19: `.github/workflows/ci.yml` written per the Implementation plan: `mysql:8.0` service with a health check, PHP 8.2 via `shivammathur/setup-php@v2`, `composer install`, a schema-load step (waits on `mysqladmin ping`, then pipes `common/sql/001_schema.sql` through the `mysql` client), a step that writes a job-local `.env` (from the job's own `env:` block, not from any real secret) so `bin/migrate`'s unconditional `Dotenv::load()` doesn't throw, `php bin/migrate`, then `vendor/bin/phpunit`.
- 2026-08-19: `README.md`'s badge row: replaced the static `tests-not%20yet-lightgrey` badge with a live `actions/workflows/ci.yml/badge.svg`, linked to the workflow's Actions page.
- 2026-08-19: **Not done, and explicitly not attempted**: a full local dry-run of the schema-load + `bin/migrate` steps against a scratch database. `bin/migrate` hardcodes its `Dotenv` path to the project's real `.env` (`bin/migrate:23-24`, `Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load()`) with no override mechanism, so pointing it at a temporary/scratch database without modifying the real `.env` file isn't possible — and modifying `.env` is against this repo's explicit rules (`CLAUDE.md`: "Never read, print, or modify `.env`, credentials, tokens, or other secrets"). This is why the workflow's correctness could only be checked by static review + YAML validation, not by executing the exact CI steps locally end-to-end. See Validation evidence and Open questions.

## Validation evidence

- AC 1 (trigger config — `push` to `master`, `pull_request`): confirmed by reading the committed `.github/workflows/ci.yml` — `on.push.branches: [master]`, `on.pull_request:` (no branch filter, so it matches every PR). `git remote -v` / `git branch -a` (run during spec planning) confirmed `master` is this repo's real default branch (`remotes/origin/HEAD -> origin/master`), so the trigger branch is correct for this repo (not the roadmap's literal, incorrect "main" wording).
- AC 2 (PHP 8.2): confirmed by reading the file — `shivammathur/setup-php@v2` step has `php-version: '8.2'`.
- YAML validity: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml')); print('YAML OK')"` → `YAML OK`.
- AC 3, 4, 5 (composer install / schema+migrate / phpunit all succeeding in a real run): **not verified**. No GitHub Actions run has executed this workflow yet — that requires pushing this branch or opening a PR, which was not done (see Open questions; not authorized as of this update). What *was* checked, short of a live run:
  - Each step's command was traced against real, current project files: `composer install` matches `composer.json`'s existing usage elsewhere in this repo; the schema-load step's target file (`common/sql/001_schema.sql`) and its self-contained `CREATE DATABASE`/`CREATE USER` statements were read directly (see Implementation log); `php bin/migrate` was read in full (`bin/migrate:1-36`) and confirmed to only need `.env` to exist and a reachable DB matching `Settings`'s expected keys, both of which the workflow's preceding steps provide; `vendor/bin/phpunit` matches spec 004's now-`Verified` local run (`docker compose exec web vendor/bin/phpunit` → `OK (7 tests, 17 assertions)`), which exercises the identical `phpunit.xml`/`tests/bootstrap.php`/test files this workflow will run.
  - A full local reproduction of the schema-load + `bin/migrate` steps against a scratch database was deliberately not attempted, since `bin/migrate` can only read the real `.env` (no override), and modifying `.env` — even temporarily — is against this repo's explicit rules. This is a genuine gap between "looks correct on paper" and "observed to work," acknowledged rather than hidden.
- AC 6 (README badge): `git diff README.md` shows the static badge replaced with `<a href="https://github.com/FocusEvenGitHub/GastroFlow/actions/workflows/ci.yml"><img src="https://github.com/FocusEvenGitHub/GastroFlow/actions/workflows/ci.yml/badge.svg" alt="CI"/></a>`. The badge URL itself will only render a real status once the workflow has run at least once on GitHub — before that, GitHub serves a generic "no status" badge image, which is expected and not a defect.
- Diff scope reviewed (`git status --short`, `git diff README.md`): confirmed exactly `.github/workflows/ci.yml` (new) and `README.md` (badge line) changed for this spec — no `src/`, `composer.json`, or other files touched here (those belong to spec 004's diff, already validated separately).
