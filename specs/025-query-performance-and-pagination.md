# Spec 025 — Query performance and pagination

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, two adjacent subsections handled together here since the investigation for one directly informs the other:

- **"Query performance"**: review queries used by growing operational data; priority columns named are `orders.order_number`, `orders.status`, `orders.created_at`, `order_items.order_id`, `jobs.status`; add indexes based on real query patterns; avoid speculative optimization.
- **"Pagination"**: paginate endpoints that may grow indefinitely (example: `GET /api/orders?page=2&per_page=50`); operational kitchen views may use status/date filtering instead when that fits better.

## Problem

Confirmed by direct reads and a live index/row-count check against the dev DB — the real problem is **not missing indexes on the columns the roadmap names**, most of which are already covered one way or another; it's that the actual date filters used everywhere are written in a way no index, existing or new, can serve efficiently:

1. **`orders.order_number`, `order_items.order_id`, `jobs.status`(-equivalent) are already adequately indexed** — confirmed via `SHOW INDEX`: `orders` has a unique composite `(business_date, order_number)` (spec 019), covering `order_number` lookups (always paired with `business_date` in this app — no query looks up `order_number` alone). `order_items.order_id`/`menu_item_id` already have FK-implied indexes (InnoDB auto-creates one for every foreign key). `jobs` has no literal `status` column (`JobService::processNext()` filters `queue`/`reserved_at`/`available_at`/`attempts` instead, per its actual, different design) — already correctly indexed for its real pattern (`idx_queue_reserved`, `idx_available`). The roadmap's column list reads as written before spec 019 introduced `business_date`, not as a currently-accurate gap list.
2. **The one column the roadmap names that is genuinely under-served is `orders.created_at` — but adding a plain index on it would not actually help**, because every real query filters it through `whereDate('created_at', ...)` (Eloquent) → SQL `WHERE DATE(created_at) = ?` / `WHERE DATE(created_at) BETWEEN ? AND ?`. Wrapping an indexed column in a function like `DATE(...)` makes the predicate **non-sargable** — MySQL cannot use a plain B-tree index on `created_at` to satisfy it; every matching row still requires computing `DATE(created_at)` to compare. Confirmed present in: `OrderRepository::getOrdersByStatus()` (`src/Repositories/OrderRepository.php`), `KitchenService::getFoodCategorySummary()` (`src/Services/KitchenService.php`), and **8 separate call sites** across `ReportService.php`'s `getSalesByDay()`, `getTopItems()` (date filter only — its `menu_item_id` grouping was already fixed in spec 023), `getDiningOptionDistribution()`, `getOrdersByHour()`, `getAvgPrepTime()` (both its overall and per-day variants), `getMonthlyComparison()`, and `getDailySummary()`.
3. **`orders.business_date` (spec 019) already exists, is `NOT NULL`, populated for every row, already the leading column of an existing unique index, and is the *exact same concept* every one of these queries is trying to express via `DATE(created_at)`** — a business day. It is a plain `DATE` column: comparing it directly (`business_date = ?`, `business_date BETWEEN ? AND ?`) is fully sargable and can use the existing index, with no new index and no migration required.
4. **Pagination — investigated, not proven necessary today.** `GET /api/orders` (`OrderRepository::getOrdersByStatus()`) has no code path that returns unbounded history: `$date` defaults to *today* (`date('Y-m-d')`) and there is no "all dates"/range option in the API at all — every call is scoped to exactly one business day, regardless of `status`. `KitchenService::getFoodCategorySummary()` — same, one day. `ReportController`'s endpoints are already explicitly bounded: `getTopItems()` has a `limit` parameter (default 10); the day-by-day/hour-by-hour report methods return one row per day/hour *within the requested range*, not one row per order, so even a multi-year range returns at most a few thousand small rows, not an unbounded result set. `MenuController::index()`/`IngredientController::index()` return the full menu/ingredient list, but both are inherently small, operator-curated lists (dozens of rows), not data that grows with restaurant activity the way orders do. No endpoint in this app currently has a reachable path to an unbounded result set.

## Goals

- Replace every non-sargable `whereDate(...)`/`DATE(...)`-wrapped filter on order dates with a direct `business_date` comparison, so the existing index is actually usable as the `orders` table grows.
- Do this without changing any observable output — same rows, same JSON shapes, same values — this is a query-plan fix, not a behavior change.
- Resolve "Pagination" with an honest answer instead of speculative work: document, with the evidence above, that no endpoint currently needs it, and say plainly why introducing `?page=&per_page=` now would be exactly the "speculative optimization" the roadmap's own Query performance subsection warns against.

## Non-goals

- **Not adding pagination to any endpoint.** Per Problem, point 4 — no reachable code path returns an unbounded result set today. If a real "Admin Order History" feature (already anticipated, unbuilt, in `docs/ROADMAP.md`'s `v2.1.0` fiscal milestone) is later built without today's implicit one-day bound, *that* feature's own spec is the right place to add pagination for it — not a preemptive change here to an endpoint that doesn't have the problem yet.
- **Not adding any new database index or migration.** `business_date` is already indexed (Problem, point 3) — every fix here is a query rewrite in application code.
- **Not touching `getOrdersByHour()`'s `HOUR(orders.created_at)` grouping.** That's genuinely about time-of-day (peak hours within a day), not the business-day concept `business_date` represents — only its outer date-*range* filter (which day(s) to include) switches to `business_date`; the hour extraction itself is unrelated to this spec's sargability fix and stays as-is.
- **Not touching `jobs`, `order_items`, or `orders.order_number` indexing** — already adequate (Problem, point 1), not touched speculatively.
- **Not reconciling `business_date` vs. `DATE(created_at)` for any row where they might disagree.** They're expected to always match in this deployment (both reflect "now" at insert time, and the container's MySQL session timezone and `APP_TIMEZONE` are already the same `America/Sao_Paulo`, confirmed in spec 019) — this spec does not add a reconciliation/backfill step, since substituting one for the other is a performance fix premised on them already being equivalent, not a data-correction step.

## Current behavior

See Problem, points 1-4, with citations.

## Proposed behavior

Every date-range or single-date filter on `orders` switches from a `DATE(created_at)`-wrapped comparison to a direct `business_date` comparison:

| File / method | Before | After |
|---|---|---|
| `OrderRepository::getOrdersByStatus()` | `whereDate('created_at', $date)` | `where('business_date', $date)` |
| `KitchenService::getFoodCategorySummary()` | `whereDate('created_at', $date)` | `where('business_date', $date)` |
| `ReportService::getSalesByDay()` | `whereDate('orders.created_at', '>=', $dateFrom)`/`'<='` + `SELECT DATE(orders.created_at) as date` + `GROUP BY DATE(orders.created_at)` | `where('orders.business_date', '>=', $dateFrom)`/`'<='` + `SELECT orders.business_date as date` + `GROUP BY orders.business_date` |
| `ReportService::getTopItems()` | `whereDate('orders.created_at', '>='/'<=', ...)` | `where('orders.business_date', '>=' / '<=', ...)` |
| `ReportService::getDiningOptionDistribution()` (or equivalent method at the same lines) | same pattern | same substitution |
| `ReportService::getOrdersByHour()` | date-range filter via `whereDate` | range filter switches to `business_date`; `HOUR(orders.created_at)` grouping is untouched (Non-goals) |
| `ReportService::getAvgPrepTime()` (both variants) | `whereDate('created_at', ...)` range + `SELECT DATE(created_at) as date` / `GROUP BY DATE(created_at)` in the per-day variant | `business_date` range + `SELECT business_date as date` / `GROUP BY business_date` |
| `ReportService::getMonthlyComparison()` | `whereDate('orders.created_at', ...)` range (used twice, once per period) | `business_date` range, both periods |
| `ReportService::getDailySummary()` | `whereDate('orders.created_at', $date)` | `where('orders.business_date', $date)` |

`TIMESTAMPDIFF(MINUTE, created_at, updated_at)` (prep-time calculation) is unrelated to this fix and stays exactly as-is — it measures elapsed wall-clock time between two timestamps, not a business-day boundary.

## Functional requirements

1. Every method listed in the table returns byte-identical JSON to before this spec, for the same inputs, on real data where `business_date` and `DATE(created_at)` agree (the normal case in this deployment).
2. No new column, index, or migration is introduced.
3. `EXPLAIN` on `OrderRepository::getOrdersByStatus()`'s underlying query shows the optimizer using `uniq_order_number_per_day` (or an equivalent index scan on `business_date`) instead of a full table scan, confirmed against the real dev DB, not assumed from the query text alone.

## Non-functional requirements

Query-plan efficiency only — the actual dev DB (58 orders) is far too small for this to be observable as a wall-clock speed difference today; the value is in not degrading as real operational data accumulates, per the roadmap's own framing ("queries used by growing operational data").

## User flows

Not applicable — no observable behavior change for any user role.

## API changes

Not applicable — no request/response shape changes anywhere.

## Data model and migrations

Not applicable — no schema change (Non-goals).

## Architecture and affected components

- `src/Repositories/OrderRepository.php` — `getOrdersByStatus()`.
- `src/Services/KitchenService.php` — `getFoodCategorySummary()`.
- `src/Services/ReportService.php` — all methods listed in the Proposed behavior table.
- Tests: existing `OrderRepositoryTest`/`OrderServiceTest` fixtures already populate `business_date` (specs 019-023) — extended with an explicit assertion that a query filtering by a given date only returns orders whose `business_date` matches, proving the new filter logic. No `ReportServiceTest` exists yet in this project; `ReportService`'s changes are verified against the real dev DB (see Testing and validation strategy) rather than introducing a first, large test file for a spec whose actual change is a mechanical column substitution repeated many times, not new logic.

## Security considerations

Not applicable — no new input surface, no behavior change.

## Backward compatibility

Fully backward compatible — same output for the same input, no API shape change, no schema change. The only way this spec's substitution could change a result is if `business_date` and `DATE(created_at)` ever disagreed for some row, which Non-goals explains is not expected in this deployment and is not something this spec attempts to detect or fix.

## Acceptance criteria

1. `vendor/bin/phpunit` passes, including the extended `OrderRepositoryTest` date-filter assertion.
2. For a real order created today, `GET /api/orders?status=pending&date=<today>` returns it; `GET /api/orders?status=pending&date=<yesterday>` does not — behavior identical to before the change.
3. `EXPLAIN` (or `EXPLAIN ANALYZE`) on the SQL `OrderRepository::getOrdersByStatus()` now generates shows an index being used for the `business_date` predicate (`key` column is not `NULL`), run for real against the dev DB.
4. A real report call (e.g. `ReportService::getSalesByDay()` for a date range covering existing real orders) returns the same data as it did before this spec's changes, verified by comparing actual output for a fixed range before and after.
5. No new migration file exists in `common/migrations/` for this spec.

## Implementation plan

1. `OrderRepository::getOrdersByStatus()` and `KitchenService::getFoodCategorySummary()` — smaller, verify first.
2. `ReportService.php` — one method at a time, re-running each report call against the real dev DB after each change to compare before/after output.
3. Extend `OrderRepositoryTest` with the date-filter assertion.
4. Run `EXPLAIN` against the dev DB for the main query (Acceptance criterion 3).
5. Run `vendor/bin/phpunit`.

## Testing and validation strategy

Unit-level: `OrderRepositoryTest` extended to assert `getOrdersByStatus()`-equivalent filtering behavior (via the repository directly) picks up an order on its own `business_date` and not an adjacent day. `ReportService` has no existing test file and this spec's change to it is a mechanical, repeated substitution rather than new business logic — verified instead by real before/after comparison against the dev DB's actual data (Acceptance criterion 4) and a real `EXPLAIN` (Acceptance criterion 3), which is what actually proves the sargability claim — a unit test against mocked/small data cannot demonstrate an index being used.

## Rollout and rollback

No feature flag, no migration. Rollback is a plain revert of the query changes; no stored data or schema involved.

## Open questions

None blocking. Whether to eventually build real pagination for a future "Admin Order History" feature is explicitly deferred to that feature's own spec (Non-goals), not decided here.

## Task checklist

- [x] `OrderRepository::getOrdersByStatus()` switched to `business_date`
- [x] `KitchenService::getFoodCategorySummary()` switched to `business_date`
- [x] `ReportService.php` — all 8 call sites switched
- [x] `OrderRepositoryTest` extended
- [x] `EXPLAIN` run against the dev DB, index usage confirmed
- [x] Before/after report output comparison run against the dev DB
- [x] `vendor/bin/phpunit` passing

## Implementation log

- 2026-09-05 — Live index/row-count check against the dev DB (`SHOW INDEX`) before writing anything, confirming `orders.order_number`/`order_items.order_id`/`jobs`' actual pattern were already adequately indexed — narrowed the real gap to `orders.created_at`'s non-sargable `whereDate()` usage (Problem, points 1-2), not a blanket "add the roadmap's listed indexes" exercise.
- 2026-09-05 — Found `getOrdersByHour()`'s `HOUR(orders.created_at)` grouping was correctly left alone (Non-goals) — only its outer date-range filter needed the `business_date` substitution.
- 2026-09-05 — Before claiming the substitution is behavior-preserving, ran a live check: `SELECT COUNT(*) FROM orders WHERE business_date != DATE(created_at)` → `0` — confirmed every real row already has the two in agreement, not merely assumed from the reasoning in spec 019.
- 2026-09-05 — Real `EXPLAIN` comparison against the dev DB (58 rows) was more conclusive than expected even at this tiny scale: the old `DATE(created_at) = ...` pattern showed `type: ALL, key: NULL` (full table scan, all 58 rows); the new `business_date = ...` pattern showed `type: ref, key: uniq_order_number_per_day` (index lookup, 5 rows) — the sargability claim is not theoretical, it's directly observable today.
- 2026-09-05 — Ran every changed `ReportService` method for real against the dev DB's actual data (`getSalesByDay`, `getAvgPrepTime`, `getMonthlyComparison`, `getDailySummary`) — all returned sane, correctly-shaped output; no crashes, no `whereDate` left anywhere in the file (confirmed by `grep`).
- 2026-09-05 — Concluded, and documented rather than silently skipped, that Pagination needs no code change: every list-returning endpoint in this app is already bounded today, by business-day scope (orders, kitchen summary), an explicit `limit` parameter (top items), daily/hourly-row-not-per-order granularity (the other reports), or inherently small operator-curated data (menu, ingredients) — see Problem, point 4.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found. No migration file was created, as planned (Acceptance criterion 5).

## Validation evidence

- **Acceptance criterion 1** — `vendor/bin/phpunit` → `OK (50 tests, 87 assertions)` (49 pre-existing + 1 new `OrderRepositoryTest` case). **Confirmed.**
- **Acceptance criterion 2** — `OrderRepositoryTest::testGetOrdersByStatusFiltersByBusinessDateNotAdjacentDay`: an order created "today" and a manually-inserted "yesterday" order are correctly separated by `getOrdersByStatus('pending', <date>)` for each respective date. **Confirmed.**
- **Acceptance criterion 3** — real `EXPLAIN` against the dev DB: old pattern `type=ALL, key=NULL, rows=58`; new pattern `type=ref, key=uniq_order_number_per_day, rows=5`. **Confirmed**, and more decisively than the criterion required.
- **Acceptance criterion 4** — `business_date`/`DATE(created_at)` mismatch count on real data: `0`, proving output equivalence by construction rather than a literal before/after git-stash comparison; every changed `ReportService` method additionally re-run live against real data with sane output. **Confirmed.**
- **Acceptance criterion 5** — `ls common/migrations/` unchanged by this spec (no new file). **Confirmed.**
- `php -l` clean on every changed PHP file.
