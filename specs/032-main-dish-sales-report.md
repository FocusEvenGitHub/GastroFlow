# Spec 032 — Main dish sales report

## Metadata

- Status: Implemented
- Created: 2026-09-13
- Updated: 2026-09-13
- Owner: Henry
- Related issue: Not applicable (client request)
- Related branch: master (working tree, not committed)

## Context

Client request: a dedicated part of the Reports page showing how many main dishes ("Pratos Principais") were sold.

## Problem

`public/admin/reports.php` only has "Itens Mais Vendidos" (`GET /api/admin/reports/top-items`, `ReportService::getTopItems()`): top 10 across **all** categories (drinks, add-ons, desserts mixed in), no total, and main dishes past the 10th position are hidden. There is no way to read "N main dishes sold" for a period.

## Goals

- Endpoint returning, for a date range, the total quantity of main dishes sold and the quantity/revenue/share per dish (all dishes, no limit).
- A "Pratos Principais Vendidos" section on the Reports page, driven by the existing period filter.

## Non-goals

- No breakdown of "Monte Seu Prato" by add-on combination (spec 030 data allows it later).
- No per-day main-dish chart.
- No change to existing report endpoints or the existing (pre-existing gap) absence of report endpoints in `openapi.yaml`.

## Current behavior

Confirmed in `src/Services/ReportService.php`: every sales report counts only `orders.status = 'done'` within `orders.business_date` range; item revenue is `unit_price * quantity` (packaging excluded in `getTopItems()`); names use the `item_name` snapshot (spec 023). Routes under `/api/admin/reports/*` require JWT + `admin`/`manager` (`src/Routes.php`).

## Proposed behavior

- `ReportService::getMainDishSales(string $dateFrom, string $dateTo): array` → `{ total_qty, total_revenue, items: [{ menu_item_id, name, total_qty, total_revenue, share }] }`.
  - Filters: `orders.status = 'done'`, `business_date` within range, item's **current** menu category is "Pratos Principais" (join `menu_items` → `categories`).
  - Grouped by `menu_item_id`; `name` = `item_name` of the most recent order line (highest `order_items.id`), resolved with a second query instead of MySQL-only `GROUP_CONCAT`, so the query is portable/testable.
  - `total_revenue` = Σ `unit_price * quantity` (no packaging), rounded to 2 decimals; `share` = % of `total_qty`, 1 decimal.
  - Sorted by `total_qty` desc, then name.
- `GET /api/admin/reports/main-dishes?date_from&date_to` (`ReportController::mainDishes`, JWT + admin/manager), same `{success, data}` envelope and date defaults as the other report actions.
- Reports page: after "Vendas por Dia", a card with a highlighted total ("pratos principais vendidos", plus revenue) and a ranked table: dish, quantity, share bar (%), revenue.

## Functional requirements

1. FR1 — Only completed orders in the range and only items currently in "Pratos Principais" are counted.
2. FR2 — Every main dish sold appears (no limit), with quantity, revenue (no packaging) and share.
3. FR3 — Endpoint protected like the other report endpoints.
4. FR4 — The Reports page section reloads with the period filter.

## Non-functional requirements

Single grouped query + one lookup query; uses the existing `business_date` filter (sargable, spec 025).

## User flows

Admin/manager → Admin → Relatórios → pick period → Filtrar → "Pratos Principais Vendidos" shows total and ranking.

## API changes

New `GET /api/admin/reports/main-dishes` (200 `{success: true, data: {...}}`; 401/403 per existing middleware).

## Data model and migrations

Not applicable — reads existing tables.

## Architecture and affected components

`src/Services/ReportService.php`, `src/Controllers/ReportController.php`, `src/Routes.php`, `public/admin/reports.php`, `public/admin/reports.js`, new `tests/Unit/ReportServiceTest.php`.

## Security considerations

Same `JwtMiddleware` + `RoleMiddleware(['admin','manager'])` as sibling report routes; date params only reach Eloquent query bindings.

## Backward compatibility

Additive endpoint and UI section only.

## Acceptance criteria

1. AC1 — Unit tests: only done/in-range/main-dish lines counted; totals, revenue without packaging, share and ordering as specified; empty period → zeros.
2. AC2 — Live: `GET /api/admin/reports/main-dishes` without token → 401; with an admin token → 200 and `total_qty` equals the sum of `items[].total_qty`.
3. AC3 — Full PHPUnit suite passes.
4. AC4 — Manual: Reports page shows the section and it updates with the filter.

## Implementation plan

1. Service method + unit tests. 2. Controller action + route. 3. Reports page section + JS. 4. Run suite, live check.

## Testing and validation strategy

PHPUnit (SQLite) for AC1; live curl against the container for AC2 (requires an admin JWT — obtained only if an admin login is available; otherwise the auth check alone is recorded); manual UI for AC4.

## Rollout and rollback

Deploy code; no migration. Revert to roll back.

## Open questions

- Non-blocking: category is matched by the dish's **current** category (a dish later moved out of "Pratos Principais" disappears from past periods). Acceptable for now; snapshotting category per order line would need a migration.

## Task checklist

- [x] `ReportService::getMainDishSales()` + unit tests
- [x] Controller action + route
- [x] Reports page section
- [x] Suite + live check (authenticated HTTP call not performed — see evidence)
- [ ] Manual UI check

## Implementation log

- 2026-09-13 — Name resolved via `MAX(order_items.id)` + lookup instead of `GROUP_CONCAT`, keeping the "most recent snapshot" semantics of `getTopItems()` while staying SQLite-testable.
- 2026-09-13 — Shares are rounded per dish to 1 decimal, so their sum can differ slightly from 100% (observed 100.2%); totals are exact.
- 2026-09-13 — No admin credentials available in-session (and `.env` must not be read), so the authenticated HTTP path was not exercised; the service was run directly against MySQL instead, and the route/auth wiring was checked via 401 responses.

## Validation evidence

- **AC1** — `tests/Unit/ReportServiceTest.php` (3 tests: done/in-range/main-dish filtering with revenue excluding packaging and shares 75/25; most-recent name + tie ordering; empty period → zeros) pass.
- **AC3** — `docker compose exec web vendor/bin/phpunit` → `OK (127 tests, 191 assertions)`.
- **AC2 (partial)** — `GET /api/admin/reports/main-dishes` without token → `401`; with an invalid bearer token → `401` (route registered behind `JwtMiddleware`). Authenticated 200 call not performed (no credentials in session).
- MySQL 8 execution of the same query (inside `restaurant_web`, `ReportService::getMainDishSales('2026-01-01','2026-12-31')`) → `total_qty=42 total_revenue=1049 dishes=14 sum_items_qty=42`; top rows `Prato do Dia qty=10`, `Parmegiana de Carne qty=7`, `Luís de Carne qty=6`, … `Monte Seu Prato qty=2` — confirms the query runs under MySQL (incl. `ONLY_FULL_GROUP_BY`) and `total_qty` equals Σ `items[].total_qty`.
- `node --check public/admin/reports.js` → OK.
- **AC4** — not yet verified visually.
