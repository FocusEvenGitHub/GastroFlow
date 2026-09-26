# Spec 043 — Audit history for sensitive administrative operations

## Metadata

- Status: Verified
- Created: 2026-09-26
- Updated: 2026-09-26
- Owner: Henry
- Related issue: Not applicable (roadmap item — `docs/ROADMAP.md`, `v1.8.0 — Reliability & Quality`, "Audit history")
- Related branch: `043`

## Context

Tenth work item of the `v1.8.0 — Reliability & Quality` milestone, next in the roadmap's own
order after "Structured logging" (spec 042, merged). `docs/ROADMAP.md` (lines 995-1009) asks for
business-level audit logging of sensitive administrative operations, naming five examples: menu
price changes, restaurant settings changes, printer configuration changes, an order being
reopened, and a user being created. It explicitly says **technical logs and audit history should
remain conceptually separate** — this is a different mechanism from spec 042's `app.log`
correlation IDs, not an extension of it.

## Problem

No audit mechanism exists today, confirmed by a broad `grep` across `src/`, `public/`,
`common/`, `bin/`: every hit is either this document, another spec's prose, or an unrelated word
match — no table, no service, no log call anywhere records who changed what. An admin's menu
price edit, a settings change, or an order reopening leaves no trace of *who* did it or *when*,
only the current state.

## Goals

- A durable, queryable record of sensitive administrative actions: actor, action, affected
  entity, relevant details, timestamp.
- Cover the roadmap's five examples, mapped to the real endpoints that actually perform them
  (see **Current behavior** — two of the five collapse into one endpoint, one has no HTTP path
  at all today).
- Keep audit history and `app.log`/Monolog technical logging conceptually and physically
  separate, per the roadmap's explicit instruction.
- Give an admin an actual way to see the record — a write-only audit log nobody can read has no
  operational value.
- Never record a password, token, or `Authorization` header value.

## Non-goals

- Not a general-purpose "audit everything" framework, event sourcing, or a generic
  before/after diff engine. Only the specific, named sensitive operations below are covered.
- Not building a user-management API (create/edit/delete users over HTTP). One of the roadmap's
  five examples ("User created another user") has no HTTP path today — only the CLI
  `bin/create-admin` — and this spec does not invent that feature to give the example a home;
  see **Open questions** for the decision on covering the CLI path instead.
- No retention/pruning. Unlike `jobs` (spec 033) and `events` (spec 041), which exist for
  operational recovery/replay with deliberately short windows, an audit trail's entire purpose
  is to persist — pruning it would defeat the point.
- No full before/after diff of every changed field. Logging *which* fields were submitted and
  their new values is enough to answer "who changed the menu price and when" without a second
  read-before-write query at every call site (see **Proposed behavior** for the one place a
  "before" value is cheap enough to include anyway).

## Current behavior

Confirmed by reading the real code, not assumed:

- **"User changed menu price"** — the real endpoint is generic:
  `PATCH /api/admin/items/{id}` → `MenuController::updateItem()` (`src/Controllers/MenuController.php:53-74`)
  → `MenuService::updateItem()` → `MenuRepository::updateItem()` (`src/Repositories/MenuRepository.php:71-91`),
  which applies **whatever fields are present** in the request body (`name`, `description`,
  `price`, `available`, `category_name`) — there is no price-specific endpoint.
- **"User changed restaurant settings" and "User changed printer configuration" are the same
  endpoint.** `PUT /api/admin/settings` → `SettingsController::updateSettings()`
  (`src/Controllers/SettingsController.php:39-61`) writes an arbitrary set of key/value pairs via
  `Setting::setValue()`. `PrintService::getPrinterConfig()` (`src/Services/PrintService.php:175-182`)
  confirms `printer_ip`/`printer_port` are just two more keys in that same `settings` table —
  there is no separate printer-configuration endpoint.
- **"User reopened an order"** exists as a real, distinct endpoint:
  `POST /api/orders/{id}/uncomplete` → `OrderController::uncomplete()`
  (`src/Controllers/OrderController.php:86-99`) → `OrderService::uncompleteOrder()`.
- **"User created another user" has no HTTP endpoint at all.** `src/Routes.php` has no
  user-management route. The only way to create a user is `bin/create-admin <username> [role]`
  (read in full), an interactive CLI script run outside any HTTP request — no JWT, no
  `RequestContext`, no authenticated actor to attribute the action to.
- **Actor identification infrastructure already exists.** `App\Logging\RequestContext`
  (`src/Logging/RequestContext.php`, spec 042) holds `user_id`, set by `JwtMiddleware`
  (`src/Middleware/JwtMiddleware.php:42-45`) immediately after a successful JWT decode, resolved
  as a shared instance per request via the DI container (verified empirically in spec 042).
  `JwtMiddleware` already has the decoded payload's `username` in scope at that exact point
  (`AuthController::login`, `src/Controllers/AuthController.php:38-44`, mints `sub`/`username`/`role`)
  but `RequestContext` today only stores `user_id`, not `username`.
- **`SettingsController` has no Service layer** — it goes straight from Controller to the
  `Setting` Eloquent model. `MenuController`/`OrderController` do have one
  (`MenuService`/`OrderService`). `CLAUDE.md` forbids inventing a Service for a domain that
  doesn't already have one purely for symmetry.
- **Closest existing precedents for shape/style**: the `events` table (spec 041,
  `common/migrations/017_realtime_events.sql`) for "a structured, queryable record of things
  that happened", and spec 042's discipline of structured context (`event`/`order_id`/`job_id`
  as real fields, not concatenated text) for how to shape a record. Neither is reused directly —
  `events` is short-retention SSE replay state, not history.
- **`RoleMiddleware`** (`src/Middleware/RoleMiddleware.php`) is the existing role-gate mechanism
  for `/api/admin/*` routes (`admin`/`manager` per route, `src/Routes.php:68-92`).
- **`LogController`/`public/admin/logs.php`** (spec 033) is the existing precedent for "expose a
  log to the operator": `GET /api/admin/logs` plus a small Alpine.js/Bootstrap page. No
  equivalent exists for anything audit-related.

## Proposed behavior

1. A new `audit_log` table (migration `common/migrations/018_audit_log.sql`): `id` (PK,
   auto-increment), `user_id` (nullable — null for the CLI-originated `user.created` entry),
   `username` (nullable snapshot, captured at write time so a later username/role change never
   rewrites history), `action` (short machine-readable string, e.g. `menu_item.updated`,
   `settings.updated`, `order.reopened`, `user.created`), `entity_type`/`entity_id` (nullable —
   `settings.updated` touches multiple keys, not one entity), `details` (JSON — the submitted
   fields/values relevant to that action; never a password/token), `created_at`. No `updated_at`
   (append-only). Indexed on `created_at` and `action` for the read path below.
2. `App\Logging\RequestContext` gains `username`/`getUsername()`/`setUsername()`, set by
   `JwtMiddleware` alongside the existing `setUserId()` call — the JWT payload already carries
   it at that point, so this is a one-line addition, not a new lookup.
3. A new `App\Services\AuditLogger` (plain concrete class, no interface — unlike `EventPublisher`,
   nothing here needs more than one implementation, so an interface would be
   ceremony without purpose). One method: `record(string $action, ?string $entityType, ?int $entityId, array $details = []): void`,
   which reads the current actor from an injected `RequestContext` (reusing spec 042's
   infrastructure rather than re-deriving it from `$request->getAttribute('user')` at every call
   site) and writes one `AuditLog` row. When `RequestContext`'s `user_id` is null (the CLI path),
   the row is written with `user_id`/`username` both null and that fact is visible in `details`
   (`'source' => 'cli'`) rather than silently looking like an anonymous HTTP action.
4. Four call sites, each calling `AuditLogger::record()` directly after its operation succeeds —
   in the Controller for `SettingsController` (no Service layer exists there to put it in
   instead) and `OrderController` (thin controller, the action itself — "reopened" — has no
   further detail to compute), and in the Service for `MenuController`'s domain
   (`MenuService::updateItem()`, which already sits between the Controller and Repository) to
   keep the call next to the actual data mutation rather than duplicating "did this succeed"
   logic in the Controller. `bin/create-admin` calls `AuditLogger` directly too, with
   `RequestContext` simply never populated in that CLI process (no HTTP middleware ran), so
   `user_id`/`username` come through as `null` correctly, not as a bug.
   - `MenuService::updateItem()`: `action = 'menu_item.updated'`, `entity_type = 'menu_item'`,
     `entity_id = $id`, `details` = the subset of `$data` actually submitted (e.g.
     `{"price": 29.9}` or `{"available": false}`) — this answers "who changed the price and to
     what, and when" without a second read-before-write query.
   - `SettingsController::updateSettings()`: `action = 'settings.updated'`, no `entity_type`/
     `entity_id` (multiple keys, not one entity), `details` = `{key: {"old": ..., "new": ...}}`
     for each submitted key — the "old" value is already in hand here for free
     (`Setting::getValue($key)` before the existing `Setting::setValue($key, $value)` loop), so
     this one call site gets a real before/after at no extra query cost.
   - `OrderController::uncomplete()`: `action = 'order.reopened'`, `entity_type = 'order'`,
     `entity_id = $id`, `details = []` (the fact of reopening is the whole story).
   - `bin/create-admin`: `action = 'user.created'`, `entity_type = 'user'`, `entity_id` = the new
     user's id, `details = {"username": ..., "role": ..., "source": "cli"}`.
5. A read path, mirroring `LogController`/`logs.php` exactly: `GET /api/admin/audit-log`
   (`AuditLogController`, admin-only via `RoleMiddleware(['admin'])` — audit history is more
   sensitive than the technical log, which `manager` cannot see either today, so this matches
   the existing bar, not a new one) returning the most recent N entries, newest first, plus a
   small `public/admin/audit-log.php` Alpine.js/Bootstrap page styled like `logs.php` (table of
   timestamp/actor/action/entity/details instead of raw text lines). Without this, the feature
   has no operator-facing value at all — a write-only table nobody can query.

## Functional requirements

1. Updating a menu item's price (or any other field) through `PATCH /api/admin/items/{id}`
   writes an `audit_log` row with `action = 'menu_item.updated'`, the acting user's id/username,
   and the submitted field(s) in `details`.
2. Updating settings through `PUT /api/admin/settings` writes an `audit_log` row with
   `action = 'settings.updated'` and, for each changed key, its old and new value in `details`.
3. This applies identically whether the changed keys are printer configuration
   (`printer_ip`/`printer_port`) or any other setting — there is no special-cased "printer" audit
   action, matching the fact that they're the same endpoint.
4. Reopening an order through `POST /api/orders/{id}/uncomplete` writes an `audit_log` row with
   `action = 'order.reopened'` and the order's id.
5. Running `bin/create-admin` writes an `audit_log` row with `action = 'user.created'`, the new
   user's id, and `source: cli` in `details`, with `user_id`/`username` both null (no
   authenticated actor exists in a CLI process).
6. `GET /api/admin/audit-log` (admin role only) returns recent audit entries, newest first.
7. No `audit_log.details` value or column ever contains a password, token, or `Authorization`
   header value.
8. A failed operation (validation error, not-found, etc.) never writes an audit row — only a
   successful mutation does.

## Non-functional requirements

- **Observability/compliance**: the entire point of this spec.
- **Security**: audit rows are readable only by `admin` role (`RoleMiddleware(['admin'])`),
  stricter than the technical log viewer's implicit bar today (`LogController`'s route is also
  `admin`-only — same bar, not a new one).
- **Performance**: one extra `INSERT` per already-infrequent admin action; negligible.
- **Data integrity**: `username` is snapshotted at write time (same discipline as
  `order_items.item_name`, spec 023) so the historical record doesn't silently change meaning if
  a user's username or role changes later.

## User flows

Admin edits a menu item's price → the change is applied as before → a new row appears in
`GET /api/admin/audit-log`/`public/admin/audit-log.php` showing who changed which item and what
field, and when. Same shape for a settings change or reopening an order. An operator running
`bin/create-admin` sees no visible change in the CLI output beyond what already happens, but the
new user's creation is now visible in the same audit viewer with `source: cli`.

## API changes

New: `GET /api/admin/audit-log` — query params `?limit=N` (default 100, same clamping pattern as
`LogController::getLogs()`, `min(max(..., 10), 5000)`). Response:
`{"success": true, "entries": [{"id", "user_id", "username", "action", "entity_type", "entity_id", "details", "created_at"}, ...], "total": N}`.
No existing endpoint's request/response shape changes — the four write-side endpoints
(`PATCH /api/admin/items/{id}`, `PUT /api/admin/settings`, `POST /api/orders/{id}/uncomplete`)
keep returning exactly what they do today; the audit write is a side effect, not a response
change.

## Data model and migrations

New table `audit_log`, migration `common/migrations/018_audit_log.sql`:

```sql
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

No foreign key to `users`: a user could, in principle, be removed later (no delete-user feature
exists today, but nothing prevents adding one), and the row must survive that — this is exactly
why `username` is snapshotted rather than joined at read time.

## Architecture and affected components

New:
- `common/migrations/018_audit_log.sql`
- `src/Models/AuditLog.php` (Eloquent, `UPDATED_AT = null`, append-only — mirrors `Event.php`)
- `src/Services/AuditLogger.php`
- `src/Controllers/AuditLogController.php`
- `public/admin/audit-log.php`

Changed:
- `src/Logging/RequestContext.php` — add `username`.
- `src/Middleware/JwtMiddleware.php` — set `username` alongside `user_id` on successful decode.
- `src/Services/MenuService.php` — `updateItem()` calls `AuditLogger::record()`.
- `src/Controllers/SettingsController.php` — `updateSettings()` calls `AuditLogger::record()`.
- `src/Controllers/OrderController.php` — `uncomplete()` calls `AuditLogger::record()`.
- `bin/create-admin` — calls `AuditLogger::record()` after successful `User::create()`.
- `src/App.php` — no new binding expected (`AuditLogger` has only a `RequestContext` dependency,
  which is already autowirable; confirm during implementation whether the same PHP-DI
  optional-parameter pitfall from spec 042 applies here — `AuditLogger`'s constructor parameter
  should be **required**, not optional-with-default, specifically to avoid that trap, since
  every HTTP call site has a real `RequestContext` and only `bin/create-admin` needs the
  CLI case, which can pass `new RequestContext()` explicitly rather than relying on a silently-unresolved default).
- `src/Routes.php` — new `GET /api/admin/audit-log` route, `admin`-only.

Not touched: `OrderService`, `MenuController` itself (the call moves to `MenuService`),
`PrinterController` (confirmed above it has no configuration-writing endpoint of its own).

## Security considerations

- `details` never carries a password, token, or `Authorization` header — the four call sites
  only ever pass menu-item fields, setting key/value pairs (settings never store credentials;
  confirmed by reading `SettingsValidator`), an order id, or a new username/role.
- Read access is `admin`-only, stricter than `manager`, matching the existing bar for the
  technical log viewer.
- `user_id` has no foreign key, deliberately (see **Data model**), so this table can never block
  a future user-deletion feature.

## Backward compatibility

- Purely additive: one new table, one new endpoint, one new page, and a few extra `AuditLogger`
  calls after existing operations succeed. No existing request/response shape changes, no
  existing behavior changes.
- `RequestContext::setUsername()` is a new method on an existing class; nothing currently reads
  a `username` from it, so adding the field cannot break anything already relying on that class.

## Acceptance criteria

1. `PATCH /api/admin/items/{id}` with `{"price": 29.90}` creates one `audit_log` row with
   `action = 'menu_item.updated'`, `entity_id` equal to the item's id, and `details` containing
   `"price": 29.9`.
2. `PUT /api/admin/settings` with `{"settings": {"printer_ip": "10.0.0.5"}}` creates one
   `audit_log` row with `action = 'settings.updated'` and `details` containing
   `"printer_ip": {"old": <previous value>, "new": "10.0.0.5"}`.
3. `POST /api/orders/{id}/uncomplete` on a completed order creates one `audit_log` row with
   `action = 'order.reopened'` and `entity_id` equal to that order's id.
4. Running `bin/create-admin newuser cashier` (real, interactive) creates one `audit_log` row
   with `action = 'user.created'`, `entity_id` equal to the new user's id,
   `details.source = 'cli'`, and `user_id`/`username` both null.
5. A failed request to any of the three HTTP endpoints above (e.g. invalid payload, non-existent
   order id) creates **no** `audit_log` row.
6. `GET /api/admin/audit-log` with an `admin` token returns `200` with the entries created
   above, newest first; the same request with a `manager` token returns `403`.
7. `grep`-ing every `AuditLogger::record()` call site for the literal strings `password`,
   `token`, `Authorization` used as a **value** being passed finds none (manual review, same
   discipline as spec 042's AC9).
8. `public/admin/audit-log.php`, loaded in a browser against a real running instance with an
   admin token, renders the entries above with no JavaScript console errors.

## Implementation plan

1. `common/migrations/018_audit_log.sql` + `src/Models/AuditLog.php`.
2. `src/Logging/RequestContext.php` — add `username`; `src/Middleware/JwtMiddleware.php` — set it.
3. `src/Services/AuditLogger.php` + unit tests (record() writes the right row; CLI-context
   `RequestContext` with no user produces null actor fields correctly).
4. Wire the four call sites: `MenuService::updateItem()`, `SettingsController::updateSettings()`
   (capturing old values before the existing `setValue()` loop), `OrderController::uncomplete()`,
   `bin/create-admin`.
5. `src/Controllers/AuditLogController.php` + `GET /api/admin/audit-log` route
   (`RoleMiddleware(['admin'])`) + `public/admin/audit-log.php`.
6. Integration tests: each of the three HTTP call sites, real request → real `audit_log` row
   assertion (same in-process `IntegrationTestCase::request()` pattern as specs 035/041/042);
   the failure-path negative check (AC5); the read endpoint's role gate (AC6).
7. Manual: `bin/create-admin` run for real against the dev/test DB (AC4); browser check of
   `audit-log.php` (AC8).
8. PHPStan, PHP-CS-Fixer, full suite, CHANGELOG + docs sync (`docs/architecture.md`,
   `specs/000-project-baseline.md`, `README.md` — per `CLAUDE.md`'s release workflow, in the
   same PR).

## Testing and validation strategy

This project has real automated test infrastructure (PHPUnit, PHPStan, PHP-CS-Fixer, CI, a
MySQL-backed integration suite, and a Playwright browser suite — confirmed current, specs
004/005/034/035/040). This spec uses it directly:

- **Unit** (`tests/Unit/`): `AuditLogger::record()` against SQLite, asserting the written row's
  columns for both an HTTP-context `RequestContext` (with user/username) and a bare
  `RequestContext` (CLI case, null actor).
- **Integration** (`tests/Integration/`, real MySQL): each HTTP call site exercised through
  `IntegrationTestCase::request()` exactly as `OrderWorkflowTest`/`PrintingTest`/`RequestTracingTest`
  already do, asserting the resulting `audit_log` row via `Db::table('audit_log')`. The
  `bin/create-admin` path (AC4) cannot go through that helper (it's a CLI script, not an HTTP
  request) — verified manually instead, run for real against a disposable test-DB user, output
  and resulting row inspected directly.
- **Manual**: AC8 (`audit-log.php` browser rendering), same throwaway-Playwright-against-`web-e2e`
  technique already used for spec 042's `logs.php` check, since no interactive browser is
  available in an agent session.

## Rollout and rollback

Rollout: merge, run `bin/migrate`. Purely additive — no existing behavior changes, so no
coordinated multi-container rollout ordering is required (unlike spec 041's `EventPublisher`,
which needed `web` and `print-worker` to move together).

Rollback: revert the commit. `audit_log` can be left in place harmlessly; old code never reads
or writes it.

## Open questions

- **Blocking? No — decided above, recorded here for visibility.** Whether to cover
  "user created" via the CLI (`bin/create-admin`) rather than inventing an HTTP
  user-management endpoint: decided **yes, cover the CLI path**, since inventing a new API
  surface just to give one roadmap example an HTTP home would be scope the roadmap never asked
  for, while the CLI path is real, already sensitive, and trivially reachable.
- **Blocking? No.** Whether `MenuController`'s audit call belongs in the Controller or
  `MenuService`: decided **`MenuService`**, since it already exists as the layer between
  Controller and Repository for this domain, and this keeps the audit call next to the actual
  mutation rather than duplicating success/failure branching in the Controller. `SettingsController`
  and `OrderController` don't have (or don't need) that indirection, so their calls stay in the
  Controller — a deliberate, justified inconsistency across domains that mirrors each domain's
  real, pre-existing layering rather than forcing one uniform rule.

## Task checklist

- [x] 1. Migration `018_audit_log.sql` + `AuditLog` model
- [x] 2. `RequestContext`/`JwtMiddleware` — add `username`
- [x] 3. `AuditLogger` + unit tests
- [x] 4. Wire the 4 call sites (`MenuService`, `SettingsController`, `OrderController`, `bin/create-admin`)
- [x] 5. `AuditLogController` + route + `audit-log.php` viewer
- [x] 6. Integration tests (3 HTTP call sites + failure-path negative + role gate)
- [x] 7. Manual: `bin/create-admin` run + browser check of the viewer
- [x] 8. PHPStan, PHP-CS-Fixer, full suite, CHANGELOG + docs sync

## Implementation log

- **2026-09-25/26 — 1. The AC8 browser check first looked like a real hang, and wasn't one.**
  A throwaway Playwright check of `audit-log.php` got stuck forever on "Carregando...", with
  the page's own `fetch()` never resolving within a 10s wait. Investigated methodically before
  concluding anything: confirmed `curl` against the same endpoint succeeded instantly; confirmed
  the exact `Authorization`-bearing request WAS being sent by the browser (network event
  listener); confirmed via `Alpine.$data()` that `refresh()` really started (`loading: true`)
  but never reached its `finally`; confirmed the *server itself* logged real `200` responses
  with correct byte counts to those exact requests (Apache access log) both for
  `/api/admin/audit-log` and for the unrelated, long-working `/api/menu` — ruling out anything
  in this spec's own code. A longer-timeout probe then showed the true cause: ~10.5s of real
  round-trip latency on this local Docker Desktop/Windows setup (the `web-e2e` container had
  been up 27h across several sessions), not an infinite hang. Fixed by giving the manual-check
  script a realistic timeout (30s) rather than chasing a code bug that didn't exist — restarting
  the container was tried first and did *not* fix the latency, confirming it wasn't accumulated
  server-side state either.
- **2026-09-25 — 2. `AuditLogger`'s constructor parameter is required, not optional, by design.**
  Unlike `JobService`'s `?RequestContext $requestContext = null` (spec 042), `AuditLogger`
  declares `RequestContext $context` with no default — deliberately, per the spec's own
  Architecture note, specifically to avoid the PHP-DI pitfall spec 042 found (autowiring never
  resolves a parameter that has a default). Every HTTP call site gets a real one via autowiring;
  `bin/create-admin` passes `new RequestContext()` explicitly. No explicit container binding was
  needed in `src/App.php` — confirmed by the full integration suite passing without one, since
  both `AuditLogger` and `RequestContext` are plain, zero-ceremony concrete classes.
- **2026-09-25 — 3. `assertSame` on an associative array is order-sensitive in PHP — caught by a
  failing test, not assumed.** `AuditLogTest::testUpdatingSettingsWritesAuditRowWithOldAndNewValues`
  first asserted the whole `['old' => ..., 'new' => ...]` array with `assertSame()`, which failed
  because PHP's `===` on arrays cares about key order, and the actual row happened to decode
  with `new` first. Fixed by asserting the two keys individually instead of the whole array.

## Validation evidence

All commands run 2026-09-25/26 inside the `web` container; MySQL-backed tests with
`MYSQL_DATABASE_TEST=restaurant_test`; browser/CLI checks against `web-e2e`/`restaurant_test`
(port 8081, `docker-compose.e2e.yml`).

- **AC1 (menu item update)** — `AuditLogTest::testUpdatingMenuItemPriceWritesAuditRow`: real
  `PATCH /api/admin/items/{id}` with `{"price": 29.90}`, resulting `audit_log` row has
  `action=menu_item.updated`, `entity_id` matching the item, `details.price == 29.9`. Passes.
- **AC2, AC3 (settings/printer, same endpoint)** —
  `AuditLogTest::testUpdatingSettingsWritesAuditRowWithOldAndNewValues`: real
  `PUT /api/admin/settings` with `printer_ip`, resulting row has `action=settings.updated`, no
  `entity_type`/`entity_id`, and `details.printer_ip` = `{"old": "10.0.0.1", "new": "10.0.0.5"}`
  (individual-key assertions, not a whole-array `assertSame` — see Implementation log entry 3).
  Passes.
- **AC4 (order reopened)** — `AuditLogTest::testReopeningOrderWritesAuditRow`: real order
  created → completed → reopened via `POST /api/orders/{id}/uncomplete`; resulting row has
  `action=order.reopened`, `entity_type=order`, `entity_id` matching. Passes.
- **AC4 (user created via CLI)** — ran `bin/create-admin auditchecktest cashier` for real
  against the disposable `restaurant_test` database (not dev). Resulting row, queried directly:
  `user_id=NULL, username=NULL, action=user.created, entity_type=user, entity_id=610,
  details={"role":"cashier","source":"cli","username":"auditchecktest"}` — the null actor is
  correct, not a bug (see Implementation log entry 1's sibling reasoning: no HTTP request, no
  authenticated actor exists).
- **AC5 (failed operations write nothing)** —
  `AuditLogTest::testFailedMenuUpdateWritesNoAuditRow` (negative price → `400`, zero audit rows)
  and `::testReopeningNonexistentOrderWritesNoAuditRow` (order 999999 → `404`, zero audit rows).
  Both pass.
- **AC6 (role gate)** — `AuditLogTest::testAuditLogEndpointRequiresAdminRole`: `admin` token →
  `200`; `manager` token → `403`; no token → `401`. Passes.
- **AC7 (no secrets logged)** — manual review of all `AuditLogger::record()` call sites
  (`MenuService::updateItem()`, `SettingsController::updateSettings()`,
  `OrderController::uncomplete()`, `bin/create-admin`): none pass a password, token, or
  `Authorization` value into `details` — confirmed by reading each call site directly, not
  grepped (the settings endpoint's payload is validated by `SettingsValidator`, which has no
  credential-shaped keys).
- **AC8 (viewer renders)** — real headless-browser check against `web-e2e`: disposable admin
  user, real login, `admin_token` in `localStorage`, `public/admin/audit-log.php` loaded for
  real, `page.on('console'|'pageerror')` listeners attached. Result: zero console errors, page
  rendered "Histórico de Auditoria" and a real `settings.updated` entry. See Implementation log
  entry 1 — this initially looked like the endpoint hanging, and was root-caused (not assumed)
  to ~10.5s of real Docker Desktop network latency on this local machine, confirmed by a raw
  `fetch()` probe with a 40s timeout that succeeded in 10458ms, and by Apache's own access log
  showing real `200` responses with correct byte counts to the exact same requests. Restarting
  the container was tried and did **not** change the latency, ruling out accumulated
  server-side state as the cause.
- **Full regression check** — `vendor/bin/phpunit`: `OK (225 tests, 535 assertions)` (was
  216/495 before this spec — +9 tests: `AuditLoggerTest` ×3, `AuditLogTest` ×6). `phpstan
  analyse`: `[OK] No errors` (58 files). `php-cs-fixer --dry-run --diff`: flagged exactly 1 of
  100 files — `bin/worker`, the same pre-existing CRLF issue already documented in specs
  041/042, untouched by this branch (`git diff` confirms zero content change on that file).
- **Migration** — `bin/migrate` applied `018_audit_log.sql` with `[OK]`; second run →
  `✔ Nenhuma migração pendente.`. `SHOW COLUMNS FROM audit_log` confirmed the schema against the
  real dev MySQL.
