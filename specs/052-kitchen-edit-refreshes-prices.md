# Spec 052 — Kitchen "Editar Pedido" refreshes prices from the Admin

## Metadata

- Status: Verified
- Created: 2026-10-02
- Updated: 2026-10-02
- Owner: Henry
- Related issue: Trello card #73 "Editar Pedido: atualizar preços com os dados do Admin ao salvar" (follow-up recorded in spec 050)
- Related branch: `052`

## Context

During the verification of spec 050 (PR #29) the client asked that, in the kitchen's "Editar Pedido" modal, every save that changes items should refresh the order with the current Admin data. The client already decided, in spec 050, that saving re-prices the **packaging fee** at the current price. This spec extends that to the **item price**.

Decisions made by the user on 2026-10-02:
- **What is refreshed:** the item's unit price, including "Monte Seu Prato" dishes (base price plus add-ons at current prices). The item **name stays** the order-time snapshot.
- **Which orders:** any order edited through the modal, pending or done (the same scope as the packaging decision). Cancelled orders stay non-editable, as today.

## Problem

Confirmed in code:
- `OrderRepository::updateOrderItem()` (`src/Repositories/OrderRepository.php`, ~L477-517) recomputes `packaging_cost`, but **never touches `unit_price`**. An existing item keeps its order-time price forever, even after a kitchen edit.
- For "Monte Seu Prato" dishes, the add-on prices are snapshotted in `order_item_components.unit_price` (spec 030) and are never refreshed either.
- The kitchen loads `/api/menu` only once, when the page opens (`public/kitchen/app.js:50`, `loadMenu()` at ~L101). The prices the modal shows (the Simples/VIP button titles from spec 050) go stale until the page is reloaded.

## Goals

- **Item price:** every successful `PATCH /api/orders/{id}/items/{itemId}` (which is what "Salvar" in "Editar Pedido" sends, once per item) recomputes the item's `unit_price` from the current menu.
- **Monte Seu Prato:** a customizable dish's add-on prices (`order_item_components.unit_price`) and its composed `unit_price` are refreshed the same way.
- **Packaging fee:** recomputed on every such save, not only when quantity or option changes.
- **Kitchen menu:** reloaded when the modal opens, so it shows current prices.

## Non-goals

- The item name (`order_items.item_name`) and the add-on names stay the order-time snapshot (user decision).
- No change to order creation (it already uses current prices) or to `addOrderItem` (it already does).
- No change to orders that aren't edited. The spec 023 snapshot still holds for every order the kitchen doesn't save.
- No "price changed" indicator in the UI and no audit entry for re-pricing.
- No change to the cashier.

## Current behavior

- **What "Salvar" sends:** `public/kitchen/app.js` `saveOrderChanges()` sends `PATCH /api/orders/{id}` (order number, customer), then **one `PATCH /api/orders/{id}/items/{itemId}` per item** with `{quantity, notes, dining_option}`, even for unchanged items. So every save touches every item.
- **What `updateOrderItem` does:**
  - Sets quantity, notes and dining option.
  - Recomputes `packaging_cost` only if `quantity` or `dining_option` is present.
  - Saves.
  - There's no transaction (only one row is written today).
- **Validation:** `OrderValidator::validateOrderItemUpdate` allows only `quantity`, `notes`, `dining_option`. Unchanged by this spec.
- **Who reads the snapshot:** `PrintService` (reprint) and `ReportService` read `unit_price`/`packaging_cost`/components from the snapshot, so a re-priced item shows up in reprints and reports.
- **Pricing math:** `PricingService::unitPriceFor()` and `composedUnitPrice()` already do it. `OrderRepository::packagingUnitFee()` (spec 050) gives the current packaging fee.

## Proposed behavior

On every successful `updateOrderItem()` call, inside one DB transaction:
1. Apply quantity, notes and dining option as today.
2. **Unit price:** re-price from the item's menu item (`order_items.menu_item_id`). If the menu item no longer exists, keep the stored price.
   - **Regular item:** `unit_price = PricingService::unitPriceFor(menuItem)`.
   - **"Monte Seu Prato" (has `order_item_components`):**
     - Each add-on row's `unit_price` becomes its menu item's current price. If an add-on's menu item no longer exists, that row keeps its stored price.
     - The item's `unit_price = composedUnitPrice(current base price, [{price, stored quantity}…])`.
     - Add-on quantities and names are unchanged.
3. **Packaging:** `packaging_cost` is always recomputed with the current linked fee (spec 050 rule), whatever fields were sent.
4. **Availability is irrelevant:** an unavailable menu item or add-on still gives its current price, same rule as packaging in spec 050. The edit never fails because of price data.

Kitchen: `openEditModal()` also calls `loadMenu()`, so the modal's prices (Simples/VIP titles) are current. The existing `fetchAll()` after save already reloads the orders.

## Functional requirements

1. FR1 — After `PATCH /api/orders/{id}/items/{itemId}` returns 200, `order_items.unit_price` equals the current `menu_items.price` of its `menu_item_id` (regular items).
2. FR2 — For an item with `order_item_components`, each add-on's `unit_price` equals its current `menu_items.price`, and the item's `unit_price` = current base price + Σ(current add-on price × stored quantity).
3. FR3 — If the item's menu item was deleted, `unit_price` is unchanged and the request still returns 200. Likewise, an add-on whose menu item was deleted keeps its stored price, and the sum uses that stored price.
4. FR4 — `packaging_cost` is recomputed on every successful item PATCH, including a notes-only PATCH.
5. FR5 — `item_name`, add-on `item_name` and add-on `quantity` never change because of this re-pricing.
6. FR6 — Re-pricing applies to pending and done orders. Cancelled orders still answer `409 ORDER_CANCELLED` and change nothing.
7. FR7 — The item update and its add-on updates are atomic (one transaction).
8. FR8 — Opening the kitchen's "Editar Pedido" modal triggers `GET /api/menu`.

## Non-functional requirements

- Money stays exact (`App\Money`, spec 021). No float sums.
- At most one menu lookup for the item plus one for its add-ons per PATCH (`whereIn`). Negligible.

## User flows

- **Regular item:**
  - The admin changes "Coca-Cola" from 5,00 to 6,00.
  - The kitchen opens an order made earlier with 2× Coca-Cola (`unit_price` 5,00) and fixes the customer name.
  - On save, the item becomes `unit_price` 6,00, and a reprint shows 12,00 for that line.
- **Monte Seu Prato:** the admin raises "Filé de Frango" from 13,00 to 14,00. Editing an order with a dish built with 2× frango re-prices the add-on to 14,00, and the dish goes up by 2,00.

## API changes

No request or response shape changes. `PATCH /api/orders/{id}/items/{itemId}` keeps its body and responses. Its side effect changes: it now re-prices the item (documented here and in the changelog).

## Data model and migrations

Not applicable — it writes existing columns (`order_items.unit_price`, `packaging_cost`, `order_item_components.unit_price`). No schema change.

## Architecture and affected components

- `src/Repositories/OrderRepository.php`: `updateOrderItem()` gets re-pricing in a transaction, plus a private helper that reuses `PricingService` and the spec 050 `packagingUnitFee()`.
- `public/kitchen/app.js`: `openEditModal()` calls `loadMenu()`.
- `tests/Unit/OrderRepositoryTest.php`: new tests.
- No new layer, no controller or validator change.

## Security considerations

- **No new input:** prices come from the DB, never from the request. The validator still rejects unknown fields.
- **Same endpoint and access:** the endpoint stays unauthenticated, as all kitchen endpoints are today (an existing, documented deployment choice in `docs/architecture.md`).
- **Effect of a call:** each call now writes server-computed prices. A caller can trigger re-pricing to current values, but can't choose a price.

## Backward compatibility

- **Behavior change, by request:** an order edited in the kitchen now picks up current item prices. Orders that are never edited keep their snapshot.
- **Reports and reprints:** totals for an edited order reflect the new prices. That's intended, and must go into the changelog.
- **API consumers:** no contract change.

## Acceptance criteria

1. AC1 — Unit test: an order created with an item priced 10,00; its menu price is changed to 12,00; `updateOrderItem(order, item, ['notes' => 'x'])` → `unit_price` = 12,00 and `item_name` unchanged.
2. AC2 — Unit test: a "Monte Seu Prato" dish (base 5,00 + 2× add-on at 13,00 = 31,00). The add-on is changed to 14,00 and the base to 6,00, then the item is PATCHed → add-on `unit_price` 14,00, quantity still 2, item `unit_price` 34,00.
3. AC3 — Unit test: the item's menu item is deleted → PATCH succeeds and `unit_price` is unchanged. A deleted add-on keeps its stored price in the sum.
4. AC4 — Unit test: a notes-only PATCH on a `viagem_vip` item recomputes `packaging_cost` with the current linked fee.
5. AC5 — Unit test: the same re-pricing works on a `done` order. A `cancelled` order throws `OrderCancelledException` and its prices are unchanged.
6. AC6 — Browser, on the 8081 test instance: change a price in the Admin, open "Editar Pedido" on an older order → a `GET /api/menu` request is seen when the modal opens; save → the stored `unit_price` matches the new price; reprinting the order isn't required.
7. AC7 — PHPUnit, PHPStan and PHP-CS-Fixer pass.

## Implementation plan

1. Wrap `updateOrderItem()` in `DB::transaction`; add a private `repriceItem(OrderItem $item)` that loads the menu item and the add-ons' menu items (`whereIn`) and writes `unit_price` and the add-on prices through `PricingService`.
2. Always recompute `packaging_cost` (drop the `$recomputePackaging` condition).
3. Unit tests AC1–AC5 in `tests/Unit/OrderRepositoryTest.php` (in-memory SQLite, existing helpers like `seedBuildYourOwnDish()`).
4. Kitchen: call `loadMenu()` in `openEditModal()`.
5. Run the checks, then the browser check on 8081.

## Testing and validation strategy

- **Automated:** PHPUnit exists (specs 004/035). AC1–AC5 are unit tests against in-memory SQLite, where the existing `OrderRepositoryTest` already builds orders, menu items and build-your-own dishes. AC7 runs the full PHPUnit, PHPStan and PHP-CS-Fixer.
- **Browser (AC6):** run on the 8081 test instance (`restaurant_test`), never the dev database, with the Chrome extension or Playwright. Price changes go through a throwaway admin with a random password, removed afterwards (the pattern from specs 050/051).

## Rollout and rollback

Code only, so deploy is the merge. Rollback is a revert. Orders already re-priced keep their new values: that's data written by an intended action, not something to undo.

## Open questions

- Non-blocking: should the kitchen show a "preço atualizado" hint when a save changed an item's total? Out of scope; follow-up if the client wants it.
- Non-blocking: `saveOrderChanges()` PATCHes every item even when unchanged, so every save re-prices the whole order. That matches the user's "toda vez que salvar" decision. Sending only changed items would narrow it, but would also contradict that decision. Recorded so the effect is explicit.

## Task checklist

- [x] `updateOrderItem()` re-prices (transaction, add-ons, deleted-item fallback)
- [x] Packaging always recomputed
- [x] Unit tests AC1–AC5
- [x] Kitchen reloads the menu when the modal opens
- [x] Checks + browser verification (AC6, AC7)

## Implementation log

- 2026-10-02 — Approved by `/spec-implement` invocation (Draft, no blocking open questions) → In Progress.
- 2026-10-02 — `repriceItem()` returns early, touching neither the price nor the add-ons, when the dish's own menu item is gone. A first draft refreshed the add-ons anyway, which would have left stored add-on prices that no longer added up to the stored `unit_price`. FR3 is unchanged by this; it only makes the "item deleted" branch consistent.
- 2026-10-02 — Add-on prices come from one `whereIn` lookup. The composed price reuses `PricingService::unitPriceFor()` + `composedUnitPrice()` with the add-ons' (possibly refreshed) stored `unit_price` and quantities, so a deleted add-on contributes its stored price (FR3).
- 2026-10-02 — The `$recomputePackaging` condition (spec 046) was removed: packaging is now recomputed on every item PATCH (FR4). The rest of the validator and controller are untouched.
- 2026-10-02 — Browser check side effect: an early run of the test script matched the wrong order card on 8081 and saved test order 1487 (`restaurant_test`), which re-priced it to current test prices. Test data only. The final run opened the exact order through `openEditModal(order)`, the function the "Editar pedido" button calls.

## Validation evidence

All run on 2026-10-02.

- **AC1–AC5** — `vendor/bin/phpunit --filter '…' --testdox` → `OK (12 tests, 34 assertions)`, including the 5 new tests in `tests/Unit/OrderRepositoryTest.php`:
  - `✔ Item update reprices from current menu but keeps the name` (10,00 → 12,00, `item_name` "Prato Teste" kept). AC1.
  - `✔ Item update reprices a build your own dish and its add ons` (31,00 → 34,00; add-on 14,00, quantity 2, name kept). AC2.
  - `✔ Item update keeps stored prices for items gone from the menu` (deleted dish keeps 10,00; deleted add-on keeps 13,00 inside 5 + 13 + 5 = 23,00). AC3.
  - `✔ Notes only update still recomputes packaging` (VIP 2× fallback 4,00 → linked 3,00 → 6,00). AC4.
  - `✔ Repricing applies to done orders but not cancelled ones` (done 10,00 → 15,00; cancelled throws `OrderCancelledException` and stays at 10,00). AC5.
  - The existing tests next to them still pass, among them `Kitchen edits use the linked menu item price` (spec 050) and `Build your own dish is priced from base plus add ons…` (spec 030).
- **AC6** — 8081 (`restaurant_test`), Playwright script outside the repo, throwaway admin with a random password (deleted afterwards, leftover users 0):
  - Test order 1490 (2× Arroz Branco, menu item 99) stored at `unit_price` 4,00.
  - `PATCH /api/admin/items/99 {"price":4.75}` → `{"success":true}`; `menu_items.price` = 4.75.
  - Kitchen `/kitchen/`, `openEditModal(order #4, id 1490)` → requests on open: `["GET /api/menu"]`.
  - Clicked "Salvar" → `PATCH /api/orders/1490/items/1490 {"quantity":2,"notes":"","dining_option":"local"}` → `200 Item updated`.
  - Stored row after the save: `Arroz Branco | 2 | 4.75`.
  - Price restored to 4,00 afterwards. No page errors.
  - An earlier run failed for a test-side reason: it closed the browser before the item PATCH response arrived. The script now waits for the response; see the log. A `curl` PATCH on 8081 re-priced correctly (4,75) in between.
- **AC7** — `vendor/bin/phpunit` → `Tests: 245, Assertions: 380, Skipped: 52` (52 = Integration suite without its env var; 5 tests more than before). `MYSQL_DATABASE_TEST=restaurant_test vendor/bin/phpunit --testsuite Integration` → `Tests: 52, Assertions: 193, Skipped: 1`. `vendor/bin/phpstan analyse` → `[OK] No errors`. `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 107 files that can be fixed`. `node --check public/kitchen/app.js` → OK.
