# Spec 006 — Default menu dishes (Pratos Principais) and dish components

## Metadata

- Status: Approved
- Created: 2026-08-30
- Updated: 2026-08-30
- Owner: GastroFlow
- Related issue:
- Related branch:

## Context

The restaurant's default menu should contain a fixed set of 13 main dishes with
canonical names, descriptions, prices, and dish components (the add-ons sold
together with each dish). The current seed data in `common/sql/001_schema.sql`
and the component mapping in `common/migrations/003_dish_components_data.sql`
have drifted from the desired default list (different prices, single "Barça"
row, "Picadinho" name, and incomplete component assignments).

## Problem

The `menu_items` table (category "Pratos Principais") currently holds rows whose
name, price, and description differ from the desired default list, and there is
no idempotent, incremental migration that brings the data to the authoritative
state. Because migrations already applied are not re-executed (filenames are
tracked), editing `001_schema.sql` would not fix existing databases.

## Goals

- Provide an incremental migration that replaces the "Pratos Principais" dishes
  with the authoritative list of 13 dishes (name, description, price).
- Split "Barça" into three dishes (de Frango / de Carne / de Tilápia).
- Rename "Picadinho" to "Picadinho da Alegria".
- Assign the correct `dish_components` for each dish, matching the add-ons
  available in the "Adicionais" category.

## Non-goals

- Not changing the "Adicionais", "Bebidas", "Sobremesas", "Viagem", or "Livros"
  categories.
- Not introducing ingredient/recipe changes (`item_ingredients` stays as-is for
  dishes that keep a sensible id; diverging recipes may remain stale until a
  dedicated spec addresses recipes).
- Not touching the frontier of `orders`/`order_items` (no migration of historic
  order data).

## Current behavior

Confirmed by reading `common/sql/001_schema.sql` and by querying the running DB:

- `menu_items` "Pratos Principais" (category_id = 1) has 13 rows, ids 1–13:
  Prato do Dia (20.00), Picadinho (28.00), Parmegiana de Frango (25.00),
  Parmegiana de Carne (28.00), Salada de Frango (20.00), Salada de Carne (23.00),
  Salada de Tilápia (26.00), Luís de Frango (23.00), Luís de Carne (26.00),
  Luís de Tilápia (28.00), Barça (30.00), Especial X (15.00), Prato Turbo (25.00).
- "Adicionais" (category_id = 2) ids 14–30 provide the component items.

Wait: ids confirmed against a running DB where no migrations had been applied.
The Adicionais ids observed: 14 Filé de Tilápia, 15 Filé de Frango, 16 Filé de
Carne, 17 Linguiça Fina, 18 Farofa, 19 Cebola, 20 Vinagrete, 21 Salada,
22 Molhos, 23 Arroz Branco, 24 Feijão Carioca, 25 Macarrão, 26 Fritas Individual,
27 Fritas Cone, 28 Ovo Frito, 29 Shot de Limão, 30 Fritas com Anel Cebola.

## Proposed behavior

After applying the migration, category "Pratos Principais" contains exactly the
13 dishes below (in id 1–13), with the given price and description, and
`dish_components` holds the mapped component rows. The prior 13 rows are removed
(replaced).

| id | name | price | description |
|----|------|-------|-------------|
| 1 | Prato do Dia | 20.00 | parmegiana de frango, macarrão alho e óleo, molho especial, mussarela e arroz |
| 2 | Picadinho da Alegria | 30.00 | macarrão acebolado, linguiça de frango, tiras de filé e barbecue |
| 3 | Parmegiana de Frango | 25.00 | frango, macarrão alho e óleo, molho especial, manjericão e mussarela |
| 4 | Parmegiana de Carne | 30.00 | carne, macarrão alho e óleo, molho especial, manjericão e mussarela |
| 5 | Salada de Frango | 20.00 | frango, milho, rúcula, beterraba, alface, tomate e cenoura |
| 6 | Salada de Carne | 26.00 | carne, milho, rúcula, beterraba, alface, tomate e cenoura |
| 7 | Salada de Tilápia | 26.00 | tilápia, milho, rúcula, beterraba, alface, tomate e cenoura |
| 8 | Luís de Frango | 23.00 | frango, arroz, feijão, salada e molho |
| 9 | Luís de Carne | 28.00 | carne, arroz, feijão, salada e molho |
| 10 | Luís de Tilápia | 28.00 | tilápia, arroz, feijão, salada e molho |
| 11 | Barça de Frango | 30.00 | frango, arroz, salada, fritas, molho e anéis de cebola |
| 12 | Barça de Carne | 32.00 | carne, arroz, salada, fritas, molho e anéis de cebola |
| 13 | Barça de Tilápia | 30.00 | tilápia, arroz, salada, fritas, molho e anéis de cebola |

"Especial X" and "Prato Turbo" are removed (they are not in the desired list).

## Functional requirements

1. After the migration, `menu_items` in category "Pratos Principais" (id 1)
   contains exactly the 13 rows in the table above.
2. Each dish's description matches the description column in the table above.
3. Each dish's price matches the price column in the table above.
4. `dish_components` has a row for each component of each dish according to the
   component mapping below (see Data model and migrations).
5. The migration is safe to rerun against a DB that already has these dishes
   (idempotent via `INSERT IGNORE` / `INSERT ... ON DUPLICATE` where applicable).

## Non-functional requirements

- The migration must not affect data in the other categories.
- Must run without error via `php bin/migrate` inside the `web` container.

## User flows

- Admin/fresh install: after `bin/migrate`, the cashier and kitchen screens show
  the 13 canonical dishes with the canonical prices and components.

## API changes

Not applicable — no API surface changes.

## Data model and migrations

New migration: `common/migrations/009_dishes_default_menu.sql`.

Component mapping (component_id refers to "Adicionais" ids):

- Dish 1 (Prato do Dia): 25 (Macarrão), 22 (Molhos), 23 (Arroz Branco)
- Dish 2 (Picadinho da Alegria): 25 (Macarrão), 17 (Linguiça Fina), 16 (Filé de Carne), 22 (Molhos)
- Dish 3 (Parmegiana de Frango): 25 (Macarrão), 22 (Molhos), 15 (Filé de Frango)
- Dish 4 (Parmegiana de Carne): 25 (Macarrão), 22 (Molhos), 16 (Filé de Carne)
- Dish 5 (Salada de Frango): 21 (Salada), 15 (Filé de Frango)
- Dish 6 (Salada de Carne): 21 (Salada), 16 (Filé de Carne)
- Dish 7 (Salada de Tilápia): 21 (Salada), 14 (Filé de Tilápia)
- Dish 8 (Luís de Frango): 23 (Arroz Branco), 24 (Feijão Carioca), 21 (Salada), 22 (Molhos), 15 (Filé de Frango)
- Dish 9 (Luís de Carne): 23 (Arroz Branco), 24 (Feijão Carioca), 21 (Salada), 22 (Molhos), 16 (Filé de Carne)
- Dish 10 (Luís de Tilápia): 23 (Arroz Branco), 24 (Feijão Carioca), 21 (Salada), 22 (Molhos), 14 (Filé de Tilápia)
- Dish 11 (Barça de Frango): 23 (Arroz Branco), 21 (Salada), 26 (Fritas Individual), 22 (Molhos), 30 (Fritas com Anel Cebola), 15 (Filé de Frango)
- Dish 12 (Barça de Carne): 23 (Arroz Branco), 21 (Salada), 26 (Fritas Individual), 22 (Molhos), 30 (Fritas com Anel Cebola), 16 (Filé de Carne)
- Dish 13 (Barça de Tilápia): 23 (Arroz Branco), 21 (Salada), 26 (Fritas Individual), 22 (Molhos), 30 (Fritas com Anel Cebola), 14 (Filé de Tilápia)

The migration deletes the pre-existing "Pratos Principais" dishes and their
`dish_components`, then inserts the authoritative rows by explicit id. Rows are
inserted with `INSERT IGNORE` so only missing ids are added (idempotent for a
second run is not strictly needed because the delete happens each run, but the
explicit-id insert keeps ids stable for consumers).

## Architecture and affected components

Only seed data — no PHP changes. The migration is consumed by
`App\Database\MigrationRunner` (`src/Database/MigrationRunner.php`) via `bin/migrate`.

## Security considerations

No secrets, authentication, or authorization involved. The user-facing strings
(prato names/descriptions) remain in Portuguese per project convention.

## Backward compatibility

- Dish ids 1–13 are preserved for the 13 canonical dishes, so existing
  `order_items`/`dish_components`/`item_ingredients` references to those ids stay
  valid where the dish still exists. Rows removed ("Especial X", "Prato Turbo",
  old "Barça" id 11) may break references if referenced by historic orders; this
  is an accepted consequence of replacing default data and depends on whether the
  target DB has order data referencing them. For fresh default installs there is
  no impact.
- The Adicionais/categories ids are not changed.

## Acceptance criteria

1. `SELECT name, price FROM menu_items WHERE category_id = 1 ORDER BY id` returns
   the 13 rows (name and price) exactly as in the Proposed behavior table.
2. `SELECT COUNT(*) FROM menu_items WHERE category_id = 1` returns 13.
3. Non-"Pratos Principais" categories are unchanged (spot-check count of
   category_id = 2 still 17).
4. `dish_components` mapping for the 13 dishes matches the mapping table above.
5. `php bin/migrate` completes with "OK", and a second run reports
   "Nenhuma migração pendente".

## Implementation plan

1. Write `common/migrations/009_dishes_default_menu.sql`.
2. Run `php bin/migrate` in the `web` container.
3. Validate with SELECT queries.

## Testing and validation strategy

No automated test infrastructure exists for migrations in this project. Manual
verification via `docker compose exec` MySQL `SELECT` queries against the stated
acceptance criteria, plus running `bin/migrate` and observing output.

## Rollout and rollback

- Rollout: `bin/migrate` applies the new migration on the next run.
- Rollback: remove the row from the `migrations` table and manually restore the
  previous "Pratos Principais" rows; there is no automated rollback mechanism.

## Open questions

None.

## Task checklist

- [x] Create spec 006-dishes-default-menu
- [x] Write migration 009_dishes_default_menu.sql
- [x] Run bin/migrate and validate the 13 dishes + components

## Implementation log

- 2026-08-30: Spec drafted and approved per user request. Approach: new
  incremental migration that deletes divergent "Pratos Principais" rows and
  re-inserts the 13 canonical dishes by explicit id, plus their components.
- 2026-08-30: Removed the `item_ingredients` DELETE from the migration — the
  table did not exist in the target DB at apply time (only `dish_components`
  existed). Ingredients/recipes are out of scope for this spec.
- 2026-08-30: Migration applied and validated against the running DB; second
  run reports "Nenhuma migração pendente".

## Validation evidence

Ran (inside `web` container / against `restaurant` DB):

```
docker compose exec web php bin/migrate
```
Output: all files 001–009 marked `[OK]`, then `✓ Concluído.` A second run
output `✔ Nenhuma migração pendente.` → Acceptance criterion 5.

```
SELECT COUNT(*) FROM menu_items WHERE category_id=1;      → 13   (AC 2)
SELECT COUNT(*) FROM menu_items WHERE category_id=2;      → 17   (AC 3, unchanged)
SELECT id,name,price FROM menu_items WHERE category_id=1 ORDER BY id;
```
Returned exactly the 13 canonical rows with the expected names and prices
(Prato do Dia 20.00, Picadinho da Alegria 30.00, Parmegiana de Frango 25.00,
Parmegiana de Carne 30.00, Salada de Frango 20.00, Salada de Carne 26.00,
Salada de Tilápia 26.00, Luís de Frango 23.00, Luís de Carne 28.00,
Luís de Tilápia 28.00, Barça de Frango 30.00, Barça de Carne 32.00,
Barça de Tilápia 30.00) → AC 1.

Component mapping verified via JOIN query against `dish_components`; each of the
13 dishes has the expected components (e.g. Barça de Carne → Filé de Carne,
Salada, Molhos, Arroz Branco, Fritas Individual, Fritas com Anel Cebola)
→ AC 4.

All acceptance criteria satisfied. No automated test infrastructure exists for
migrations; verification was manual via SQL queries and runner output.
