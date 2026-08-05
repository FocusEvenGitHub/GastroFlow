# Spec 000 — Project baseline

## Metadata

- Status: Implemented
- Created: 2026-08-05
- Updated: 2026-08-05
- Owner: GastroFlow maintainers
- Related issue: Not applicable — this spec predates the issue-tracking workflow it documents
- Related branch: Not applicable — no branch; captured directly on `master`

## Context

This is not a feature spec. It is a one-time snapshot of GastroFlow as it existed when the spec-driven development workflow (`specs/`, `CLAUDE.md`, `/spec-plan`, `/spec-implement`) was introduced, so that future specs have a documented, code-verified baseline to reference instead of re-deriving it each time. Every claim below is labeled as one of: **confirmed in code**, **described only in README**, **partially implemented**, **not found**, or **not confirmed**.

## Problem

Not applicable — a baseline records current state, it does not describe a problem to fix.

## Goals

- Record the confirmed architecture, entities, endpoints, flows, and commands as of this date.
- Make gaps and inconsistencies explicit instead of letting them stay implicit.

## Non-goals

- Not a roadmap or backlog. An untracked `ROADMAP.md` already exists locally with planned architectural work (tests, CI, repository/validator completeness, hardcoded-secret removal); this baseline does not duplicate or supersede it.
- Not a proposal to change anything described here.

## Current behavior

### Purpose (confirmed in code + README)

A restaurant order-management web app covering cashier order entry, kitchen order display, menu/ingredient administration, sales reporting, and automatic thermal-receipt printing. Confirmed by the route map (`src/Routes.php`) and the module set under `public/` and `src/Controllers/`.

### Modules (confirmed in code)

- **Cashier** (`public/cashier/`) — static Alpine.js page, no dedicated backend controller; calls `/api/menu` and `/api/orders`.
- **Kitchen** (`public/kitchen/`) — Alpine.js page backed by `KitchenController`/`KitchenService` plus the generic orders endpoints and an SSE stream.
- **Admin** (`public/admin/`) — menu/ingredients/settings/logs/reports pages backed by `MenuController`, `IngredientController`, `AdminController`, `ReportController`.
- **API** (`public/api/`) — `public/api/docs/` (static OpenAPI viewer, `openapi.yaml`) and `public/api/events/stream.php` (SSE endpoint, plain PHP script outside the Slim app).

### Architecture observed (confirmed in code)

- Framework: **Slim 4**, bootstrapped in `public/index.php` → `App\App::get()` (`src/App.php`): builds a PHP-DI container, boots Eloquent (`Database::boot()`), registers `JsonBodyParserMiddleware` and `CorsMiddleware`, sets a JSON-only error handler, then calls `App\Routes::register()` (`src/Routes.php`). Not Laravel, despite a leftover comment in `Dockerfile:19` ("Ajuste do DocumentRoot para a pasta public do Laravel/Framework").
- Request flow at the web-server level: `public/.htaccess` serves any existing file/directory directly and only routes everything else through `index.php` (the Slim front controller). This means `public/cashier/index.php`, `public/kitchen/index.php`, and `public/admin/*.php` are plain PHP/HTML view scripts executed directly by Apache — they are **not** Slim routes, only the JSON API under `/api/*` and the `/` redirect go through Slim.
- Layers under `src/`, matching what actually exists (not the idealized README description):
  - `Controllers/` — 8 classes: `AdminController`, `AuthController`, `DishController`, `IngredientController`, `KitchenController`, `MenuController`, `OrderController`, `ReportController`.
  - `Services/` — 6: `JobService`, `KitchenService`, `MenuService`, `OrderService`, `PrintService`, `ReportService`.
  - `Repositories/` — only 2: `MenuRepository`, `OrderRepository`. **Partially implemented**: `IngredientController`/`DishController` talk to Eloquent models directly, with no `IngredientRepository`.
  - `Validators/` — only 1: `OrderValidator` (wraps `Valitron\Validator`). No validator exists for menu items, ingredients, or settings — **partially implemented** relative to what the README's "Validators" bullet implies.
  - `Middleware/` — 3: `CorsMiddleware`, `JsonBodyParserMiddleware`, `JwtMiddleware` (PSR-15, applied only to the `/api/admin` route group).
  - `Models/` — 8 Eloquent models (see Entities below).
  - Bootstrap/support classes not mentioned in the README's structure diagram but present and load-bearing: `src/App.php`, `src/Routes.php`, `src/Settings.php`, `src/Database.php`, `src/Database/MigrationRunner.php`, `src/Jobs/PrintOrderJob.php`.
- `common/config.php` and `common/db.php` — **confirmed dead code**: they define a raw PDO connection helper (`getPDO()`), but a repo-wide search found no `require`/`include` of `common/config.php` anywhere, and `common/db.php` is only referenced by itself. The live app uses `src/Database.php` (Eloquent Capsule) exclusively. Not deleted as part of this baseline — flagged only.
- `legacy/` exists as an empty tracked directory.

### Order flow (confirmed in code)

`POST /api/orders` (`OrderController::store` → `OrderValidator` → `OrderService` → `OrderRepository`, `src/Repositories/OrderRepository.php`): validates payload (table, items with `dining_option` ∈ `local`/`viagem_simples`/`viagem_vip`), writes the order and its items inside a DB transaction, dispatches an async print job (`JobService` + `PrintOrderJob`) when printing is enabled, and notifies the kitchen by writing a JSON file to `sys_get_temp_dir()` that the SSE stream (`public/api/events/stream.php`) polls — a deliberate, non-abstracted mechanism, not a message queue. `GET /api/orders`, `POST /api/orders/{id}/complete`, `POST /api/orders/{id}/uncomplete`, `GET /api/orders/next-number` round out the flow.

### Cashier flow (confirmed in code, frontend-only)

`public/cashier/` is an Alpine.js SPA-style page with no dedicated backend module: it reads the menu via `GET /api/menu` and posts orders via `POST /api/orders`. There is no `CashierController`/`CashierService` — "cashier" is a UI role, not a backend concept.

### Kitchen flow (confirmed in code)

`public/kitchen/` polls `GET /api/orders?status=pending` and `GET /api/kitchen/food-summary` (`KitchenController::foodCategorySummary` → `KitchenService`, which aggregates pending order items into a per-category production summary, resolving dish "components"), and listens to the SSE stream for near-real-time updates. Orders are marked done/reopened via `/api/orders/{id}/complete` and `/api/orders/{id}/uncomplete`.

### Menu administration (confirmed in code)

`MenuController` + `MenuService` + `MenuRepository` under `/api/admin/menu`, `/api/admin/items`, `/api/admin/items/{id}` (update/delete), and `/api/admin/items/{id}/components` (get/update) for dish composition. `IngredientController` (route not present in the reviewed `src/Routes.php` group list beyond the ones enumerated below — **not confirmed** whether ingredient CRUD routes are registered elsewhere; only the routes literally present in `src/Routes.php` are listed in "Endpoints" below). All admin menu routes sit behind `JwtMiddleware`.

### Authentication (confirmed in code)

`POST /api/login` (unauthenticated, outside the `/api` group, wired directly in `src/Routes.php`) → `AuthController::login`: looks up `App\Models\User` by `username`, checks `password_verify()`, issues an HS256 JWT (8h expiry) via `firebase/php-jwt` containing `sub`/`username`/`role`. `JwtMiddleware` guards the entire `/api/admin` route group. **Confirmed security gap**: `src/Routes.php:19` falls back to a hardcoded secret (`'your-secret-key-change-me'`) when `$_ENV['JWT_SECRET']` is unset. A default `admin`/`admin123` user (bcrypt hash) is seeded by `common/sql/001_schema.sql` (lines ~148–150), matching the README's stated default credentials.

### Reports (confirmed in code)

`ReportController` + `ReportService`, routes: `sales`, `top-items`, `dining-options`, `summary`, `peak-hours`, `prep-time`, `month-comparison`, all under `/api/admin/reports/*` behind JWT. This is the most recently touched module (commit history) and the only one using constructor property promotion and a shared private `json()` response helper consistently.

### Thermal printing (confirmed in code)

`PrintService` builds an ESC/POS receipt (header, items, packaging labels, total, footer) via `mike42/escpos-php` over `NetworkPrintConnector`, plus a `printTestPage()` diagnostic used by `POST /api/admin/settings/test-print`. Printing is dispatched asynchronously through a custom DB-backed job queue: `src/Jobs/PrintOrderJob.php` + `src/Services/JobService.php` + `src/Models/Job.php` (table `jobs`, migration `common/migrations/007_jobs.sql`), processed by the long-running `bin/worker` CLI script. Print failures are logged via Monolog but intentionally never thrown ("best effort" per the service's own logic).

### Persistence (confirmed in code)

Eloquent (`illuminate/database ^10`) via `Illuminate\Database\Capsule\Manager`, booted in `src/Database.php` from `Settings` (`DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`; MySQL, `utf8mb4`). Initial schema: `common/sql/001_schema.sql`, mounted into the `db` container's `docker-entrypoint-initdb.d` (only executes on first volume init — confirmed in `docker-compose.yml`). Incremental changes: 8 files in `common/migrations/*.sql` (`001_ingredients.sql` … `008_order_items_price.sql`), applied by the custom `App\Database\MigrationRunner` (`src/Database/MigrationRunner.php`) through `bin/migrate`, which tracks applied files in a `migrations` table. There is no ORM-style migration framework (no up/down classes) and no formal seeding mechanism beyond the one admin row in `001_schema.sql`.

### Main entities (confirmed in code, via `$table`/`$fillable` on each Eloquent model)

| Model | Table | Key fillable fields |
|---|---|---|
| `User` | `users` | `username`, `password`, `role` |
| `Category` | `categories` | `name`, `type` |
| `MenuItem` | `menu_items` | `category_id`, `name`, `description`, `price`, `available` |
| `Ingredient` | `ingredients` | `name`, `unit`, `category` |
| `Order` | `orders` | `table_number`, `customer_name`, `status` |
| `OrderItem` | `order_items` | `order_id`, `menu_item_id`, `quantity`, `notes`, `dining_option`, `unit_price`, `packaging_cost` |
| `Setting` | `settings` | `key`, `value` |
| `Job` | `jobs` | (queue bookkeeping fields; not enumerated in full here) |

### Main endpoints (confirmed in code, from `src/Routes.php`)

Public (no auth):
- `GET /` → 302 redirect to `/cashier/`
- `GET /api/menu`, `GET /api/orders`, `POST /api/orders`, `POST /api/orders/{id}/complete`, `POST /api/orders/{id}/uncomplete`, `GET /api/orders/next-number`
- `GET /api/kitchen/food-summary`
- `POST /api/login`

Behind `JwtMiddleware` (`/api/admin/*`):
- `GET /api/admin/menu`, `POST /api/admin/items`, `PATCH /api/admin/items/{id}`, `DELETE /api/admin/items/{id}`, `GET /api/admin/items/{id}/components`, `PUT /api/admin/items/{id}/components`
- `GET/PUT /api/admin/settings`, `POST /api/admin/settings/logo`, `POST /api/admin/settings/test-print`, `GET /api/admin/logs`
- `GET /api/admin/reports/{sales,top-items,dining-options,summary,peak-hours,prep-time,month-comparison}`

**Not confirmed**: no `/api/admin/ingredients*` routes were found in `src/Routes.php`, even though `src/Controllers/IngredientController.php` exists — either ingredient management is invoked another way not identified during this investigation, or its routes are not yet wired up. Flagged as an open question below rather than guessed at.

### Integrations (confirmed in code / not found)

- **Confirmed**: ESC/POS network thermal printer (`mike42/escpos-php`, `NetworkPrintConnector`), configured via the `settings` table/admin UI.
- **Confirmed, internal only**: Server-Sent Events at `public/api/events/stream.php`, driven by a temp-file signal written by `OrderService`, not an external broker.
- **Not found**: no payment gateway, SMS/email, or third-party POS integration anywhere in `src/` or `public/`.

### Known commands (confirmed in code/config)

- `docker compose up -d` — starts `db` (MySQL 8.0, port 3306) and `web` (built from `Dockerfile`, `php:8.2-apache`, port `8080:80`, container name **`restaurant_web`**).
- `docker compose exec web composer install|update|require|remove` — Composer inside the running `web` container (bind-mounted `composer.json`/`composer.lock`).
- `bin/migrate` — runs pending `common/migrations/*.sql` via `MigrationRunner`.
- `bin/worker [--once] [queue]` — processes the `jobs` table (e.g. print jobs); handles `SIGINT`/`SIGTERM`.
- `composer start` — `php -S 0.0.0.0:80 -t public` (the only script in `composer.json`).
- **No test or lint command exists** in `composer.json` — see Limitations below.

## Proposed behavior

Not applicable — this document describes existing behavior only.

## Functional requirements

Not applicable in the feature-spec sense — the confirmed functional behavior is documented above under "Current behavior" and serves as the reference point for future specs.

## Non-functional requirements

Not applicable as a forward-looking requirement list. Confirmed gaps relevant to non-functional quality: no automated tests, no static analysis/lint tooling, and one confirmed hardcoded-secret fallback (see Security considerations).

## User flows

Covered above under "Order flow", "Cashier flow", and "Kitchen flow" in Current behavior, to avoid duplication.

## API changes

Not applicable — no change proposed. The current API surface is documented under "Main endpoints" above.

## Data model and migrations

Covered above under "Persistence" and "Main entities" in Current behavior.

## Architecture and affected components

Covered above under "Architecture observed" in Current behavior.

## Security considerations

- **Confirmed**: hardcoded JWT secret fallback at `src/Routes.php:19` (`$_ENV['JWT_SECRET'] ?? 'your-secret-key-change-me'`) — if `JWT_SECRET` is ever unset in an environment, tokens become forgeable with a publicly known string.
- **Confirmed**: only the `/api/admin/*` group requires a JWT; `/api/orders*`, `/api/menu`, `/api/kitchen/*` are unauthenticated by design (consistent with a single-location, trusted-network deployment model, but worth naming explicitly).
- **Confirmed**: passwords are stored bcrypt-hashed (`password_verify()` in `AuthController`).
- **Not confirmed**: whether `.env` values are enforced/rotated in any deployed environment — out of scope to check (would require reading `.env`, which this workflow must never do).

## Backward compatibility

Not applicable — baseline snapshot, not a change.

## Acceptance criteria

This baseline is considered accurate if every claim above either cites a specific file (and, where shown, a line range) or is explicitly labeled `not confirmed`/`not found`. Any future discrepancy discovered between this document and the code must be reported and this file corrected, per the sync rule in `specs/README.md`.

## Implementation plan

Not applicable — no implementation, this is a documentation-only spec.

## Testing and validation strategy

Not applicable in the usual sense — see Validation evidence below for how this document's claims were checked (direct file reads and repo-wide searches, not runtime execution).

## Rollout and rollback

Not applicable — no code changes.

## Open questions

- **Not blocking**: `IngredientController.php` exists but no `/api/admin/ingredients*` (or similar) route was found in `src/Routes.php`. Is ingredient management reachable today, and if so, how?
- **Not blocking**: `common/config.php`/`common/db.php` appear to be dead code (no callers found). Should they be removed in a future spec, or are they intentionally kept for some external/manual use not visible in `src/`?
- **Not blocking**: the README's curl example uses `docker exec -it gastroflow_web composer update`, but `docker-compose.yml` sets `container_name: restaurant_web` — the container name in the README example does not match the actual compose file.
- **Not blocking**: `composer.lock` is gitignored and not tracked, while the Dockerfile and README workflow assume it exists and is meaningful for reproducible installs — its actual reproducibility across machines is not confirmed.

## Task checklist

Not applicable — this is a documentation snapshot, not a unit of implementation work.

## Implementation log

- 2026-08-05 — Initial baseline written by direct investigation: full reads of `README.md`, `composer.json`, `COMMIT_CONVENTION.md`, `.gitignore`, `docker-compose.yml`, `Dockerfile`, `src/Routes.php`, `src/App.php`, `src/Database.php`, `src/Settings.php`, `common/config.php`, `common/db.php`, `src/Controllers/AuthController.php`, `public/.htaccess`, `public/cashier/index.php`, `common/sql/001_schema.sql` (grep for seed data); repo-wide greps for `common/config`/`common/db` usage and for `$table`/`$fillable` across `src/Models/`; `git status`, `git log`, `git ls-files`, and `git check-ignore -v composer.lock` to confirm tracked/untracked state. No containers were started and no database was queried — all findings are static-code confirmations.

## Validation evidence

- `git status` → confirms `.github/` and `ROADMAP.md` are untracked; everything else at root is tracked.
- `git ls-files` → confirms no `tests/`, `phpunit.xml`, `.claude/`, `CLAUDE.md`, or `specs/` existed prior to this workflow being added.
- `git check-ignore -v composer.lock` → confirms `composer.lock` is ignored per `.gitignore:55` and not tracked.
- Direct file reads listed in Implementation log above back every "confirmed in code" claim; anything not backed by a direct read is explicitly marked `not confirmed`/`not found` rather than asserted.
- **Status is `Implemented`, not `Verified`**: this baseline was validated entirely through static reading of the repository, not by running `docker compose up -d`, hitting the live API, or querying the database. No claim here should be read as runtime-verified.
