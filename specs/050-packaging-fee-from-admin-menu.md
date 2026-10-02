# Spec 050 — Packaging fee from Admin menu items

## Metadata

- Status: Verified
- Created: 2026-10-02
- Updated: 2026-10-02
- Owner: Henry
- Related issue: Not applicable (client request)
- Related branch: `050`

## Context

Client request: the "Viagem Simples" and "Viagem VIP" dining options must charge the price of the packaging items listed in the Admin menu. Changing a packaging item's price in the Admin must change the fee used by Cashier mode. Today it doesn't — the fees are hardcoded.

## Problem

The admin lists "Embalagem Simples" (R$ 1,00) and "Embalagem Especial" (R$ 2,00) in category "Viagem" (`common/sql/001_schema.sql:125-126`), but editing their price has no effect: the packaging fee is a constant in three places.

## Goals

- Packaging fee per unit for `viagem_simples` / `viagem_vip` = current price of the menu item linked to that option.
- Server (order creation, kitchen add/edit item) and Cashier UI use the same value.
- Link survives renaming the item.

## Non-goals

- No UI to change which item is linked to which option (done once by migration; changeable only by a migration/SQL). Add later if needed.
- No change to historical orders (`order_items.packaging_cost` snapshot, spec 023) — **except** when the kitchen edits an item through "Editar Pedido": that recomputes its packaging at the linked item's current price (decision recorded 2026-10-02, see Implementation log).
- No change to the dining option set (`local`, `viagem_simples`, `viagem_vip`).
- Admin UI redesign — separate spec 051.

## Current behavior

Confirmed in code:
- `src/Services/PricingService.php:22-29` `packagingFeeFor()` returns R$ 1,00 × qty (`viagem_simples`), R$ 2,00 × qty (`viagem_vip`), zero otherwise.
- Called from `src/Repositories/OrderRepository.php:169` (`createOrder`), `:420` (`addOrderItem`), `:477` (`updateOrderItem`).
- `public/cashier/app.js:253-257` `packagingCost()` hardcodes 1.0/2.0; `get total()` at `:284-293` duplicates the same constants.
- Button titles "(+R$ 1,00)" / "(+R$ 2,00)" hardcoded in `public/cashier/index.php:289,294,425,430` and `public/kitchen/index.php:450,455`.
- `MenuRepository::getFullMenu()` (`src/Repositories/MenuRepository.php:13`) feeds both `GET /api/menu` and `GET /api/admin/menu` (`src/Routes.php:51,85`, same `MenuController::index`). Cashier and kitchen already load `/api/menu`.
- `PrintService` and reports read the stored `packaging_cost`; unaffected.

## Proposed behavior

- New nullable, unique column `menu_items.packaging_option` (`viagem_simples` | `viagem_vip` | NULL). Migration links the existing items by name.
- When pricing a line, the server looks up the menu item whose `packaging_option` equals the dining option and uses its `price` as unit fee. If none is linked (e.g. item deleted), it falls back to the legacy constants (1,00 / 2,00) so orders never fail. The linked item's `available` flag doesn't matter — only its price.
- `/api/menu` items expose `packaging_option`. Cashier computes fees from it (same fallback). Cashier and kitchen button titles show the current fee.
- Admin menu card shows a badge "Embalagem Simples"/"Embalagem VIP" on linked items.

## Functional requirements

1. FR1 — Migration adds `menu_items.packaging_option VARCHAR(20) NULL` with a UNIQUE index; idempotent.
2. FR2 — Migration sets `packaging_option='viagem_simples'` on the item named `Embalagem Simples` and `'viagem_vip'` on `Embalagem Especial`, only when no item already holds that option.
3. FR3 — `PricingService::packagingFeeFor(string $diningOption, int $quantity, ?Money $unitFee = null)`: `local`/unknown → zero; otherwise `($unitFee ?? legacy default) × quantity`. Stays I/O-free.
4. FR4 — `createOrder`, `addOrderItem`, `updateOrderItem` pass the linked item's price as `$unitFee`.
5. FR5 — Each item in `GET /api/menu` and `GET /api/admin/menu` includes `packaging_option` (string or null).
6. FR6 — Cashier `packagingCost()` and `total` use the fee from the menu payload; fallback 1.00/2.00 when no item is linked.
7. FR7 — Cashier and kitchen dining-option button titles show the current fee.
8. FR8 — Admin menu shows a badge on linked items.

## Non-functional requirements

- `createOrder` does at most one lookup per distinct viagem option (cached per call, including the "not linked" result); `addOrderItem`/`updateOrderItem` do one each. Negligible.
- Money stays exact (`App\Money`), as spec 021 requires.

## User flows

- Admin: edits "Embalagem Especial" price to 3,00 → saves.
- Cashier: reloads page → marks item as VIP → line shows "+ R$ 3,00 embalagem", total includes it → sends order → stored `packaging_cost` = 3,00 × qty.
- Kitchen: saves "Editar Pedido" → every item it sends is recomputed with the current linked price. The kitchen resends quantity + dining option for **all** items on save (`public/kitchen/app.js` `saveOrderChanges()`), so this applies even to items whose option didn't change.
- Cashier: fees are read from the menu loaded with the page. A price changed in the Admin while the Cashier is open only shows after a reload; the server always charges the current price.

## API changes

`GET /api/menu` and `GET /api/admin/menu`: each item gains `"packaging_option": "viagem_simples" | "viagem_vip" | null`. Additive only; no status codes change.

## Data model and migrations

- `common/migrations/019_menu_item_packaging_option.sql`: guarded `ALTER TABLE menu_items ADD COLUMN packaging_option VARCHAR(20) NULL` + `UNIQUE INDEX uq_menu_items_packaging_option` (guard via `information_schema`, like migration 006), then the two guarded `UPDATE`s by name.
- `common/sql/001_schema.sql` unchanged (migrations run after it on fresh installs).

## Architecture and affected components

- `src/Services/PricingService.php` — new optional param + legacy default.
- `src/Repositories/OrderRepository.php` — private helper `packagingUnitFee(string $diningOption): ?Money`; 3 call sites.
- `src/Repositories/MenuRepository.php` — expose field.
- `public/cashier/app.js`, `public/cashier/index.php`, `public/kitchen/app.js`, `public/kitchen/index.php`, `public/admin/index.php`.
- No new Controller/Service/Repository/Validator.

## Security considerations

No new endpoint, no auth change. The fee is resolved server-side; the client-sent value is never trusted (unchanged — the payload doesn't carry fees). Only admins can change menu item prices (existing `/api/admin/items` auth).

## Backward compatibility

- Stored orders keep their `packaging_cost` snapshot.
- With no linked item, behavior equals today's (1,00/2,00).
- Existing `packagingFeeFor($opt, $qty)` callers/tests unchanged thanks to the optional param.
- API change is additive.

## Acceptance criteria

1. AC1 — After `bin/migrate`, `SELECT name, packaging_option FROM menu_items WHERE packaging_option IS NOT NULL` returns `Embalagem Simples|viagem_simples` and `Embalagem Especial|viagem_vip` (dev DB with seed data). Re-running is a no-op.
2. AC2 — Linked VIP item priced 3,00 → `createOrder` with one item `viagem_vip`, qty 2 → stored `packaging_cost` = 6,00.
3. AC3 — No item linked to `viagem_simples` → `createOrder` with `viagem_simples`, qty 3 → `packaging_cost` = 3,00 (fallback).
4. AC4 — `updateOrderItem` switching `local` → `viagem_simples` with linked price 1,50, qty 2 → `packaging_cost` = 3,00. `addOrderItem` uses the linked price likewise.
5. AC5 — `packagingFeeFor('viagem_vip', 2, Money::fromReais(2.5))` = 5,00; `packagingFeeFor('local', 2, Money::fromReais(9))` = 0.
6. AC6 — `GET /api/menu` returns `packaging_option` on every item.
7. AC7 — Manual: change "Embalagem Especial" price in Admin, reload Cashier, mark VIP → line and total use the new price; sent order's stored `packaging_cost` matches.
8. AC8 — PHPUnit, PHPStan, PHP-CS-Fixer pass.

## Implementation plan

1. Migration 019.
2. `PricingService` optional `$unitFee` + unit test.
3. `OrderRepository` helper + 3 call sites + unit tests (SQLite schema in `OrderRepositoryTest` gains the column).
4. `MenuRepository` exposes `packaging_option`.
5. Cashier: `packagingFees` getter, `packagingCost()`, `total` reuse, titles.
6. Kitchen titles; Admin badge.
7. Run checks + manual verification.

## Testing and validation strategy

PHPUnit exists (`tests/Unit`, `tests/Integration`, spec 004/035). AC2–AC5 via `tests/Unit/PricingServiceTest.php` and `tests/Unit/OrderRepositoryTest.php` (in-memory SQLite). AC1/AC6/AC7 manually against the dev stack (port 8080) after `bin/migrate`. AC8: `vendor/bin/phpunit`, `phpstan analyse`, `php-cs-fixer fix --dry-run --diff`. Integration suite run if `restaurant_test` is available.

## Rollout and rollback

Deploy = `bin/migrate` **before** the new code goes live: the new code queries `menu_items.packaging_option`, and without the column every viagem order and kitchen item edit would fail with a SQL error. Rollback = revert code; the extra nullable column is harmless to old code (no drop needed — destructive ops avoided).

## Open questions

- Non-blocking: should the Admin let users choose which item is linked to each option? Out of scope; follow-up if needed.
- Non-blocking: if the client renames "Embalagem Especial" before migrating, FR2 won't link it → fallback 2,00 applies. Link manually via a follow-up migration if that happens.

- **Follow-up (requested by the user 2026-10-02, out of scope here — for a branch after 050 and 051):** in the kitchen's "Editar Pedido", every save that changes items must refresh **all** item data from the Admin. Checked against the code, today only part of it happens:
  - ✅ packaging fee is recomputed with the current linked price on every save (`OrderRepository::updateOrderItem`);
  - ✅ items added through the modal use the current price (`addOrderItem`);
  - ❌ an existing item's `unit_price` is never recomputed in `updateOrderItem` — it keeps the order-time price;
  - ❌ the kitchen loads `/api/menu` once at page load (`public/kitchen/app.js:50`), so the prices it shows (e.g. the Simples/VIP button titles) go stale until reload.
  Tracked on the Trello Backlog card "Editar Pedido: atualizar preços com os dados do Admin ao salvar".

## Task checklist

- [x] Migration 019
- [x] PricingService `$unitFee` + test
- [x] OrderRepository lookup at 3 call sites + tests
- [x] MenuRepository exposes `packaging_option`
- [x] Cashier fees from menu + titles
- [x] Kitchen titles, Admin badge
- [x] Checks run + manual verification

## Implementation log

- 2026-10-02 — Approved by `/spec-implement` invocation (Draft, no blocking open questions) → In Progress.
- 2026-10-02 — Legacy fees moved to `PricingService::DEFAULT_PACKAGING_FEES`; `packagingFeeFor()` uses it both as the "is this a viagem option?" check and as fallback. `OrderRepository::packagingUnitFee()` reuses the same constant to skip the lookup for `local`.
- 2026-10-02 — `createOrder()` memoizes the unit fee per dining option within one call (`$unitFees`), so N lines cost at most 2 lookups. `addOrderItem()`/`updateOrderItem()` do one lookup each.
- 2026-10-02 — Cashier `get total()` now sums `itemTotal()` instead of duplicating the fee constants. Cashier `packagingFees` getter and kitchen `packagingTitle()` carry the same 1,00/2,00 fallback as the server (kept as small JS literals: the frontend has no build step / shared module to import a server constant from).
- 2026-10-02 — Independent verification (subagent, read-only) of PR #29 found: (1) the kitchen's "Editar Pedido" resends every item, so any save re-prices all viagem items of the order at the current linked price — before this spec that recompute used constants and was a no-op in practice. Asked the user; **decision: always use the current price** (accepted behavior, documented in Non-goals/User flows and in a code comment in `updateOrderItem`). (2) `??=` re-queried when no item was linked (cached null) → switched to `array_key_exists`. (3) Cashier shows the page-load price until reload — documented in User flows. (4) Deploy order (migrate before code) — documented in Rollout.
- 2026-10-02 — Docker Desktop daemon not running and no host PHP: PHPUnit/PHPStan/CS-Fixer/migrate pending until the stack is up.

## Validation evidence

All run on 2026-10-02 against the local Docker stack (Docker Desktop started for this; `web` on 8080 → dev DB, `web-e2e` on 8081 → `restaurant_test`).

- **AC1** — `docker compose exec web bin/migrate` → `▶ Executando 019_menu_item_packaging_option.sql ... [OK]`; second run → `✔ Nenhuma migração pendente.` The SQL body was also executed a second time directly via `mysql` (bypassing the runner's tracking) without error (the guarded ALTER took the `SELECT 1 AS dummy` branch). Dev DB query:
  ```
  57  Embalagem Simples   1.00  viagem_simples
  58  Embalagem Especial  2.00  viagem_vip
  ```
- **AC2, AC3, AC4** — `vendor/bin/phpunit --filter 'Packaging|LocalStaysFree|KitchenEdits' --testdox` → `✔ Packaging fee uses the linked menu item price` (VIP 3,00 × 2 = 6,00), `✔ Packaging fee falls back to the default when no item is linked` (3 × 1,00), `✔ Kitchen edits use the linked menu item price` (updateOrderItem → 3,00; addOrderItem → 1,50). `OK (9 tests, 10 assertions)`.
- **AC5** — same run: `✔ Packaging fee uses the linked item price when given`, `✔ Local stays free even with a unit fee`.
- **AC6** — `curl localhost:8080/api/menu` → 73 items, 73 carry `packaging_option`; non-null only on Embalagem Especial (`viagem_vip`) and Embalagem Simples (`viagem_simples`).
- **AC7** — on 8081 (`restaurant_test`, never the dev DB):
  - Real Admin path: throwaway admin with a random per-run password (same pattern as `IntegrationTestCase`), `POST /api/login` → `PATCH /api/admin/items/58 {"price":4.25}` → `{"success":true}`; `/api/menu` → `('Embalagem Especial', 4.25)`; `POST /api/orders` (2 × item, `viagem_vip`, `print_ticket:false`) → stored `packaging_cost = 8.50`. Price reverted to 2,00 and test user deleted afterward.
  - Cashier UI (Playwright, ad-hoc script outside the repo, VIP price set to 3,50 in `restaurant_test`): button title `Viagem VIP (+R$ 3,50)`, Simples `(+R$ 1,00)`; 2 × Arroz Branco as VIP → line `R$ 8.00 item + R$ 7.00 embalagem`, total `R$ 15.00`; order sent from the page's own selection → stored `packaging_cost = 7.00`. Price reverted.
  - Kitchen: `packagingTitle()` evaluated on `/kitchen/` → `Viagem Simples (+R$ 1,00)`, `Viagem VIP (+R$ 2,00)`, 0 page errors.
  - Deviation, declared: `restaurant_test` contains each seed category many times over (one populated, the rest empty), so the cashier on 8081 renders no menu items at all (duplicate `x-for` keys). Pre-existing test-data state, unrelated to this spec. The Playwright script merged same-name categories in the `/api/menu` response before the page saw it; prices/`packaging_option` were untouched. The Chrome extension was not connected, hence Playwright.
- **FR8 (Admin badge — no AC; gap raised by /spec-review)** — Chrome (Claude extension) on `localhost:8080/admin/`, logged in by the user, search "Embalagem": `Embalagem Especial | badge: Embalagem VIP | R$ 2.00` (red) and `Embalagem Simples | badge: Embalagem Simples | R$ 1.00` (yellow), read from the rendered DOM and seen in a screenshot. Read-only, nothing changed.
- **AC8** — `vendor/bin/phpunit` → `Tests: 240, Assertions: 367, Skipped: 52` (52 = Integration suite without env var). `MYSQL_DATABASE_TEST=restaurant_test vendor/bin/phpunit --testsuite Integration` → `Tests: 52, Assertions: 193, Skipped: 1` (runs migrations incl. 019 through `MigrationRunner`). `vendor/bin/phpstan analyse` → `[OK] No errors`. `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 107 files that can be fixed`.
