# Spec 023 — Historical order snapshots

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Historical order snapshots" subsection: historical orders must remain accurate after menu changes; order items should preserve item name, unit price, and packaging value; reports and receipts for old orders should not depend on current menu prices.

Price was already snapshotted by `common/migrations/008_order_items_price.sql` (`order_items.unit_price`/`packaging_cost`, confirmed live-correct by spec 021). **Item name was never snapshotted** — this spec closes that specific, remaining gap.

## Problem

Confirmed by direct reads:

1. `order_items` has no `item_name` (or any name-like) column (`common/sql/001_schema.sql:42-52`). Every read of an order item's name is a **live join** to `menu_items.name` — `OrderRepository::getOrdersByStatus()` (`src/Repositories/OrderRepository.php:36`: `$item->menuItem->name ?? 'Unknown'`), `PrintService::printOrder()` (`src/Services/PrintService.php:219`: `$name = $menuItem->name;`), and `ReportService::getTopItems()` (`src/Services/ReportService.php:52-62`, joining `menu_items.name` and grouping by it). If a menu item is renamed, every past order/receipt/report referencing it silently displays the *new* name for what was actually sold under the old one — exactly the inaccuracy the roadmap names.
2. **Reprinting makes this concretely observable, not just theoretical.** `public/kitchen/app.js`'s `reprintOrder()` → `POST /api/orders/{id}/print` → `PrintService::printOrder()` can run long after the original order, with no re-verification that the menu hasn't changed since. A reprinted receipt for an old order would show today's item name, not the name printed (and presumably handed to the customer) the first time.
3. **`?? 'Unknown'` (`OrderRepository.php:36`) is effectively dead code today**, not a real safety net — `common/sql/001_schema.sql:51`, `FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)` has no `ON DELETE CASCADE`/`SET NULL`, so MySQL's default `RESTRICT` already prevents deleting a menu item that any `order_items` row references (confirmed by `MenuController::delete()`'s own existing `QueryException` catch for exactly this FK violation, `src/Controllers/MenuController.php:160-165`). A menu item referenced by history can be renamed, but never actually vanish out from under `menu_items.name ?? 'Unknown'` — so this fallback isn't the real protection the roadmap is asking for.

## Goals

- `order_items` stores the item's name at the moment it was ordered.
- Every read of an order item's name (kitchen/order listing, receipt printing, the top-items report) uses the stored snapshot, not a live join to `menu_items`.
- Existing historical rows get a best-effort backfill (today's live name — the closest available approximation for orders that predate this spec; a genuinely accurate historical name for an item already renamed before this migration cannot be recovered, and this spec does not claim otherwise).

## Non-goals

- **Not snapshotting `description` or `category_name`.** The roadmap's own wording lists "item name, unit price, packaging value" — not description or category. Both stay live-joined, unchanged.
- **Not a `PricingService` extraction** (`docs/ROADMAP.md`'s separate "Pricing domain" subsection) — this spec only adds one more historical field alongside the price fields spec 021/migration 008 already handle correctly.
- **Not adding a "menu item was renamed" audit log.** Out of scope — `v1.8.0`'s "Audit history" is the relevant future item if that's ever wanted.
- **Not changing `MenuController::delete()`'s existing FK-violation handling** — already correct (Problem, point 3), not touched.

## Current behavior

See Problem, points 1-3, with citations.

## Proposed behavior

Add `order_items.item_name VARCHAR(100) NOT NULL DEFAULT ''` (matching `menu_items.name`'s own length, `common/sql/001_schema.sql:21`), populated:

- **Going forward**: captured once, at the moment an item is added to an order — `OrderRepository::createOrder()` and `::addOrderItem()` both already resolve the `MenuItem` before creating the `OrderItem` row; both now also copy `$menuItem->name` into `item_name` at that same moment.
- **For existing rows**: backfilled from each row's *current* `menu_items.name` in the migration itself — the best available approximation, not a claim of perfect historical accuracy for items already renamed before this migration ran (see Backward compatibility).

Every read site switches from the live join to the stored value:

- `OrderRepository::getOrdersByStatus()` — `'name' => $item->item_name` (drops the now-inapplicable `?? 'Unknown'` fallback — the column is `NOT NULL DEFAULT ''` and always populated going forward and by the backfill).
- `PrintService::printOrder()` — `$name = $orderItem->item_name;`. This also removes `PrintService`'s only remaining dependency on the `menuItem` relation (it used nothing else from it), so the `if (!$menuItem) continue;` guard (dead code per Problem, point 3, but still present) is removed along with the now-unnecessary relation access.
- `ReportService::getTopItems()` — regrouped by `order_items.menu_item_id` (the stable identity) instead of `menu_items.id, menu_items.name`, taking `MAX(order_items.item_name) as name` for display. This drops the `menu_items` join entirely (the report no longer needs current menu state at all) and avoids splitting one item's stats into two rows if it was renamed partway through the queried date range — a report is a business summary, not a forensic per-name audit trail.

## Functional requirements

1. `order_items.item_name` exists, `NOT NULL`, and is populated for every row (new and pre-existing) after the migration.
2. Creating an order (`POST /api/orders`) stores the menu item's name at that moment in the new column.
3. Adding an item to an existing order (`POST /api/orders/{id}/items`) does the same.
4. `GET /api/orders`'s item `name` field comes from the stored snapshot, not a live join.
5. A reprinted receipt (`POST /api/orders/{id}/print`) shows the name stored at order-creation time, not the menu item's current name, if they've since diverged.
6. `ReportService::getTopItems()` shows the (best-effort, most-recent) stored name per `menu_item_id`, without joining `menu_items`.
7. Renaming a menu item in Admin after an order exists does not change how that order's item name displays anywhere in the app.

## Non-functional requirements

`ReportService::getTopItems()` loses one `JOIN` (a minor, incidental performance improvement, not the goal of this spec).

## User flows

**Admin renames a menu item; kitchen/reports/reprints are unaffected for orders placed before the rename.** Concretely verifiable: create an order, rename the item in Admin, re-fetch the order (`GET /api/orders`) and reprint it — both must still show the original name.

## API changes

`GET /api/orders`'s response `items[].name` field is unchanged in shape (still a string) — only its *source* changes, invisibly to any consumer.

## Data model and migrations

New file `common/migrations/014_order_item_name_snapshot.sql` (next free migration number — `013_order_cancellation.sql` is current latest):

```sql
ALTER TABLE order_items ADD COLUMN item_name VARCHAR(100) NOT NULL DEFAULT '' AFTER menu_item_id;

UPDATE order_items oi
JOIN menu_items mi ON mi.id = oi.menu_item_id
SET oi.item_name = mi.name
WHERE oi.item_name = '';
```

Guarded the same way migration 006 already established for this project (idempotent `ADD COLUMN` check against `information_schema` before altering, in case this migration is ever re-run against a partially-migrated database) — full guard written during implementation, following that exact existing pattern rather than a new one.

## Architecture and affected components

- `common/migrations/014_order_item_name_snapshot.sql` (new).
- `src/Models/OrderItem.php` — add `item_name` to `$fillable`.
- `src/Repositories/OrderRepository.php` — `createOrder()`, `addOrderItem()` (capture `item_name`); `getOrdersByStatus()` (read from snapshot).
- `src/Services/PrintService.php` — `printOrder()` (read from snapshot, drop the now-fully-unused `menuItem` access and its dead `continue` guard).
- `src/Services/ReportService.php` — `getTopItems()` (regroup by `menu_item_id`, drop the `menu_items` join).
- Tests: `tests/Unit/OrderRepositoryTest.php` (item_name captured on create/add); `tests/Unit/PrintServiceTest.php` (already builds fake `OrderItem`s with a `menuItem` relation for the name — switch fixtures to set `item_name` directly, proving `PrintService` no longer reads the relation for it).

## Security considerations

Not applicable — no new input surface (the name is always copied from an already-trusted `MenuItem` row, never accepted directly from the client).

## Backward compatibility

**Not a claim of perfect historical accuracy.** For any menu item already renamed *before* this migration runs, the backfill uses today's (post-rename) name — the true, original name at the time each of those past orders was placed is not recoverable from data this project has ever stored. This is disclosed plainly, not glossed over: the fix stops the bug from continuing, it doesn't retroactively repair already-lost history. No API shape changes, no breaking change for any consumer.

## Acceptance criteria

1. `SHOW COLUMNS FROM order_items LIKE 'item_name'` → present, `NOT NULL`.
2. Every existing `order_items` row has a non-empty `item_name` immediately after the migration runs (`SELECT COUNT(*) FROM order_items WHERE item_name = ''` → `0`).
3. Create an order for a real menu item, then rename that menu item in `menu_items` directly (simulating an Admin rename) — `GET /api/orders?status=pending` for that order still shows the *original* name.
4. Reprinting that same order (`POST /api/orders/{id}/print`) produces a receipt with the original name, not the renamed one — verified by inspecting the print job's rendered output (real `PrintService` call against a test connector, per the pattern already established in `PrintServiceTest`).
5. `ReportService::getTopItems()` for a date range spanning the rename still attributes all quantity/revenue to a single row (grouped by `menu_item_id`, not split by name).
6. `vendor/bin/phpunit` passes, including new/updated tests for the above.

## Implementation plan

1. Write and apply `common/migrations/014_order_item_name_snapshot.sql`; verify against the dev DB (Acceptance criteria 1-2).
2. Update `OrderItem` model, `OrderRepository::createOrder()`/`::addOrderItem()`/`::getOrdersByStatus()`.
3. Update `PrintService::printOrder()`.
4. Update `ReportService::getTopItems()`.
5. Update/extend tests.
6. Manually verify Acceptance criteria 3-5 against the real running stack (a live rename is the clearest possible proof this actually works, not just that the code compiles).

## Testing and validation strategy

Unit-level (PHPUnit): `OrderRepositoryTest` extended to assert `item_name` is captured correctly on both creation paths (real in-memory SQLite, same fixture approach as specs 019/020/022). `PrintServiceTest`'s existing fake-`OrderItem` helper updated to set `item_name` directly instead of relying on a `menuItem` relation, proving the print path no longer needs it. Manual, real-stack verification for the rename scenario itself (Acceptance criteria 3-5) — genuinely proving "renaming didn't change history" requires an actual rename against real data, which a unit test with static fixtures can assert but not truly demonstrate end-to-end.

## Rollout and rollback

No feature flag. Migration adds a column with a safe default and backfills immediately — reversible by a manual `ALTER TABLE order_items DROP COLUMN item_name` if ever needed (this project's migrations have no automatic down-migration, per `CLAUDE.md`).

## Open questions

None blocking.

## Task checklist

- [x] Migration `014_order_item_name_snapshot.sql` written, applied, verified
- [x] `OrderItem` model updated
- [x] `OrderRepository::createOrder()`/`::addOrderItem()` capture `item_name`
- [x] `OrderRepository::getOrdersByStatus()` reads from snapshot
- [x] `PrintService::printOrder()` reads from snapshot, drops dead `menuItem` guard
- [x] `ReportService::getTopItems()` regrouped by `menu_item_id`, drops `menu_items` join
- [x] Tests updated/added
- [x] `vendor/bin/phpunit` passing
- [x] Manual rename-scenario verification against the real running stack

## Implementation log

- 2026-09-05 — Confirmed empirically (not just by reading source) that `MenuItem::with('category')->findOrFail(...)` in `addOrderItem()` doesn't require a `categories` table in the `OrderRepositoryTest` SQLite fixture — the existing test suite (spec 022's `testAddOrderItemWithUnavailableMenuItemThrows`) already exercised this exact call successfully with no `categories` table present, so no fixture change was needed for the two new tests added here.
- 2026-09-05 — Removing `PrintService`'s `menuItem` relation access turned out to fully eliminate its only use in that method — confirmed via `grep`, then removed the dead `if (!$menuItem) continue;` guard alongside it (already established as unreachable in Problem, point 3), rather than leaving it as inert leftover code.
- 2026-09-05 — Live end-to-end verification went further than a single rename check: created an order, completed it, renamed the menu item directly in the DB, created and completed a *second* order for the same (now differently-named) item, and confirmed `ReportService::getTopItems()` still attributed both orders' quantity to one consolidated row (`total_qty: 3`) — proving the `GROUP BY menu_item_id` change, not just the snapshot column itself.
- 2026-09-05 — The live-rename test touched real menu data (item id 72, "Beterraba") rather than a throwaway fixture — restored its original name immediately after verification, and cancelled the two orders created for the test (via spec 020's `POST /api/orders/{id}/cancel`, since no hard-delete exists).
- 2026-09-05 — Reprinting (Acceptance criterion 4) was validated via the existing `PrintServiceTest` unit-test path (captured-connector assertion on printed text), not a live print job — this dev environment has no real/dummy network printer configured for `bin/worker`, and the unit test already exercises the exact same `PrintService::printOrder()` code path with real assertions on rendered output, which is what actually proves the fix.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found.

## Validation evidence

- **Acceptance criterion 1** — `SHOW COLUMNS FROM order_items LIKE 'item_name'` → `item_name varchar(100) NO`. **Confirmed.**
- **Acceptance criterion 2** — `SELECT COUNT(*) FROM order_items WHERE item_name = ''` → `0`; spot-checked real backfilled values (e.g. `Barça de Frango`, `Luís de Frango`) against real `menu_item_id`s. **Confirmed.**
- **Acceptance criterion 3** — created order 84 (item 72, "Beterraba"), renamed item 72 to "Beterraba Renomeada" directly in the DB, `GET /api/orders?status=pending` for that order still returned `["Beterraba"]`. **Confirmed**, live, against the real running stack.
- **Acceptance criterion 4** — `PrintServiceTest::testReceiptTotalIsExactAcrossManyItems` (and the other `PrintServiceTest` cases) now build fixtures via `item_name` with no `menuItem` relation at all, and still pass — proving `PrintService` reads the snapshot, not a live join. **Confirmed** (unit-level; see Implementation log for why not a live print job).
- **Acceptance criterion 5** — completed order 84 and a second order (2× item 72, post-rename), called `ReportService::getTopItems()` for today directly: one row, `name: "Beterraba Renomeada"`, `total_qty: 3` (1 + 2) — not split into two rows despite the two orders having different stored `item_name` snapshots. **Confirmed**, live.
- **Acceptance criterion 6** — `vendor/bin/phpunit` → `OK (44 tests, 75 assertions)` (42 pre-existing + 2 new `OrderRepositoryTest` cases). **Confirmed.**
- `php -l` clean on every changed PHP file. **Confirmed.**
