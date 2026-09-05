# Architecture

Deep dive into how GastroFlow is actually built — not an idealized version. See the [README](../README.md) for the short version, and [`specs/000-project-baseline.md`](../specs/000-project-baseline.md) for the code-verified snapshot this document is derived from.

## Request lifecycle

Slim bootstraps in `public/index.php` → `App\App::get()` (`src/App.php`) → `App\Routes::register()` (`src/Routes.php`). The detail that matters most: **not everything goes through Slim**. `public/.htaccess` serves any existing file or directory directly, so `public/cashier/`, `public/kitchen/`, and `public/admin/*.php` are plain PHP/Alpine.js view scripts executed directly by Apache. Only `/api/*` and the `/` redirect are actual Slim routes.

```mermaid
flowchart TD
    Client(["Browser<br/>Cashier / Kitchen / Admin"]) --> Htaccess{"public/.htaccess<br/>file or dir exists?"}

    Htaccess -->|"yes"| Views["Static PHP view<br/>public/cashier, public/kitchen,<br/>public/admin/*.php (Alpine.js, outside Slim)"]
    Htaccess -->|"no, fall through"| Front["public/index.php<br/>Slim front controller"]

    Views -->|"fetch() JSON calls"| Front

    Front --> Boot["App::get() — src/App.php<br/>DI container, Eloquent boot,<br/>CORS + JSON body middleware"]
    Boot --> Routes["Routes::register() — src/Routes.php"]

    Routes --> PublicRoutes["Public routes<br/>/api/menu, /api/orders,<br/>/api/kitchen/*, /api/login"]
    Routes --> Guard{"JwtMiddleware<br/>/api/admin/*"}

    PublicRoutes --> Controllers["Controllers"]
    Guard -->|"valid JWT"| Controllers

    Controllers --> Services["Services"]
    Services --> Data["Repositories /<br/>Eloquent Models"]
    Data --> DB[("MySQL 8.0")]

    Services -.->|"async on order create"| Jobs["jobs table + bin/worker"]
    Jobs --> Printer["ESC/POS thermal printer<br/>mike42/escpos-php"]

    Services -.->|"signal file"| SSE["public/api/events/stream.php<br/>SSE, polls a temp file"]
    SSE -.->|"near real-time update"| Views
```

Two things worth stating explicitly:

- **Only `/api/admin/*` requires a JWT.** `/api/orders*`, `/api/menu`, `/api/kitchen/*` are intentionally unauthenticated — a deliberate choice for a single-location, trusted-network deployment, not an oversight.
- **`public/.htaccess` bypassing Slim is deliberate**, not incidental: the three panels are static Alpine.js shells that only call the JSON API, so there's no reason to pay routing/middleware overhead for them. The cost is that two separate request-handling paths exist, and anything needing CORS/auth middleware has to live under `/api/*`.

## Authentication

The full lifecycle, documented in one place (`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Authentication hardening"):

- **Issuance**: `POST /api/login` (`AuthController::login`) looks up the user by username, verifies the password with `password_verify()`, and issues an HS256 JWT via `firebase/php-jwt` containing `sub`/`username`/`role`, valid for 8 hours (`'exp' => time() + 3600 * 8`).
- **Verification**: `JwtMiddleware`, applied to the `/api/admin` route group (plus the standalone `PATCH /api/admin/account/password` route — see below), distinguishes three outcomes: no `Authorization: Bearer ...` header → `401` `"Token não fornecido."`; a syntactically valid but expired token → `401` `"Token expirado."`; anything else that fails to decode (bad signature, malformed, wrong algorithm) → `401` `"Token inválido."`. On success, the decoded payload is attached to the request as the `user` attribute for downstream handlers.
- **Password hashing**: `password_hash($password, PASSWORD_BCRYPT)` everywhere a password is stored (`bin/create-admin`, `AuthController::changePassword`), verified with `password_verify()`. No custom hashing scheme.
- **Password changes**: `PATCH /api/admin/account/password` (JWT-protected), added to close the "password changes" item in the roadmap's authentication checklist. Requires the correct `current_password` before accepting a `new_password` (minimum 8 characters) — this stops a leaked/stolen JWT alone from letting an attacker lock out the legitimate user, since there is no server-side session to separately invalidate. Changing a password does **not** invalidate the JWT used to make the request, or any other JWT already issued to that user — it remains valid until its own 8-hour expiry. This is a deliberate limitation, not an oversight: revoking it would require a server-side token-revocation mechanism (see next point).
- **Logout**: there is no `POST /api/logout` route and no server-side token-revocation mechanism of any kind. JWTs are stateless and self-contained — nothing in `Middleware/` queries a database or cache to validate a token beyond its signature and expiry. "Logging out" today means the client discards its own token; the worst case of a leaked token is bounded by its 8-hour expiry. This is a deliberate choice matching the project's "Keep the stack proportional" principle (`docs/ROADMAP.md`) — a revocation list would need a shared store (Redis, a DB table) for a single-location deployment that doesn't otherwise need one. If this stops being acceptable (e.g., multi-location deployments, longer token lifetimes), it would need its own dedicated design, not a small addition here.
- **Login throttling**: **not implemented.** `POST /api/login` has no rate limiting, failed-attempt counting, or lockout today. This is a known, tracked gap (`docs/ROADMAP.md`'s "Authentication hardening" item lists it explicitly) — deliberately deferred to its own follow-up spec rather than bundled into the work above, since it needs its own design decisions (per-IP vs per-username tracking, storage mechanism — this project has no Redis/cache, so it likely needs a small schema addition — and lockout duration).

## Order lifecycle

`orders.status` (`common/sql/001_schema.sql`, migrated by `common/migrations/013_order_cancellation.sql`) is a 3-value enum, each reachable through an actual code path — the schema previously also had unused `preparing`/`ready` values (spec 020 removed them, per `docs/ROADMAP.md`'s "Order lifecycle" item: don't keep statuses "merely for theoretical completeness").

```mermaid
stateDiagram-v2
    [*] --> pending: POST /api/orders
    pending --> done: POST /api/orders/{id}/complete
    done --> pending: POST /api/orders/{id}/uncomplete
    pending --> cancelled: POST /api/orders/{id}/cancel
    done --> cancelled: POST /api/orders/{id}/cancel
    cancelled --> [*]
```

- **`cancelled` is terminal.** No route transitions an order out of it — `OrderRepository::completeOrder()`/`::uncompleteOrder()`/`::cancelOrder()` each check the current status first and throw `\DomainException` (→ `409`) if it's already `cancelled`. This is a deliberate design choice (nothing in this project's real workflow needs "un-cancel"), not a roadmap requirement — see `specs/020-order-lifecycle.md`'s Non-goals.
- **Cancellation preserves the row.** `POST /api/orders/{id}/cancel` replaced the old `DELETE /api/orders/{id}` (hard delete, no trace left) — a cancelled order stays in `orders`, just excluded from the kitchen's `pending`/`done` views and from `ReportService`'s revenue queries (both already filtered by exact status value, unaffected by adding a third one).
- **`preparing`/`ready` were never implemented** as a real kitchen workflow (no UI, no code path) — removed from the schema rather than kept as dead options. Reintroducing a multi-stage kitchen flow would be new scope, not a gap in what's documented here.

## Layers under `src/`

Coverage is real but uneven — this reflects what's actually implemented, not a target architecture:

| Layer | What exists | Gap |
|---|---|---|
| `Controllers/` | 8: Admin, Auth, Dish, Ingredient, Kitchen, Menu, Order, Report | `Dish`/`Ingredient` have **no route registered anywhere** in `src/Routes.php` (confirmed by grep, spec 024) — both are entirely unreachable dead code today, not just under-layered |
| `Services/` | 6: Job, Kitchen, Menu, Order, Print, Report | — |
| `Repositories/` | 2: Menu, Order | `Dish`/`Ingredient` controllers call Eloquent models directly — no repository layer for them (moot while unreachable, above) |
| `Validators/` | 1: `OrderValidator` (wraps `vlucas/valitron`) | No validator for menu items, ingredients, or settings |
| `Middleware/` | 4 (PSR-15): Cors, JsonBodyParser, Jwt, Role | `Jwt`/`Role` apply only to the `/api/admin` route group |
| `Models/` | 9 Eloquent models: User, Category, MenuItem, Ingredient, Order, OrderItem, OrderNumberCounter, Setting, Job | — |

Extending `Repositories/`/`Validators/` to cover the remaining domains is tracked in `docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase ("Controller responsibilities", "Persistence boundaries"), not assumed to already be done.

## Real-time kitchen updates (SSE)

The kitchen's "real-time" update is a signal file, not a message queue: `OrderService` writes a JSON file to `sys_get_temp_dir()` on order creation/completion, and `public/api/events/stream.php` polls it to emit Server-Sent Events. This works correctly for a single app instance and zero extra infrastructure, but is not safe under concurrent writes or a multi-instance deployment — `docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` phase ("Realtime reliability") plans to replace it with Redis pub/sub or a MySQL-backed `events` table.

## Background jobs and printing

`PrintService` builds an ESC/POS receipt (header, items, packaging labels, total, footer) via `mike42/escpos-php` over `NetworkPrintConnector`. Printing is dispatched asynchronously: `src/Jobs/PrintOrderJob.php` + `src/Services/JobService.php` write to a DB-backed `jobs` table (migration `common/migrations/007_jobs.sql`), processed by the long-running `bin/worker` CLI script — not started by `docker compose up -d` alone, it must be supervised separately. Print failures are logged via Monolog but intentionally never thrown: a receipt failing to print should not fail the order.

## Persistence

Eloquent (`illuminate/database ^10`) via `Illuminate\Database\Capsule\Manager`, booted in `src/Database.php`. Initial schema: `common/sql/001_schema.sql`, mounted into the `db` container's `docker-entrypoint-initdb.d` (only runs on first volume init). Incremental changes: 8 files under `common/migrations/*.sql`, applied by the custom `App\Database\MigrationRunner` through `bin/migrate`, which tracks applied files in a `migrations` table. There is no ORM-style migration framework — migrations are forward-only, with no `down()`/rollback semantics.

`common/config.php` and `common/db.php` are legacy raw-PDO helpers with no callers found anywhere in `src/` or `public/` — dead code, not yet removed (open question in `specs/000-project-baseline.md`: whether something external still depends on them).

## Project structure

```
GastroFlow
├── public/                  # DocumentRoot. Slim entry point AND static view scripts.
│   ├── index.php            # Slim front controller — only path that goes through App::get()
│   ├── .htaccess             # Routes existing files/dirs directly, everything else to index.php
│   ├── cashier/, kitchen/, admin/   # Alpine.js views, executed as plain PHP outside Slim
│   ├── api/docs/             # Static OpenAPI viewer (openapi.yaml)
│   ├── api/events/stream.php # SSE endpoint, plain PHP script, also outside Slim
│   └── assets/               # CSS, JS, images
├── src/                      # PSR-4 application code (App\)
│   ├── Controllers/, Services/, Repositories/, Validators/, Middleware/, Models/   # see table above
│   ├── Jobs/                   # PrintOrderJob — processed by bin/worker
│   └── App.php, Routes.php, Settings.php, Database.php, Database/MigrationRunner.php
├── common/
│   ├── sql/001_schema.sql     # Initial schema — mounted into MySQL's first-init only
│   ├── migrations/*.sql       # Incremental migrations, applied via bin/migrate
│   └── config.php, db.php     # Legacy raw-PDO helpers with no callers — dead code
├── bin/                        # migrate, worker — CLI entry points
├── legacy/                     # Empty, tracked — kept as a marker, not in active use
├── specs/                       # Spec-driven development: baseline, template, and one file per change
├── docs/                         # This directory
├── .claude/skills/               # /spec-plan and /spec-implement skill definitions
├── CLAUDE.md, ROADMAP.md, CHANGELOG.md, COMMIT_CONVENTION.md
├── Dockerfile                    # php:8.2-apache
└── docker-compose.yml             # db (MySQL 8.0) + web (container: restaurant_web)
```

## Known architectural limitations

Named, not hidden — tracked in `specs/000-project-baseline.md` and `docs/ROADMAP.md`:

- CORS defaults to `*` when `CORS_ALLOWED_ORIGIN` is unset; configurable per spec 001, but the permissive default is still an open gap (`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase doesn't yet name a fix for the default itself).
- No lint/static-analysis tooling (`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` phase: "Static analysis", "Code style"). The hardcoded JWT-secret fallback and the lack of a test suite/CI pipeline, both previously listed here, were fixed by specs 002, 004 and 005 (`v1.5.6`).
- `IngredientController` exists but no `/api/admin/ingredients*` route was found wired in `src/Routes.php` — not confirmed whether it's reachable another way.
- Migrations are forward-only; no rollback mechanism.
- Signal-file SSE and the DB-backed job queue both assume a single app instance.
