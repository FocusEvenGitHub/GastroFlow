# Spec 061 — Clean initial database

## Metadata

- Status: Implemented
- Created: 2026-10-26
- Updated: 2026-10-26
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Clean initial database". Builds on spec 054 (`bin/seed-demo`).
- Related branch: `061`

## Context

The roadmap item: "The default installation must not contain restaurant-specific production information. Separate schema from optional demo data […] A fresh production installation should start clean." It is also the first prerequisite of "Installation experience" and of the v1.9 Exit Gate (someone unfamiliar with GastroFlow installs it and configures *their* restaurant).

Spec 054 already added `bin/seed-demo`, but only for **orders**: it requires the menu to exist and fills history on top of it. The menu itself still ships with every install.

## Problem

Measured on `master` @ `dbd8578` (2026-10-26). A fresh install (`001_schema.sql` via the MySQL container's first init, then `bin/migrate`) gets the **menu of the restaurant GastroFlow was built for**, with its prices:

1. `common/sql/001_schema.sql:55-133` — 6 categories (including `Livros`) and ~60 `menu_items`: "Prato do Dia", "Picadinho", drinks, desserts, "Bandeja Ovos", "Manteiga de Garrafa", "Livro UDF 15"/"Livro UDF 22"…
2. Eight migrations add or rewrite restaurant data on top of it:
   - `001_ingredients.sql` — ingredients and `item_ingredients` for menu ids 1–3;
   - `002_food_category_and_components.sql` — `food_category` of add-ons, by name;
   - `003_dish_components_data.sql` — dish → add-on links by hard-coded ids;
   - `009_dishes_default_menu.sql` — **deletes** every main dish and inserts the client's canonical 13 ("Picadinho da Alegria", "Luís de Frango"…) with ids 1–13;
   - `010_default_main_dishes.sql`, `010_empanado_protein.sql` — client add-ons ("Frango Empanado", "Carne Empanada", "Mussarela", "Rúcula"…) and their links;
   - `015_build_your_own_dish.sql` — "Monte Seu Prato" (a **feature** item: `is_customizable = 1`, price 0);
   - `019_menu_item_packaging_option.sql` — links "Embalagem Simples/Especial" to the `viagem_*` packaging fee.
3. A new restaurant must delete all of that by hand in the Admin before operating — and items with order history cannot be deleted, so the earlier they start, the worse.

**Constraint that shapes the solution:** applied migrations cannot be edited — `MigrationRunner::assertNoHashDivergence()` (`src/Database/MigrationRunner.php:75`) refuses to run when an applied file's content changed (spec 048). The client restaurant in production has all of them applied with this data, and must not be touched.

## Goals

- A fresh install has the **structure** the code needs and no restaurant's menu.
- Existing installs (the client in production, any dev database) are **unchanged** by upgrading.
- The demo menu stays available, opt-in, through `bin/seed-demo`.

## Non-goals

- Editing or deleting any existing migration (hash rule above).
- Category management in the Admin (no route exists; categories stay fixed — see Current behavior).
- Changing the default `printer_ip` (`192.168.0.100`, `006_settings.sql`) — a generic placeholder, not restaurant data (Open question 3).
- The rest of "Installation experience" (docs walkthrough) — next roadmap item.

## Current behavior

Confirmed in code:

- **Categories are structure, not data.** The code matches category **names**: `'Adicionais'` (`src/Repositories/OrderRepository.php:22`, `public/cashier/app.js:37`, `public/admin/app.js:66`), `'Pratos Principais'` (`src/Services/ReportService.php:14`, `public/cashier/index.php:207-210,309,528`, `public/admin/index.php:118-121,232-238`, `public/kitchen/app.js:42,262`), `'Bebidas'`/`'Sobremesas'` (`bin/seed-demo:62-64`). `src/Routes.php` has no category endpoint — the Admin cannot create one. So the categories must ship with the install.
- **Packaging fee** = price of the menu item linked by `menu_items.packaging_option` (spec 050); with no linked item, `PricingService` falls back to R$ 1,00 / 2,00 (`src/Services/PricingService.php:24`). The Admin has no UI to set `packaging_option` (spec 050 trade-off: "migration/SQL only").
- **"Monte Seu Prato"** (`015`) is how the build-your-own-dish feature appears in the cashier — a feature row, price editable.
- **`bin/seed-demo`** (`bin/seed-demo:59-64`) picks items from `Pratos Principais`, `Bebidas`, `Adicionais`, `Sobremesas`; it "needs the default menu".
- **Tests depend on the seeded menu.** `tests/Integration/IntegrationTestCase.php:242-256` `availableMenuItem()` takes the first available item "from the seeded reference data" and fails if there is none; used by `OrderWorkflowTest`, `PrintingTest`, `PrinterBlockTest`, `AuditLogTest`, `RequestTracingTest`, `MenuReportsAndJourneyTest`. E2E `printer-block.spec.ts:83`, `realtime-events.spec.ts:32`, `connection-status.spec.ts:151` read the real `/api/menu` of `restaurant_test`. CI builds that database from `001_schema.sql` + `bin/migrate` (`.github/workflows/ci.yml:82-91`).
- `CLAUDE.md` and `specs/000-project-baseline.md` state there is "no seeded data beyond the menu/category rows in `001_schema.sql`" — incomplete today (the migrations above also seed).

## Proposed behavior

**Mechanism — a fresh-install marker, cleaned by one new migration:**

1. `001_schema.sql` (run only on a database's first init — never on an existing install):
   - inserts only the **structural categories**: `Pratos Principais`, `Adicionais`, `Bebidas`, `Sobremesas`, `Viagem` (drops `Livros`);
   - inserts **no** `menu_items` *(corrected 2026-10-26 by `/spec-review`: it does insert the two packaging items "Embalagem Simples/Especial" — migration `019` links the packaging fee to them by name; see the Implementation log)*;
   - creates an empty marker table `fresh_install` — its existence means "this database was born from the clean schema".
2. The existing migrations run unchanged on a fresh install. Some of them insert client data (009/010 insert by explicit id or by name; 001/003 use `INSERT IGNORE` against ids that may not exist).
3. New migration `022_clean_fresh_install.sql`, **only if the `fresh_install` table exists**:
   - deletes `item_ingredients`, `ingredients`, `dish_components` rows and every `menu_items` row **except** "Monte Seu Prato" (`is_customizable = 1`) and the two packaging items (`packaging_option IS NOT NULL`);
   - resets those three items to neutral values where they are client values (packaging names "Embalagem Simples"/"Embalagem Especial" are generic; prices R$ 1,00/2,00 equal the code fallback — kept);
   - drops `fresh_install`.
   Without the table (every existing install), it does nothing.
4. `bin/seed-demo` gains the demo **menu**: when no orderable item exists (only the 3 structural rows), it first inserts a small fictional menu (generic names, no client's dishes) in the structural categories, then the orders as today. Same safety rule as today (refuses on a database with orders unless `APP_ENV=development`).
5. Tests stop depending on the production seed: `availableMenuItem()` creates its fixture item when none exists; the e2e/CI path gets its menu from `bin/seed-demo` (or from fixtures), not from `001_schema.sql`.
6. Docs: README installation step mentions the empty menu and `bin/seed-demo` for a demo; `CLAUDE.md`/baseline sentence about seeded data corrected.
7. *(Added 2026-10-26 at the owner's request, during implementation.)* The pilot client's menu is kept as an **opt-in seed**: `common/seeds/client-1.sql` + `bin/seed-client-1`, reproducing on a clean install exactly what `001_schema.sql` + `bin/migrate` produced before this spec.

Why a marker and not "no orders → clean": a real restaurant that just installed and configured its menu has no orders yet; a rule based on orders could wipe its work. The marker can only exist on a database created from the new `001_schema.sql`, and `022` removes it the first time it runs.

## Functional requirements

1. FR1 — A database created from the new `001_schema.sql` + `bin/migrate` has exactly the categories `Pratos Principais`, `Adicionais`, `Bebidas`, `Sobremesas`, `Viagem`; `menu_items` holds only "Monte Seu Prato" and the two packaging items; `ingredients`, `item_ingredients`, `dish_components`, `orders` are empty; table `fresh_install` does not exist afterwards.
2. FR2 — On a database **without** `fresh_install` (any existing install), `022` changes no row: row counts and content of `categories`, `menu_items`, `ingredients`, `item_ingredients`, `dish_components` are identical before and after.
3. FR3 — No existing migration file changes (`git diff master -- common/migrations/0[01]*.sql common/migrations/02[01]*.sql` empty); `bin/migrate` on an existing install reports no hash divergence.
4. FR4 — On a clean install, `bin/seed-demo` inserts the demo menu and then its orders; the cashier shows the demo items. On an install that already has orderable items, it does not insert menu items.
5. FR5 — On a clean install, without `bin/seed-demo`: the cashier loads with no errors (empty categories hidden or shown empty), the Admin can create items in each category, and a packaging (`viagem_simples`) order charges the linked item's price.
6. FR6 — PHPUnit (Unit + Integration) and the Playwright suite pass in CI on a database built from the new `001_schema.sql` + `bin/migrate`.
7. FR7 — On a clean install, `bin/seed-client-1` produces categories, menu items (price, description, availability, position, `food_category`, flags), ingredients, item ingredients and dish components identical, by name, to a pre-061 fresh install; on a database with a menu it exits 1 without writing.

## Non-functional requirements

- No new dependency; no Docker/Composer change.
- `022` is idempotent and safe to re-run (follows the `information_schema` + `PREPARE` pattern of the existing migrations).
- Destructive deletes in `022` are guarded only by the marker table — never by row counts or names alone.

## User flows

- **New restaurant:** `docker compose up -d` → `bin/migrate` → `bin/create-admin` → Admin: create its own menu items → cashier sells them.
- **Evaluating / demo:** same, plus `bin/seed-demo` → menu + 45 days of orders for Kitchen and Reports.
- **Existing restaurant upgrading:** `git pull` → `bin/migrate` (applies `022`, no-op) → nothing changes.

## API changes

Not applicable — no endpoint changes. `/api/menu` simply returns fewer items on a clean install.

## Data model and migrations

- `common/sql/001_schema.sql` — fewer seed rows; new empty table `fresh_install` (no columns beyond an id).
- `common/migrations/022_clean_fresh_install.sql` — conditional cleanup + `DROP TABLE fresh_install`.
- No column changes; no Eloquent model changes.

## Architecture and affected components

- `common/sql/001_schema.sql`, new `common/migrations/022_clean_fresh_install.sql`.
- `bin/seed-demo` — demo menu before demo orders.
- *(Added during implementation, recorded by `/spec-review` 2026-10-26)* `bin/seed-client-1` + `common/seeds/client-1.sql` (FR7); `tests/Integration/MigrationUpgradeTest.php` (schema read from the tag, FR1/FR2 tests); `.gitattributes` and `.php-cs-fixer.dist.php` (both list `bin/` scripts one by one).
- `tests/Integration/IntegrationTestCase.php` (`availableMenuItem()` fixture; the "seed" comment at :99), `.github/workflows/ci.yml` and/or `docker-compose.e2e.yml` (menu for e2e), possibly `tests/e2e/specs/{printer-block,realtime-events,connection-status}.spec.ts`.
- Docs: `README.md` (installation), `CLAUDE.md` (Architecture › Persistence sentence), `specs/000-project-baseline.md`, `docs/architecture.md`, `CHANGELOG.md`, `docs/ROADMAP.md`.
- No PHP layer under `src/` changes.

## Security considerations

- `022` deletes data; its only trigger is the `fresh_install` table, which exists solely on databases created from the new `001_schema.sql`. A bug in that guard would wipe a restaurant's menu → FR2 must be proven against a copy of a real-shaped database (the dev DB backup), not only the empty test DB.
- No secrets involved.

## Backward compatibility

- Existing installs: `022` is a no-op (FR2); no migration edited (FR3).
- Anyone relying on a fresh install *having* the old menu (dev habits, screenshots): replaced by `bin/seed-demo`. The README screenshots stay as they are.
- `IntegrationTestCase`'s comment about "001_schema.sql seeds menu_items without idempotency guard" becomes partly obsolete (categories still inserted unguarded).

## Acceptance criteria

1. AC1 — Fresh database (`restaurant_test` dropped/recreated **only with explicit user approval**, or a new scratch DB) built from `001_schema.sql` + `bin/migrate`: `SELECT name FROM categories` returns the 5 structural names; `SELECT name FROM menu_items` returns exactly "Monte Seu Prato", "Embalagem Simples", "Embalagem Especial"; `ingredients`/`item_ingredients`/`dish_components` count 0; `SHOW TABLES LIKE 'fresh_install'` empty.
2. AC2 — On a restore of a current backup into a scratch database (the dev data, no marker), counts and a checksum (`CHECKSUM TABLE`) of `categories`, `menu_items`, `ingredients`, `item_ingredients`, `dish_components` are equal before and after `bin/migrate` applies `022`.
3. AC3 — `git diff master --stat -- common/migrations/` lists only `022_clean_fresh_install.sql`.
4. AC4 — On the AC1 database: `bin/seed-demo` exits 0, `/api/menu` lists demo items in `Pratos Principais`/`Bebidas`/`Adicionais`/`Sobremesas`, and orders exist; running it on the AC2 database inserts no menu item.
5. AC5 — On the AC1 database without demo: `/cashier/` loads with no console error; an item created in Admin appears in the cashier; a `viagem_simples` order has `packaging_cost` = price of "Embalagem Simples".
6. AC6 — CI green on the PR (PHPStan, CS-Fixer, PHPUnit Unit + Integration, Playwright), with CI's database built from the new `001_schema.sql`.
7. AC7 — A by-name fingerprint (categories; items with price/description/available/position/food_category/flags; ingredients; item_ingredients; dish_components) of `master`'s `001_schema.sql` + migrations equals the same fingerprint of a clean install + `bin/seed-client-1` (`diff` empty); a second run exits 1.

## Implementation plan

1. `001_schema.sql`: structural categories only, no items, `fresh_install` marker.
2. `022_clean_fresh_install.sql`: guarded cleanup + drop marker.
3. `bin/seed-demo`: demo menu when no orderable item exists.
4. Tests: `availableMenuItem()` fixture; CI/e2e menu source; run the suites.
5. Validation on scratch databases (AC1, AC2, AC4, AC5).
6. Docs pass (README, CLAUDE.md, baseline, architecture, CHANGELOG, ROADMAP).

## Testing and validation strategy

The project has PHPUnit (Unit + MySQL Integration, spec 035) and Playwright (spec 040) — the template's "no automated test infrastructure" note is outdated.

- **Automated:** an integration test for `022` on both paths — with the marker (cleans, drops marker) and without (no row changes) — running the migration SQL against the test database; existing suites green (AC6).
- **Database checks (AC1, AC2):** scratch databases only (`CREATE DATABASE` needs the user's/root grant — ask first); never the development database `restaurant`, never `restaurant_test` drops without approval.
- **Manual/browser (AC5):** cashier and Admin on the clean database.

## Rollout and rollback

- New installs: effective immediately. Existing installs: `bin/migrate` records `022` as applied, nothing else.
- Rollback: revert the commit. A database already created clean stays clean (data was never there); `bin/seed-demo` can refill it with demo data. `022` remaining in the `migrations` table after a revert is harmless (the runner only flags hash changes of files that still exist).

## Open questions

1. *(non-blocking)* **Marker vs. baseline squash.** Alternative: a new `001_schema.sql` with the full current schema and the `migrations` table pre-filled, so data migrations never run on a fresh install. Rejected as default: pre-filled hashes must match `normalizedHash()` exactly, and every future migration would have to be mirrored into the baseline. Proposed: marker.
2. *(non-blocking)* **Packaging items and "Monte Seu Prato" stay?** Proposed **yes**: they carry behavior (packaging fee link with no Admin UI; build-your-own dish), not a menu. Their prices equal the code fallback / zero.
3. *(non-blocking)* **Default `printer_ip` `192.168.0.100`.** Today's LAN test (spec 060) showed it is wrong for a `192.168.1.x` network. Proposed: out of scope here; consider it in "Printer documentation".
4. *(non-blocking)* **Demo menu content.** Proposed: ~12 fictional, generic items (e.g. "Prato Executivo", "Frango Grelhado", "Refrigerante Lata", "Pudim"), prices in round numbers; not the client's dishes.

## Task checklist

- [x] Step 1 — `001_schema.sql` clean + marker
- [x] Step 2 — `022_clean_fresh_install.sql`
- [x] Step 3 — `bin/seed-demo` demo menu
- [x] Step 4 — tests/CI independent of the production seed
- [~] Step 5 — validation on scratch databases (AC2 checksum on a dev-data copy and AC5 in a real browser not done — see Validation evidence)
- [x] Step 6 — docs pass
- [x] Step 7 — `bin/seed-client-1` + `common/seeds/client-1.sql` (added at the owner's request)

## Implementation log

- 2026-10-26 — `/spec-implement` on a Draft with no blocking open questions → treated as approval (Draft → Approved → In Progress). Open questions taken at their proposed defaults: marker (not baseline squash); "Monte Seu Prato" and packaging items kept; `printer_ip` default untouched; generic demo menu.
- **Deviation from "Proposed behavior" item 1:** `001_schema.sql` still inserts the two packaging items ("Embalagem Simples/Especial", R$ 1,00/2,00), not "no `menu_items`". Reason found while implementing: migration `019` links the packaging fee to these items **by name**, and they used to come only from `001_schema.sql` — with no row there, `019` links nothing and the fee silently falls back to the hard-coded R$ 1,00/2,00 with nothing to edit in the Admin. FR1's expected end state (3 behavior rows) is unchanged.
- Side effect, checked harmless: those two items take ids 1–2, so `009`'s `INSERT IGNORE` with explicit ids 1–2 skips two client dishes, and `001`/`003` attach ingredients/components to ids 1–3; `022` deletes all `item_ingredients`/`dish_components` on a fresh install, and the fresh-install test asserts the end state.
- `022` adds a second guard besides the marker: it also requires `orders` to be empty (a database with orders is never cleaned, even with the marker).
- `MigrationUpgradeTest` read `001_schema.sql` from the working tree on the premise "unchanged since v1.7.1" — false after this spec. It now reads the schema from the tag via `git show`, like the migrations (`gitShow()` helper extracted from the existing loop). Two assertions added to the upgrade test (an old-seed item, "Livro UDF 15", survives `022`; no marker left), and a new `testFreshInstallStartsWithoutRestaurantData` builds the current schema + migrations in the same dedicated database.
- `IntegrationTestCase::availableMenuItem()` now excludes packaging items and creates one fixture item ("Prato (teste)") when none exists. The three e2e specs that pick "the first non-customizable item" from the real `/api/menu` also skip packaging items; in CI that item comes from the Integration suite's fixture, which runs before Playwright in the same database.
- `bin/seed-demo` inserts the 14-item demo menu only when no sellable item (not customizable, no `packaging_option`) exists.
- 2026-10-26 — **Scope added by the owner:** keep the pilot client's menu as `bin/seed-client-1`. Generated, not hand-written: the pre-061 state was rebuilt in `restaurant_test_upgrade` from `master`'s `001_schema.sql` + migrations (read with `git show`, like `MigrationUpgradeTest`) — 6 categories, 74 items, 13 ingredients, 13 item ingredients, 73 dish components, **no duplicate names** — and a one-off script (kept out of the repo) emitted `common/seeds/client-1.sql` with every reference by name, so it applies on a clean install whose ids differ. "Monte Seu Prato" and the packaging items already exist there, so the seed only `UPDATE`s them with the client's values. Settings (`restaurant_name`, printer) are not included — generic defaults, not menu data. Also: `.gitattributes` `eol=lf` rule and the `.php-cs-fixer.dist.php` file list gained the new script (both list `bin/` files explicitly).
- Docs: also corrected `specs/000-project-baseline.md`'s "no formal seeding mechanism beyond the one admin row" (stale since spec 015) with a dated note. `README.md`'s installation block still has no `bin/migrate` step — noticed, left for "Installation experience" (next roadmap item), not changed here.

## Validation evidence

Collected 2026-10-26 on branch `061`, local Docker (`restaurant_web`, PHP 8.2.35). Databases used: `restaurant_test` (shared test DB, old menu) and `restaurant_test_upgrade` (dedicated, dropped/rebuilt by `MigrationUpgradeTest` by design). The development database `restaurant` was not touched.

| AC | Command / evidence | Result |
|---|---|---|
| AC1 | `MigrationUpgradeTest::testFreshInstallStartsWithoutRestaurantData` (current `001_schema.sql` + all migrations) → OK, 9 assertions. Then on that database: categories `Pratos Principais,Adicionais,Bebidas,Sobremesas,Viagem`; `menu_items` = `Embalagem Simples,Embalagem Especial,Monte Seu Prato`; `ingredients`/`item_ingredients`/`dish_components`/`orders` 0; no `fresh_install` table | Met |
| AC2 | `testUpgradeFromV171…` builds v1.7.1 (schema + migrations from the tag) and upgrades to HEAD incl. `022` → "Livro UDF 15" still present, no marker. `restaurant_test` (real old seed, no marker) after `022` applied at 2026-10-26 20:07:52: 4275 `menu_items`, 53 `dish_components`, 13 `ingredients` remain. **Not done:** the `CHECKSUM TABLE` before/after on a restore of the dev backup the AC names | Partially met |
| AC3 | `git diff master --stat -- common/migrations/` → no tracked migration changed; `git status` → only `022_clean_fresh_install.sql` new | Met |
| AC4 | Clean DB: `bin/seed-demo` (`MYSQL_DATABASE=restaurant_test_upgrade`) → "✓ Cardápio de demonstração criado." + 60 done / 5 pending, exit 0; 14 demo items in 4 categories, all 14 sold. UTF-8 checked (`HEX(Água)` = `C381677561`). On a DB already holding an item ("Prato Novo do Restaurante"), with `APP_ENV=development`: no "Cardápio" line, `menu_items` stayed at 4 | Met |
| AC5 | Clean DB served by `php -S` (port 8099, inside the container): `/api/menu` → only Monte Seu Prato + 2 packaging items; `/cashier/` → 200; item inserted in "Pratos Principais" appears in `/api/menu`; `POST /api/orders` with it as `viagem_simples` → 201, `packaging_cost` 1.00 = price of "Embalagem Simples". **Not done:** item created through the Admin screen (inserted in the DB instead) and the cashier checked in a browser for console errors | Partially met |
| AC6 | Local: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 113 files`; `phpunit --testsuite Smoke,Unit` `OK (238 tests, 474 assertions)`; `phpunit --testsuite Integration` `OK (70 tests, 330 assertions)`. Playwright not run locally (needs `web-e2e`). CI not run yet (no PR) | Pending CI |

| AC7 | Clean DB (fresh-install test) → `bin/seed-client-1` → "✓ Cardápio do cliente 1 carregado … (74 itens)", exit 0. Fingerprint query (by name) on the pre-061 state (179 rows) vs. after the seed (179 rows): `diff` → **identical**. Second run → "Erro: … já tem cardápio …", exit 1. `php -l` OK, PHPStan `[OK] No errors`, CS-Fixer `Found 0 of 114 files` | Met |

### CI evidence (PR #47, recorded 2026-10-26 in spec 062's PR)

CI run `40420655166` on `014555b` (branch `061`), conclusion `success`, raw log: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 114 files`; Unit `OK (238 tests, 474 assertions)`; Integration `OK (70 tests, 330 assertions)` — on a `restaurant_test` built from scratch with the new `001_schema.sql`, i.e. the clean-install path with the `fresh_install` marker, so the fixture item came from `availableMenuItem()`; Playwright `29 passed / 5 skipped`. PR #47 merged as `cf07102`. **AC6 → Met.**

### AC2 — additional observation (recorded 2026-10-27 in spec 063's PR)

On 2026-10-26 the owner ran `bin/migrate` on the **development database** (a real-shaped install: pilot menu, 95 orders, no `fresh_install` marker): `▶ Executando 022_clean_fresh_install.sql ... [OK]`. Read afterwards: `orders=95`, `menu_items=73` (both equal to the counts read before, at the dev stack restart in spec 062's run), `ingredients=13`, `dish_components=53`, no `fresh_install` table. Counts, not the `CHECKSUM TABLE` the criterion names, and `ingredients`/`dish_components` were not counted before — so AC2 stays **Partially met**, now with a real database behind it.

Status stays `Implemented`, not `Verified`: AC2 (checksum on a copy of the dev data) and AC5 (Admin/cashier in a browser) are still partially met.
