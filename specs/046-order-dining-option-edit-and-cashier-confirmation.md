# Spec 046 — Kitchen dining-option edit and Cashier send confirmation modal

## Metadata

- Status: Verified
- Created: 2026-09-26
- Updated: 2026-09-26
- Owner: Henry
- Related issue: Not applicable (client-requested fix, not tied to a `docs/ROADMAP.md` milestone item — same category as the client-requested work `docs/ROADMAP.md` already lists as landing between milestones, e.g. specs 030-032)
- Related branch: 046 (to be created at `/spec-implement` time, per this repo's one-branch-per-spec convention)

## Context

The user (restaurant staff) reports frequent mistakes where the cashier forgets to mark an item as "para viagem" (takeout) when the customer actually asked for it — the item goes to the kitchen tagged "Local" (eat-in) by default and the mistake is only caught after the fact, if at all. Two changes were requested to reduce this:

1. Let Kitchen staff fix an order's dining option (Local / Simples / VIP) after the fact, from the existing "Editar Pedido" modal, without having to cancel and recreate the whole order.
2. Make the Cashier show a confirmation modal listing the order's items right before it is actually sent, as one last chance to catch a missed "para viagem" mark before the ticket reaches the kitchen.

"Comer no local, Simples ou VIP" in the request refers to the existing per-item `dining_option` field (`local` / `viagem_simples` / `viagem_vip`, migration `common/migrations/005_dining_option.sql`), already surfaced in the Cashier UI with exactly those three labels (`public/cashier/index.php:282-297`) and already priced (+R$1,00 / +R$2,00 packaging fee — `src/Services/PricingService.php:22-29`). This spec does not introduce a new concept — it extends where that existing field can be edited, and adds a review step before an order is sent.

## Problem

- **Kitchen cannot correct a wrong dining option.** The "Editar Pedido" modal (`public/kitchen/index.php:385-464`, driven by `public/kitchen/app.js` `openEditModal`/`saveOrderChanges`) lets staff change `order_number`, `customer_name`, item `quantity` and `notes`, and add/remove items — but has no control for `dining_option`. The backend has no path for it either: `OrderValidator::validateOrderItemUpdate` (`src/Validators/OrderValidator.php:109-119`) only declares rules for `quantity` and `notes`, and `OrderRepository::updateOrderItem` (`src/Repositories/OrderRepository.php:452-468`) only ever writes `quantity`/`notes` to the `OrderItem` row. Today, the only way to "fix" a wrong dining option once an order exists is to cancel the whole order and recreate it.
- **Cashier sends immediately, with no review step.** `submitOrder()` (`public/cashier/app.js:303-353`) is called directly by the "Enviar Pedido" button (`public/cashier/index.php:322-326`) and fires the `POST /api/orders` request right away. Nothing forces a last look at each item's dining option before the order — and the kitchen ticket — is created.

## Goals

- Allow editing an existing order item's dining option (`local` / `viagem_simples` / `viagem_vip`) from Kitchen's "Editar Pedido" modal, with the change persisted and reflected in pricing (`packaging_cost`), reprints, and reports.
- Add a mandatory confirmation modal in Cashier, shown between clicking "Enviar Pedido" and the order actually being submitted, that lists every item with its current dining option so the cashier can catch a mistake before sending.

## Non-goals

- Not changing the packaging fee values or the pricing rule itself (R$1,00 Simples / R$2,00 VIP) — that is spec 026 territory, unchanged here.
- Not adding automatic detection/inference of "the customer probably wanted takeout" — no heuristic or validation blocks a "Local" order from being sent; this is a manual review step only.
- Not changing `POST /api/orders` request/response shape — `OrderValidator::validateOrderData` already accepts `dining_option` per item at creation time (`src/Validators/OrderValidator.php:22-81`).
- Not touching `PrintService` receipt formatting or `ReportService`'s dining-option breakdown — both already read `dining_option`/`packaging_cost` live off the `order_items` row at the time they run (`src/Services/PrintService.php:361-380`, `src/Services/ReportService.php:143-162`), so a corrected value is picked up automatically once the repository persists it correctly.
- ~~Not adding an editing UI *inside* the new Cashier confirmation modal...~~ **Superseded 2026-09-26** (user request, mid-implementation): the confirmation modal *does* let the cashier change an item's dining option directly, without closing the modal. See updated `Proposed behavior`/functional requirement 13/acceptance criterion 8. Quantity, notes, and item removal remain outside the modal's scope — only the dining-option control was added.
- Not changing authentication/authorization on any of the affected routes — `/api/orders/{id}/items/{itemId}` (PATCH) is already public/unauthenticated, consistent with every other Cashier/Kitchen order route (`src/Routes.php:50-69`, no login for these workflows per spec 018).

## Current behavior

**Kitchen edit modal**
- `openEditModal(order)` deep-copies the order into `editingOrder` (`public/kitchen/app.js:321-326`).
- The modal (`public/kitchen/index.php:385-464`) renders each item with a name, category badge, notes input, quantity stepper, and a remove button — no dining-option control.
- `saveOrderChanges()` (`public/kitchen/app.js:379-412`) PATCHes `/api/orders/{id}` with `order_number`/`customer_name`, then loops `editingOrder.items` and PATCHes `/api/orders/{id}/items/{item_id}` with only `{ quantity, notes }`.
- `OrderController::updateItem` (`src/Controllers/OrderController.php:173-194`) validates the body with `OrderValidator::validateOrderItemUpdate`, which only has rules for `quantity`/`notes` (`src/Validators/OrderValidator.php:109-119`) — a `dining_option` key in the body today is silently ignored (Valitron only validates declared fields; it does not reject unknown ones), and `OrderRepository::updateOrderItem` never reads it even if it did (`src/Repositories/OrderRepository.php:452-468`: only `quantity` and `notes` are assigned).
- `OrderRepository::updateOrderItem` also never recomputes `packaging_cost`, even for a `quantity` change today — it's a pre-existing gap. This spec fixes it in the process, since editing `dining_option` requires recomputing `packaging_cost` correctly, and computing it from the item's already-current quantity when only `dining_option` changes.

**Cashier order flow**
- `addItem()` and `confirmBuilder()` default new lines to `diningOption: 'local'` (`public/cashier/app.js:118-138`, `216-240`).
- `setDiningOption(index, option)` (`public/cashier/app.js:242-247`) flips one line's option; the three-button Local/Simples/VIP group already exists per item in the summary panel (`public/cashier/index.php:280-297`).
- The "Enviar Pedido" button calls `submitOrder()` directly on click (`public/cashier/index.php:322-326`); `submitOrder()` builds the payload from `selectedItems` and POSTs immediately (`public/cashier/app.js:303-353`).
- An existing modal pattern already used for a similar purpose is the "Monte Seu Prato" builder modal: a boolean-ish state object toggled by an `x-effect` that shows/hides a Bootstrap modal instance (`public/cashier/index.php:333-350`, `builder.open` in `app.js`).

## Proposed behavior

**Kitchen**
- Each item row in the "Editar Pedido" modal gains a three-button group — Local / Simples / VIP — identical in labels/colors/behavior to the one already in Cashier (`public/cashier/index.php:282-297`), bound to `item.dining_option` on the copied `editingOrder.items` entries.
- `saveOrderChanges()` includes `dining_option: item.dining_option` in the per-item PATCH body, alongside the existing `quantity`/`notes`.
- `OrderValidator::validateOrderItemUpdate` adds an optional `dining_option` rule restricted to the same three values already used elsewhere (`DINING_OPTIONS` constant, `src/Validators/OrderValidator.php:15`).
- `OrderRepository::updateOrderItem` assigns `dining_option` when present in `$data`, and whenever either `dining_option` or `quantity` is present in `$data`, recomputes `packaging_cost` via `$this->pricingService->packagingFeeFor($item->dining_option, $item->quantity)` (using the item's resulting values after applying whichever fields were provided) before saving.

**Cashier**
- A new confirmation modal (same `x-effect` + `bootstrap.Modal.getOrCreateInstance` pattern as the builder modal) lists every entry in `selectedItems`: name, quantity, a dining-option control (Local/Simples/VIP — see below), notes if present, and the order total.
- **Updated 2026-09-26:** the dining-option control inside the modal is the same interactive three-button group already used in the summary panel (`public/cashier/index.php:282-297`), not a read-only badge. It calls the existing `setDiningOption(index, option)` directly on `selectedItems` (the modal iterates the same array, not a copy), so a change is immediately reflected everywhere that reads `selectedItems` — the modal's own item total/order total, and the summary panel underneath once the modal closes. Quantity, notes, and removal are still not editable from inside the modal (unchanged non-goal).
- The "Enviar Pedido" button no longer calls `submitOrder()` directly — it opens the confirmation modal (same disabled guard as today: `!orderNumber || selectedItems.length === 0 || submitting`).
- The modal has two actions: "Voltar" (closes the modal, no request sent, `selectedItems` untouched other than any dining-option change already made inside the modal) and "Confirmar e enviar" (calls the existing `submitOrder()` unchanged, then closes the modal on success as part of `submitOrder()`'s existing success path).

## Functional requirements

1. In Kitchen's "Editar Pedido" modal, each item row shows a Local/Simples/VIP control reflecting `item.dining_option`, independently settable per item, before saving.
2. `saveOrderChanges()` sends `dining_option` in every per-item `PATCH /api/orders/{id}/items/{itemId}` request it issues.
3. `PATCH /api/orders/{id}/items/{itemId}` with `{"dining_option": "local"}`, `{"dining_option": "viagem_simples"}`, or `{"dining_option": "viagem_vip"}` returns HTTP 200 and persists that value on the corresponding `order_items` row.
4. `PATCH /api/orders/{id}/items/{itemId}` with `dining_option` set to any value outside `local`/`viagem_simples`/`viagem_vip` returns HTTP 400 with `code: VALIDATION_FAILED`.
5. After a `PATCH` that includes `dining_option`, `quantity`, or both, the item's stored `packaging_cost` equals `PricingService::packagingFeeFor(<resulting dining_option>, <resulting quantity>)->toReais()`, where "resulting" means the new value if provided in this request, otherwise the value already stored.
6. `PATCH /api/orders/{id}/items/{itemId}` on an order whose `status` is `cancelled` returns HTTP 409 `ORDER_CANCELLED` (unchanged existing behavior — confirmed still exercised by this code path).
7. Reprinting an order (`POST /api/orders/{id}/print`) after a `dining_option` correction produces a ticket whose packaging label (`[Simples]`/`[VIP]`/none) and packaging cost match the corrected value (no code change needed in `PrintService` — verification only).
8. In Cashier, clicking "Enviar Pedido" with a non-empty `orderNumber` and at least one item in `selectedItems` opens the confirmation modal; no `POST /api/orders` request is sent at that point.
9. The confirmation modal lists every item currently in `selectedItems` with its name, quantity, current dining-option label, and notes (when set).
10. Dismissing the confirmation modal (via "Voltar" or the close control) sends no request; `selectedItems`, `customerName`, and `orderNumber` remain exactly as they were.
11. Clicking "Confirmar e enviar" triggers the same request `submitOrder()` sends today (verified unchanged: same URL, method, and payload shape), and on success clears `selectedItems`/`customerName`, fetches the next order number, and closes the modal — matching today's post-submit behavior.
12. If `orderNumber` is empty or `selectedItems` is empty, "Enviar Pedido" stays disabled and the confirmation modal cannot be opened (same guard condition that exists today, only relocated to gate opening the modal instead of gating `submitOrder()`).
13. **Added 2026-09-26:** inside the confirmation modal, clicking Local/Simples/VIP on an item row (for items whose `category_name` is `Pratos Principais` or `Adicionais`, same restriction as the summary panel) updates that item's `diningOption`, and the modal's displayed item total and order total update immediately to reflect the new packaging cost — without closing the modal.

## Non-functional requirements

Not applicable beyond what's already covered by functional requirements — no new performance, security, or observability surface is introduced (same routes, same auth boundary, one additional client-side confirmation step with no network call of its own).

## User flows

**Kitchen — correcting a dining option**
1. Staff opens "Editar Pedido" on an order.
2. Staff clicks "Simples" (or "VIP"/"Local") on the affected item row.
3. Staff clicks "Salvar".
4. The modal closes; the order list refreshes; a reprint (if needed) reflects the corrected packaging.

**Cashier — sending an order**
1. Cashier assembles the order as today (adds items, sets dining option per item, optional notes).
2. Cashier clicks "Enviar Pedido".
3. Confirmation modal opens, listing each item with its dining-option badge.
4. Cashier either:
   - a. Notices a mistake, clicks "Voltar", fixes the dining option on the affected item in the summary panel, and repeats from step 2; or
   - b. Confirms everything is correct and clicks "Confirmar e enviar" — the order is submitted exactly as it would be today.

## API changes

- `PATCH /api/orders/{id}/items/{itemId}` — request body gains an optional `dining_option` field, validated against `local`/`viagem_simples`/`viagem_vip`. Response shape (`{"success": true, "message": "Item updated"}`) is unchanged. No new endpoint.
- No change to `POST /api/orders`, its validator, or any other endpoint.

## Data model and migrations

Not applicable — `order_items.dining_option` and `order_items.packaging_cost` already exist (migrations `005_dining_option.sql`, `008_order_items_price.sql`); this spec only extends which code path is allowed to write to them after item creation.

## Architecture and affected components

- `src/Validators/OrderValidator.php` — `validateOrderItemUpdate()` gains an optional `dining_option` rule against the existing `DINING_OPTIONS` constant.
- `src/Repositories/OrderRepository.php` — `updateOrderItem()` assigns `dining_option` when present and recomputes `packaging_cost` via the already-injected `PricingService` whenever `dining_option` and/or `quantity` change.
- `public/kitchen/app.js` — `saveOrderChanges()` includes `dining_option` in its per-item PATCH payload; no new methods needed (`setDiningOption`-equivalent can reuse the same inline `@click` pattern already used in `public/cashier/index.php`, just setting `item.dining_option` directly since `editingOrder.items` entries are plain objects, not requiring a dedicated function unless preferred for consistency with Cashier's `setDiningOption`).
- `public/kitchen/index.php` — "Editar Pedido" modal's item row template gains the Local/Simples/VIP button group.
- `public/cashier/app.js` — new state (e.g. `confirmingOrder: false`), a method to open the confirmation modal (replacing the direct `@click="submitOrder"` on the send button), `submitOrder()` itself unchanged except for being invoked from the modal's confirm button and closing the modal on success.
- `public/cashier/index.php` — send button changes its `@click` target to "open modal" instead of `submitOrder`; new modal markup added alongside the existing `#buildDishModal`.
- No changes to `OrderController`, `OrderService`, `OrderValidator::validateOrderData`, `PricingService`, `PrintService`, or `ReportService` — all confirmed to already read/handle `dining_option`/`packaging_cost` correctly wherever they're consumed after this change.

## Security considerations

No new authentication/authorization surface: `/api/orders/{id}/items/{itemId}` (PATCH) remains public/unauthenticated, consistent with every other Cashier/Kitchen order-mutation route (per spec 018's documented decision that these workflows have no login). Input validation for the new `dining_option` field reuses the exact same enum (`DINING_OPTIONS`) already enforced on order creation and item addition (`OrderValidator::validateOrderData`, `validateOrderItemAdd`), so no new invalid-value surface is introduced.

## Backward compatibility

- Existing `PATCH /api/orders/{id}/items/{itemId}` callers that omit `dining_option` see no behavior change: the field stays optional, and if `quantity` is also omitted, `packaging_cost` recomputation is skipped exactly as `updateOrderItem` behaves today (no-op on `packaging_cost`).
- Any external API consumer relying on today's PATCH ignoring an unexpected `dining_option` key is unaffected in success cases; a consumer that was accidentally sending an invalid `dining_option` value in this field's name and getting away with it (since it was previously unvalidated and unused) would now get a 400 — considered acceptable since no controller in this codebase sends such a value today, and it matches the validation already applied to the same field elsewhere.

## Acceptance criteria

1. Given an existing order with an item whose `dining_option` is `local`, a `PATCH /api/orders/{id}/items/{itemId}` with `{"dining_option": "viagem_vip"}` returns 200, and a subsequent `GET /api/orders?status=pending&date=...` shows that item with `dining_option: "viagem_vip"` and `packaging_cost` equal to `2.00 × quantity`.
2. Given the same setup, a `PATCH` with `{"dining_option": "sim"}` (invalid) returns 400 with `code: VALIDATION_FAILED`, and the item's `dining_option` is unchanged.
3. Given an order with `status: cancelled`, a `PATCH` to one of its items with `dining_option` set returns 409 `ORDER_CANCELLED`, and no field is changed.
4. In the Kitchen UI, opening "Editar Pedido" on an order, changing an item's option from Local to Simples, and clicking "Salvar" results in the order's item showing the "Simples" badge in the pending-orders list without a page reload.
5. In the Cashier UI, with at least one item selected, clicking "Enviar Pedido" opens a modal listing that item and its current dining option, and no network request to `/api/orders` has fired yet (checked via the browser's network panel or `read_network_requests`).
6. In the Cashier UI, clicking "Voltar" in that modal closes it, and `selectedItems` is unchanged (still shown in the summary panel exactly as before).
7. In the Cashier UI, clicking "Confirmar e enviar" results in the same success toast and item-list reset that clicking "Enviar Pedido" produces today, and exactly one `POST /api/orders` request fires (not zero, not two).
8. **Added 2026-09-26:** In the Cashier UI, with the confirmation modal open, clicking "Simples" (or "VIP"/"Local") on an item row updates its active state immediately inside the modal, the item's line total and the order total both update to reflect the new packaging cost, and the change persists in `selectedItems` after closing the modal with "Voltar" (visible in the summary panel underneath).

## Implementation plan

1. `src/Validators/OrderValidator.php`: add the optional `dining_option` rule to `validateOrderItemUpdate()`.
2. `src/Repositories/OrderRepository.php`: extend `updateOrderItem()` to assign `dining_option` when present in `$data` and recompute/persist `packaging_cost` whenever `dining_option` and/or `quantity` are present.
3. `public/kitchen/index.php` + `public/kitchen/app.js`: add the Local/Simples/VIP control to each item row in the edit modal; include `dining_option` in `saveOrderChanges()`'s per-item PATCH payload.
4. `public/cashier/index.php` + `public/cashier/app.js`: add the confirmation modal (state, open/close wiring, markup listing items+dining option+total), change the send button to open it instead of calling `submitOrder()` directly, wire "Confirmar e enviar" to the existing `submitOrder()`.
5. Manual verification per the Testing and validation strategy below.
6. Update `CHANGELOG.md` under the in-progress milestone once this lands on `master`, and check `docs/architecture.md`/`docs/technical-decisions.md`/`specs/000-project-baseline.md`/`README.md` for anything this makes false (per `CLAUDE.md`'s "the changelog pass and the docs pass are the same pass").

## Testing and validation strategy

This project has no automated test infrastructure for this exact area beyond what's noted below — `tests/Unit` and `tests/Integration` exist (spec 004, spec 035) but neither currently covers `OrderRepository::updateOrderItem()` or the Cashier/Kitchen frontend JS. Validation here is manual/API-level:

- API-level (`curl` or equivalent, against a real running `web` container, non-destructively on a test order): exercise acceptance criteria 1-3 directly against `PATCH /api/orders/{id}/items/{itemId}`.
- Browser-level (Kitchen): open `public/kitchen/`, use "Editar Pedido" on a real pending order, change a dining option, save, confirm the badge updates in the list and that a `GET /api/orders` reflects the new `dining_option`/`packaging_cost`.
- Browser-level (Cashier): open `public/cashier/`, add items, click "Enviar Pedido", confirm the modal appears with correct content and that no request fires until "Confirmar e enviar" is clicked; use the browser's network panel to confirm exactly one `POST /api/orders` per confirmed send and zero for "Voltar".
- If `tests/Unit/OrderRepositoryTest.php` or `tests/Integration` already has fixtures/helpers for `OrderRepository`, consider adding a unit/integration test for the new `packaging_cost` recomputation during `/spec-implement` — not required by this spec to reach `Implemented`, but recommended before `Verified` given this touches money calculations (spec 021's exactness rule).

## Rollout and rollback

No feature flag, migration, or data backfill involved — this is a client-side UI change plus a backend validator/repository change on an existing, already-public endpoint. Rollback is a plain revert of the PR; no data cleanup needed since no new columns are introduced and existing `dining_option`/`packaging_cost` values are only ever overwritten with values computed by the same `PricingService` rule already used at order-creation time.

## Open questions

- None blocking. One non-blocking note: `OrderRepository::updateOrderItem()`'s current behavior of *not* recomputing `packaging_cost` on a bare `quantity`-only change (today, before this spec) is being fixed as a side effect of this work rather than filed as a separate spec, since the fix is required anyway to make `dining_option` edits correct. Flagging this explicitly per `CLAUDE.md`'s "record relevant implementation decisions" guidance, in case the team wants it split out instead during `/spec-implement`.
- Resolved during `/spec-implement`: `git diff` review confirmed the two backend files stay within the project's existing style (PSR-12 spacing, `declare(strict_types=1)` already present, promoted-property pattern untouched). `vendor/bin/phpstan analyse` (0 errors) and `vendor/bin/php-cs-fixer fix --dry-run --diff` (this spec's files: 0 flagged; 2 unrelated pre-existing files flagged, out of scope) were run after commit `4f45ce8` — see Validation evidence.

## Task checklist

- [x] `OrderValidator::validateOrderItemUpdate()` accepts optional `dining_option`
- [x] `OrderRepository::updateOrderItem()` persists `dining_option` and recomputes `packaging_cost`
- [x] Kitchen edit modal: Local/Simples/VIP control per item, wired to save
- [x] Cashier: confirmation modal added, send button opens it instead of submitting directly
- [x] Cashier: confirmation modal's dining-option control is editable (Local/Simples/VIP), not read-only (added 2026-09-26 per user request)
- [x] Manual API-level verification (acceptance criteria 1-3)
- [x] Manual browser verification, Kitchen (acceptance criterion 4)
- [x] Manual browser verification, Cashier (acceptance criteria 5-8)
- [ ] `CHANGELOG.md` / docs pass — deferred: not yet merged to `master` (`CLAUDE.md`'s changelog rule triggers on landing on `master`, not on branch work)

Kept in sync with actual implementation progress, not the original plan.

## Implementation log

- 2026-09-26 — Implemented backend (`OrderValidator::validateOrderItemUpdate`, `OrderRepository::updateOrderItem`) and frontend (`public/kitchen/index.php`/`app.js`, `public/cashier/index.php`/`app.js`) exactly per the Implementation plan. No deviations from the spec's Proposed behavior.
- 2026-09-26 — Docker was not running at the start of implementation (`docker compose ps`/`docker info` failed to reach the daemon). Asked the user how to proceed rather than starting Docker Desktop unilaterally (crosses into "start containers", which the spec-implement skill flags as needing explicit awareness); user started Docker themselves and confirmed readiness to test.
- 2026-09-26 — `mcp__claude-in-chrome` extension reported "not connected" when attempting browser-level verification (Kitchen edit modal, Cashier confirmation modal). Backend/API verification (acceptance criteria 1-3, plus the quantity-only `packaging_cost` regression check) was completed via `curl` against the real `web` container instead. Browser-level UI verification (criteria 4-7 wording) could not be completed in this session — see Validation evidence and final report for what remains.
- Confirmed while re-reading `OrderItem.php`: `quantity`/`dining_option` have no Eloquent cast, so `updateOrderItem()` explicitly casts `(int) $item->quantity` when calling `PricingService::packagingFeeFor()` — matches the pattern already used in `createOrder()`.
- 2026-09-26 — **Requirement change, mid-implementation:** after the first pass was already `Implemented` (backend done, Cashier modal read-only), the user asked for the Cashier confirmation modal to also let the cashier choose Local/Simples/VIP directly inside it, not just view it. This superseded the spec's original non-goal ("not adding an editing UI inside the new Cashier confirmation modal"). Updated `Non-goals`, `Proposed behavior`, added functional requirement 13 and acceptance criterion 8 before touching code, per `CLAUDE.md`'s "do not silently change requirements during implementation." Implementation: reused the existing `setDiningOption(index, option)` method and the modal iterates `selectedItems` directly (not a copy, unlike the Kitchen edit modal's deep-copied `editingOrder`), so no new state or method was needed beyond the markup — a change inside the modal is visible immediately in the modal's own totals and in the summary panel underneath.
- 2026-09-26 — The Chrome extension reconnected mid-session (it had reported "not connected" earlier). Completed full browser-level verification for both Kitchen and Cashier that was previously blocked — see Validation evidence below.
- 2026-09-26 — After commit `4f45ce8` and PR #23 were already open, ran `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff` (both deferred earlier since Docker only came up mid-session). Clean for every file this spec touches; two unrelated pre-existing files (`bin/worker`, `bin/create-admin`) were flagged by php-cs-fixer but are out of scope (not part of this diff) — left untouched per "stay in scope."

## Validation evidence

Environment: `docker compose ps` confirmed `restaurant_web` (port 8080), `gastroflow-db-1`, and `restaurant_print_worker` running (user started them after being asked). All commands below ran against `http://localhost:8080`.

**Syntax check**
```
$ docker compose exec -T web php -l src/Validators/OrderValidator.php
No syntax errors detected in src/Validators/OrderValidator.php
$ docker compose exec -T web php -l src/Repositories/OrderRepository.php
No syntax errors detected in src/Repositories/OrderRepository.php
```
**Static analysis / style (run 2026-09-26, after commit `4f45ce8`, containers up):**
```
$ docker compose exec -T web vendor/bin/phpstan analyse
 [OK] No errors
```
```
$ docker compose exec -T web vendor/bin/php-cs-fixer fix --dry-run --diff
Found 2 of 104 files that can be fixed:
   1) bin/worker
   2) bin/create-admin
```
Neither flagged file is part of this change (`git diff HEAD~1 --name-only` lists only the 7 files from commit `4f45ce8`, and `bin/worker`/`bin/create-admin` are not among them) — pre-existing style drift, out of scope for spec 046. None of this spec's own changed files were flagged.

**AC1** — PATCH to `viagem_vip` persists value + recomputes packaging_cost:
- Created test order (`POST /api/orders`, item id 72 "Beterraba", qty 1, `dining_option: local`) → order id 124, item_id 191, initial `packaging_cost: 0`.
- `PATCH /api/orders/124/items/191 {"dining_option":"viagem_vip"}` → `200 {"success":true,"message":"Item updated"}`.
- `GET /api/orders?status=pending&date=...` → item shows `"dining_option":"viagem_vip","packaging_cost":2` (= 2.00 × qty 1). **Matches AC1.**

**AC2** — invalid value rejected, no change:
- `PATCH /api/orders/124/items/191 {"dining_option":"sim"}` → `400 {"success":false,"error":"Validation failed","code":"VALIDATION_FAILED","messages":{"dining_option":["Dining Option contains invalid value"]}}`.
- Follow-up `GET` confirmed item still `"dining_option":"viagem_vip"` (unchanged). **Matches AC2.**

**AC3** — cancelled order rejects the PATCH:
- `POST /api/orders/124/cancel` → success.
- `PATCH /api/orders/124/items/191 {"dining_option":"local"}` → `409 {"success":false,"error":"Não é possível editar itens de um pedido cancelado.","code":"ORDER_CANCELLED"}`. **Matches AC3.**

**Functional requirement 5 (quantity-only change also recomputes packaging_cost)** — not a numbered acceptance criterion but explicitly required; verified since it's the pre-existing gap this spec also fixes:
- Created a second test order (item id 72, qty 1, `dining_option: viagem_simples`) → order id 125, item_id 192, `packaging_cost: 1`.
- `PATCH /api/orders/125/items/192 {"quantity":3}` (dining_option omitted) → `200`.
- `GET` confirmed `"quantity":3,"dining_option":"viagem_simples","packaging_cost":3` (= 1.00 × 3). **Confirms the fix.**

**AC7 (reprint reflects corrected value) — partial:**
- `POST /api/orders/125/print` → `200 {"success":true,"message":"Print job queued"}` (job enqueued without a PHP error, meaning `PrintService` built the ticket — including the item line with `dining_option`/`packaging_cost` — successfully before attempting the network connector).
- `bin/jobs-status print` showed the job failing with `Cannot initialise NetworkPrintConnector: Connection timed out` — expected in this dev environment (no physical printer attached; dozens of pre-existing jobs show the same failure mode for unrelated reasons). This confirms the code path runs cleanly through the corrected values but **does not visually confirm the printed ticket content**, since no real/simulated printer output was available to inspect.

**Cleanup:** both test orders (124, 125) were cancelled (soft-cancel, no hard delete, no schema/data changes outside normal API use) — no leftover test data beyond two cancelled orders visible in history, consistent with how cancelled orders are already handled everywhere else in this codebase.

**Browser-level UI verification (Chrome extension, real running app at `localhost:8080`):**

**AC4 (Kitchen — dining option correction reflected live):**
- Opened `http://localhost:8080/kitchen/`; order #127 (`Beterraba`, qty 1) showed badge "Simples" in the pending list.
- Clicked its "Editar pedido" button → modal "Editar Pedido #127" opened; the new Local/Simples/VIP control was present under the item's notes field, with "Simples" shown active (matches the item's stored `dining_option`).
- Clicked "VIP" (verified active via zoomed screenshot: VIP filled red, Simples reverted to outline) → clicked "Salvar" → toast "Pedido atualizado!" appeared, modal closed, and the pending-orders list updated **in place** to show badge "VIP" on order #127, no page reload. **Matches AC4.**
- Independently confirmed server-side: `GET /api/orders?status=pending` showed `"dining_option":"viagem_vip","packaging_cost":2` for that item.

**AC5, AC6, AC8 (Cashier — confirmation modal, no premature request, editable dining option, state persists on "Voltar"):**
- Opened `http://localhost:8080/cashier/`, added "Beterraba" (qty 1, default `Local`) to `selectedItems`.
- Clicked "Enviar Pedido" → modal "Confirmar pedido" opened showing "1x Beterraba" with a Local/Simples/VIP control (Local active) and total R$2,00. **Matches AC5** (modal opened; no `POST /api/orders` had fired at this point — verified via `read_network_requests` before the confirm click, see below).
- Clicked "Simples" **inside the modal** → the button's active state changed immediately, the item line and the modal's total both updated to R$3,00 live, and the "Resumo do Pedido" panel visible behind the modal updated simultaneously (same `selectedItems` array, not a copy). **Matches AC8.**
- Clicked "Voltar" → modal closed; the summary panel still showed "Simples"/R$3,00 (change persisted, as expected since it mutates `selectedItems` directly, not modal-local state). **Matches AC6.**
- Re-opened the modal (still showing "Simples," confirming persistence across open/close) and clicked "Confirmar e enviar."

**AC7 / AC11 (Cashier — confirm triggers exactly one submit, matches today's success behavior):**
- `read_network_requests` (filtered `/api/orders`) after clicking "Confirmar e enviar" showed **exactly one** `POST /api/orders` (`201`), followed by the expected `GET /api/orders/next-number` (`200`) — no duplicate submit, no premature call. **Matches AC7 and functional requirement 11.**
- Screenshot after confirm showed the summary panel reset to "Nenhum item selecionado" and the senha auto-advanced (4 → 5) — identical to today's pre-existing post-submit behavior.
- Independently confirmed server-side: `GET /api/orders?status=pending` showed the new order (#127) with `"dining_option":"viagem_simples","packaging_cost":1` — i.e. the in-modal dining-option change was correctly included in the submitted payload.

**Cleanup:** order #127 was cancelled via `POST /api/orders/127/cancel` after verification (same soft-cancel used for the earlier API-level test orders; no hard delete, no schema/data changes).

All acceptance criteria (1-8) and functional requirements referenced above now have real, recorded evidence. `phpstan`/`php-cs-fixer` were run after commit `4f45ce8` with clean results for every file this spec changed (see above).
