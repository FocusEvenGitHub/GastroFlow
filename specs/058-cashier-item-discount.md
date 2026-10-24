# Spec 058 — Cashier item discount (percent or fixed, per order line)

## Metadata

- Status: Implemented
- Created: 2026-10-22
- Updated: 2026-10-23
- Owner: Henry
- Related issue: Not applicable (client request — out of milestone, patch-tag work per `CLAUDE.md` "Release workflow")
- Related branch: `058`

## Context

Client request: the cashier needs a quick, simple way for a non-technical operator to give a discount, as a **fixed amount** (e.g. R$ 5,00) or a **percentage** (e.g. 90%). The discount must never exceed the value of the selected product. The purpose is **reports that match the money actually received**, for example when a customer from a partner entity gets a discount.

**Revision 2026-10-23 (2).** After this revision the client asked for two more changes: the kitchen's "Editar Pedido" must also be able to edit each line's discount, and the ticket shows the discount (Open question 1 closed). The table below reflects both.

**Revision 2026-10-23.** The first draft (one order-level discount, preset reasons managed in Admin, discount editable in the kitchen) was rejected by the client as too configuration-heavy. The client asked for something freer at the cashier, but still bounded. Decisions:

| Topic | Decision |
|---|---|
| Where | **Cashier, while building the order**: the operator taps the **price of an item** in "Resumo do Pedido", and a small modal opens to enter the discount. **Kitchen, in "Editar Pedido" only**: each item card can apply, change or remove its discount (to fix a mistake). |
| Scope | **Per order line** (the selected product). Each line can have its own discount, or none. |
| Type | Percentage or fixed reais, chosen in the modal. |
| Base | The line's product value: `unit_price × quantity`, add-ons of a build-your-own dish included. **Quantity**: the discount applies to the whole line (3× R$ 20 − R$ 5 = R$ 55). **Packaging** is never discounted. |
| Upper bound | A line's discount can never exceed that line's product value. |
| Control ("livre, mas não solto") | **One Admin setting, a maximum discount (%)**, applied to every line. **Default 100%** (confirmed by the client on 2026-10-23); 0 disables discounts. **No list of reasons, no presets, no reason field.** |
| Kitchen | The **board cards and the food summary show nothing** about discounts: they stay about preparing the dish. Only the "Editar Pedido" modal shows and edits it. When the kitchen changes an item's quantity, or the item is re-priced (spec 052), without touching its discount, that line's discount is **adjusted automatically** on the server. |
| Reports | Net revenue everywhere, plus a "Descontos concedidos" summary. |
| Ticket | Shows the discount under the discounted line, and the TOTAL is net (confirmed by the client). |

## Problem

Measured on `master` @ `8f6adda` (2026-10-22):

1. **No discount concept exists.** `grep -ri discount src/ public/ common/` finds nothing. Spec 026 explicitly deferred it ("Do not add a discount field, parameter, or code path").
2. **Revenue is always gross.** Every revenue query in `src/Services/ReportService.php` sums `unit_price * quantity (+ packaging_cost)` from `order_items`. An item actually sold at a discount is reported at full price.
3. **No workaround in the cashier.** The only lever the operator has is to leave an item out of the order, which corrupts both the kitchen order and the item-level reports.

## Goals

- The cashier can discount any line of the order being built in a few steps: tap the price, type the value, tap "Aplicar". A live preview shows the before and after price.
- A discount is a percentage or a fixed amount, never above the line's product value or the admin's maximum. The server enforces the same limits.
- The discount is stored per order line as an order-time snapshot, exact to the cent.
- Every report revenue figure becomes net of discounts, with a new "Descontos concedidos" summary.
- The kitchen's board and food summary show no discount information. Its "Editar Pedido" can apply, change or remove a line's discount under the same limits.

## Non-goals

- **Order-level discount** (one discount for the whole order). Replaced by per-line discounts.
- **Preset reasons, a reason list, or a reason/note field.**
- **Showing discounts on the kitchen board cards or in the food summary.**
- A discount on a line added by the kitchen in the same request that adds it (`POST /api/orders/{id}/items`). The line is added first; its discount can then be set in the same "Editar Pedido" before saving.
- **Discounting the packaging fee.**
- **Operator identity / manager PIN.** Cashier and kitchen have no login (spec 018). The admin's maximum is the control.
- **Coupons, promo codes, automatic or scheduled promotions.**
- **Fiscal / NFC-e treatment** of discounts (v2.1 milestone).
- **Back-filling** historical orders. Existing lines are treated as having no discount.

## Current behavior

Confirmed in code:

- **Pricing**: `src/Services/PricingService.php` computes:
  - the unit price (`unitPriceFor`, and `composedUnitPrice` for build-your-own dishes, spec 030)
  - the packaging fee (`packagingFeeFor`, spec 050)
  - `lineTotal()` and `orderTotal()`

  It works on `App\Money` integer cents (`src/Money.php`) and is stateless and free of I/O.
- **Persistence**: `OrderRepository::createOrder()` (`src/Repositories/OrderRepository.php:92-201`) snapshots `item_name`, `unit_price` and `packaging_cost` per `order_items` row in one transaction. Every item is resolved and validated *before* the order number is allocated (spec 022). `orders` has no money columns; totals are always derived from the items.
- **Kitchen edits**:
  - `addOrderItem()` (`:415`) creates new lines.
  - `updateOrderItem()` (`:482`) changes quantity, notes or dining option, and re-prices the line from the current menu on every save (spec 052).
  - `removeOrderItem()` (`:559`) deletes a line.
  - The kitchen's "Editar Pedido" sends one `PATCH /api/orders/{id}/items/{itemId}` per item on save (`public/kitchen/app.js:388`).
- **Kitchen UI shows no prices at all.** `public/kitchen/index.php` has no price or total rendering; its only "total" is the dish count (`:339-341`).
- **Cashier UI**: `public/cashier/app.js` keeps the cart in `selectedItems`.
  - `itemTotal(index)` (`:277-283`) = `price × quantity + packaging × quantity`.
  - `get total` (`:301`) sums those totals.
  - The line price is rendered in "Resumo do Pedido" at `public/cashier/index.php:262-265`, as `R$ <itemTotal>` next to the trash button.
  - The confirmation modal (spec 046, `:400-453`) repeats the per-line totals and the Total.
  - `submitOrder()` (`:326-365`) posts `items[]` to `POST /api/orders`.
  - `addItem()` (`:123-147`) merges a repeated plain item into the existing line (`quantity++`).
- **Validation**: `OrderValidator::validateOrderData()` checks the shape of `items[]`. DB-state rules raise `\DomainException` in `OrderRepository`, which `OrderController::store()` maps to `400 INVALID_ORDER_ITEM`.
- **Reports**:
  - `getSalesByDay`, `getMonthlyComparison` and `getDailySummary` sum `unit_price * quantity + packaging_cost`.
  - `getTopItems` and `getMainDishSales` sum `unit_price * quantity`.
  - All of them count `status = 'done'` orders only.
- **Ticket**: `PrintService` (`src/Services/PrintService.php:359-432`) prints each line's total (`PricingService::lineTotal`), an optional packaging label under the line, and `TOTAL: R$ …` (`orderTotal`).
- **Settings**: key/value `settings` table (migration 006), with admin-only `GET/PUT /api/admin/settings`. `SettingsValidator` validates the known keys. `SettingsController::updateSettings` audits `settings.updated`.
- **Next free migration number**: `021`.

## Proposed behavior

### Rules (single source of truth, in `PricingService`)

For one order line, let **L** be its product value in cents: `unit_price × quantity`, add-ons included, packaging excluded. Let **C** be the `discount_max_percent` setting (0–100, default 100).

1. **Type** is `percent` or `fixed`.
2. **Percent**: `value` is from 0.01 to 100, with at most 2 decimal places. The amount is `round_half_up(L × value / 100)`, in cents. To avoid floats, it is computed as integer basis points.
3. **Fixed**: `value` is in reais, at least 0.01, with at most 2 decimal places. The amount is `value` in cents. It applies to the **whole line**, not per unit.
4. **Ceiling (product)**: amount ≤ L. A line with L = 0 can't be discounted.
5. **Ceiling (admin)**:
   - Percent: `value` ≤ C.
   - Fixed: amount ≤ `round_half_up(L × C / 100)`.
   - **C = 0 disables discounts.**
6. **Line total** = L − amount + packaging. **Order total** = Σ line totals.

### Automatic adjustment (server only)

When the kitchen's `PATCH /api/orders/{id}/items/{itemId}` **does not carry a `discount` field** but changes a discounted line's quantity, or re-prices it (spec 052), that line's discount is recomputed in the same transaction:

- **Percent**: the stored `value` is reapplied to the new L.
- **Fixed**: amount = `min(value, new L)`.

The admin's maximum is **not** re-enforced here. It governs a person's choice, not an automatic recalculation. Removing a line removes its discount with it. A line added by the kitchen starts with no discount.

When the `PATCH` **does** carry `discount`, it is an explicit choice: an object sets the line's discount and is validated with every rule (including the admin's maximum) against the line's new, re-priced value; `null` removes it.

### Cashier UI

Designed for a non-technical operator: one obvious place to tap, every number visible before confirming.

**The line price becomes the button.** In each "Resumo do Pedido" line, the price (`R$ <itemTotal>`, `index.php:263`) turns into a small tappable price tag:
- `btn btn-link`, with a dotted underline, the `fa-tag` icon and the title "Toque para dar desconto"
- it is not rendered as a button (plain text, as today) when C = 0 or when the line's product value is R$ 0,00

**Line with a discount**:

```
2x Prato do Dia                    R̶$̶ ̶4̶0̶,̶0̶0̶  R$ 32,00   🗑
                                   [−20%]
```

- The old value appears struck through and muted, the new value in bold, and a small badge underneath (`−20%`, or `−R$ 5,00`). Tapping either value reopens the modal.
- When the line has packaging, the existing sub-line becomes `R$ 32,00 item (−R$ 8,00 desconto) + R$ 4,00 embalagem`.

**Modal "Desconto no item"** (Bootstrap modal driven by `x-effect`, like the existing builder and confirmation modals):

1. Title: `Desconto em 2x Prato do Dia`. Subtitle: `Valor do item: R$ 40,00` (packaging not included; a note "A embalagem não entra no desconto" appears when the line has packaging).
2. **Type toggle**: two large segmented buttons, **"% Porcentagem"** and **"R$ Valor"**. The default is %.
3. **Value field**: large, autofocused, with `inputmode="decimal"`, and it accepts a comma ("12,5"). It has a "%" suffix or an "R$" prefix depending on the type.
4. **No ready-made percentage buttons.** The operator always types the value (client decision, 2026-10-23).
5. **Live preview** (no request, recalculated on every keystroke):
   `De R$ 40,00 por R$ 32,00  (desconto de R$ 8,00)`
6. **Inline errors** (red text under the field). While any error is shown, **"Aplicar"** is disabled:
   - empty or zero: "Informe um valor maior que zero."
   - above the item value: "O desconto não pode ser maior que o valor do item (R$ 40,00)."
   - above the maximum: "O desconto máximo permitido é de 30% (R$ 12,00 neste item)."
7. Footer: **"Cancelar"** and **"Aplicar"**. If the line already has a discount, there is also a link-style **"Remover desconto"**. Pressing **Enter** applies, if valid.

**Order total**:
- When any line has a discount, a line `Descontos  −R$ 13,00` (`text-danger`) appears right above `Total`, and `Total` is net.
- Without discounts, the summary is unchanged.

**Cart changes after a discount**:
- `+`/`−` quantity and the merge-on-repeat in `addItem()` keep the line's discount. Percent recomputes live; fixed stays.
- If a fixed discount becomes invalid (above the new line value or above the maximum), the badge turns red and reads "Revise o desconto", and **"Enviar Pedido" is disabled** until it is fixed or removed. The cashier never clamps silently, because a human is present and should see it.
- "Editar montagem" (build-your-own dish) keeps the discount and revalidates it the same way.

**Confirmation modal (spec 046)**: each discounted line shows the struck-through and new value plus the badge. A `Descontos` line appears above `Total`.

**After a successful send**, discounts reset with the cart.

### Kitchen UI

**Board cards and food summary: no change.** They must not render any discount value, badge or wording.

**"Editar Pedido" modal**: each item card gains one more row, below the dining option and quantity controls:

```
🏷️ Desconto: −20% (−R$ 8,00)            [Alterar]
```

or `🏷️ Sem desconto   [Dar desconto]` when the line has none. When the admin's maximum is 0, the row is not rendered.

- Tapping the button expands an **inline panel inside that item card**. It is inline, not a second modal, because Bootstrap modals don't stack well. The panel has the same controls and rules as the cashier's modal:
  - the "% Porcentagem" / "R$ Valor" toggle
  - the value field
  - the preview `De R$ 40,00 por R$ 32,00`
  - the same inline errors
  - the buttons "Aplicar", "Remover desconto" and "Cancelar"
- The line value is computed from the item's current `unit_price` and the quantity being edited. If the server re-prices the line on save and the discount no longer fits, the save returns the error as a toast.
- "Aplicar" or "Remover" only changes the modal's local copy. Nothing is sent until **"Salvar"**, consistent with how the modal already treats quantity, notes and dining option. On save, `discount` is included in an item's `PATCH` **only if it was changed** in this modal, so an unchanged line keeps the automatic adjustment and no audit row is written.
- The validation logic (amount, ceilings, messages) is shared with the cashier through `GF.discount` in `public/assets/js/gf.js`, so the two screens can't drift.

### Admin UI ("Configurações", admin only)

A new small card **"Desconto no caixa"** (`fa-tag`) with one field:
- **"Desconto máximo por item (%)"**: a number from 0 to 100, default 100
- help text: "0 desativa o desconto no caixa."

It is saved through the existing settings save.

### Reports (`/admin/reports.php`)

- **Net revenue everywhere**:
  - Daily, monthly comparison and summary: `unit_price*quantity + packaging_cost − discount_amount`.
  - Top items and main dishes: `unit_price*quantity − discount_amount`.
  - Revenue headings gain the hint "(líquido de descontos)".
- **New card "Descontos concedidos"** for the selected period:
  - total discounted (R$)
  - number of orders with at least one discounted line
  - discount as % of gross product sales
  - the top 10 discounted items: Item · Linhas · Total desconto
- `GET /api/admin/reports/summary` also returns `discount_total`.

### Ticket (`PrintService`)

- **Without any discount, the ticket output is byte-for-byte unchanged.**
- **A discounted line**:
  - the line total printed on the item row is **net**
  - a line is added under it, like the existing packaging label: `   Desconto 20%: -R$ 8,00`, or `   Desconto: -R$ 5,00` for a fixed discount
  - `TOTAL` is net

## Functional requirements

1. **FR1** — In `POST /api/orders`, each element of `items[]` accepts an optional `discount` object: `{ "type": "percent"|"fixed", "value": number|string }`. Omitting it, or sending `null`, keeps today's behavior exactly.
2. **FR2** — The server computes each line's discount amount with the rules above (`PricingService`). Client-computed amounts are never accepted.
3. **FR3** — A discount that breaks any of rules 2–5 rejects the **whole** order with `400`, code `INVALID_DISCOUNT`, and the Portuguese message listed in "Inline errors", prefixed with the item name (e.g. "Prato do Dia: O desconto não pode ser maior que o valor do item (R$ 40,00)."). No order is created and no order number is consumed.
4. **FR4** — `PATCH /api/orders/{id}/items/{itemId}` accepts an optional `discount`:
   - an object (same shape as FR1) sets it, under every rule, against the re-priced line value
   - `null` removes it
   - absent keeps it, with the automatic adjustment
   
   A rule violation → `400 INVALID_DISCOUNT` and nothing in that item changes. `POST /api/orders/{id}/items` does **not** accept `discount` (`400 VALIDATION_FAILED`).
5. **FR5** — `updateOrderItem()` recomputes a discounted line's amount as described in "Automatic adjustment" when `discount` is absent. `addOrderItem()` always stores a line with no discount.
6. **FR6** — `GET /api/orders` keeps its shape. It adds per item `discount: null | { type, value, amount }` (numbers in reais or percent). The kitchen board ignores it; only "Editar Pedido" reads it.
7. **FR7** — The `discount_max_percent` setting is validated by `SettingsValidator` (numeric, 0–100, at most 2 decimals). It is exposed to the cashier and the kitchen through a new public `GET /api/settings/discount` → `{ "discount_max_percent": number }`, which both load together with `/api/menu`. `/api/menu`'s bare-array shape is left untouched.
8. **FR8** — `ReportService` revenue figures subtract `order_items.discount_amount` as described in "Reports". The new `GET /api/admin/reports/discounts?date_from&date_to` (admin or manager, like the other reports) returns `{ total_discount, orders_with_discount, gross_products, top_items: [{ name, lines, total }] }`, counting done orders only.
9. **FR9** — `PrintService` prints net line totals, the per-line discount label and a net `TOTAL`, only for lines with `discount_amount > 0`.
10. **FR10** — Audit entries:
    - Creating an order with at least one discount writes one `order.discount_applied` entry, with details `{ source: "cashier", items: [{ name, type, value, amount }] }`.
    - A kitchen `PATCH` that sets a discount writes `order.discount_applied`, with details `{ source: "kitchen", item_id, name, type, value, amount }`.
    - A kitchen `PATCH` that removes an existing discount writes `order.discount_removed`, with details `{ item_id, name }`.
    - Automatic adjustments (FR5) are not audited.

## Non-functional requirements

- **Exactness**: everything is computed in `App\Money` integer cents, and percentages as integer basis points. There is no float accumulation in PHP, and the cashier's JS sums in cents, as `builderUnitPrice` already does.
- **No new dependencies**: only vendored Bootstrap, Alpine and Font Awesome (spec 055).
- **Responsiveness**: the cashier preview needs no request. Only "Enviar Pedido" reaches the server.
- **Language split**: domain comments in English, user-facing strings in Portuguese (CLAUDE.md).

## User flows

**Cashier — percentage**
1. Add 2× Prato do Dia. The line shows `R$ 40,00 🏷️`.
2. Tap the price and type `20`. The preview reads "De R$ 40,00 por R$ 32,00". Tap **Aplicar**.
3. The line shows `R̶$̶ ̶4̶0̶,̶0̶0̶ R$ 32,00 [−20%]`, and the summary shows `Descontos −R$ 8,00`.
4. **Enviar Pedido**, then **Confirmar e enviar**.

**Cashier — fixed amount**
1. Tap the price, tap **R$ Valor**, type `5`, and tap **Aplicar**. The line now costs R$ 35,00.

**Cashier — mistake caught before sending**
1. A line has a R$ 30 fixed discount, and the operator lowers the quantity from 2 to 1 (line value R$ 20).
2. The badge turns red ("Revise o desconto") and **Enviar Pedido** is disabled.
3. The operator taps the price and fixes or removes the discount.

**Cashier — removing**
1. Tap the discounted price, then **Remover desconto**.

**Kitchen — fixing a discount**
1. Open **Editar Pedido**. The Prato line shows `🏷️ Sem desconto [Dar desconto]`.
2. Tap it, type `20`, then tap **Aplicar**. The row shows `−20% (−R$ 8,00)`.
3. Tap **Salvar**.

**Kitchen — changing quantity only**
1. The board shows no discount anywhere.
2. If the kitchen changes a discounted line's quantity and saves, the server adjusts the discount automatically.

**Admin**
1. Open **Configurações → Desconto no caixa**, set `30`, and save. The cashier enforces it the next time the menu loads.

**Admin/Manager — reports**
1. Revenue figures are net. **"Descontos concedidos"** shows how much was given and on which items.

## API changes

All additive. Existing request shapes stay valid.

- `POST /api/orders`: optional `items[].discount` (FR1).

  ```json
  {
    "items": [
      { "id": 1, "quantity": 2, "discount": { "type": "percent", "value": 20 } },
      { "id": 31, "quantity": 1 }
    ]
  }
  ```

- `PATCH /api/orders/{id}/items/{itemId}`: optional `discount` object or `null` (FR4). `POST /api/orders/{id}/items`: `discount` rejected.
- `GET /api/orders`: per-item `discount` (FR6).
- `GET /api/menu`: today it returns a bare array of categories (`MenuController::index`), consumed by cashier, kitchen and admin. **Adding a top-level field would break that shape.** The cap is therefore delivered through a separate, tiny public endpoint, **`GET /api/settings/discount`**, which returns `{ "discount_max_percent": 100 }`. The cashier and the kitchen call it alongside `/api/menu` (FR7).
- `PUT /api/admin/settings`: accepts `discount_max_percent` (FR7).
- `GET /api/admin/reports/discounts`: new (FR8).
- `GET /api/admin/reports/summary`: adds `discount_total`.
- `public/api/docs/openapi.yaml` is updated for all of the above.

## Data model and migrations

New migration **`common/migrations/021_order_item_discount.sql`**. It is idempotent, with `information_schema` column guards in the style of migrations 015/019.

```sql
ALTER TABLE order_items
  ADD COLUMN discount_type ENUM('percent','fixed') NULL DEFAULT NULL AFTER packaging_cost,
  ADD COLUMN discount_value DECIMAL(10,2) NULL DEFAULT NULL AFTER discount_type,   -- as typed: 20.00 (=20%) or 5.00 (=R$5)
  ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value; -- applied, in reais

INSERT IGNORE INTO settings (`key`, `value`) VALUES ('discount_max_percent', '100');
```

- No new table, and no change to `orders`.
- Existing rows get `discount_amount = 0.00`, so every existing total is unchanged.
- `App\Models\OrderItem` gets the new attributes added to `$fillable` (and casts if needed).

## Architecture and affected components

The real layering is followed. **No new Controller, Repository or Validator class** (the discount belongs to the existing order and pricing domain), except one small controller action for the cashier settings endpoint.

- **`src/Money.php`**: add `minus()`, `min()`, `isGreaterThan()` and `percent(int $basisPoints)` (half-up on integer cents).
- **`src/Services/PricingService.php`**:
  - `lineProductValue(Money $unitPrice, int $quantity): Money`
  - `lineDiscount(string $type, string $value, Money $lineProductValue): Money`
  - `discountCap(Money $lineProductValue, string $maxPercent): Money`
  - `lineTotal()` gains an optional `Money $discount` parameter, so existing callers stay unchanged.

  All are pure, as the rest of the class.
- **New `src/InvalidDiscountException.php`**, following the `OrderCancelledException` precedent. `OrderController::store()` maps it to `400 INVALID_DISCOUNT`.
- **`src/Validators/OrderValidator.php`**:
  - `items[].discount` shape in `validateOrderData()`: type in the enum; value numeric (not exponent notation), positive, at most 2 decimals.
  - Reject `discount` in `validateOrderItemAdd()`; accept the same shape (or `null`) in `validateOrderItemUpdate()`.
- **`src/Repositories/OrderRepository.php`**:
  - `createOrder()` computes and validates each line's discount **before** allocating the order number (spec 022 principle), reading `discount_max_percent` once.
  - `updateOrderItem()` applies or removes an explicit `discount`, or recalculates after `repriceItem()`.
  - `getOrdersByStatus()` adds `discount`.
- **`src/Services/OrderService.php`**: the audit entries (FR10). `AuditLogger` is injected like in `MenuService`.
- **`src/Controllers/SettingsController.php`**: the public `discountSettings` action. **`src/Routes.php`**: `GET /api/settings/discount` in the public `/api` group, and `GET /api/admin/reports/discounts` under `adminOrManager`.
- **`src/Validators/SettingsValidator.php`**: the `discount_max_percent` rule.
- **`src/Services/ReportService.php`**: net expressions, `getDiscounts()`, and `discount_total` in `getDailySummary()`. **`src/Controllers/ReportController.php`**: the `discounts` action.
- **`src/Services/PrintService.php`**: net line totals and the discount label.
- **Frontend**:
  - `public/cashier/app.js` + `index.php`: per-line `discount` state, the price-tag button, the modal, live validation, the totals and the payload.
  - `public/admin/settings.php` + `settings.js`: the new card.
  - `public/admin/reports.php` + `reports.js`: net labels and the "Descontos concedidos" card.
  - `public/assets/js/gf.js`: a shared `GF.discount` helper (cents math, ceilings, Portuguese messages).
  - `public/kitchen/app.js` + `index.php`: the discount row and inline panel in "Editar Pedido" only.
- **Docs**:
  - `public/api/docs/openapi.yaml`
  - `docs/architecture.md` (pricing section)
  - `specs/000-project-baseline.md` (dated note)
  - `README.md` (feature list)
  - `CHANGELOG.md` ("Não lançado")

## Security considerations

- **No authentication on the cashier** is unchanged and deliberate (spec 018). Anyone on the LAN who can create orders can discount up to the admin's maximum. That maximum, enforced **server-side** on every order, is the control. Setting it to 0 disables the feature completely.
- Only an **admin** (JWT + `RoleMiddleware(['admin'])`) can change the maximum, and the change is audited (`settings.updated`).
- The server recomputes every amount. A tampered client can't exceed the limits or inject a precomputed amount.
- Input validation:
  - the type is in an enum
  - values are rejected when negative, zero, `NaN`, in exponent notation, arrays, or with more than 2 decimals
- The kitchen's `PATCH` enforces exactly the same rules and maximum as order creation (FR4), so it can't be used to exceed them.

## Backward compatibility

- **Stored data**: existing lines have `discount_amount = 0`, so every historical total, report and reprinted ticket is unchanged.
- **API consumers**: all changes are additive. An order without `discount` behaves exactly as today. `GET /api/menu`'s shape is deliberately untouched.
- **Kitchen**: the board is unchanged. "Editar Pedido" gains the discount row. Its existing `PATCH` calls without `discount` keep working exactly as before, and a discounted line is adjusted automatically.
- **Reports** only change for periods that contain discounted lines, which is the intended correction.
- **Migration** is additive with defaults, so older code keeps working against the new schema during deploy.

## Acceptance criteria

The prices used are seeded menu items: Prato do Dia R$ 20,00 (id 1), Coca-Cola R$ 5,00 (id 31). Packaging items are linked per spec 050 (Simples R$ 1,00, Especial R$ 2,00). C = 100 unless stated.

1. **AC1 — percent**: `POST /api/orders` with 2× Prato (`discount {percent, 20}`) + 1× Coca → `201`.
   - Prato line: `discount_type = percent`, `discount_value = 20.00`, `discount_amount = 8.00`.
   - Coca line: `discount_amount = 0.00`.
   - The order total is `37.00`.
2. **AC2 — fixed, whole line**: 2× Prato with `{fixed, 5}` → `discount_amount = 5.00`, line total `35.00` (not 30.00).
3. **AC3 — packaging excluded**: 1× Prato `viagem_vip` with `{percent, 100}` → `discount_amount = 20.00`, line total `2.00`.
4. **AC4 — rounding**: 3× Coca with `{percent, 33.33}` → `discount_amount = 5.00` (1500 × 33.33% = 499.95 cents, rounded half-up to 500).
5. **AC5 — above item value**: 2× Prato with `{fixed, 40.01}` → `400 INVALID_DISCOUNT` "Prato do Dia: O desconto não pode ser maior que o valor do item (R$ 40,00)."
   - No row is added to `orders`.
   - `GET /api/orders/next-number` returns the same value as before the request.
6. **AC6 — admin maximum**: with `discount_max_percent = 30`, on 2× Prato:
   - `{percent, 31}` → `400`
   - `{fixed, 12.01}` → `400`
   - `{fixed, 12}` → `201`
7. **AC7 — disabled**: with `discount_max_percent = 0`:
   - any `discount` → `400 INVALID_DISCOUNT` "O desconto está desativado."
   - on the cashier, the line price is plain text (no button)
8. **AC8 — shape**:
   - `{type "half", …}`, `{percent, -5}`, `{percent, "1e1"}`, `{fixed, 1.005}` and `{percent, 0}` each → `400 VALIDATION_FAILED`
   - `discount` sent to `POST /api/orders/{id}/items` → `400 VALIDATION_FAILED`
9. **AC9 — silent adjustment**:
   - On the AC1 order, `PATCH /items/{prato}` with `quantity 1` → that line's `discount_amount = 4.00`.
   - On a 2× Prato line with `{fixed, 30}`, `PATCH quantity 1` → `discount_amount = 20.00`.
   - A line added via `POST /api/orders/{id}/items` → `discount_amount = 0.00`.
10. **AC10 — kitchen board shows nothing / edit modal edits**:
    - With a discounted pending order on screen and no modal open, the kitchen board's rendered text contains neither "Desconto" nor "desconto", nor any "R$" value (Playwright).
    - In "Editar Pedido", `PATCH /items/{prato}` with `discount {percent, 10}` on the AC1 order → `200`, and that line's `discount_amount = 4.00`.
    - With `discount null` → `discount_amount = 0.00`, `discount_type` is NULL.
    - With `discount {fixed, 50}` → `400 INVALID_DISCOUNT`, and the line is unchanged.
    - With `discount_max_percent = 30`, `{percent, 31}` → `400`.
11. **AC11 — reports**: with only the AC1 order marked done on day D:
    - `GET /api/admin/reports/summary?date=D` → `revenue 37.00`, `discount_total 8.00`
    - `/reports/top-items` → Prato `32.00`, Coca `5.00`
    - `/reports/discounts` → `total_discount 8.00`, `orders_with_discount 1`, `gross_products 45.00`, `top_items [{ "Prato do Dia", 1, 8.00 }]`
12. **AC12 — reports unchanged without discounts**: on a database with only discount-free orders, every `/api/admin/reports/*` response is identical before and after the change.
13. **AC13 — ticket**:
    - The AC1 ticket shows the Prato line at `R$ 32,00`, followed by `   Desconto 20%: -R$ 8,00`, and `TOTAL: R$ 37,00`.
    - A discount-free order's ticket output is byte-identical to the pre-change output.
14. **AC14 — cashier UI** (Playwright):
    - Tapping a line price opens "Desconto no item".
    - Typing `41` in R$ mode on a R$ 40,00 line shows the item-value error and disables "Aplicar".
    - Applying `20%` shows the struck-through `R$ 40,00`, `R$ 32,00`, the badge `−20%` and `Descontos −R$ 8,00`.
    - Lowering the quantity of a line with a R$ 30 fixed discount to 1 disables "Enviar Pedido".
15. **AC15 — audit**:
    - AC1 writes exactly one `audit_log` row with action `order.discount_applied` (`source: cashier`), whose details list only the Prato line.
    - AC9's adjustments write none.
    - AC10's set writes one `order.discount_applied` (`source: kitchen`), and its removal writes one `order.discount_removed`.
16. **AC16 — admin setting**:
    - `PUT /api/admin/settings {discount_max_percent: "101"}` → `400`.
    - `"30"` → `200`, and `GET /api/settings/discount` then returns `30`.
    - As `manager`, `PUT /api/admin/settings` → `403`, unchanged.

## Implementation plan

1. Migration `021_order_item_discount.sql` and the `OrderItem` attributes.
2. `Money` helpers and the `PricingService` discount methods, with unit tests (rules 2–6, AC4 rounding).
3. `OrderValidator` discount shape and the rejection on the kitchen endpoints (AC8).
4. `InvalidDiscountException`, the discount in `OrderRepository::createOrder()`, the controller mapping and the audit entry (AC1–AC7, AC15).
5. Explicit discount and automatic adjustment in `updateOrderItem()`, and the `discount` field in `GET /api/orders` (AC9, AC10).
6. The `discount_max_percent` setting: validator, `GET /api/settings/discount` and route (AC16).
7. `ReportService` net figures, `getDiscounts()` and `discount_total`, plus the controller and route (AC11, AC12).
8. `PrintService` ticket changes (AC13).
9. Cashier UI: price-tag button, modal, live validation, totals, confirmation modal and payload (AC14).
10. Admin "Desconto no caixa" card.
11. Admin reports: net labels and the "Descontos concedidos" card.
12. Kitchen "Editar Pedido" discount row and inline panel (AC10).
13. OpenAPI and docs pass, plus the `CHANGELOG.md` entry under "Não lançado", in this spec's PR.

## Testing and validation strategy

The repository has automated tests (specs 004, 034, 035, 040), so the `/spec-plan` skill's "no automated test infrastructure" line is out of date. Use them:

- **Unit (`tests/Unit`)**:
  - `PricingServiceTest`: percent/fixed, half-up, the cap and the ceilings
  - `MoneyTest`: the new helpers
  - `OrderValidatorTest`: AC8 shapes
  - `SettingsValidatorTest`: `discount_max_percent`
  - `PrintServiceTest`: AC13, with and without a discount
- **Integration (`tests/Integration`, MySQL `restaurant_test`)**:
  - `OrderWorkflowTest`: AC1–AC9
  - `MenuReportsAndJourneyTest`: AC11, AC12
  - `AuditLogTest`: AC15
  - `GuardTest`: AC16
  - `MigrationUpgradeTest`: migration 021 is idempotent and runs on a populated DB
- **Browser (`tests/e2e`, Playwright on :8081)**: AC14 (cashier modal bindings) and AC10 (the kitchen board shows nothing).
- **Static/style**: PHPStan level 5 and PHP-CS-Fixer, as in CI.
- **Manual**:
  - Print a real ticket for AC13 (column fit at 32 chars).
  - Have a non-technical person discount a line by % and by R$ without instructions, and note any hesitation in the Implementation log.

## Rollout and rollback

- **Rollout**: `bin/migrate` (applies 021), then deploy. With the default maximum of 100%, the price tag is tappable immediately. Tell the client, or set the maximum first.
- **Rollback**:
  - The code can be reverted independently, because old code ignores the new columns.
  - Reports and tickets would go back to gross figures, including for orders that had discounts. The data stays in the DB and comes back with a re-deploy.
  - The columns are additive and stay in place. Dropping them would be a destructive DB operation and is not part of the rollback (CLAUDE.md security rules).

## Open questions

None blocking.

1. ~~Ticket and the kitchen~~: closed on 2026-10-23. The client confirmed that the ticket shows the discount.
2. ~~Wrong discount after sending~~: closed on 2026-10-23. The kitchen's "Editar Pedido" can now fix it.

## Task checklist

- [x] 1. Migration 021 + `OrderItem` attributes
- [x] 2. `Money` helpers + `PricingService` discount methods + unit tests
- [x] 3. `OrderValidator` discount shape + rejection on `POST /items` (accepted on `PATCH`)
- [x] 4. Discount on order creation + error mapping + audit
- [x] 5. Explicit discount + automatic adjustment in `updateOrderItem()` + `discount` in `GET /api/orders`
- [x] 6. `discount_max_percent` setting + `GET /api/settings/discount`
- [x] 7. Net revenue in reports + discounts report
- [x] 8. Ticket changes
- [x] 9. Cashier UI
- [x] 10. Admin "Desconto no caixa" card
- [x] 11. Admin reports UI
- [x] 12. Kitchen "Editar Pedido" discount row
- [x] 13. OpenAPI + docs pass + CHANGELOG

## Implementation log

- 2026-10-23: **No order-level money is stored**, as planned: `orders` is untouched; three columns on `order_items`. `PricingService` gained `chosenDiscount()` (a person's choice: every rule + the admin's maximum) and `adjustedDiscount()` (automatic recalculation: percent re-applied, fixed clamped, no maximum). Values are integer hundredths end to end (`Money::percentOf()` in basis points, half-up), so 33.33% of R$ 15,00 is exactly R$ 5,00.
- 2026-10-23: `createOrder()` now computes each line's composed unit price in the first (validation) loop instead of the insert loop, so a rejected discount aborts before `Order::create()`/number allocation (AC5). Same arithmetic, moved earlier.
- 2026-10-23: `InvalidDiscountException extends \DomainException` (precedent: `OrderCancelledException`); `OrderController` catches it **before** the generic `\DomainException` in `store()`, and in `updateItem()` → `400 INVALID_DISCOUNT`. Messages from order creation are prefixed with the item name.
- 2026-10-23: kitchen `PATCH` semantics — `discount` object sets (validated against the line **after** spec 052's re-pricing), `null` removes, absent keeps + auto-adjusts. The kitchen UI only sends `discount` for lines the operator actually changed in the modal, so saving an order neither re-validates untouched discounts against the maximum nor writes audit rows. A rejected discount rolls back the whole item update (one transaction) — covered by `testKitchenCanSetAndRemoveADiscount`.
- 2026-10-23: `OrderService` now takes `AuditLogger` (constructor change → `tests/Unit/OrderServiceTest.php` updated). The lookup of a new order's discounted lines lives in `OrderRepository::getDiscountedItems()` so the service stays DB-free in unit tests.
- 2026-10-23: endpoint name `GET /api/settings/discount` (the spec draft said `/api/cashier/settings`; renamed in the spec before coding because the kitchen reads it too). `SettingsValidator` needs `required` on `discount_max_percent`: Valitron skips custom rules on an empty string, which would have silently meant 100%.
- 2026-10-23: client asked to **remove the quick 10/20/50/100% buttons** after seeing the plan; removed from cashier and kitchen, the spec's UI section updated, and the browser test now asserts no `NN%` button exists. Default maximum stays 100% (confirmed).
- 2026-10-23: browser checks found three UI bugs, fixed: (1) `x-show` on elements with Bootstrap `d-flex`/`d-block` never hides (those utilities are `!important`) — "Descontos −R$ 0.00" and "A embalagem não entra no desconto" showed when they shouldn't; switched to `x-if`/wrapper divs. (2) Tapping "Aplicar" during the modal's opening animation left it open (Bootstrap ignores `hide()` mid-transition) — `syncDiscountModal()` re-hides on `shown.bs.modal`. (3) Esc closed the Bootstrap modal but left Alpine's state open, so the next tap on the price did nothing — Esc now goes through Alpine (`data-bs-keyboard="false"` + `@keydown.escape`).
- 2026-10-23: the docs pass found `docs/architecture.md`'s migration count had also missed spec 053's `020_login_attempts.sql`, and the baseline's report route list had missed spec 032's `main-dishes`; both corrected with dated notes.
- 2026-10-23: client approved implementation ("Pode começar") after adding kitchen discount editing and confirming the ticket. Status Draft → Approved → In Progress.
- 2026-10-23: `.claude/skills/spec-implement/SKILL.md` step 9 still says the project has no test infrastructure; that is outdated (specs 004/034/035/040). The existing PHPUnit suites are used and extended instead.
- 2026-10-23 (planning, not implementation): the first draft (order-level discount, preset reasons in Admin, kitchen editing) was replaced by this per-line, cashier-only design at the client's request. It was never implemented.

## Validation evidence

Environment: local sandbox — PHP 8.3.6 CLI, MySQL 8.0.46 installed locally (no Docker daemon available), databases `restaurant` (built from `001_schema.sql` + all migrations, as CI does), `restaurant_test`, `restaurant_test_upgrade`; Playwright 1.63 with the sandbox's preinstalled Chromium, against `php -S` on the test database. No `.env` was created or read: configuration came from environment variables.

**Baseline before any change**: `vendor/bin/phpunit` → `OK (250 tests, 608 assertions)`.

**After the change**:

- `vendor/bin/phpunit` (Smoke + Unit + Integration, with `MYSQL_DATABASE_TEST`/`_UPGRADE` set) → `OK (307 tests, 793 assertions)`. Includes `MigrationUpgradeTest` (upgrade from `v1.7.1` through 021) and every pre-existing test unchanged except the constructor update in `OrderServiceTest` and the new columns in the in-memory schemas of `OrderRepositoryTest`/`ReportServiceTest`.
- `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 113 files that can be fixed`.
- `git ls-files --eol` → no CRLF/mixed blobs; new files resolve to `eol=lf`.
- Browser suite `npx playwright test` (all specs) → `25 passed, 5 skipped` (the 5 are `realtime-events.spec.ts`, which skips itself under `E2E_PHP_DIRECT=1` by design); `item-discount.spec.ts` alone with `--repeat-each=3` → `24 passed`.
- Real HTTP against `php -S` on the test DB: an order with `{percent, "20"}` on 2× Prato do Dia (R$ 29,90 in that DB) and `{fixed, "1.5"}` on a Coca-Cola, then completed → `/reports/discounts` returned `total_discount 13.46, gross_products 64.8, share 20.8`, `/reports/summary` returned `revenue 51.34, discount_total 13.46` (64,80 − 13,46).
- Screens inspected visually (screenshots): cashier modal, cashier summary with struck-through price and badge, error state, confirmation modal, kitchen board (no discount shown), kitchen "Editar Pedido" panel, Admin "Desconto no caixa" card, Admin "Descontos concedidos" card.

| AC | Evidence |
|---|---|
| AC1 | `ItemDiscountTest::testPercentDiscountIsStoredOnTheLine` (Integration): `percent / 20.00 / 8.00`, Coca `0.00`, total `37.00` |
| AC2 | `testFixedDiscountAppliesToTheWholeLine` (Integration) + `testFixedDiscountAppliesToTheLineNotPerUnit` (Unit): total `35.00` |
| AC3 | `testPackagingIsNotDiscounted` (Integration) + `testPackagingIsNeverDiscounted` (Unit) |
| AC4 | `testPercentRoundsHalfUp` (Integration) + `testPercentOfRoundsHalfUpInIntegerCents` (Unit): `5.00` |
| AC5 | `testDiscountAboveTheItemRejectsTheWholeOrderWithoutConsumingANumber`: `400 INVALID_DISCOUNT`, exact message, 0 orders, next-number unchanged |
| AC6 | `testAdminMaximumIsEnforced`: 31% → 400, R$ 12,01 → 400, R$ 12 → 201 |
| AC7 | `testMaximumZeroDisablesDiscounts` (API) + browser "teto 0 deixa o preço como texto, sem botão" |
| AC8 | `testMalformedDiscountIsAValidationError`, `testKitchenCannotSendADiscountWhenAddingAnItem` (Integration) + `testDiscountShape` data set (Unit) |
| AC9 | `testKitchenQuantityChangeAdjustsTheDiscountAutomatically`: `4.00`; fixed 30 → `20.00`; kitchen-added line `discount: null` |
| AC10 | Browser "cozinha: o quadro não mostra nada de desconto" (no `desconto`, no `R$`) and "\"Editar Pedido\" mostra e altera…"; Integration `testKitchenCanSetAndRemoveADiscount` (10% → `4.00`, `null` → cleared, R$ 50 → 400 with item unchanged, 31% over a 30% maximum → 400) |
| AC11 | `testReportsAreNetOfDiscounts`: summary `37.00`/`8.00`, top items `32.00`/`5.00`, sales `37.00`, discounts report as specified; `ReportServiceTest::testMainDishRevenueIsNetOfItemDiscounts` |
| AC12 | All pre-existing report tests (`ReportServiceTest`, `MenuReportsAndJourneyTest`) pass unchanged on discount-free data. Not compared byte-by-byte against a pre-change server on the same database — the SQL change is `− discount_amount`, which is `0.00` on every pre-existing row by the migration's default |
| AC13 | `PrintServiceTest::testDiscountedLineIsPrintedNetWithItsDiscountLabel` (`R$ 32,00`, `   Desconto 20%: -R$ 8,00`, `TOTAL: R$ 37,00`), `testFixedDiscountLabelHasNoRate`, `testTicketWithoutDiscountIsUnchanged` (same bytes with and without zeroed discount fields). **Not done: a real thermal print** (no printer here) |
| AC14 | Browser `item-discount.spec.ts`: modal opens from the price; R$ 41 on R$ 40,00 → message + disabled "Aplicar"; 20% → `R$ 40.00` struck, `−20%`, `−R$ 8.00`, total `32.00`; R$ 30 then quantity 2 → 1 → "Revise o desconto" + "Enviar Pedido" disabled; payload carries `{type: "percent", value: "12.50"}` |
| AC15 | `testAuditRecordsOnlyPeopleChoosingADiscount`: one cashier row listing only the discounted item; none for the automatic adjustment; kitchen set → `source: kitchen`; removal → one `order.discount_removed`; removing nothing → no new row |
| AC16 | `testDiscountMaximumSettingIsValidatedAndPublic`: `"101"` → 400, `"30"` → 200 and `GET /api/settings/discount` → `30`, manager → 403 and value unchanged; `SettingsValidatorTest::testDiscountMaxPercent` |

**Not run here — to do before `Verified`:**

- **PHPStan**: `phpstan/phpstan` could not be installed in this sandbox (its dist download from GitHub was refused by the network proxy). It runs in CI; check the CI result on the PR.
- **Real thermal ticket** (AC13 manual part: 32-column fit and accents on the Epson).
- **Non-technical user check** of the cashier modal (planned in "Testing and validation strategy").
- The suite ran on PHP 8.3 locally; CI uses PHP 8.2.
