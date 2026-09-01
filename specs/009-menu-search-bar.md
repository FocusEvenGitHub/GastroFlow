# Spec 009 — Menu search bar (Caixa + Admin)

## Metadata

- Status: Implemented
- Created: 2026-09-01
- Updated: 2026-09-01
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

This is a reduced spec (small, obvious frontend addition, per `CLAUDE.md`'s "Small and obvious fixes may use a reduced spec" allowance) — sections that don't apply to a client-side-only UI change are marked accordingly rather than filled with filler.

## Context

Both `public/cashier/` (Caixa) and `public/admin/index.php` (Admin) list the full menu grouped by category, with no way to jump straight to an item by typing its name. As the menu grows (see `common/sql/001_schema.sql`'s ~70 seeded items across 6 categories), finding one item means scrolling through category tabs (Caixa) or scrolling the whole page (Admin).

## Problem

1. Neither Caixa nor Admin has a text search over menu items — only category filtering (Caixa) or no filtering at all (Admin).
2. In Caixa, after adding an item to the order, any text typed to find it stays in the (new) search box, which is confusing when the cashier is about to look for the next item.

## Goals

- Add a menu-item name search input to Caixa, combined with the existing category tab filter.
- Add a menu-item name search input to Admin's "Cardápio Atual" list.
- In Caixa, clear the search input automatically whenever an item is added to the order (by card click or the "Adicionar" button).

## Non-goals

- No backend/API changes — filtering is client-side over the already-loaded `/api/menu` / `/api/admin/menu` response, matching how category filtering already works in Caixa (`filteredMenu` getter in `public/cashier/app.js`).
- No search over ingredients, dish components, or order history.
- No change to Admin's "Adicionar Novo Item" form or the edit modal.
- No fuzzy/typo-tolerant matching — a simple case-insensitive substring match on `item.name`, consistent with the simplicity of the rest of these screens.

## Current behavior

- `public/cashier/app.js`: `filteredMenu` getter (lines 42-45) filters `this.menu` by `currentCategory` only. The template (`public/cashier/index.php` lines 128-164) renders `filteredMenu`. `addItem(item)` (lines 48-64) pushes/increments `selectedItems` and does not touch any search state.
- `public/admin/app.js`: no `filteredMenu` getter exists; the template (`public/admin/index.php` line 161) iterates `menu` directly.

## Proposed behavior

- Caixa: a text input above/alongside the category tabs, bound to `searchQuery`. `filteredMenu` filters by category **and** by `item.name` containing `searchQuery` (case-insensitive), hiding categories left with zero matching items. Adding an item (`addItem`) resets `searchQuery` to `''`.
- Admin: a text input above the "Cardápio Atual" list, bound to `searchQuery`. A new `filteredMenu` getter applies the same case-insensitive substring filter on `item.name`, hiding categories with zero matches. The template iterates `filteredMenu` instead of `menu`.
- Both: when a search is active and no items match, show a message that reflects that (distinct from the existing generic "no items" message).

## Functional requirements

1. Typing text in the Caixa search box shows only items whose name contains that text (case-insensitive), within the currently selected category tab.
2. Clearing the Caixa search box (or the category tab) restores the previously visible items.
3. Adding any item to the order in Caixa clears the search box.
4. Typing text in the Admin search box shows only items whose name contains that text (case-insensitive), across all categories.
5. Neither search input calls the API — filtering only touches already-loaded `menu` data in memory.

## Non-functional requirements

Not applicable — client-side string filtering over an already-loaded array (at most ~100 items today); no measurable performance concern.

## User flows

- Cashier: opens Caixa, types part of a dish name in the search box, sees matching items only, clicks one to add it to the order, sees the search box empty again and ready for the next search.
- Admin: opens the menu screen, types part of an item name, sees only matching items across categories to edit/toggle/delete.

## API changes

Not applicable — no backend change.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

Frontend only, following the existing Alpine.js views (`CLAUDE.md`: static PHP/Alpine views under `public/`, no build step):

- `public/cashier/index.php`, `public/cashier/app.js`
- `public/admin/index.php`, `public/admin/app.js`

No `src/` (Controllers/Services/Repositories/Models) changes.

## Security considerations

Not applicable — no new input reaches the server; the search text never leaves the browser.

## Backward compatibility

No stored data or API contract changes. Existing category-tab filtering in Caixa keeps working unchanged when the search box is empty.

## Acceptance criteria

1. In Caixa, entering a substring of an existing item's name in the search box leaves only categories/items matching that substring visible.
2. In Caixa, adding an item (via card click or the "Adicionar" button) empties the search box (`searchQuery === ''`).
3. In Admin, entering a substring of an existing item's name in the search box leaves only categories/items matching that substring visible.
4. Clearing either search box restores the full (category-filtered, for Caixa) menu view.
5. `php -l` passes on both edited `.php` files.

## Implementation plan

1. Caixa: add `searchQuery: ''` to `cashierApp()` state; extend `filteredMenu` to also filter items by name substring and drop empty categories; clear `searchQuery` at the end of `addItem()`; add the search `<input>` to `public/cashier/index.php` near the category tabs; adjust the empty-state message.
2. Admin: add `searchQuery: ''` to `adminApp()` state; add a `filteredMenu` getter with the same substring filter; change the template to iterate `filteredMenu`; add the search `<input>` to `public/admin/index.php` near the "Cardápio Atual" heading; adjust the empty-state message.

## Testing and validation strategy

No automated frontend test infrastructure exists for these Alpine.js views (`CLAUDE.md`: "There is no test command"). Validation is manual: `php -l` on both edited files, then loading Caixa and Admin in a browser against the running `docker compose` stack and exercising the search box and add-item flow per the acceptance criteria above.

## Rollout and rollback

Plain file edit, no migration or flag. Rollback is reverting the four edited files.

## Open questions

None blocking.

## Task checklist

- [x] Caixa: `searchQuery` state + extended `filteredMenu` + clear-on-add + search input UI
- [x] Admin: `searchQuery` state + new `filteredMenu` getter + template switched to it + search input UI
- [x] `php -l` on both edited `.php` files
- [ ] Manual browser validation of both screens (not performed — no browser tool available this session; see Validation evidence)

## Implementation log

- Implemented client-side-only, per the "Non-goals" above — no `src/` or migration changes were needed or made.
- Reused the existing `filteredMenu` pattern already present in Caixa rather than inventing a new filtering mechanism, and introduced the same pattern in Admin for consistency between the two screens.

## Validation evidence

- `docker exec restaurant_web php -l public/cashier/index.php` → "No syntax errors detected in public/cashier/index.php"
- `docker exec restaurant_web php -l public/admin/index.php` → "No syntax errors detected in public/admin/index.php"
- `curl http://localhost:8080/cashier/` and `/admin/` → both HTTP 200, both responses contain the new `searchQuery`-bound input.
- `curl http://localhost:8080/cashier/app.js` / `/admin/app.js` → confirmed the running container serves the edited `addItem()` (search cleared on add) and the new `filteredMenu` getter (no build step, files are bind-mounted, so this reflects the actual edits).
- `curl http://localhost:8080/api/menu` → confirmed the real response shape (`category_name`, `items[].name`) matches what both `filteredMenu` getters filter on.
- **Not done:** interactive browser verification (typing in the search box, clicking to add an item, watching it clear) — no browser tool was available this session. This is the one gap keeping status at `Implemented` rather than `Verified`; a manual pass in a browser is the remaining acceptance check before calling this `Verified`.
