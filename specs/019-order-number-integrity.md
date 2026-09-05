# Spec 019 — Order number integrity

## Metadata

- Status: Verified
- Created: 2026-09-04
- Updated: 2026-09-04
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Order number integrity" subsection, requires: define the exact behavior of `order_number`, decide whether it increases continuously or resets on an explicit business-day rule, make generation safe under concurrent requests, enforce uniqueness at the database level, and add concurrency-focused tests.

This item was explicitly deferred here by `specs/010-order-terminology-cleanup.md`, which corrected `table_number`'s *meaning* (a customer-facing pickup ticket, "Senha", never a physical restaurant table) but left the column itself named `table_number`, with an explicit forward pointer: `OrderRepository::getNextNumber()`'s docblock (`src/Repositories/OrderRepository.php:122-126`) and `Order::$fillable`'s comment (`src/Models/Order.php:13-14`) both say "see docs/ROADMAP.md's v1.7.0 'Order number integrity' for the planned order_number rework." This spec is that rework — it is expected to complete the `table_number` → `order_number` rename spec 010 started at the terminology level.

## Problem

Confirmed by direct reads:

1. **The rename spec 010 deferred is still pending.** The DB column (`common/sql/001_schema.sql:33`, `table_number VARCHAR(50) NOT NULL`), the Eloquent model (`src/Models/Order.php:15`, `$fillable = ['table_number', ...]`), the repository, validator, controller, both request/response API fields, and every frontend reference (`public/cashier/app.js`, `public/cashier/index.php`, `public/kitchen/index.php`, `public/kitchen/app.js`, `public/api/docs/openapi.yaml`) still say `table_number`, even though the roadmap's own v1.7.0 text already calls it `order_number` as the "canonical operational identifier."
2. **Number generation has a real, exploitable race.** `OrderRepository::getNextNumber()` (`src/Repositories/OrderRepository.php:127-131`) runs `SELECT COALESCE(MAX(CAST(table_number AS UNSIGNED)), 0) + 1 AS next FROM orders` with no locking. `public/cashier/app.js:20-40` calls `GET /api/orders/next-number` once, on page load, and pre-fills the ticket-number field with the result; nothing re-checks or re-locks that number at submit time. Two cashier terminals (or two browser tabs) loading around the same moment receive the same suggested number, and both can submit it.
3. **Nothing rejects a duplicate at any layer.** `orders.table_number` has no unique constraint (`common/sql/001_schema.sql:31-39`). `OrderValidator::validateOrderData()` (`src/Validators/OrderValidator.php:16-17`) only checks `required` + `lengthMax:50` — no numeric-format check, no uniqueness check. `OrderRepository::createOrder()` (`src/Repositories/OrderRepository.php:60-100`) writes whatever value it's given, straight through. Two concurrent `POST /api/orders` calls can and will produce two orders with an identical, customer-facing ticket number.
4. **The suggested number is also manually editable, on purpose.** `public/cashier/index.php:101-102` — `<input type="text" id="tableNumber" x-model="tableNumber">`, labeled "Número da Senha *". The cashier can freely overwrite the pre-filled suggestion. Any fix that silently removes this (e.g. by making the server assign the number unconditionally, ignoring client input) would be a real behavior change the roadmap text doesn't ask for and this spec does not assume is wanted — see Open Questions.
5. **No explicit reset policy exists.** `getNextNumber()`'s query has no date filter — the number climbs across the table's *entire history*, forever. That's a real, current behavior, but it's never been an explicit product decision; it's just what an unscoped `MAX()+1` happens to produce. A walk-up "Senha" ticket scheme (as opposed to a permanent order ID) commonly resets daily in real counter-service restaurants — the roadmap explicitly asks this to be decided, not assumed.
6. `orders.status` (`common/sql/001_schema.sql:35`) is already `ENUM('pending','preparing','ready','done')`, but `Order` (`src/Models/Order.php:21-22`) only defines `STATUS_PENDING`/`STATUS_DONE` — confirming (out of scope here) that the "Order lifecycle" v1.7.0 item is a separate, not-yet-started piece of work; this spec does not touch order status.

## Goals

- Complete the `table_number` → `order_number` rename end-to-end: DB column, Eloquent model, repository, validator, controller, API request/response field, frontend, OpenAPI docs, and tests.
- Make order-number generation safe under concurrent order creation — no unlocked `MAX()+1` read.
- Enforce the uniqueness rule at the database level (a real constraint, not just application logic).
- Preserve the cashier's existing ability to see a suggested number and manually override it before submitting.
- Add concurrency-focused tests that actually exercise the fix, not just a mocked happy path.

## Non-goals

- **Not the order lifecycle / status state machine.** `docs/ROADMAP.md`'s v1.7.0 "Order lifecycle" subsection is separate, later work; this spec does not add `PREPARING`/`READY` transitions or touch `Order::STATUS_*`.
- **Not pricing, money representation, or historical snapshots.** Separate v1.7.0 subsections.
- **Not general API error standardization.** This spec introduces one new error shape (a duplicate-order-number conflict) consistent with the existing per-controller pattern (`OrderController::errorResponse()`), but does not build the roadmap's broader "one consistent API error format" — that's its own v1.7.0 subsection.
- **Not query performance / pagination work** beyond the one index this feature itself needs.
- **Not changing `orders.id`** or its role as the internal primary key — `order_number` remains a separate, customer-facing value.

## Current behavior

See Problem, points 1-6, with citations. Summary: `table_number` is an unlocked, unconstrained, cashier-editable free-text field, suggested via an unscoped `MAX()+1` scan with no expiry/consumption semantics, no uniqueness enforcement anywhere, and no defined reset policy.

## Proposed behavior

**Rename.** `table_number` becomes `order_number` everywhere: column, model, repository, validator, controller, `POST`/`PUT /api/orders*` request fields, response bodies, OpenAPI schema, `public/cashier/*`, `public/kitchen/*`. Internal PHP variable/property names, JSON field names, and user-facing Portuguese labels ("Número da Senha") follow the same split `CLAUDE.md` already documents (English identifiers, Portuguese user-facing strings) — only the identifier changes, not the Portuguese label text.

**Numbering scope — decided: resets per business day** (confirmed by the user, resolving Open Question 1). `order_number` is a daily operational ticket ("Senha"), scoped and unique per `business_date`; `orders.id` remains the separate, global, immutable technical identifier and is never renumbered or reused. "Business day" is computed in application code using the already-configured `APP_TIMEZONE` (`Settings`, spec 011) at the moment of order creation — not a MySQL `CONVERT_TZ` expression, to avoid MySQL timezone-table/config fragility.

**Concurrency-safe allocation.** Replace the unlocked `MAX()+1` scan with a dedicated counter table locked per business day:

```sql
CREATE TABLE order_number_counters (
    business_date DATE NOT NULL PRIMARY KEY,
    last_number   INT UNSIGNED NOT NULL DEFAULT 0
);
```

Inside `OrderRepository::createOrder()`'s existing `DB::transaction()`, `allocateNextNumber()` is only called when the client omitted `order_number` (see resolved Open Question 2 below for exactly when that is):
1. `SELECT * FROM order_number_counters WHERE business_date = ? FOR UPDATE` — lock the row directly. **Implementation note (found and fixed during validation):** the first version of this method unconditionally ran `INSERT IGNORE` before this `SELECT ... FOR UPDATE`, to guarantee the row existed. Under real concurrent load that combination reliably **deadlocked** (confirmed with 8 parallel requests): every request's `INSERT IGNORE` took a shared lock via its duplicate-key check on the already-existing row, then every request tried to upgrade that shared lock to exclusive via `FOR UPDATE` — a lock-upgrade cycle MySQL detects as a deadlock. Locking first and only creating the row on a genuine cache-miss removes that shared-then-exclusive upgrade for the common case (every day after its first order).
2. If no row exists yet (first order of a new `business_date`), create it (`last_number = 0`); a concurrent request racing to create the same row gets an ordinary duplicate-key error here (not a deadlock — neither side holds a lock on an existing row yet), which is caught and simply followed by re-reading the row under lock.
3. Increment `last_number` and save, still inside the lock.
4. If the cashier *did* submit an explicit `order_number` (manual override), use it exactly as submitted and **do not touch the counter at all** — confirmed requirement: automatic generation must be concurrency-safe *and independent of manual overrides*. The counter only ever reflects auto-assigned numbers; a manual override never advances or otherwise mutates it. A manually-typed number can therefore validly collide with a *future* auto-assigned one (or vice versa) — that collision, like any other, is caught by the unique index below, not prevented by counter bookkeeping.
5. Insert the order with `order_number` + `business_date` inside the same transaction, so the counter update (if any) and the order row are atomic together.

**Database-level uniqueness.** Add `business_date DATE NOT NULL` to `orders` (set once, at creation, from the same value used above — not derived later from `created_at`, so it can't drift if `created_at`'s meaning ever changes) and a composite unique index:

```sql
ALTER TABLE orders ADD COLUMN business_date DATE NOT NULL AFTER order_number;
ALTER TABLE orders ADD UNIQUE KEY uniq_order_number_per_day (business_date, order_number);
```

A collision (two manual overrides, or a manual value that happens to match a future auto-assigned one) fails at the database with a duplicate-key error (`Illuminate\Database\QueryException`, SQLSTATE `23000`), which propagates unmodified up through the repository and service (no custom exception class — matching the existing precedent already in this codebase, `MenuController::delete()`'s own `QueryException` catch for a different constraint) and is caught in `OrderController::store()`/`update()`, which check `$e->getCode() === '23000'` before mapping to `409 Conflict` — any *other* `QueryException` (a deadlock, a lock-wait timeout) falls through to the existing generic error handler instead, since it isn't actually a duplicate `order_number`:

```json
{"success": false, "error": "Número de senha já utilizado hoje.", "code": "ORDER_NUMBER_TAKEN"}
```

**`GET /api/orders/next-number` becomes a non-consuming preview.** It computes and returns what the next number *would* be (peeks `order_number_counters.last_number + 1` for today, creating today's row if absent, without locking or incrementing) but does not allocate anything — allocation only happens inside `POST /api/orders`'s transaction. This fixes today's implicit assumption that fetching this endpoint "reserves" a number (it doesn't, and never did) without changing its response shape.

## Functional requirements

1. `orders.order_number` (renamed from `table_number`) plus a new `orders.business_date` column exist after migration, with `order_number`'s type/length unchanged (`VARCHAR(50) NOT NULL`).
2. `orders` has a unique index on `(business_date, order_number)`. Attempting to insert a second order with the same `order_number` on the same `business_date` fails at the database level.
3. A new `order_number_counters` table tracks the last-issued number per `business_date`.
4. `POST /api/orders` accepts `order_number` (was `table_number`); omitting it (or an explicit "auto-assign" signal — exact shape decided during implementation) causes the server to allocate the next number for today under a row lock; submitting an explicit value uses it, subject to the unique constraint.
5. Two concurrent `POST /api/orders` requests that both omit `order_number` never receive the same allocated number.
6. A `POST /api/orders` request whose submitted `order_number` already exists for the current business date returns `409` with `code: "ORDER_NUMBER_TAKEN"`, not a raw SQL error and not a silently-succeeded duplicate.
7. `GET /api/orders/next-number` returns a preview value; calling it repeatedly without creating an order returns the same value each time (it does not consume/increment anything by itself).
8. `PUT /api/orders/{id}` (update) accepts `order_number` (was `table_number`) and is still subject to the same uniqueness constraint when changed.
9. All existing non-numbering order functionality (items, notes, dining option, status complete/reopen, print, delete) is unaffected.

## Non-functional requirements

- The row lock in step 2 of allocation (`SELECT ... FOR UPDATE` on one `order_number_counters` row) must not lock the whole `orders` table — concurrent order creation for *different* business days, or reads of existing orders, must not block on it.
- Business-day computation must use `Settings`'s `APP_TIMEZONE` (spec 011), not the DB server's timezone or PHP's default, so a restaurant's configured timezone is what actually determines "today."

## User flows

**Cashier — normal flow (unchanged from the cashier's perspective).** Cashier opens the terminal → sees a suggested "Número da Senha" (now sourced from the non-consuming preview) → optionally edits it → adds items → submits. If nothing else raced them, this succeeds exactly as today.

**Cashier — collision (new).** Two cashiers submit orders around the same moment. Previously: both could succeed with the same ticket number, confusing the kitchen and the customer. Now: at most one succeeds with a given number for today; the second either gets a different auto-assigned number (if they didn't manually type one) or, if they both manually typed the same number, the second gets a `409` and a clear Portuguese error, and must re-submit (with a corrected number, or by re-fetching the suggestion).

## API changes

- `GET /api/orders/next-number` — same response shape (`{"next": N}`), now backed by a non-consuming preview instead of an unlocked `MAX()+1` scan.
- `POST /api/orders` — request field `table_number` → `order_number`. New possible response: `409 Conflict`, `{"success": false, "error": "Número de senha já utilizado hoje.", "code": "ORDER_NUMBER_TAKEN"}`.
- `PUT /api/orders/{id}` — request field `table_number` → `order_number`, same new `409` possible on collision.
- `GET /api/orders` (and any other endpoint returning order objects) — response field `table_number` → `order_number`.
- `public/api/docs/openapi.yaml` updated to match all of the above (mirroring how spec 010 updated it for the request-field rename).

## Data model and migrations

New file `common/migrations/012_order_number_integrity.sql` (next free migration number — `011_user_roles.sql` is the current latest):

- Guarded rename `table_number` → `order_number` on `orders` (idempotent check against `information_schema.COLUMNS`, matching the pattern already used in `common/migrations/006_settings.sql`, in case this migration is ever re-run against a partially-migrated database).
- Add `orders.business_date DATE NOT NULL` (backfilled for existing rows from each row's own `created_at`, converted using the same `APP_TIMEZONE` logic the application will use going forward — a one-time backfill statement, not a live computed column).
- Add `UNIQUE KEY uniq_order_number_per_day (business_date, order_number)` on `orders` — **the backfill must be checked for pre-existing collisions before this index is added**; if any exist (two historical rows with the same `table_number` value on what would become the same `business_date`), the migration must surface that clearly rather than silently failing partway (exact handling — abort with a report, or auto-suffix duplicates — is an implementation-time decision, not pre-decided here, since it depends on what the real data actually contains).
- Create `order_number_counters (business_date DATE PRIMARY KEY, last_number INT UNSIGNED NOT NULL DEFAULT 0)`, seeded from `MAX(order_number)` per existing `business_date` in `orders` so post-migration suggestions continue from the right place instead of restarting at 0.

## Architecture and affected components

- `src/Models/Order.php` — `$fillable` now includes `order_number`/`business_date`.
- `src/Models/OrderNumberCounter.php` (new) — one row per `business_date`, `last_number`; non-incrementing string/date primary key, no timestamps.
- `src/Repositories/OrderRepository.php` — `getNextNumber()` (preview-only, no write), `createOrder()` (allocation dispatch), new private `allocateNextNumber()` (locking logic — see Proposed behavior for the deadlock found and fixed here), `updateOrder()` (rename), `getOrdersByStatus()` (rename in the response array).
- `src/Validators/OrderValidator.php` — rename `table_number` → `order_number`, `required` → `optional` in `validateOrderData()`.
- `src/Controllers/OrderController.php` — rename references; `store()`/`update()` catch `\Illuminate\Database\QueryException`, branching on SQLSTATE (`23000` → `409`, anything else → the existing generic handler). No new exception class — matches the existing `QueryException`-in-controller precedent already in `MenuController::delete()` for a different constraint.
- `src/Services/OrderService.php` — no changes needed; it already passes `$data`/exceptions through unmodified (matches this project's existing thin-service pattern for `OrderService`).
- `src/Services/PrintService.php` — **not in the original plan, found during implementation**: the printed receipt (`printOrder()`) rendered `"Senha: " . $order->table_number`, a real remaining reference this spec's own acceptance criterion 1 (repo-wide `table_number` grep) would have caught regardless.
- `public/cashier/app.js`, `public/cashier/index.php`, `public/kitchen/index.php`, `public/kitchen/app.js` — field rename, plus a new `orderNumberAuto` flag in the cashier (see resolved Open Question 2) so the client tells the server whether to auto-allocate or use the submitted value as a manual override.
- `public/api/docs/openapi.yaml` — schema/field renames, `order_number` no longer `required` in `CreateOrderInput`, new `409` response documented on `POST /api/orders`, `next-number`'s description updated to describe the non-consuming preview.
- `tests/Unit/OrderServiceTest.php`, `tests/Unit/OrderValidatorTest.php`, `tests/Unit/PrintServiceTest.php` — field rename; `OrderValidatorTest`'s old "missing table_number is rejected" test inverted to "missing order_number is accepted" (it's optional now).
- `tests/Unit/OrderRepositoryTest.php` (new) — exercises `allocateNextNumber()`/`createOrder()`/`getNextNumber()` against a real in-memory SQLite DB: sequential auto-allocation, manual override not advancing the counter, a same-day manual duplicate throwing `QueryException`, and the preview being non-consuming. Sequential/single-process only — see Testing and validation strategy for the separate real-concurrency evidence.

## Security considerations

Not a new attack surface — `order_number`/`business_date` are not secrets and don't cross the auth boundary differently than today (`POST /api/orders` remains an intentionally public, trusted-network endpoint, unchanged by this spec). The one input-validation gap this spec closes is a data-integrity one (duplicate/racy ticket numbers), not a security vulnerability.

## Backward compatibility

**Breaking, on top of spec 010's already-breaking rename.** Any API consumer still sending `table_number` (spec 010 already broke `POST`'s old `table` field name; this spec goes further and renames the column itself) must switch to `order_number`. No stored data is lost — the rename is in-place, and `business_date` is backfilled, not left null. Existing tagged behavior: this repeats the same kind of breaking change spec 010 already made and documented in `CHANGELOG.md`; this spec's own `CHANGELOG.md` entry must call it out the same way.

## Acceptance criteria

1. `grep -rn "table_number" src/ public/cashier/ public/kitchen/ public/api/docs/openapi.yaml` returns no matches (excluding historical references in `specs/010-*.md`/`docs/ROADMAP.md`, which describe past/planned states and are not code).
2. `orders` table has columns `order_number` and `business_date`, and a unique index covering both together (confirmed via `SHOW CREATE TABLE orders` or equivalent, run for real against the dev DB).
3. `order_number_counters` table exists and contains one row per business day that has had at least one order since this migration ran.
4. A real, scripted test that fires two concurrent `POST /api/orders` requests (both omitting `order_number`) against the running dev stack results in two orders with two different `order_number` values for the same `business_date` — no duplicate.
5. A `POST /api/orders` request with an explicit `order_number` that collides with an existing order for the same `business_date` returns HTTP `409` with `code: "ORDER_NUMBER_TAKEN"`.
6. `GET /api/orders/next-number` called twice in a row, with no order created in between, returns the same `next` value both times.
7. `vendor/bin/phpunit` passes, including new/updated unit tests for the renamed field and the allocation logic (see below).

## Implementation plan

1. Write and apply `common/migrations/012_order_number_integrity.sql` (rename, `business_date` backfill + collision check, unique index, `order_number_counters` seeded from existing data). Run `bin/migrate` against the dev DB and confirm success before touching PHP code.
2. Update `src/Models/Order.php`, `src/Repositories/OrderRepository.php` (rename + new locking allocation logic in `createOrder()`, preview-only `getNextNumber()`), `src/Validators/OrderValidator.php`, `src/Services/OrderService.php`, `src/Controllers/OrderController.php` (rename + `409` handling).
3. Add the duplicate-detection mechanism decided in Architecture (typed exception or `QueryException`/SQLSTATE check).
4. Update `public/cashier/app.js`, `public/cashier/index.php`, `public/kitchen/index.php`, `public/kitchen/app.js` field references.
5. Update `public/api/docs/openapi.yaml`.
6. Update `tests/Unit/OrderServiceTest.php`, `tests/Unit/OrderValidatorTest.php` for the rename; add new tests per Testing and validation strategy.
7. Manually verify the concurrency fix against the real running stack (see Acceptance criterion 4) — this is the one criterion a unit test with a mocked repository cannot actually prove.

## Testing and validation strategy

This project now has PHPUnit (`phpunit.xml`, `tests/Smoke`, `tests/Unit`, since specs 004/005) — `CLAUDE.md` and `specs/000-project-baseline.md`'s older "no test infrastructure" statement is stale as of `v1.6.0`/spec 004. However, **there is still no integration-test harness that spins up real concurrent DB connections** (`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` "Integration tests" subsection is later, not-yet-started work) — existing tests (`tests/Unit/OrderServiceTest.php`) mock `OrderRepository` entirely, which cannot exercise real row-locking behavior.

Given that gap, validation here is split:
- **Unit-level (PHPUnit, mocked):** rename coverage in `OrderValidatorTest`/`OrderServiceTest`; a new test asserting `OrderService`/`OrderController` correctly maps a duplicate-key condition to a `409`/`ORDER_NUMBER_TAKEN` response (mocking the repository to throw the duplicate exception).
- **Real concurrency proof (manual, this spec's own validation, not a permanent test suite addition):** a small script — e.g. `curl` fired via two background shell processes, or a short standalone PHP script using two DB connections — that issues two simultaneous `POST /api/orders` requests against the real running dev stack and confirms no duplicate `order_number` results (Acceptance criterion 4). This is validation evidence for *this spec*, run and recorded during `/spec-implement`, not new permanent test infrastructure (building a real concurrency-testing harness is bigger than this one spec and would belong under `v1.8.0`'s "Integration tests" if it becomes a recurring need).
- Migration correctness (backfill, collision check, counter seeding) verified by running `bin/migrate` for real against the dev DB and inspecting the resulting rows directly (`SELECT` against `orders`/`order_number_counters`), not assumed from reading the SQL.

## Rollout and rollback

No feature flag — this is a straight schema + code change behind the existing `/api/orders*` endpoints, applied via the normal `bin/migrate` path. Rollback is a new down-migration or manual reversal (rename `order_number` back to `table_number`, drop `business_date`/the unique index/`order_number_counters`) — this project's migration system has no automatic down-migration support (`CLAUDE.md`: "Hand-rolled SQL migrations... the cost is no rollback semantics"), so a rollback would be a deliberate, separately-written SQL script, not automatic.

## Open questions

1. ~~**Blocking.** Does order-number uniqueness reset per business day, or stay a single continuous sequence forever?~~ **Resolved by the user (2026-09-04):** `order_number` is a daily operational ticket, reset per `business_date`; `orders.id` stays the global, immutable technical identifier; `(business_date, order_number)` must be unique; automatic generation must be concurrency-safe *and* independent of manual overrides (the counter never reacts to a manually-typed number — see Proposed behavior, allocation step 4). Design above updated accordingly.
2. ~~**Not blocking.** Exact shape of "auto-assign vs. explicit override"~~ **Resolved during implementation:** the cashier UI tracks a client-side `orderNumberAuto` flag, `true` by default and on every programmatic re-fill from the preview, flipped to `false` the moment the cashier types into the field (`@input` on `#orderNumber`). `POST /api/orders` omits `order_number` entirely while `orderNumberAuto` is `true` (server auto-allocates); it's sent as-is otherwise (manual override, validated only by the unique index).
3. ~~**Not blocking.** Exact migration behavior if the one-time backfill finds real historical collisions~~ **Resolved during implementation:** the dev database *did* have 4 real collision groups on `2026-07-10` (`order_number` 114/115/120/121 — 11 rows total, one group with 5 duplicates). Presented to the user with full detail; confirmed as test data, safe to delete. Kept the earliest row (lowest `id`) of each group, deleted the other 7 (`orders` ids 27, 29, 35, 37, 38, 39, 40 — `order_items` cascaded), then the migration applied cleanly. No `orders.id` values were reused or altered.

## Task checklist

- [x] Migration `012_order_number_integrity.sql` written, applied, verified against dev DB
- [x] `Order` model updated
- [x] `OrderNumberCounter` model created
- [x] `OrderRepository` updated (rename + locked allocation + preview-only `getNextNumber()`)
- [x] `OrderValidator` updated
- [x] `OrderService` — confirmed no change needed (pass-through)
- [x] `OrderController` updated (rename + `409` handling, SQLSTATE-discriminated)
- [x] `PrintService` updated (found during implementation, not in original plan)
- [x] Duplicate-detection mechanism implemented (`QueryException` + SQLSTATE `23000`, no new exception class)
- [x] Cashier frontend updated (`app.js`, `index.php`) — `orderNumberAuto` tracking added
- [x] Kitchen frontend updated (`app.js`, `index.php`)
- [x] `openapi.yaml` updated
- [x] Unit tests updated/added (`OrderServiceTest`, `OrderValidatorTest`, `PrintServiceTest`, new `OrderRepositoryTest`), `vendor/bin/phpunit` passing
- [x] Real concurrency check performed and recorded (Acceptance criterion 4) — found and fixed a real deadlock in the process

## Implementation log

- 2026-09-04 — Blocking Open Question 1 resolved by the user before implementation started: per-business-day reset, `orders.id` stays global/immutable, `(business_date, order_number)` unique, auto-generation independent of manual overrides. Spec updated, `Status: Draft → Approved`.
- 2026-09-04 — `/spec-implement` invoked. Re-read `Order.php`, `OrderRepository.php`, `OrderService.php`, `OrderController.php`, `OrderValidator.php`, `common/sql/001_schema.sql`, the cashier/kitchen frontend, and `public/api/docs/openapi.yaml` before editing — all matched what the spec assumed, no drift found. Additionally found `src/Services/PrintService.php:195` still referencing `$order->table_number` (not listed in the original Architecture section) and `src/Services/JobService.php`/`src/Controllers/MenuController.php` as real precedents for, respectively, the `lockForUpdate()` idiom and the `QueryException`-in-controller idiom — used both instead of inventing a new pattern.
- 2026-09-04 — Wrote and applied migration `012_order_number_integrity.sql`. First run failed exactly as designed: MySQL rejected the new unique index with a genuine duplicate-entry error (`2026-07-10-114`). Investigated with the user (Open Question 3): the dev DB had 4 real collision groups on `2026-07-10` (114, 115, 120, 121 — 121 had 5 duplicate rows), 11 orders total, all `status='done'`. Confirmed as test data and safe to delete; kept the earliest row of each group (lowest `id`), deleted the other 7 (`order_items` cascaded via FK). Re-ran the migration — applied cleanly. Confirmed via `SHOW COLUMNS`/`SHOW INDEX`/`order_number_counters` contents.
- 2026-09-04 — Implemented the rename + allocation logic, frontend changes, and OpenAPI updates per the plan. `vendor/bin/phpunit` (18 tests, including the 4 new `OrderRepositoryTest` cases) and `php -l` on every changed file passed on the first attempt.
- 2026-09-04 — Real concurrency check (Acceptance criterion 4): fired 2, then 8, concurrent `POST /api/orders` requests (both via raw CLI `OrderRepository::createOrder()` calls and via real HTTP through Apache) omitting `order_number`. **Found a genuine bug**: most concurrent requests failed with a MySQL deadlock (`SQLSTATE 40001`) on the counter's `SELECT ... FOR UPDATE`, which `OrderController`'s broad `QueryException` catch was mislabeling as "número já utilizado" (a `23000`-specific message, shown for the wrong SQLSTATE). Root-caused with a controlled two-process lock test (confirmed `lockForUpdate()` itself works correctly — one process blocked for exactly the other's sleep duration) and then a full `createOrder()` CLI concurrency test (8 parallel calls, 7 failures) that isolated the bug to `allocateNextNumber()`'s original `INSERT IGNORE` immediately followed by `SELECT ... FOR UPDATE` on the same already-existing row — a shared-lock-then-exclusive-upgrade pattern MySQL detects as a deadlock under real concurrency. Fixed by locking the row directly first and only creating it on a genuine miss (see Proposed behavior); re-ran both the 8-way CLI test and an 8-way real-HTTP test — all 8 succeeded with 8 distinct sequential numbers each time. Also fixed `OrderController::store()`/`update()` to check `$e->getCode() === '23000'` before returning the duplicate-specific `409`, so a future deadlock/lock-timeout surfaces as a real (sanitized) error instead of a misleading duplicate message. Debug scripts used for this investigation (`bin/_debug_lock_test.php`, `bin/_debug_create_order.php`) were deleted afterward — not part of the deliverable.
- 2026-09-04 — Full `git diff` self-review; no unrelated changes found. `~20` orders created in the dev DB across all the manual/curl validation above (ids 59-81+, `business_date` 2026-09-04, item id 72, no customer name) were left in place as test data, consistent with the pre-existing dev DB already containing non-production rows — flagged to the user in the final report rather than deleted unilaterally.

## Validation evidence

- **Acceptance criterion 1** (`grep -rn "table_number" src/ public/ tests/`) — empty result. **Confirmed.**
- **Acceptance criterion 2** (`orders` has `order_number`, `business_date`, and a unique index over both) — `SHOW COLUMNS FROM orders` lists both columns (`order_number varchar(50) NO`, `business_date date NO`); `SHOW INDEX FROM orders` lists `uniq_order_number_per_day` covering `business_date` + `order_number`. **Confirmed.**
- **Acceptance criterion 3** (`order_number_counters` has one row per business day with orders) — `SELECT * FROM order_number_counters` returned one row per historical business date (2026-07-05 through 2026-09-04), correctly seeded from `MAX(order_number)` per day. **Confirmed.**
- **Acceptance criterion 4** (two concurrent `POST /api/orders` never collide) — real concurrent requests via CLI (8-way) and real HTTP (8-way, through Apache) both produced 8 distinct sequential `order_number` values with zero collisions, after the deadlock fix described in the Implementation log above (the pre-fix version reliably produced 1 success + N failures out of N+1 concurrent requests — the bug this criterion exists to catch). **Confirmed**, including a real regression caught and fixed along the way.
- **Acceptance criterion 5** (colliding manual `order_number` → `409`/`ORDER_NUMBER_TAKEN`-equivalent) — `curl -X POST /api/orders -d '{"order_number":"500",...}'` twice: first succeeds (`{"success":true,"id":60,...}`), second returns `HTTP/1.1 409 Conflict`, `{"error":"Número da senha já utilizado hoje. Peça um novo número."}`. **Confirmed.**
- **Acceptance criterion 6** (`GET /api/orders/next-number` is non-consuming) — called twice in a row with no order created in between: `{"next":1}` both times. **Confirmed.**
- **Acceptance criterion 7** (`vendor/bin/phpunit` passes) — `OK (18 tests, 39 assertions)`, run after the deadlock fix (final state). **Confirmed.**
- Additionally verified, not a numbered acceptance criterion: `docker compose exec web composer validate --strict` → `./composer.json is valid` (no regression from this spec's changes — it made none to `composer.json`).
