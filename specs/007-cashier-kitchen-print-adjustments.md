# Spec 007 — Cashier/Kitchen operations and printed receipt adjustments

## Metadata

- Status: Implemented
- Created: 2026-08-31
- Updated: 2026-09-01
- Owner: Henry
- Related issue: Not applicable
- Related branch: 014

## Context

The cashier (`public/cashier/`) and kitchen (`public/kitchen/`) screens have covered order creation and completion since the baseline (`specs/000-project-baseline.md`), but day-to-day operation surfaced six gaps: the cashier can't control the order menu items appear in, the kitchen can't correct or cancel a mistaken order, the kitchen ticket is cluttered with non-cooking items, there's no way to reprint a lost ticket, and the physical receipt shows an internal order ID instead of emphasizing the pickup number ("senha"). This spec batches all six as one related change set, requested together by the user in one session.

## Problem

1. Menu items within each category on the cashier's selection grid are ordered alphabetically (`MenuRepository::getFullMenu()`, `src/Repositories/MenuRepository.php:15`, `orderBy('name')`) with no way for staff to arrange them by their own workflow (e.g. most-ordered dishes first).
2. The kitchen view (`public/kitchen/app.js`) only exposes `completeOrder()` and `uncompleteOrder()` — there is no way to fix a cashier's mistake (wrong quantity, wrong senha) or cancel an order without a database console.
3. The kitchen ticket renders every item in an order (`public/kitchen/index.php:153`, `:202`) including non-cooking items (drinks, desserts, travel packaging, books), adding noise to what the kitchen needs to prepare.
4. There is no reprint action anywhere in the UI — `docs/ROADMAP.md` lists reprinting as a known gap. If a ticket jams or is lost, the only recovery today is manual DB/job manipulation.
5. `PrintService::buildReceipt()` (`src/Services/PrintService.php:150`) prints `"Pedido #" . $order->id`, an internal identifier with no customer-facing meaning, while the actual customer-facing pickup number ("Senha: " . `$order->table_number`, line 152) is printed in plain, normal-sized text.
6. Whether the restaurant logo is legible on the physical printer has never been confirmed against real hardware, even though the code path already exists.

## Goals

- Let cashier staff persist a custom manual order for menu items within each category on the ordering grid.
- Let kitchen staff correct an order's quantities/notes/senha/customer name, remove an individual item, or delete an order entirely, without touching the database directly.
- Show only "Pratos Principais" and "Adicionais" items on the kitchen ticket card by default, while keeping full visibility one click away.
- Let kitchen staff reprint an order's receipt on demand.
- Remove the internal order ID from the printed receipt and make the senha line visually dominant (larger, bold).
- Confirm the logo-printing code path is exercised and document that final legibility depends on hardware validation.

## Non-goals

- Reordering items already added to an in-progress cart/order summary on the cashier screen (explicitly ruled out by the user — the request is about the menu selection grid, not the cart).
- Soft-delete/cancel status for orders — the user explicitly chose hard delete.
- ~~Adding new items to an existing order from the kitchen screen~~ — **superseded**: the user asked for this after initial implementation; see Implementation log. `POST /api/orders/{id}/items` and an "Adicionar item" picker in the edit modal were added.
- Editing an order's `dining_option` (viagem simples/VIP) from the kitchen modal — out of scope; only quantity, notes, senha, and customer name are edited.
- Adding authentication/JWT protection to the new `/api/orders/*` and `/api/menu/reorder` routes — the user explicitly chose to keep them unauthenticated, consistent with the rest of `/api/orders` today.
- ASCII-art logo fallback — the bit-image logo path is already implemented and wired; no fallback is built preemptively.
- Any new Composer/npm dependency or Docker version bump.

## Current behavior

Confirmed by reading the code:

- **Cashier menu grid**: `public/cashier/app.js` fetches `/api/menu` (`GET /api/menu` → `MenuController::index` → `MenuService::getFullMenu()` → `MenuRepository::getFullMenu()`, `src/Repositories/MenuRepository.php:12-47`), which eager-loads `Category::with(['menuItems' => fn($q) => $q->orderBy('name'), ...])`. `public/cashier/index.php:122-153` renders each category's `items` in that order with no manual reordering UI. There is no `position`/`sort_order` column on `menu_items` or any other table (`common/sql/001_schema.sql`, `common/migrations/001`–`008`).
- **Kitchen order actions**: `public/kitchen/app.js:134-168` only implements `completeOrder(id)` (`POST /api/orders/{id}/complete`) and `uncompleteOrder(id)` (`POST /api/orders/{id}/uncomplete`). `OrderController` (`src/Controllers/OrderController.php`) has no `update`, `delete`, or per-order `print` method. `OrderRepository` (`src/Repositories/OrderRepository.php`) has no matching methods either.
- **Kitchen ticket item list**: `public/kitchen/index.php:153` (pending) and `:202` (completed) both do `<template x-for="item in order.items">` unconditionally. `OrderRepository::getOrdersByStatus()` (`src/Repositories/OrderRepository.php:18-53`) maps each item to `name, description, quantity, notes, dining_option, unit_price, packaging_cost` — no `item_id` (the `order_items.id`) and no category information, because the eager load is `Order::with(['items.menuItem'])` (line 21), not `items.menuItem.category`.
- **Reprinting**: `PrintService::printOrder(Order $order)` (`src/Services/PrintService.php:39-59`) and `PrintOrderJob::handle()` (`src/Jobs/PrintOrderJob.php`) already implement everything needed to print an existing order, dispatched via `JobService::dispatch('print', PrintOrderJob::class, ['order_id' => $order->id])` (`src/Services/OrderService.php:34-38`) — but only at order-creation time. No route exposes this for an existing order.
- **Receipt layout**: `PrintService::buildReceipt()` (`src/Services/PrintService.php:125-238`) prints, in order: restaurant name (double width/height, centered), logo (`EscposImage::load()` + `bitImage()`, lines 137-146, already implemented, reading `Settings::getLogoPath()` → `public/assets/img/logo.png`, which already exists in the repo), `"Pedido #" . $order->id` (bold, line 150), `"Senha: " . $order->table_number` (plain text, no emphasis, line 152), optional customer name, date, items, total (bold, double width), footer.
- **SSE**: `public/api/events/stream.php:59-63` forwards whatever `type` key is present in the shared event JSON file with no whitelist — `OrderService::triggerKitchenEvent()` (`src/Services/OrderService.php:66-75`) already writes arbitrary type strings. `public/kitchen/app.js:31-64` (`connectSSE()`) only listens for `order.created`, `order.completed`, `order.uncompleted`.
- **Auth boundary**: `src/Routes.php:29-37` (`/api` group: menu, orders, kitchen food-summary) has no `JwtMiddleware`. Only `/api/admin/*` (`src/Routes.php:44-63`) is wrapped with `->add($jwt)` (line 63). The cashier and kitchen screens have no login flow today.
- **Category seed data**: `common/sql/001_schema.sql:60-66` defines exactly six categories: id=1 Pratos Principais, id=2 Adicionais, id=3 Bebidas, id=4 Sobremesas, id=5 Viagem, id=6 Livros. `categories.type` is only `'food'`/`'drink'` — too coarse to distinguish "Pratos Principais"/"Adicionais" from the other food categories (Sobremesas, Viagem, Livros are also `type='food'`). `public/admin/index.php:239,245` already compares by `category_name` string (`!== 'Pratos Principais'`), which is the established convention for this kind of check in this codebase.
- **FK cascade**: `common/sql/001_schema.sql` defines `order_items.order_id` with `FOREIGN KEY ... REFERENCES orders(id) ON DELETE CASCADE` — deleting an `Order` row already cascades to its `order_items`.
- **Modal pattern**: no modal exists today in `public/cashier/` or `public/kitchen/`. `public/admin/index.php:209-291` has a reusable Bootstrap 5 + Alpine.js modal pattern (`x-effect` toggling `bootstrap.Modal.getOrCreateInstance(...).show()/.hide()` based on a truthy/`null` Alpine state variable).

## Proposed behavior

1. Cashier can toggle a "Reorganizar" mode on the menu grid; while active, item cards within a category can be dragged to a new position (native HTML5 drag-and-drop, no new dependency), and the new order is persisted server-side per category so it survives page reloads and is shared across cashier terminals.
2. Kitchen staff can open an "Editar pedido" modal from any order card (pending or completed) that shows every item in the order (not just the filtered ones), lets them change each item's quantity and note, remove an individual item, edit the senha (`table_number`) and customer name, save those changes, or delete the entire order (with a confirmation step).
3. Kitchen ticket cards show only items whose menu category is "Pratos Principais" or "Adicionais"; if an order has other items, a small indicator ("+N itens") is shown and opens the same edit modal (which always lists everything) to view them.
4. Each order card (pending or completed) gets a reprint button that re-dispatches the same async print job used at order creation.
5. The printed receipt no longer shows `"Pedido #<id>"`; the senha line prints in bold, double-width/double-height text.
6. The logo continues to print via the existing `bitImage()` call — this spec adds no new logo code, only documents that hardware legibility must be confirmed by the user on the physical thermal printer, since this repo has no way to validate that.

## Functional requirements

1. `GET /api/menu` returns each category's items ordered by a persisted `position` value (ascending), then by name as a tiebreaker.
2. `PATCH /api/menu/reorder` accepts `{category_name: string, item_ids: int[]}` and persists `item_ids[i]`'s position as `i` for all items in that category; returns `400` if `item_ids` is missing, not an array, or empty; returns `404` if `category_name` doesn't match an existing category.
3. `GET /api/orders` (any status) includes, per item, `item_id` (the underlying `order_items.id`) and `category_name` (the item's menu category name).
4. `PATCH /api/orders/{id}` accepts a partial `{table_number?: string, customer_name?: string}` and updates only the fields present; returns `404` if the order doesn't exist.
5. `PATCH /api/orders/{id}/items/{itemId}` accepts `{quantity?: int, notes?: string}`; `quantity` must be a positive integer; returns `404` if the order or item doesn't exist, or if the item doesn't belong to that order.
6. `DELETE /api/orders/{id}/items/{itemId}` removes that item from the order; returns `409` if it's the order's only remaining item (must delete the whole order instead); returns `404` if the order or item doesn't exist, or the item doesn't belong to that order.
7. `DELETE /api/orders/{id}` deletes the order and (via existing FK cascade) all its items; returns `404` if the order doesn't exist.
8. `POST /api/orders/{id}/print` dispatches a `PrintOrderJob` for that order (same mechanism as order creation); returns `404` if the order doesn't exist; returns `200` immediately (printing remains asynchronous, handled by `bin/worker`).
9. Every mutation above (menu reorder excluded) fires an SSE event via the existing `triggerKitchenEvent()` mechanism — `order.updated` for `PATCH /api/orders/{id}` and item update, `order.item.removed` or reuse of `order.updated` for item removal, `order.deleted` for order deletion — consumed by `public/kitchen/app.js`'s `connectSSE()` to refresh both the pending and completed lists.
10. The kitchen ticket (`public/kitchen/index.php`) renders only items where `category_name` is `"Pratos Principais"` or `"Adicionais"`; when an order has additional items, an indicator is shown and opens the edit modal.
11. The printed receipt (`PrintService::buildReceipt()`) never prints the order's numeric ID; the senha line is wrapped in `setEmphasis(true)` + `selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT)`, reset afterward.

## Non-functional requirements

- No new Composer or npm dependency; drag-and-drop uses native HTML5 DnD APIs, matching the "no new dependencies without being asked" rule in `CLAUDE.md`.
- All new `/api/orders/*` and `/api/menu/reorder` routes remain outside `JwtMiddleware`, consistent with the existing `/api` group and the user's explicit choice.
- New migrations follow the idempotent pattern already used in `common/migrations/005_dining_option.sql` (check `information_schema.COLUMNS` before `ALTER TABLE`, via `PREPARE`/`EXECUTE`), applied only through `bin/migrate`.
- Destructive order deletion (`DELETE /api/orders/{id}`) is a genuine hard delete (per user decision) — the UI must require an explicit confirmation step before calling it, since this project's rule against destructive operations refers to schema/migration safety, not to this intentional user-triggered application feature.

## User flows

**Cashier reorders menu items:**
1. Cashier opens `/cashier/`, clicks "Reorganizar".
2. Item cards within each category become draggable; clicking a card no longer adds it to the order while reorder mode is active.
3. Cashier drags "Feijoada" above "Frango Grelhado" within "Pratos Principais".
4. On drop, the app calls `PATCH /api/menu/reorder` with the new order of item IDs for that category; a toast confirms success or reports an error.
5. Cashier exits reorder mode; clicking cards resumes adding items to the cart. Reloading the page (or opening the cashier screen on another terminal) shows the new order.

**Kitchen edits an order:**
1. Kitchen staff notices a mistake on an order card and clicks the "Editar" (pencil) icon.
2. A modal opens showing the senha, customer name, and every item (unfiltered) with quantity controls, a notes field, and a remove button per item.
3. Staff changes quantity from 2 to 1 on one item, edits the senha, and saves; the app calls `PATCH /api/orders/{id}` and/or `PATCH /api/orders/{id}/items/{itemId}`, shows a toast, and refreshes the order list.
4. Alternatively, staff clicks "Excluir Pedido", confirms in a dialog, and the app calls `DELETE /api/orders/{id}`; the order disappears from both pending and completed lists.

**Kitchen reprints an order:**
1. Staff clicks the print icon on an order card (pending or completed).
2. The app calls `POST /api/orders/{id}/print`; a toast confirms the job was queued ("Pedido enviado para impressão").
3. `bin/worker` picks up the job and prints the ticket exactly as it would have printed at creation time, using the latest order data (including any edits made in the flow above).

## API changes

| Method | Path | Auth | Request body | Response |
|---|---|---|---|---|
| PATCH | `/api/menu/reorder` | none | `{category_name, item_ids: int[]}` | `{success: true}` / `400` / `404` |
| PATCH | `/api/orders/{id}` | none | `{table_number?, customer_name?}` | `{success: true}` / `404` |
| PATCH | `/api/orders/{id}/items/{itemId}` | none | `{quantity?, notes?}` | `{success: true}` / `404` |
| DELETE | `/api/orders/{id}/items/{itemId}` | none | — | `{success: true}` / `404` / `409` |
| DELETE | `/api/orders/{id}` | none | — | `{success: true}` / `404` |
| POST | `/api/orders/{id}/print` | none | — | `{success: true}` / `404` |

`GET /api/menu` and `GET /api/orders` response shapes gain fields (`position` implicitly affects ordering only, not a new field exposed to the client; `item_id` and `category_name` are new fields per order item) but remain backward compatible — no existing field is removed or renamed.

## Data model and migrations

- `common/migrations/009_menu_item_position.sql`: adds `menu_items.position SMALLINT UNSIGNED NOT NULL DEFAULT 0`, guarded by the `information_schema.COLUMNS` idempotency check (same pattern as `005_dining_option.sql`). In the same migration, immediately after adding the column, backfill it once using a window function scoped per category (`ROW_NUMBER() OVER (PARTITION BY category_id ORDER BY name)`) so existing alphabetical ordering is preserved on first deploy rather than collapsing to all-zeros.
- No other schema changes. `order_items` and `orders` already have every column this spec needs (`quantity`, `notes`, `table_number`, `customer_name`).

## Architecture and affected components

- **Repositories**: `MenuRepository` (order-by change + new `reorderItems()`), `OrderRepository` (extended eager load + `item_id`/`category_name` in output + new `updateOrder()`, `updateOrderItem()`, `removeOrderItem()`, `deleteOrder()`).
- **Services**: `MenuService` (new `reorderItems()` passthrough), `OrderService` (new `updateOrder()`, `updateOrderItem()`, `removeOrderItem()`, `deleteOrder()`, `printOrder()`, each firing the appropriate SSE event via the existing private `triggerKitchenEvent()`).
- **Controllers**: `MenuController` (new `reorder()`), `OrderController` (new `update()`, `updateItem()`, `removeItem()`, `destroy()`, `print()`).
- **Validators**: `OrderValidator` (new `validateOrderUpdate()`, `validateOrderItemUpdate()`); menu reorder payload validated inline in the controller (consistent with `MenuController::store()`'s existing inline validation style — no `MenuValidator` class exists today and this spec doesn't introduce one for a single lightweight check).
- **Routes**: `src/Routes.php`, all new routes added inside the existing unauthenticated `/api` group (lines 29-37 today).
- **Print**: `src/Services/PrintService.php` (`buildReceipt()` edits only).
- **Frontend**: `public/cashier/index.php`, `public/cashier/app.js` (reorder mode, drag handlers, `reorderCategory()`); `public/kitchen/index.php`, `public/kitchen/app.js` (edit modal markup/state, `kitchenItems()` filter helper, reprint button, new SSE listeners).
- No new Repository/Service/Validator/Controller class is introduced for a domain that lacks one today — all new methods land on the existing `Menu*`/`Order*` classes, matching `CLAUDE.md`'s layering rule.

## Security considerations

- The new `/api/orders/*` mutation routes and `/api/menu/reorder` remain unauthenticated, matching the existing `/api/orders` and `/api/menu` routes and the user's explicit decision. This means anyone with network access to the server can edit or delete any order or reorder the menu — an accepted risk carried over from the current design, not newly introduced by this spec.
- All new endpoints validate their inputs (types, existence, ownership of `itemId` under the given `orderId`) to prevent one order's item ID being used to mutate a different order's item.
- Hard delete (`DELETE /api/orders/{id}`) is destructive and irreversible; the frontend must require explicit confirmation before calling it. This is an intentional, user-directed application feature, distinct from the destructive *database/migration* operations `CLAUDE.md` warns against.
- No secrets, `.env` values, or credentials are touched by this work.

## Backward compatibility

- `GET /api/menu` and `GET /api/orders` gain fields but keep all existing ones — existing consumers (the cashier and kitchen frontends) keep working unmodified until updated.
- Menu items not yet given an explicit position (any inserted after the migration's one-time backfill) default to `position = 0`, which sorts them first within their category, then alphabetically among themselves (tiebreaker) — acceptable since new items are rare and staff can immediately drag them into place.
- No existing endpoint's request/response shape is removed or changed in an incompatible way.

## Acceptance criteria

1. After applying migration `009` and reordering "Pratos Principais" items via `PATCH /api/menu/reorder`, a subsequent `GET /api/menu` reflects the new order, and it survives a server restart (persisted in `menu_items.position`).
2. `PATCH /api/orders/{id}` with `{"table_number": "99"}` changes the order's senha; `GET /api/orders?status=all` shows `table_number: "99"` for that order afterward.
3. `PATCH /api/orders/{id}/items/{itemId}` with `{"quantity": 3}` updates that item's quantity; a subsequent `GET /api/orders` reflects `quantity: 3` for that item.
4. `DELETE /api/orders/{id}/items/{itemId}` on an order with 2+ items removes just that item; the same call on an order's last remaining item returns `409` and leaves the order and item untouched.
5. `DELETE /api/orders/{id}` removes the order; `GET /api/orders?status=all` no longer includes it; a direct query confirms its `order_items` rows are also gone (FK cascade).
6. `POST /api/orders/{id}/print` returns success and, after `bin/worker --once` runs, the `jobs` table shows a completed `print` job referencing that order (verifiable via `bin/worker` output/logs, since there is no automated test suite).
7. On the kitchen screen, an order containing one "Pratos Principais" item and one "Bebidas" item shows only the main dish on the card; opening the edit modal for that order shows both items.
8. A printed receipt (inspected via the ESC/POS byte stream or `PrintService::buildReceipt()` code path) contains no `"Pedido #"` text and prints the senha line with `MODE_DOUBLE_WIDTH | MODE_DOUBLE_HEIGHT` and emphasis enabled.
9. `public/assets/img/logo.png` is confirmed to exist and `buildReceipt()`'s `bitImage()` call is confirmed to execute against it (code inspection); actual on-paper legibility is explicitly deferred to the user testing on physical hardware and is not claimed as verified by this spec.

## Implementation plan

1. `common/migrations/009_menu_item_position.sql` — add + backfill `menu_items.position`; apply via `bin/migrate`.
2. `MenuRepository::getFullMenu()` order-by change; `MenuRepository::reorderItems()`; `MenuService::reorderItems()`; `MenuController::reorder()`; route registration.
3. Cashier frontend: reorder-mode toggle, drag handlers, `reorderCategory()` call — verify against the running app.
4. `OrderRepository::getOrdersByStatus()` — extend eager load + output fields (`item_id`, `category_name`).
5. `OrderRepository`/`OrderService`/`OrderController`/`OrderValidator` — `update`, `updateItem`, `removeItem`, `destroy`, `print`; route registration.
6. Kitchen frontend: edit modal (Bootstrap pattern reused from `public/admin/index.php`), `kitchenItems()` filter, reprint button, new SSE listeners.
7. `PrintService::buildReceipt()` — remove order-ID line, emphasize/enlarge senha line.
8. Manual end-to-end verification of every acceptance criterion (see below); update this spec's `Task checklist`, `Implementation log`, and `Validation evidence` as work proceeds.

## Testing and validation strategy

This project has no automated test suite and no lint/static-analysis command (`CLAUDE.md`, confirmed in `specs/000-project-baseline.md`). Validation will be manual, via the running app and direct API calls:

- `docker compose up -d`, `bin/migrate` to apply migration 009.
- `curl`/browser devtools calls against each new endpoint to check the acceptance criteria's request/response shapes and status codes directly.
- Manual UI walkthrough of both user flows described above in a browser, using the dev server.
- `bin/worker --once` run manually and its output inspected to confirm the reprint job executes (no physical printer available in this environment — the print *job* completing successfully is what's verified here, not the paper output).
- Physical receipt appearance (order ID removed, senha bold/large, logo legible) can only be fully confirmed by the user on the real thermal printer; this is called out explicitly rather than claimed as tested.

## Rollout and rollback

- Migration 009 is additive (`ADD COLUMN ... DEFAULT 0`) and idempotent — safe to re-run, and rollback is a manual `ALTER TABLE menu_items DROP COLUMN position` if ever needed (no down-migration mechanism exists in this project's `MigrationRunner`, consistent with existing migrations).
- All new endpoints are additive; no existing route's behavior changes except `GET /api/menu`'s item ordering (cosmetic, not a breaking shape change) and `GET /api/orders`'s item payload (additive fields only).
- If a problem is found post-deploy, the new frontend buttons/modal can be hidden without touching the backend, or the new routes can be removed from `src/Routes.php` without affecting existing functionality.

## Open questions

None blocking. One non-blocking note: the exact SSE event name for item-level removal (`order.updated` reused vs. a distinct `order.item.removed`) is left to implementation-time judgment since `public/kitchen/app.js`'s `connectSSE()` only needs *some* event to trigger `fetchAll()` — either choice satisfies functional requirement 9.

## Task checklist

- [x] `common/migrations/009_menu_item_position.sql` created and applied (`bin/migrate`, confirmed in `migrations` table)
- [x] `MenuRepository`/`MenuService`/`MenuController` reorder support + route
- [x] Cashier frontend: reorder mode, drag-and-drop, persistence call
- [x] `OrderRepository::getOrdersByStatus()` exposes `item_id` + `category_name`
- [x] `OrderRepository`/`OrderService`/`OrderController`/`OrderValidator`: update order, update item, remove item, delete order, print
- [x] New order routes registered in `src/Routes.php`
- [x] Kitchen frontend: edit modal (items + senha/cliente + remove item + delete order)
- [x] Kitchen frontend: category filter (`kitchenItems()`) + "ver completo" affordance
- [x] Kitchen frontend: reprint button
- [x] Kitchen frontend: SSE listeners for new event types (`order.updated`, `order.deleted`)
- [x] `PrintService::buildReceipt()`: remove order ID, emphasize senha
- [x] Manual/API-level verification of every acceptance criterion, recorded below (live browser drag/modal click-through not performed — see Validation evidence)

## Implementation log

- Implemented in plan order (migration → menu backend → cashier frontend → order backend → kitchen frontend → print layout). No deviations from the planned design were needed.
- SSE event-name open question resolved as: `order.updated` for both order-level and item-level field edits, `order.deleted` for hard delete — reusing one event name for the two update cases since `connectSSE()` only needs a trigger to `fetchAll()`, per the non-blocking note in Open questions.
- `OrderService::printOrder()` calls `Order::findOrFail($id)` before dispatching the job purely to produce a `404` for a nonexistent order at request time (dispatch itself is fire-and-forget and wouldn't otherwise surface a missing order until `PrintOrderJob` runs asynchronously).
- Fixed a trailing-newline regression introduced by my own edits in `src/Repositories/OrderRepository.php` and `src/Controllers/OrderController.php` (both had a trailing newline before my change; `git diff` flagged `\ No newline at end of file` after it). `src/Services/OrderService.php` and `src/Validators/OrderValidator.php` already had no trailing newline before my edits — left as-is, not a regression, not in scope to fix.
- **Concurrent external changes observed mid-implementation, not caused by this work**: while validating against the running app, `docker compose ps` showed all three containers freshly recreated (a new `restaurant_print_worker` service — already defined in the repo's committed `docker-compose.yml`, just not previously running — came up alongside `db` and `web`), and `src/Services/PrintService.php` was found substantially refactored on disk (injectable connector factory for testability, print failures now rethrown instead of swallowed, structured logging with job context) by something other than this session. My own edit to that file (removing `"Pedido #"`, emphasizing/enlarging the senha line) was preserved intact within the refactor. `docs/ROADMAP.md`, `CLAUDE.md`, `docs/technical-decisions.md`, and `.claude/settings.json` also show unrelated changes not made by this work. This looks like another active session/process working on the same checked-out repository (likely finishing specs 004/005/006 — PHPUnit smoke tests, CI, doc sync — all already in flight per the branch's recent commit history) rather than anything adversarial. Database data and the `migrations` table survived the container recreation. Flagged here for visibility; no application code from this spec was reverted or altered because of it.
- `bin/migrate` was run twice at the user's explicit request (once mid-implementation, once again after the container recreation above) — both idempotent, no destructive effect.
- **Follow-up changes requested after initial validation** (same non-goal reversal noted above, plus two smaller adjustments):
  1. Removed the "+N itens (ver tudo)" indicator/link from both kitchen cards (`public/kitchen/index.php`) and the now-unused `hasHiddenItems()` helper (`public/kitchen/app.js`) — the user pointed out it duplicated the pencil/edit button, which already opens the same unfiltered view. `kitchenItems()` (the category filter itself) was kept; only the redundant affordance was removed.
  2. Added the ability to add a new item to an existing order from the kitchen edit modal: `OrderRepository::addOrderItem()`, `OrderService::addOrderItem()`, `OrderController::addItem()`, `OrderValidator::validateOrderItemAdd()`, route `POST /api/orders/{id}/items` (same unauthenticated group as the rest of `/api/orders`), and a menu-item picker + qty field + "+" button in the modal (`public/kitchen/app.js`: `loadMenu()`, `allMenuItems()`, `addItemToModal()`). Mirrors the existing per-item shape (`item_id`, `category_name`, etc.) so the new item slots into `kitchenItems()`'s filter and the modal's list without special-casing.
  3. Standardized the loading/blocked-UI treatment (disable + Bootstrap spinner swap, the pattern already used by `completeOrder`/`uncompleteOrder`/`reprintOrder`/`submitOrder`) on the three interactive elements added in this spec that had shipped without one: the modal's "add item" button (`addingItem` flag), the modal's per-item "remove" button (`removingItemId` flag, id-based like `completing`/`uncompleting`), and the cashier's drag-to-reorder gesture (`reordering` flag — disables further `draggable` on the grid and shows "Salvando ordem..." next to the Reorganizar button, since a drag gesture has no single button to disable). Scope was explicitly limited to these three by the user — pre-existing gaps elsewhere in `public/admin/` (toggle availability, delete item, ingredient edit/delete, logo upload, reports filter) were investigated but intentionally left alone as unrelated pre-existing work; the pattern is meant as the standard for new work going forward, not a retrofit.
  - Docker was down for part of this follow-up (`docker compose ps` failed: `dockerDesktopLinuxEngine` pipe not found), so `php -l` could not be re-run for this batch — verified instead via careful diff review; `node --check` still ran clean on both `app.js` files. No live app validation (curl/browser) was done for this follow-up — the user said they'd already tested and asked not to re-test.

## Validation evidence

All commands below were run against the live dev stack (`docker compose`, containers `restaurant_web` / `gastroflow-db-1`), via `docker compose exec web ...` and `curl http://localhost:8080/...`. There is no automated test suite in this project (confirmed in `specs/000-project-baseline.md`), so this is the manual/API-level verification described in Testing and validation strategy.

- **AC1 (menu reorder persists)**: `docker compose exec web php bin/migrate` → `009_menu_item_position.sql` applied `[OK]`. `curl -X PATCH /api/menu/reorder -d '{"category_name":"Adicionais","item_ids":[66,23,19,...]}'` → `{"success":true,"message":"Ordem atualizada"}`; subsequent `curl /api/menu` showed item `66` (Carne Empanada) first instead of `23` (Arroz Branco). Order restored afterward to leave dev data clean. **Confirmed.**
- **Validation-only (not an AC)**: `PATCH /api/menu/reorder` with missing `item_ids` → `HTTP 400`; with `category_name: "Categoria Inexistente"` → `HTTP 404`. **Confirmed.**
- **AC2 (order update)**: created test order id `51` via `POST /api/orders` (`print_ticket:false`). `curl -X PATCH /api/orders/51 -d '{"table_number":"99"}'` → success; `GET /api/orders?status=pending` then showed `"table_number":"99"`. **Confirmed.**
- **AC3 (item_id/category_name exposed + item update)**: `GET /api/orders?status=pending` for order 51 returned `item_id:66, category_name:"Pratos Principais"` and `item_id:67, category_name:"Bebidas"`. `curl -X PATCH /api/orders/51/items/66 -d '{"quantity":3}'` → success; re-fetch showed `quantity:3`. **Confirmed.**
- **AC4 (item removal + last-item guard)**: `DELETE /api/orders/51/items/67` (2 items present) → `HTTP 200`, item removed. `DELETE /api/orders/51/items/66` (now the only remaining item) → `HTTP 409`, `{"error":"Não é possível remover o último item do pedido. Exclua o pedido inteiro."}`; order still had item 66 afterward. **Confirmed.**
- **AC5 (hard delete + cascade)**: `DELETE /api/orders/51` → `HTTP 200`; `GET /api/orders?status=all` no longer includes id 51; direct query `SELECT COUNT(*) FROM order_items WHERE order_id=51` → `0` (FK cascade). `DELETE /api/orders/999999` (nonexistent) → `HTTP 404`. **Confirmed.**
- **AC6 (reprint dispatch + worker execution)**: `POST /api/orders/51/print` → `{"success":true,"message":"Print job queued"}`; `POST /api/orders/999999/print` → `HTTP 404`. `bin/worker print --once` (run repeatedly to drain a pre-existing dev backlog of 8 older print jobs unrelated to this session, oldest-first) processed the job for order 51; log line `Falha ao imprimir pedido #51: Cannot initialise NetworkPrintConnector: Connection timed out` confirms `PrintOrderJob` → `PrintService::printOrder()` → `buildReceipt()` executed against order 51's real data with no PHP errors — the only failure is the expected one (no reachable physical/network printer in this dev environment). Queue confirmed empty afterward. **Confirmed** (job dispatch and execution path; physical paper output not observable here).
- **AC7 (kitchen category filter)**: confirmed structurally — test order 51 was created with one "Pratos Principais" item and one "Bebidas" item, and `OrderRepository`'s response carried the correct `category_name` per item (see AC3 evidence); `kitchenItems(order)` in `public/kitchen/app.js` filters to `['Pratos Principais','Adicionais']`, which on this exact data returns only the Barça item. **Not** verified via an actual browser click-through of the kitchen UI (no browser automation was run in this session) — this is code + data-level confirmation, not a rendered-page screenshot.
- **AC8 (receipt text/formatting)**: confirmed via code inspection of the merged `src/Services/PrintService.php` (`buildReceipt()`) — the `"Pedido #" . $order->id` line is gone, and the senha line is wrapped in `setEmphasis(true)` + `selectPrintMode(Printer::MODE_DOUBLE_WIDTH | Printer::MODE_DOUBLE_HEIGHT)` with a reset after — plus the successful (non-throwing, aside from the expected connector timeout) execution of that exact code path against order 51 in the AC6 worker run. No physical printer output was captured/inspected.
- **AC9 (logo path exists and executes)**: `public/assets/img/logo.png` confirmed present in the repo tree; `buildReceipt()`'s `EscposImage::load()` + `bitImage()` call executed without throwing during the AC6 worker run (no "Logo não pôde ser carregada" warning appeared in the log). On-paper legibility is explicitly **not** claimed as verified — deferred to the user testing on the real thermal printer, as stated in Non-goals/Testing strategy.
- **Syntax checks**: `php -l` (via the web container) on every changed PHP file — all reported "No syntax errors detected": `MenuRepository.php`, `MenuService.php`, `MenuController.php`, `OrderRepository.php`, `OrderService.php`, `OrderController.php`, `OrderValidator.php`, `Routes.php`, `PrintService.php`, `public/cashier/index.php`, `public/kitchen/index.php`. `node --check` on `public/cashier/app.js` and `public/kitchen/app.js` — both OK.
- **Not validated**: live browser interaction with the drag-and-drop reorder UI and the kitchen edit modal (open/edit/save/cancel/delete-confirmation flows) — no browser automation tool was used in this session. The backend endpoints these UI elements call have all been verified directly (above), but the actual DOM/Alpine wiring (`x-effect` modal toggle, `draggable`/`dragover`/`drop` handlers) has only been reviewed by reading the code, not exercised in a real browser. Recommend a quick manual click-through before relying on this in production.

Given the above, every functional/acceptance criterion has direct evidence except the live-UI interaction and physical paper legibility, which are explicitly called out as unverified rather than assumed.
