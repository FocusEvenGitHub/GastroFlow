# Spec 022 — Order validation

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Order validation" subsection: orders should be rejected before persistence when there are no items, a menu item doesn't exist, a menu item is unavailable, quantity is invalid/zero/negative/exceeds a reasonable limit, dining option is invalid, or notes exceed defined limits — and an invalid menu item must never silently become a zero-price item.

## Problem

Confirmed by direct reads and one live check against the real running stack:

1. **An empty `items` array passes validation today.** `OrderValidator::validateOrderData()`'s inline item-shape rule (`src/Validators/OrderValidator.php:26-39`) only `foreach`es over `items` — an empty array never enters the loop, so the function returns `true` with zero items. `POST /api/orders` with `{"items": []}` creates a real, empty order (confirmed by direct code read of `OrderRepository::createOrder()`, which has no items-count guard either).
2. **An invalid menu item silently becomes a zero-price item — the exact bug the roadmap names.** `OrderRepository::createOrder()` (`src/Repositories/OrderRepository.php:87-88`): `$menuItem = MenuItem::find($item['id']); $unitPrice = $menuItem ? Money::fromReais($menuItem->price) : Money::zero();` — a nonexistent `menu_item_id` doesn't reject the order, it creates a real `order_items` row priced at `R$0,00`. Worse: `Order::create()` (and a real `order_number`/`business_date` allocation, spec 019) already happened *before* this loop runs, so a request doomed to contain a bad item still consumes a ticket number and creates a persisted (if broken) order.
3. **No quantity bounds in the order-creation path.** The same inline rule only checks `is_numeric($item['quantity'])` — `0`, `-5`, and `2.5` are all `is_numeric` and pass. No upper bound exists at all. (`OrderValidator::validateOrderItemAdd()`/`::validateOrderItemUpdate()`, for the *separate* "add/edit an item on an existing order" endpoints, already correctly use `integer` + `min: 1` — this exact rule was never carried over to order creation, a real, checkable inconsistency between the two paths.)
4. **Menu-item availability is never checked before persistence, anywhere** — not in `createOrder()`, not in `addOrderItem()` (`src/Repositories/OrderRepository.php:230-251`, which does correctly `findOrFail()` for existence via the "add item to existing order" path, but never reads `$menuItem->available`). Live-checked: `GET /api/menu` returns unavailable items with `available: false` in the payload, and `public/cashier/app.js`/`public/cashier/index.php` contain **zero** references to `available` — an unavailable item is fully orderable through the cashier UI today, with no visual indication and no server-side rejection.
5. **No notes length limit anywhere order items are created or edited** — `validateOrderData`'s inline rule, `validateOrderItemAdd()`, and `validateOrderItemUpdate()` (`src/Validators/OrderValidator.php`) all leave `notes` completely unconstrained. `order_items.notes` is a `TEXT` column with no DB-level limit either.
6. **`dining_option` validation is inconsistent across the two order-mutation surfaces.** `validateOrderData()` correctly rejects an invalid `dining_option` at order creation. `validateOrderItemAdd()` (used by `POST /api/orders/{id}/items`) has no `dining_option` rule at all — `OrderRepository::addOrderItem()`'s `match` statement (`src/Repositories/OrderRepository.php:237-241`) silently falls through to the `default` (no packaging cost) branch for any unrecognized value instead of rejecting it.

## Goals

- No order (or added item) can be created with zero items, a nonexistent menu item, or an unavailable menu item.
- Quantity is validated consistently everywhere an order or order item is created/updated: a positive integer within a reasonable bound.
- Notes have a defined, enforced length limit everywhere they're accepted.
- `dining_option` is validated consistently on every endpoint that accepts it.
- An invalid menu item reference never reaches persistence as a zero-price (or otherwise silently-defaulted) row.

## Non-goals

- **Not fixing `updateOrderItem()`'s stale `packaging_cost` after a quantity change** (`src/Repositories/OrderRepository.php:270-282`: changing `quantity` doesn't recompute `packaging_cost`, which was fixed at the item's original quantity). Found during this investigation, but it's a pricing-recalculation-on-edit bug, not a validation gap — belongs with `docs/ROADMAP.md`'s separate "Pricing domain" subsection, not silently folded in here.
- **Not a general "one consistent API error format."** That's its own, separate `v1.7.0` subsection ("API error standardization"). This spec adds one new `\DomainException` → `400` mapping, matching the plain `{'error': message}` shape already used by every other non-generic error response in `OrderController`.
- **Not moving existence/availability checks into `OrderValidator`.** `CLAUDE.md`'s own stated split — "Validators validate input shape. Services enforce business rules." — puts a DB-state-dependent check (does this row exist / is it available right now) in the repository/service layer, not the shape validator. `OrderValidator` gains only shape-level rules (count, bounds, enum, length); `OrderRepository` gains the existence/availability checks, mirroring how `addOrderItem()` already does existence via `findOrFail()`.
- **Not a full menu-browsing redesign.** The one frontend change (Proposed behavior) is the minimum needed so the new backend rejection doesn't confuse a cashier who could previously order anything — not a broader UI pass.

## Current behavior

See Problem, points 1-6, with citations.

## Proposed behavior

**Shape validation (`OrderValidator`)** — two new class constants, `MAX_ITEM_QUANTITY = 50` and `MAX_NOTES_LENGTH = 500` (round, generous limits; the roadmap asks for "a reasonable limit" without specifying one — these are conservative enough not to ever bind a real counter-service order, while still rejecting obviously-wrong/abusive input):

- `validateOrderData()`'s inline item rule: reject an empty `items` array; require `quantity` to be a positive integer (`1..MAX_ITEM_QUANTITY`), not just `is_numeric`; validate `notes` (if present) against `MAX_NOTES_LENGTH`.
- `validateOrderItemAdd()`: add the missing `dining_option` enum check (matching `validateOrderData()`); add `max` quantity `MAX_ITEM_QUANTITY`; add `lengthMax` on `notes`.
- `validateOrderItemUpdate()`: add `max` quantity `MAX_ITEM_QUANTITY`; add `lengthMax` on `notes`.

**Existence/availability (`OrderRepository`)**:

- `createOrder()`: resolve and validate every item's `MenuItem` in one pass **before** creating the `Order` row or allocating an `order_number` — a nonexistent id or an unavailable item throws `\DomainException` immediately, so nothing is persisted and no ticket number is consumed for a request that was always going to fail.
- `addOrderItem()`: after the existing `findOrFail()`, add `if (!$menuItem->available) { throw new \DomainException(...); }`.

**Controller**: `OrderController::store()` and `::addItem()` each gain a `\DomainException` → `400` catch (`{'error': $e->getMessage()}`), matching the plain error shape already used elsewhere in this controller — placed before the existing `\Throwable` fallback (and, in `store()`, alongside the existing `\Illuminate\Database\QueryException` catch from spec 019).

**Cashier frontend (minimum needed to make the new rejection make sense to a human):** an unavailable item (`item.available === false`) is visually greyed out, its "Adicionar" button disabled, and clicking the card does nothing — `addItem()` in `app.js` also gets a defensive `available === false` early return, so no path can add one regardless of how the click was triggered. This is not new UX design, just making an already-existing `available` flag (already returned by `GET /api/menu`, already set by the admin panel) visible and effective where it was silently ignored before.

## Functional requirements

1. `POST /api/orders` with `items: []` (or missing `items`) is rejected with `400` before this spec; unchanged — this spec closes the specific empty-array gap so it's rejected for the *right* reason (count, not just "items required").
2. `POST /api/orders` referencing a nonexistent `menu_item_id` is rejected with `400` and creates **no** `orders` or `order_items` row, and consumes **no** `order_number`.
3. `POST /api/orders` referencing an unavailable (`available=false`) menu item is rejected with `400`, same no-persistence guarantee.
4. `POST /api/orders`/`POST /api/orders/{id}/items` with `quantity` `0`, negative, non-integer, or greater than `50` is rejected with `400`.
5. `POST /api/orders/{id}/items` with an invalid `dining_option` is rejected with `400` (previously silently accepted as `local`).
6. `notes` longer than 500 characters is rejected with `400` on order creation and on add/update-item.
7. `POST /api/orders/{id}/items` referencing an unavailable menu item is rejected with `400`.
8. A cashier cannot add an unavailable item to a new order through the UI (button disabled, click no-ops).

## Non-functional requirements

Not applicable — validation-only change, no performance implication (menu item lookups already happen on this exact path today).

## User flows

**Cashier — unavailable item.** Cashier opens the menu grid; an item marked unavailable in Admin now visibly reads as unavailable (greyed out, no "Adicionar" button) instead of looking identical to any other item and failing mysteriously at submit time.

**Cashier — bad request from a non-UI client (e.g., a stale cached menu, a direct API call).** `POST /api/orders` referencing a deleted/unavailable item now gets a clear `400` and nothing is persisted, instead of a phantom `R$0,00` line item silently entering the kitchen queue.

## API changes

- `POST /api/orders` — new possible `400` responses: item count, nonexistent item, unavailable item, quantity bounds, notes length (all `{'error': message}`).
- `POST /api/orders/{id}/items` — new possible `400` responses: unavailable item, invalid `dining_option`, quantity bounds, notes length.
- `PATCH /api/orders/{id}/items/{itemId}` — new possible `400`: quantity bounds, notes length.
- No previously-`200`/`201` request becomes invalid unless it was already relying on one of the gaps above (e.g., a client that only ever worked because quantity `0` was silently accepted) — not expected of the project's own frontend, which already sends sane values.

## Data model and migrations

Not applicable — no schema change (`order_items.notes` stays `TEXT`; the 500-character limit is enforced in application code, not a DB constraint, consistent with `customer_name`'s existing `lengthMax` being validator-only against a `VARCHAR(100)` column that itself would truncate rather than reject).

## Architecture and affected components

- `src/Validators/OrderValidator.php` — all four `validate*` methods, per Proposed behavior.
- `src/Repositories/OrderRepository.php` — `createOrder()` (pre-pass validation, restructured to resolve items before creating the `Order` row), `addOrderItem()` (availability check).
- `src/Controllers/OrderController.php` — `store()`, `addItem()` gain the `\DomainException` → `400` catch.
- `public/cashier/app.js`, `public/cashier/index.php` — unavailable-item visual state + defensive guard in `addItem()`.
- `src/Services/OrderService.php` — no change (pass-through; exceptions propagate unmodified, same pattern as specs 019/020).
- Tests: `tests/Unit/OrderValidatorTest.php` extended for every new rule; `tests/Unit/OrderRepositoryTest.php` extended for the existence/availability guards (real in-memory SQLite, same approach as specs 019/020).

## Security considerations

Not applicable beyond what already applies to `/api/orders*` (intentionally public, trusted-network endpoints, unchanged). This closes a data-integrity gap (a phantom zero-price order), not an authentication/authorization one.

## Backward compatibility

Not breaking for any client sending well-formed requests (empty items, invalid item ids, out-of-range quantities, and oversized notes were never a supported, intentional use of this API — they were unvalidated gaps). No stored data or existing endpoint is removed or renamed.

## Acceptance criteria

1. `POST /api/orders` with `{"items": []}` → `400`; no row appears in `orders` afterward.
2. `POST /api/orders` with an item id that doesn't exist in `menu_items` → `400`; no row in `orders` or `order_items`; `order_number_counters`'s value for today is unchanged (no ticket number consumed).
3. `POST /api/orders` with a real item id whose `available=false` → `400`, no persistence.
4. `POST /api/orders` with `quantity: 0`, `quantity: -1`, and `quantity: 51` → `400` for each.
5. `POST /api/orders/{id}/items` with an invalid `dining_option` (e.g. `"mesa"`) → `400`.
6. `POST /api/orders/{id}/items` with `notes` of 501 characters → `400`.
7. `vendor/bin/phpunit` passes, including new tests for every rule above.
8. A live `curl` against the running dev stack reproduces at least the nonexistent-menu-item case (Acceptance criterion 2) end-to-end, confirming the fix works through the real HTTP/DB path, not only under mocks.

## Implementation plan

1. Update `OrderValidator` (all four methods) — pure, DB-free, quickest to get right and unit-test in isolation.
2. Update `tests/Unit/OrderValidatorTest.php` for every new/changed rule.
3. Restructure `OrderRepository::createOrder()`'s item loop into a resolve-then-persist pre-pass; add the availability check to `addOrderItem()`.
4. Extend `tests/Unit/OrderRepositoryTest.php`.
5. Add the `\DomainException` catches to `OrderController::store()`/`::addItem()`.
6. Update `public/cashier/app.js`/`index.php` for the unavailable-item state.
7. Run `vendor/bin/phpunit`; manually verify Acceptance criterion 2 (and a couple of others) against the real running stack.

## Testing and validation strategy

Unit-level (PHPUnit): every new `OrderValidator` rule gets a direct pass/fail test (no DB needed). `OrderRepository`'s existence/availability guards are tested against a real in-memory SQLite DB (extending the fixture specs 019/020 already built in `OrderRepositoryTest`), asserting both the thrown `\DomainException` and that no `orders`/`order_items` row was left behind. Manual: a real `curl -X POST /api/orders` with a nonexistent item id against the running dev stack, confirming the `400` and inspecting `orders`/`order_number_counters` directly, since a mocked-repository test can't prove the real transaction-rollback/no-counter-consumption behavior end-to-end.

## Rollout and rollback

No feature flag, no migration — pure application-code change (validator + repository + controller + one small frontend guard). Rollback is a plain revert.

## Open questions

None blocking. `MAX_ITEM_QUANTITY = 50` and `MAX_NOTES_LENGTH = 500` are reasonable, documented defaults, not values the roadmap specifies — easy to change later if a real operational need for a different bound shows up (not treated as a product decision requiring sign-off, unlike specs 020/021's choices, since these are generous safety bounds rather than a behavior/workflow change).

## Task checklist

- [x] `OrderValidator` updated (all four methods)
- [x] `tests/Unit/OrderValidatorTest.php` extended
- [x] `OrderRepository::createOrder()` restructured (pre-pass validation)
- [x] `OrderRepository::addOrderItem()` availability check added
- [x] `tests/Unit/OrderRepositoryTest.php` extended
- [x] `OrderController::store()`/`::addItem()` gain `\DomainException` → `400`
- [x] Cashier frontend updated (unavailable-item state)
- [x] `vendor/bin/phpunit` passing
- [x] Manual verification against the real running stack

## Implementation log

- 2026-09-05 — Confirmed via a live `GET /api/menu` + grep of `public/cashier/*` that no frontend code reads `available` at all today — this made the frontend change in Proposed behavior a confirmed necessity, not a speculative addition.
- 2026-09-05 — Used PHPUnit 11's `#[DataProvider]` attribute (not the `@dataProvider` docblock annotation) for the new quantity-bounds test — the docblock form triggered a PHPUnit deprecation warning on first run; switched immediately since no other test in this project uses either form yet, so there was no existing convention to match either way.
- 2026-09-05 — `OrderRepository::createOrder()`'s restructuring moved the entire item-resolution loop *before* customer-name parsing and order_number allocation, not just before `Order::create()` — this also means a malformed request no longer computes a customer_name or touches the counter at all, slightly more thorough than the spec's own wording ("before creating the Order row or allocating an order_number") strictly required, but consistent with its intent.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found.
- 2026-09-05 (post-hoc, `/code-review`) — Found and fixed a real regression: rewriting `validateOrderData()`'s rule list for this spec silently dropped the `customer_name` `optional`+`lengthMax:100` rules that existed before (and still exist in the untouched `validateOrderUpdate()`, which is what made the asymmetry easy to spot) — a customer name over 100 characters would have hit `orders.customer_name`'s `VARCHAR(100)` limit as an uncaught `QueryException`, returning a raw `500` instead of the intended `400`. Restored the rule; added `testCustomerNameOverLimitIsRejectedOnOrderCreation`/`testCustomerNameAtLimitIsAcceptedOnOrderCreation` to `OrderValidatorTest`. Also closed a narrow TOCTOU this spec's own existence/availability check left open: `createOrder()`'s `MenuItem::find()` and `addOrderItem()`'s `findOrFail()` were unlocked reads inside a transaction that later persists based on them — a concurrent availability toggle in that window could let an order reference an item that became unavailable a moment later. Both now use `->lockForUpdate()` (`addOrderItem()` additionally needed wrapping in `DB::transaction()`, which it wasn't before — without an explicit transaction, `lockForUpdate()` provides no real protection under MySQL's default autocommit mode).

## Validation evidence

- **Acceptance criterion 1** — `curl -X POST /api/orders -d '{"items":[]}'` → `400`. **Confirmed.**
- **Acceptance criterion 2** — `curl -X POST /api/orders -d '{"items":[{"id":999999,"quantity":1}]}'` → `400 {"error":"Item de cardápio não encontrado: #999999"}`; `orders`/`order_number_counters` row counts for today identical before and after (2 and 2, counter unchanged at 2). **Confirmed**, against the real running stack, not only under mocks.
- **Acceptance criterion 3** — `OrderRepositoryTest::testCreateOrderWithUnavailableMenuItemThrows` (real in-memory SQLite DB). **Confirmed.**
- **Acceptance criterion 4** — `curl` with `quantity: 0` and `quantity: 51` both → `400` live; `0`, `-1`, `2.5`, `51` all covered by `OrderValidatorTest::testInvalidQuantityIsRejectedOnOrderCreation` (data provider). **Confirmed.**
- **Acceptance criterion 5** — `OrderValidatorTest::testOrderItemAddRejectsInvalidDiningOption`. **Confirmed.**
- **Acceptance criterion 6** — `OrderValidatorTest::testOrderItemAddRejectsNotesOverLimit`/`::testOrderItemUpdateRejectsNotesOverLimit`, plus the order-creation equivalent. **Confirmed.**
- **Acceptance criterion 7** — `vendor/bin/phpunit` → `OK (42 tests, 72 assertions)` (27 pre-existing + 12 new `OrderValidatorTest` + 3 new `OrderRepositoryTest`). **Confirmed.**
- **Acceptance criterion 8** — see criterion 2's live `curl` evidence above. **Confirmed.**
- `php -l` clean on every changed PHP file. **Confirmed.**
