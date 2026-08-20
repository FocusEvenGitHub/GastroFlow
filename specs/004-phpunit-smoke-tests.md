# Spec 004 — PHPUnit with smoke tests

## Metadata

- Status: Verified
- Created: 2026-08-19
- Updated: 2026-08-19
- Owner: Henry (via Claude Code)
- Related issue: #1
- Related branch: 013

## Context

`docs/ROADMAP.md` v2.1 — Tests & Quality, item #1. The project has zero automated tests (confirmed in `specs/000-project-baseline.md`: "No test or lint command exists in `composer.json`"). The user explicitly requested implementation of roadmap items #1 (this spec) and #15 (GitHub Actions CI, a separate spec since #15 depends on this one being done first — see `docs/ROADMAP.md`'s "Recommended execution order").

## Problem

There is no way to catch a regression before it reaches a human tester. Nothing verifies that the API boots, that order creation logic behaves correctly, or that order-payload validation rejects malformed input.

## Goals

- Add PHPUnit as a dev dependency.
- Add a minimal, working test suite covering exactly the three cases the roadmap names: an API smoke test, an `OrderService` unit test, an `OrderValidator` unit test.
- Make `vendor/bin/phpunit` runnable and green, inside the existing Docker dev environment (`docker compose exec web vendor/bin/phpunit`) — this project has no other way to run PHP.

## Non-goals

- Full test coverage of every Controller/Service/Repository — out of scope, the roadmap names exactly these three test files.
- Building a separate test database/schema or a DB-mocking layer. No test-DB infrastructure exists today; the smoke test is a read-only `GET /api/menu` against the real dev database reachable via `docker compose up -d`, mirroring how specs 001–003 were manually validated against the running container.
- Any change to `Settings`, `Database`, or application runtime behavior — this is additive test infrastructure only.
- Setting up CI/CD (GitHub Actions) — that's roadmap item #15, a separate spec (`/spec-plan` to follow this one).
- Adding a lint/static-analysis tool — not requested by roadmap #1.

## Current behavior

- `composer.json` (`D:\...\gastroflow\composer.json`) has no `require-dev` block and no test script; only `scripts.start` exists.
- No `tests/` directory, no `phpunit.xml`, no `vendor/bin/phpunit` (confirmed absent, `specs/000-project-baseline.md`).
- `src/Services/OrderService.php` — constructor takes `OrderRepository $orderRepo, PrintService $printService, JobService $jobService` (plain constructor params, no interfaces). `createOrder(array $data): Order` calls `$this->orderRepo->createOrder($data)`, then conditionally calls `$this->jobService->dispatch('print', PrintOrderJob::class, ['order_id' => $order->id])` when `$data['print_ticket']` is truthy or unset (defaults to `true`), then calls a private `triggerKitchenEvent()` which does `@file_put_contents(sys_get_temp_dir() . '/gastroflow-events.json', ...)` — a real (suppressed-error) filesystem write, not abstracted behind any injected dependency.
- `src/Validators/OrderValidator.php` — `validateOrderData(array $data): bool` requires `table` and `items` (array); each item must have numeric `id`/`quantity`, and if present, `dining_option` must be one of `local`/`viagem_simples`/`viagem_vip`. `errors(): array` returns Valitron's error list after a failed `validate()` call.
- `src/Controllers/MenuController.php::index()` (routed at `GET /api/menu`, `src/Routes.php:27`, unauthenticated) calls `MenuService::getFullMenu()` and returns it as JSON with `Content-Type: application/json`; no explicit status code is set, so Slim's default (200) applies. This path goes through `MenuService` → `MenuRepository` → Eloquent → the real MySQL database.
- `src/App.php::get()` builds the full Slim app (container, Eloquent boot via `Database::boot()`, middleware, routes) and returns a ready `\Slim\App`; `public/index.php` is the only current caller, via `(new App($settings))->get()->run()`.
- `src/Routes.php:19` (post spec 002) throws `\RuntimeException` if `$_ENV['JWT_SECRET']` is unset — `App::get()` calls `Routes::register()` unconditionally, so any code that boots the full app (including a smoke test) requires `JWT_SECRET` to be set in the environment.
- `public/index.php:9-10` loads `.env` via `Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load()` before constructing `Settings`. No other bootstrap path (tests included) currently does this.
- `docker-compose.yml`: the `web` service also sets `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` directly as container environment variables (separate from `.env`), and mounts the real `.env` file into the container. `Settings::get()` (`src/Settings.php:13-17`) reads only `$_ENV`, not `getenv()`.

## Proposed behavior

A `tests/` directory with a `bootstrap.php` that loads Composer's autoloader and `.env` (via `Dotenv::createImmutable(...)->safeLoad()`, tolerating a missing `.env` in environments where config arrives purely as real environment variables), then bridges any `getenv()` value not already present in `$_ENV` into `$_ENV` — so tests run correctly both via `docker compose exec web vendor/bin/phpunit` (today, `.env`-driven) and later from a CI job that sets real environment variables instead of a `.env` file (roadmap #15, not built here but not blocked by this bootstrap either).

Three test files, matching the roadmap's acceptance criteria exactly:
- `tests/Smoke/ApiTest.php` — boots the real app via `App::get()` and dispatches a synthetic `GET /api/menu` request through Slim's `$app->handle()`, asserting a `200` status.
- `tests/Unit/OrderServiceTest.php` — constructs `OrderService` with `createMock()`-based test doubles for `OrderRepository`, `PrintService`, `JobService` (all concrete classes with no interfaces — PHPUnit's `createMock()` supports mocking concrete classes directly), calls `createOrder()` with a valid payload, and asserts the returned `Order` and that the repository/job-service methods were invoked as expected.
- `tests/Unit/OrderValidatorTest.php` — exercises `validateOrderData()` with a valid payload (asserts `true`) and with invalid payloads (missing `table`, missing `items`, malformed item) (asserts `false` for each), and checks `errors()` is non-empty after a failed validation.

## Functional requirements

1. `composer.json` has `phpunit/phpunit` under `require-dev`, constrained to `^11.0`.
2. `phpunit.xml` exists at the project root, defines a bootstrap of `tests/bootstrap.php`, and declares at least two test suites/directories: `tests/Smoke` and `tests/Unit`.
3. `tests/Smoke/ApiTest.php` sends a `GET /api/menu` request through the real, fully-booted Slim app (via `App::get()`) and asserts the response status is `200`.
4. `tests/Unit/OrderServiceTest.php` calls `OrderService::createOrder()` with a valid payload (`table`, `items` with numeric `id`/`quantity`) using mocked collaborators, and asserts: (a) the call returns an `Order` instance, (b) `OrderRepository::createOrder()` was invoked with the given data, (c) `JobService::dispatch()` was invoked once with `'print'`, `PrintOrderJob::class`, and an array containing `order_id`.
5. `tests/Unit/OrderValidatorTest.php` asserts `validateOrderData()` returns `true` for a valid payload and `false` for: missing `table`, missing `items`, an item missing `id`/`quantity`, and an item with an invalid `dining_option`. It also asserts `errors()` is non-empty immediately after any `false` result.
6. Running `vendor/bin/phpunit` inside the `web` container (`docker compose exec web vendor/bin/phpunit`), with the container's normal `.env`-driven database available, exits `0` with all tests passing.

## Non-functional requirements

Not applicable — this is test infrastructure with no runtime/production impact (dev dependency only; no `src/` behavior changes).

## User flows

Not applicable — no user-facing behavior changes; this affects only the development workflow.

## API changes

Not applicable — no endpoint, request, or response changes. `GET /api/menu` is exercised as-is, not modified.

## Data model and migrations

Not applicable — no schema changes. The smoke test only reads from the existing `menu_items`/`categories` tables via the existing `GET /api/menu` path; it does not require new tables or seed data (an empty menu is still a valid `200` response).

## Architecture and affected components

- `composer.json` — add `require-dev.phpunit/phpunit`.
- `phpunit.xml` — new file, project root.
- `tests/bootstrap.php` — new file.
- `tests/Smoke/ApiTest.php` — new file.
- `tests/Unit/OrderServiceTest.php` — new file.
- `tests/Unit/OrderValidatorTest.php` — new file.
- `docker-compose.yml` — the `web` service only bind-mounts `public/`, `src/`, `common/`, `legacy/`, `bin/`, `composer.json`, `composer.lock`, `.env` (confirmed against both `docker-compose.yml` and `Dockerfile`'s build-time `COPY` list — neither includes `tests/` or `phpunit.xml`); discovered during implementation that `tests/` and `phpunit.xml` must be added as bind mounts too, or `docker compose exec web vendor/bin/phpunit` cannot see them at all. This wasn't anticipated when the spec was drafted — see Implementation log.
- No `src/` files are modified.

## Security considerations

- Tests run against the real dev database over the same trusted local/Docker network as normal development — no new exposure.
- `tests/bootstrap.php` only reads configuration (via the same `.env`/environment mechanism `public/index.php` already uses); it does not print, log, or persist secret values anywhere.
- The smoke test is read-only (`GET /api/menu`) — it does not create, modify, or delete data in the shared dev database.

## Backward compatibility

Fully additive — no existing file's runtime behavior changes. `composer.json`'s existing `require`/`scripts` are untouched; only `require-dev` gains an entry.

## Acceptance criteria

1. `composer.json` lists `phpunit/phpunit` (`^11.0`) under `require-dev`.
2. `phpunit.xml` exists and is a valid PHPUnit 11 configuration (bootstrap + at least the `Smoke` and `Unit` suites).
3. `tests/Smoke/ApiTest.php` exists and, when run, issues a `GET /api/menu` request through the real app and asserts HTTP `200`.
4. `tests/Unit/OrderServiceTest.php` exists and asserts a valid `createOrder()` call succeeds and triggers the expected collaborator calls (see Functional requirement 4).
5. `tests/Unit/OrderValidatorTest.php` exists and asserts invalid input is rejected (`validateOrderData()` returns `false`, `errors()` non-empty) for each case listed in Functional requirement 5, and valid input is accepted.
6. `docker compose exec web vendor/bin/phpunit` exits with status `0` (all tests green).

## Implementation plan

1. `docker compose exec web composer require --dev phpunit/phpunit:^11.0` (adds to `composer.json`/`composer.lock`, installs into `vendor/`).
2. Create `tests/bootstrap.php`: require `vendor/autoload.php`; `Dotenv::createImmutable(dirname(__DIR__))->safeLoad()`; bridge `getenv()` into `$_ENV` for any key not already set.
3. Create `phpunit.xml` at the project root: `bootstrap="tests/bootstrap.php"`, test suites `Smoke` (`tests/Smoke`) and `Unit` (`tests/Unit`).
4. Create `tests/Unit/OrderValidatorTest.php` (no DB dependency — write and run this first to get the suite working end-to-end quickly).
5. Create `tests/Unit/OrderServiceTest.php` using `createMock()` on `OrderRepository`, `PrintService`, `JobService`.
6. Create `tests/Smoke/ApiTest.php`, booting `App::get()` with a real `Settings` instance and dispatching a synthetic PSR-7 `GET /api/menu` request via `$app->handle()`.
7. Run `docker compose exec web vendor/bin/phpunit` and fix any failures until green.

## Testing and validation strategy

This spec *is* the introduction of automated test infrastructure, so "testing" and "validation" are the same activity here: run `docker compose exec web vendor/bin/phpunit` against the running dev stack (`docker compose up -d`) and confirm all tests pass, with actual command output captured in `Validation evidence` below once implementation is done.

## Rollout and rollback

Additive change on branch `013`. Rollback is a plain revert of the new files and the `composer.json`/`composer.lock` diff; no data/schema impact.

## Open questions

None blocking.

## Task checklist

- [x] `composer require --dev phpunit/phpunit:^11.0`
- [x] `tests/bootstrap.php`
- [x] `phpunit.xml`
- [x] `tests/Unit/OrderValidatorTest.php`
- [x] `tests/Unit/OrderServiceTest.php`
- [x] `tests/Smoke/ApiTest.php`
- [x] `docker compose exec web vendor/bin/phpunit` green

## Implementation log

- 2026-08-19: `docker compose exec web composer require --dev phpunit/phpunit:^11.0` installed PHPUnit 11.5.56 (and its transitive deps) directly into the running container's `vendor/` — but `vendor/` isn't a bind-mounted volume, so this alone was ephemeral.
- 2026-08-19: **Deviation from the plan** — discovered `docker-compose.yml`'s `web` service does not bind-mount `tests/` or a root `phpunit.xml` (confirmed against both `docker-compose.yml` and `Dockerfile`'s build-time `COPY` list, neither of which reference them). Without a mount, `docker compose exec web vendor/bin/phpunit` could not see any file under `tests/` at all (`Test file "tests/Unit/OrderValidatorTest.php" not found`). Added two lines to `docker-compose.yml`'s `web.volumes`: `./tests:/var/www/html/tests` and `./phpunit.xml:/var/www/html/phpunit.xml`, then `docker compose up -d` to recreate the container with the new mounts (this reset the container's non-mounted `vendor/`, so `docker compose exec web composer install` was re-run afterward — reinstalled everything, including `phpunit/phpunit`, from the now-updated `composer.lock`). This is the one change outside the spec's original `Architecture and affected components` list; recorded there too.
- 2026-08-19: `tests/bootstrap.php` written to load `.env` via `safeLoad()` (not `load()`, so a missing `.env` — e.g. spec 005's future CI job — doesn't throw) and bridge `getenv()` into `$_ENV` for CI-supplied real environment variables, per the spec's proposed behavior; not exercised against an actual CI environment in this pass (that's spec 005's job), only confirmed to not break the normal `.env`-driven local/Docker path.
- 2026-08-19: `tests/Unit/OrderValidatorTest.php` written first (no DB dependency) specifically to get the PHPUnit/bootstrap/`phpunit.xml` wiring validated end-to-end before adding the DB- and app-booting tests, per the plan's own ordering rationale.
- 2026-08-19: `tests/Unit/OrderServiceTest.php` mocks `OrderRepository`, `PrintService`, `JobService` via PHPUnit's `createMock()` on the concrete classes directly (none of the three have interfaces) — works as expected, no interface extraction was needed.
- 2026-08-19: `tests/Smoke/ApiTest.php` boots the real app via `(new App(new Settings()))->get()` and dispatches a synthetic `GET /api/menu` request built with `Slim\Psr7\Factory\ServerRequestFactory`, then calls `$app->handle($request)` directly (no real HTTP call, no running web server needed) — asserts `getStatusCode() === 200`. Requires the real dev database (via the already-running `db` service) to be reachable, which it was.

## Validation evidence

All commands run via `docker compose exec web ...` against the already-running dev stack (`db` + `web` containers, confirmed up via `docker compose ps` before starting).

- AC 1 (`composer.json` lists `phpunit/phpunit` `^11.0` under `require-dev`): `git diff composer.json` shows:
  ```
  +  "require-dev": {
  +    "phpunit/phpunit": "^11.0"
  +  }
  ```
- AC 2 (`phpunit.xml` valid PHPUnit 11 config): `docker compose exec web vendor/bin/phpunit tests/Unit/OrderValidatorTest.php` output shows `Configuration: /var/www/html/phpunit.xml` and the run completes normally (see AC 5 below) — a malformed config would fail to even start PHPUnit.
- AC 3 (smoke test, `GET /api/menu` → 200): `docker compose exec web vendor/bin/phpunit tests/Smoke/ApiTest.php` →
  ```
  .                                                                   1 / 1 (100%)
  OK (1 test, 1 assertion)
  ```
- AC 4 (`OrderServiceTest`, valid `createOrder()` path + collaborator calls): `docker compose exec web vendor/bin/phpunit tests/Unit/OrderServiceTest.php` →
  ```
  .                                                                   1 / 1 (100%)
  OK (1 test, 7 assertions)
  ```
  (7 assertions = the returned-`Order` identity check plus PHPUnit's internal expectation assertions for the two mocked method calls.)
- AC 5 (`OrderValidatorTest`, valid accepted / each invalid case rejected with non-empty `errors()`): `docker compose exec web vendor/bin/phpunit tests/Unit/OrderValidatorTest.php` →
  ```
  .....                                                               5 / 5 (100%)
  OK (5 tests, 9 assertions)
  ```
- AC 6 (full suite green): `docker compose exec web vendor/bin/phpunit` →
  ```
  .......                                                             7 / 7 (100%)
  OK (7 tests, 17 assertions)
  ```
- Syntax check on every new PHP file: `docker compose exec web php -l` on `tests/bootstrap.php`, `tests/Unit/OrderValidatorTest.php`, `tests/Unit/OrderServiceTest.php`, `tests/Smoke/ApiTest.php` → "No syntax errors detected" for all four.
- `docker compose exec web composer validate --no-check-publish` → "./composer.json is valid" (one pre-existing, unrelated warning about a missing `license` field, not introduced by this change).
- Diff reviewed (`git status --short` / `git diff composer.json docker-compose.yml`) — confirmed scope is exactly: `composer.json` (`require-dev` addition), `docker-compose.yml` (two new volume mounts, the one documented deviation), `phpunit.xml` (new), `tests/` (new). No `src/` file was modified.
