# Spec 030 — "Monte Seu Prato" and kitchen dish summary

## Metadata

- Status: Implemented
- Created: 2026-09-13
- Updated: 2026-09-13
- Owner: Henry
- Related issue: Not applicable (client request)
- Related branch: master (working tree, not committed)

## Context

Client request: in "Pratos Principais", offer a **"Monte Seu Prato"** option that opens a modal to assemble a dish from the Adicionais, prices it correctly, and shows well in Kitchen mode. Also, the Kitchen side panel — today only "Resumo de Ingredientes" — should optionally show a **dish summary**: total number of dishes and a count per dish.

## Problem

- The only way to sell a custom plate today is adding each Adicional as a separate order line (`public/cashier/app.js` `addItem()`), so the kitchen sees loose add-ons with no indication they form one plate, and the receipt/total don't express "one dish".
- `order_items` has no way to store per-line add-ons; `menu_items` has no way to mark a dish as assembled-to-order (`common/sql/001_schema.sql`, migrations 001–014).
- The Kitchen panel (`public/kitchen/index.php`) only offers the ingredient summary from `GET /api/kitchen/food-summary`; there is no per-dish count.

## Goals

- A "Monte Seu Prato" menu item in Pratos Principais that the cashier assembles from available Adicionais (with quantities) in a modal, with a live price.
- Server-side, authoritative price: `unit_price = base price + Σ(add-on price × add-on quantity)`, via `PricingService`, with an order-time snapshot of each add-on.
- The kitchen order card, the printed receipt, and the ingredient summary all reflect the chosen add-ons.
- A Kitchen panel toggle between "Ingredientes" and "Pratos"; the dish view shows the total number of pending dishes and the count per dish (custom dishes broken down by combination).

## Non-goals

- No admin UI to flag other items as customizable, or to limit which add-ons a dish allows (all available Adicionais are offered). The seeded item's base price/availability/name remain editable through the existing Admin item form.
- No add-on editing of an already-placed order from the Kitchen edit modal (`POST /api/orders/{id}/items` rejects customizable items; quantity/notes edits still work).
- No changes to reports: a custom dish counts as one "Monte Seu Prato" line with its composed revenue; add-ons are not reported as separate Adicionais sales.
- No new API endpoint for the dish summary (computed client-side from the pending orders the page already loads).

## Current behavior

- `MenuRepository::getFullMenu()` returns items with `components` from `dish_components` (fixed recipe) — confirmed in code.
- `OrderRepository::createOrder()` locks referenced menu items (sorted, single query), prices each line via `PricingService::unitPriceFor()`/`packagingFeeFor()`, and snapshots `item_name`/`unit_price`/`packaging_cost` (specs 022/023/026).
- `KitchenService::getFoodCategorySummary()` expands each pending order line through the menu item's `dish_components`, or counts the item itself.
- `PrintService::buildReceipt()` prints one line per order item (+ packaging label and notes).
- Kitchen right panel shows only the ingredient summary.

## Proposed behavior

- **Data**: `menu_items.is_customizable` flag; seeded "Monte Seu Prato" (Pratos Principais, base R$ 0,00, last position); new `order_item_components` table snapshotting `menu_item_id`, `item_name`, `quantity`, `unit_price` per order item.
- **Order creation**: `items[].components: [{id, quantity}]` optional. A customizable dish requires ≥ 1 component; a non-customizable item must not have any. Each component must exist, be available, belong to category "Adicionais", and not itself be customizable. Repeated component ids are merged. Component menu items are locked in the same sorted lock query as the dishes. Violations → `DomainException` → existing `400 INVALID_ORDER_ITEM`, whole order rejected, nothing persisted.
- **Pricing**: `PricingService::composedUnitPrice(Money $base, iterable $components)`; `unit_price` stores the composed price so existing totals, receipts and report SQL keep working unchanged. Packaging stays per plate.
- **Listing**: `GET /api/orders` items gain `components: [{menu_item_id, name, quantity, unit_price}]` (empty for regular items). `POST /api/orders/{id}/items` response item gains `components: []`.
- **Kitchen add-item**: customizable item → `DomainException` "… só pode ser montado pelo Caixa." (400 `MENU_ITEM_UNAVAILABLE`, existing mapping); the kitchen select hides customizable items.
- **Ingredient summary**: order lines with components count those components (× line quantity) instead of the menu item's recipe.
- **Receipt**: each add-on printed under its dish as `   + 2x Filé de Frango`.
- **Cashier UI**: customizable card is highlighted, shows "a partir de R$ x" and a "Montar" button; clicking opens a modal with Adicionais grouped by `food_category`, +/− steppers (0–10), selected chips, live price (cents arithmetic), confirm disabled until ≥ 1 add-on. Each assembled dish is its own order line (never merged), listing its add-ons with an "Editar montagem" link.
- **Kitchen UI**: assembled dishes show a magic icon and add-on chips under the name. Summary panel header gets an "Ingredientes | Pratos" toggle (persisted in `localStorage.kitchenSummaryMode`). "Pratos": highlighted total, then one row per Pratos Principais name with its quantity (desc), and for assembled dishes one sub-row per add-on combination.

## Functional requirements

1. FR1 — Migration 015 adds `menu_items.is_customizable` (default 0), inserts "Monte Seu Prato" once, creates `order_item_components`; re-running is a no-op.
2. FR2 — `GET /api/menu` items include boolean `is_customizable`.
3. FR3 — `POST /api/orders` accepts `items[].components`; validator rejects non-array, > 30 entries, missing/non-numeric id or quantity, non-integer quantity, quantity < 1 or > 10.
4. FR4 — Server computes `unit_price` = base + Σ(component price × quantity) and snapshots each component.
5. FR5 — Business rules from "Proposed behavior → Order creation" reject the whole order with 400.
6. FR6 — `GET /api/orders` exposes `components` per item.
7. FR7 — `POST /api/orders/{id}/items` rejects customizable items.
8. FR8 — Food summary counts chosen add-ons for assembled dishes.
9. FR9 — Receipt lists add-ons under the dish.
10. FR10 — Cashier modal assembles, prices, edits and submits assembled dishes.
11. FR11 — Kitchen cards show add-ons; the summary panel toggles to a dish summary with total and per-dish counts.

## Non-functional requirements

- Money arithmetic in integer cents (`Money`) on the server; cents rounding on the client display.
- Lock ordering guarantee of `createOrder()` preserved (one sorted `lockForUpdate()` query over dish + component ids).
- No new dependencies; no Composer/Docker changes.

## User flows

- **Cashier**: Pratos Principais → "Monte Seu Prato" → modal → tap add-ons / +/− → see price → "Adicionar ao pedido" → line appears with add-ons → optional "Editar montagem", dining option, notes, quantity → "Enviar Pedido".
- **Kitchen**: order card shows "1x ✨ Monte Seu Prato" with chips "2x Filé de Frango" "1x Arroz Branco"; panel toggle "Pratos" shows "Total de pratos: N", then per dish counts.

## API changes

- `GET /api/menu`: + `is_customizable` per item.
- `POST /api/orders`: + optional `items[].components[] {id, quantity}`; new 400 `INVALID_ORDER_ITEM` messages for FR5.
- `GET /api/orders`: + `items[].components[] {menu_item_id, name, quantity, unit_price}`.
- `POST /api/orders/{id}/items`: 400 `MENU_ITEM_UNAVAILABLE` for customizable items; response item + `components: []`.
- `public/api/docs/openapi.yaml` updated accordingly.

## Data model and migrations

- `common/migrations/015_build_your_own_dish.sql` (guarded/idempotent, same patterns as 002/009/010/014).
- New model `App\Models\OrderItemComponent`; `OrderItem::components()` hasMany; `MenuItem` casts `is_customizable` to boolean.

## Architecture and affected components

- `src/Validators/OrderValidator.php` — components shape.
- `src/Services/PricingService.php` — `composedUnitPrice()`.
- `src/Repositories/OrderRepository.php` — resolve/lock/validate components, persist snapshots, list components, reject in `addOrderItem()`.
- `src/Repositories/MenuRepository.php` — expose `is_customizable`.
- `src/Services/KitchenService.php` — food summary uses chosen add-ons.
- `src/Services/PrintService.php` — print add-ons.
- `public/cashier/index.php`, `public/cashier/app.js` — card, modal, order lines, payload.
- `public/kitchen/index.php`, `public/kitchen/app.js` — add-on chips, summary toggle, `dishSummary()`, hide customizable in add-item select.
- No controller or route change (existing `DomainException` mappings reused).

## Security considerations

- Price is never taken from the client; only ids/quantities. Components are validated against DB state inside the locked transaction (no TOCTOU on availability).
- Input bounds (≤ 30 components, quantity 1–10) cap payload size and price abuse.
- Same (unauthenticated) route exposure as today's `/api/orders` — unchanged.
- All user-provided/add-on names are rendered with Alpine `x-text` (no HTML injection).

## Backward compatibility

- Existing clients that don't send `components` behave exactly as before for non-customizable items.
- Existing orders have no component rows → `components: []`.
- Report SQL unchanged; `unit_price` already holds the full per-plate price.
- A client sending `components` for a regular item now gets 400 (previously the key was ignored) — acceptable, no known client sends it.

## Acceptance criteria

1. AC1 — After `bin/migrate`, `GET /api/menu` has exactly one "Monte Seu Prato" in Pratos Principais with `is_customizable: true`; re-running `bin/migrate` reports no pending migration and doesn't duplicate it.
2. AC2 — `POST /api/orders` with Monte Seu Prato (base B) + components `[{Filé de Frango ×2}, {Arroz Branco ×1}]` returns 201 and the stored `unit_price` = B + 2×price(Filé de Frango) + price(Arroz Branco); `GET /api/orders` lists those components with snapshot names/prices.
3. AC3 — `POST /api/orders` with Monte Seu Prato and no components → 400 `INVALID_ORDER_ITEM`; with a non-Adicional component → 400; with components on a regular dish → 400; no order row is created in any case.
4. AC4 — Validator rejects the malformed component shapes listed in FR3 (unit tests).
5. AC5 — `POST /api/orders/{id}/items` with Monte Seu Prato → 400.
6. AC6 — Receipt text contains `+ 2x Filé de Frango` for such an order (unit test).
7. AC7 — `GET /api/kitchen/food-summary` counts the chosen add-ons for an assembled dish.
8. AC8 — Full PHPUnit suite passes.
9. AC9 — Manual UI: cashier modal assembles and submits a dish with the correct displayed total; kitchen shows add-on chips; the "Pratos" toggle shows total and per-dish counts and persists after reload.

## Implementation plan

1. Migration 015 + `OrderItemComponent` model + model casts/relations.
2. `PricingService::composedUnitPrice()`, validator shape.
3. `OrderRepository` create/list/addItem changes; `MenuRepository` flag.
4. `KitchenService`, `PrintService`.
5. Cashier UI; Kitchen UI.
6. Unit tests; OpenAPI.
7. Run migration, test suite, API checks against the running container; manual UI check.

## Testing and validation strategy

- Unit (PHPUnit, SQLite in-memory like existing tests): `PricingServiceTest`, `OrderValidatorTest`, `OrderRepositoryTest`, `PrintServiceTest` new cases → AC2–AC6 at repository/service level.
- Live API via `curl` against `restaurant_web` (MySQL) → AC1, AC2, AC3, AC5, AC7.
- UI has no automated test infrastructure (no JS tests/e2e) → AC9 is manual.

## Rollout and rollback

- Rollout: deploy code, run `bin/migrate`.
- Rollback: revert code; the extra column/table/row are inert for the old code (old `getFullMenu()` ignores the column; old UI would show "Monte Seu Prato" as a R$ 0,00 regular item — mark it unavailable in Admin). Dropping the schema objects is a destructive step and must be a separate, explicit migration.

## Open questions

- Non-blocking: base price of "Monte Seu Prato" — seeded at R$ 0,00 (total = sum of add-ons); client can set a base price in Admin.
- Non-blocking: should some Adicionais (e.g. "Fritas Cone") be excluded from the builder? Currently all available Adicionais are offered.

## Task checklist

- [x] Migration 015, `OrderItemComponent`, model casts/relations
- [x] `PricingService::composedUnitPrice()`, `OrderValidator` components shape
- [x] `OrderRepository` (create/list/addOrderItem), `MenuRepository` flag
- [x] `KitchenService`, `PrintService`
- [x] Cashier UI (card, modal, lines, payload)
- [x] Kitchen UI (chips, summary toggle, dish summary, add-item select)
- [x] Unit tests, OpenAPI
- [x] Migration applied + test suite run
- [x] Live API checks
- [ ] Manual UI check (AC9) — not performed yet

## Implementation log

- 2026-09-13 — Chose a flag on `menu_items` + per-order-item snapshot table over reusing `dish_components` (that table is a fixed per-dish recipe, not a per-order choice) or encoding add-ons in `notes` (not priceable/queryable).
- 2026-09-13 — `unit_price` stores the composed price so `PrintService`, `ReportService` SQL and the cashier/kitchen totals need no formula change.
- 2026-09-13 — Dish summary computed client-side from `this.orders` (pending, selected date) — same data set the ingredient summary covers, updates instantly on complete/uncomplete, no new endpoint.
- 2026-09-13 — `PrintService` only lazy-loads `components` for persisted items (`exists`) or an explicitly set relation, so in-memory `OrderItem`s in `PrintServiceTest` don't hit the DB.
- 2026-09-13 — First suite run: the new receipt test failed because escpos-php re-encodes "é" to CP850 (`0x82`); the printed output was correct. Assertion changed to ASCII-only fragments.
- 2026-09-13 — Found during AC7: in the local DB, Adicionais "Arroz Branco" (#23), "Carne Empanada" (#66) and "Salada Premium" (#65) have `food_category = NULL`, so the ingredient summary skips them — pre-existing rule (also applies to fixed recipes), not changed here. They appear under "Outros" in the builder modal. `food_category` is not editable in Admin (`MenuRepository::updateItem()` ignores it); data left untouched.

## Validation evidence

- **AC8** — `docker compose exec web vendor/bin/phpunit` → `OK (123 tests, 183 assertions)` (includes new cases in `PricingServiceTest`, `OrderValidatorTest`, `OrderRepositoryTest`, `PrintServiceTest`).
- **AC4** — `OrderValidatorTest::testInvalidComponentsAreRejected` (8 data sets) and `testItemWithValidComponentsPasses` pass.
- **AC6** — `PrintServiceTest::testBuildYourOwnDishPrintsItsAddOnsUnderTheItem` passes; raw output contained `+ 2x Fil\x82 de Frango`, `+ 1x Arroz Branco`, `TOTAL: R$ 30,00`.
- **AC1** — `bin/migrate` → `▶ Executando 015_build_your_own_dish.sql ... [OK]`; second run → `✔ Nenhuma migração pendente.`; `GET /api/menu` → exactly 1 customizable item: `{"id":81,"price":0,"is_customizable":true}` in Pratos Principais.
- Live checks below: script run inside `restaurant_web` against MySQL (`php < spec030_api_check.php`), `print_ticket: false`.
- **AC3** — no components → `400 INVALID_ORDER_ITEM "Escolha ao menos um adicional para Monte Seu Prato."`; Coca-Cola as component → `400 INVALID_ORDER_ITEM "Coca-Cola não é um adicional."`; components on Prato do Dia → `400 INVALID_ORDER_ITEM "Prato do Dia não aceita adicionais."`; component quantity 11 → `400 VALIDATION_FAILED`; order count for today before=0 after=0.
- **AC2** — Monte Seu Prato ×2 with Filé de Frango ×2 (R$13) + Arroz Branco ×1 (R$4), base R$0 → `201`, expected 3000 cents; `GET /api/orders` → `unit_price` 3000 cents, `components=[{"menu_item_id":15,"name":"Filé de Frango","quantity":2,"unit_price":13},{"menu_item_id":23,"name":"Arroz Branco","quantity":1,"unit_price":4}]`. Repository-level snapshot-after-rename also covered by `OrderRepositoryTest::testBuildYourOwnDishIsPricedFromBasePlusAddOnsAndSnapshotsThem`.
- **AC7** — food-summary delta after that order: Filé de Frango +4 (2 plates × 2) ✔; Arroz Branco +0 because its `food_category` is NULL in this DB (see Implementation log) — the add-on path works for classified items; unclassified ones are skipped by the pre-existing rule.
- **AC5** — `POST /api/orders/111/items {menu_item_id: 81}` → `400 MENU_ITEM_UNAVAILABLE "Monte Seu Prato só pode ser montado pelo Caixa."`
- Cleanup: test order #111 cancelled (`200`), row preserved per spec 020.
- `GET /cashier/` and `GET /kitchen/` → `200`; `node --check` on both `app.js` files → OK.
- **AC9** — not yet verified: no browser check performed; needs a manual pass in the cashier/kitchen UI.
