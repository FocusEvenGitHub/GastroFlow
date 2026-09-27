# Spec 047 — Kitchen "Editar Pedido" modal UI redesign

<!-- Reduced spec (CLAUDE.md: "small and obvious fixes may use a reduced spec, but must still
document the problem, expected result, and validation") — visual/markup-only change, no new
functional requirement, no API/backend change. -->

## Metadata

- Status: Verified
- Created: 2026-09-27
- Updated: 2026-09-27
- Owner: Henry
- Related issue: Not applicable (client-requested UI polish, not tied to a `docs/ROADMAP.md` milestone item)
- Related branch: 047

## Context

The user asked to redo the UI of the Kitchen "Editar Pedido" modal (`public/kitchen/index.php:385-486`). No new capability was requested — spec 046 already added every control this modal needs (dining-option correction, notes, quantity, add/remove item). This is a visual/layout pass only.

## Problem

- Every item row was a single dense flex line: name, category badge, a text input, a `btn-group` with three dining-option buttons forced to `font-size:0.7rem; padding:0.1rem 0.4rem` (inline style), a quantity stepper, and a remove button — all sharing one row. On the touch screens Kitchen actually runs on, the dining-option buttons were noticeably smaller than every other touch target in the same screen (the pending-orders list next to it uses 1.6rem item rows and `.btn-sm-icon` with 1.4rem icons).
- There was no visual separation between the order-level fields (Senha/Cliente) and the item list, and no separation between items themselves beyond a thin `border-bottom`.
- The modal had no scroll handling: a long order (many items) could push the footer (Salvar/Cancelar) off-screen with no way to reach it except resizing the browser.

## Goals

- Give each item its own visually distinct card (name/category header, notes field, then a row splitting the dining-option control and the quantity stepper), instead of one cramped line.
- Make the dining-option buttons and quantity stepper comfortably tappable, consistent with the touch-target sizing used elsewhere on the same screen.
- Keep the modal usable when an order has many items: fixed header/footer, scrollable body.
- No behavior change: same Alpine bindings/methods (`editingOrder`, `openEditModal`, `closeEditModal`, `saveOrderChanges`, `addItemToModal`, `removeItemFromModal`, `allMenuItems`, `newItemId`, `newItemQty`), same API calls, same validation.

## Non-goals

- No change to `public/kitchen/app.js` — every method and state field it exposes is reused as-is.
- No change to any backend file, endpoint, or the Cashier UI.
- No change to what fields are editable (still: `order_number`, `customer_name`, per-item `notes`/`quantity`/`dining_option`, add/remove item, cancel order) — same scope as spec 046 left it.

## Current behavior

Described under Problem above; confirmed by reading `public/kitchen/index.php:385-486` (markup) and `public/kitchen/app.js:321-430` (the modal's backing methods, unchanged by this spec).

## Proposed behavior

- `.edit-order-fields`: Senha/Cliente inputs grouped in a bordered/tinted panel above the item list.
- `.edit-items-heading`: a small section label ("Itens do pedido") with an item-count badge, matching the section-header convention already used in the main Kitchen board (`.section-header`).
- `.edit-item-card`: one bordered, padded card per item — item name + category badge on top (with the remove button at the top right instead of buried at the end of a long row), the notes input below, then a row with the Local/Simples/VIP toggle (`.dining-toggle`) on the left and the quantity stepper (`.qty-stepper`) on the right, both sized larger than the previous inline `font-size:0.7rem`.
- `.add-item-card`: the existing "add item" row (select + quantity + button), now visually boxed (dashed border) to read as its own action distinct from the item cards above it.
- Modal body scrolls internally (`max-height: 85vh` on the template's root, `overflow-y: auto` on `.modal-body`) so the header and footer stay visible regardless of item count.
- All new classes reuse the app's existing CSS custom properties (`--bg`, `--border`, `--text`, `--text-muted`, `--danger`) and already-defined dark-mode overrides — no new colors introduced, light/dark parity preserved the same way the rest of the page achieves it (`[data-theme="dark"]` overrides alongside the light rules).

## Functional requirements

Not applicable as numbered testable statements beyond "no behavior change" — this is a visual/markup change. The controls' behavior (what happens when each button/input is used) is unchanged from spec 046 and is not re-specified here.

## Non-functional requirements

- Visual/usability only: larger touch targets for the dining-option and quantity controls, scrollable modal body for long orders, no dependency added (still Bootstrap 5 + Alpine.js, no build step).

## User flows

Same flow as spec 046's "Kitchen — correcting a dining option" — only the modal's visual layout changed, not the steps.

## API changes

Not applicable — no backend file touched.

## Data model and migrations

Not applicable.

## Architecture and affected components

- `public/kitchen/index.php` only: the `<style>` block (new rules for `.edit-order-fields`, `.edit-items-heading`, `.edit-item-card`, `.dining-toggle`, `.qty-stepper`, `.add-item-card`, and their `[data-theme="dark"]` overrides) and the `#editOrderModal` markup (restructured, same `x-model`/`@click`/`x-for` bindings).
- `public/kitchen/app.js`: unchanged.

## Security considerations

Not applicable — no new input surface, no new endpoint, no authentication/authorization change.

## Backward compatibility

Not applicable — no stored data, API contract, or external consumer is affected; this is a same-page markup/CSS change behind the same Alpine component.

## Acceptance criteria

1. Opening "Editar Pedido" on an order still populates Senha, Cliente, and every item's name/category/notes/dining-option/quantity exactly as returned by the API (no data-binding regression).
2. Changing an item's dining option, notes, or quantity in the redesigned modal and clicking "Salvar" still issues the same PATCH requests as before (`PATCH /api/orders/{id}` then one `PATCH /api/orders/{id}/items/{itemId}` per item, same body shape) and the pending-orders list reflects the change without a page reload.
3. Adding an item via the "Adicionar item" control and removing an item via the trash icon still work exactly as before (same endpoints, same success/error toasts).
4. `docker compose exec -T web php -l public/kitchen/index.php` reports no syntax errors.
5. `docker compose exec -T web vendor/bin/php-cs-fixer fix --dry-run --diff public/kitchen/index.php` reports the file needs no changes.
6. The modal remains fully usable (all buttons reachable, footer visible) for an order with enough items to exceed the viewport height, via the modal body's internal scroll.

## Implementation plan

1. Add the new CSS rules to `public/kitchen/index.php`'s `<style>` block.
2. Restructure the `#editOrderModal` markup into the card-based layout, keeping every existing `x-model`/`@click`/`:class`/`x-for`/`x-text` binding pointed at the same state and methods.
3. Lint and style-check the file; visually verify in the browser.

## Testing and validation strategy

No automated frontend test covers this modal (Playwright suite, spec 040, only covers what breaks exclusively in a browser for other flows; this modal's behavior is already covered by spec 046's manual verification, unchanged here). Validation is: `php -l`, `php-cs-fixer --dry-run`, and manual browser verification opening a real pending order's edit modal.

## Rollout and rollback

No feature flag, migration, or backend change — a plain revert of the PR undoes this entirely. No data implication.

## Open questions

None blocking.

## Task checklist

- [x] New CSS rules added (light + dark)
- [x] Modal markup restructured into per-item cards, same bindings preserved
- [x] `php -l` clean
- [x] `php-cs-fixer --dry-run` clean
- [x] Manual browser verification (performed by the user directly, since `claude-in-chrome` stayed disconnected this session — see Implementation log)
- [ ] `CHANGELOG.md` / docs pass — deferred until this lands on `master`

Kept in sync with actual implementation progress, not the original plan.

## Implementation log

- 2026-09-27 — Restructured `public/kitchen/index.php`'s edit modal per the Proposed behavior above; no changes to `app.js` needed since every Alpine binding already matched what the new markup requires. Reused the app's existing CSS custom properties rather than introducing a new palette, per `CLAUDE.md`'s existing (confirmed) design tokens in `public/assets/css/style.css`.
- 2026-09-27 — A pre-existing, unrelated uncommitted change to `CLAUDE.md` was found on branch `046` at session start (the "no AI attribution" rule, not yet committed). It was stashed (`git stash push -u -- CLAUDE.md`) before branching off `master` for this spec, so it isn't mixed into this PR; it remains stashed for the user to restore on branch `046` separately.
- 2026-09-27 — Attempted browser-level verification via the `claude-in-chrome` extension; it reported "Browser extension is not connected" on four consecutive attempts. Stopped after repeated failures per the tool's own guidance rather than continuing to retry. Fell back to `php -l` and `php-cs-fixer --dry-run` (both clean, see Validation evidence) plus a manual re-read of the resulting markup against the original bindings.
- 2026-09-27 — User verified the redesigned modal manually in the browser (`http://localhost:8080/kitchen/`) and confirmed it works as expected, closing the gap left by the disconnected extension.

## Validation evidence

**Syntax check**
```
$ docker compose exec -T web php -l public/kitchen/index.php
No syntax errors detected in public/kitchen/index.php
```

**Style check**
```
$ docker compose exec -T web vendor/bin/php-cs-fixer fix --dry-run --diff public/kitchen/index.php
Found 0 of 1 files that can be fixed
```

**API-level smoke check (bindings unaffected):** created a temporary order (`POST /api/orders`, item id 72 "Beterraba", qty 2, `dining_option: local`, notes "sem cebola") to have real pending-order data available for a browser check → order id 128 → cancelled immediately after (`POST /api/orders/128/cancel`) once browser verification could not proceed (extension disconnected). No lasting data change.

**Manual browser verification (performed by the user, 2026-09-27):** the user opened `http://localhost:8080/kitchen/`, exercised the redesigned "Editar Pedido" modal on a real pending order, and confirmed it works as expected — covering acceptance criteria 1-3 and 6 (data binding, save/add/remove behavior, scroll usability with the new layout). Criteria 4-5 are verified above via `php -l`/`php-cs-fixer`.
