# Spec 007 — Empanado protein for Prato do Dia and Parmesan-style dishes

## Metadata

- Status: Approved
- Created: 2026-08-30
- Updated: 2026-08-30
- Owner: GastroFlow
- Related issue:
- Related branch:

## Context

The kitchen mode ("Cozinha") aggregates pending order items by the
`food_category` of a dish's components and displays a summary grouped by
category (`protein`, `grain`, `vegetable`, `sauce`, `side`, `other`). The
kitchen staff needs the protein of "Prato do Dia" and the parmesan-style dishes
("Parmegiana de Frango" / "Parmegiana de Carne") to be presented as breaded
protein ("empanado") rather than "filé".

## Problem

Confirmed against the running DB:

- "Prato do Dia" (id 1) is assigned "Frango Empanado" (id 64) as its protein
  component, but that item's `food_category` is `NULL`, so it does not appear
  under the "Proteínas" group in the kitchen summary.
- "Parmegiana de Frango" (id 3) and "Parmegiana de Carne" (id 4) use "Filé de
  Frango" (15) / "Filé de Carne" (16) as their protein — kitchen wants
  "empanado" instead.
- There is no "Carne Empanada" item in the "Adicionais" category to swap for the
  beef parmesan dish.

## Goals

- Ensure "Frango Empanado" is categorized as `protein` so it shows under
  "Proteínas" in kitchen mode.
- Add a "Carne Empanada" item to "Adicionais" categorized as `protein`.
- Assign the empanado protein components:
  - Prato do Dia → Frango Empanado
  - Parmegiana de Frango → Frango Empanado (replacing Filé de Frango)
  - Parmegiana de Carne → Carne Empanada (replacing Filé de Carne)

## Non-goals

- Not changing the other dishes' components or the ESC/POS printing flow.
- Not modifying orders/order_items data.
- Not touching dish prices or descriptions of the parmegianas.

## Current behavior

Confirmed in code: `src/Services/KitchenService.php` groups dish components by
their `food_category` (skipping components with a NULL `food_category`).
`src/Models/MenuItem.php` exposes `components()` via `dish_components`.

Confirmed in DB: dish 1 has component 64 (Frango Empanado, `food_category`
`NULL`); dishes 3/4 have components 15/16 (Filé de Frango/Carne, `food_category`
`protein`).

## Proposed behavior

- `menu_items.food_category = 'protein'` for both "Frango Empanado" and the new
  "Carne Empanada".
- New "Adicionais" item "Carne Empanada" (price 15.00, matching Filé de Carne).
- `dish_components`:
  - dish 1 (Prato do Dia): protein = Frango Empanado
  - dish 3 (Parmegiana de Frango): protein = Frango Empanado
  - dish 4 (Parmegiana de Carne): protein = Carne Empanada
- Kitchen food summary shows these proteins under the `protein` group.

## Functional requirements

1. "Frango Empanado" has `food_category = 'protein'`.
2. A "Carne Empanada" item exists in category "Adicionais" with
   `food_category = 'protein'` and price 15.00.
3. Dish 3's protein component is "Frango Empanado" (not "Filé de Frango").
4. Dish 4's protein component is "Carne Empanada" (not "Filé de Carne").
5. Dish 1's protein component is "Frango Empanado".
6. The migration is idempotent (safe to rerun without duplicates).

## Non-functional requirements

- Must run cleanly via `php bin/migrate`.
- Must not affect other categories or dishes.

## User flows

- Kitchen: opening the kitchen screen, "Prato do Dia", "Parmegiana de Frango"
  and "Parmegiana de Carne" appear (through their components) under "Proteínas".

## API changes

Not applicable — no API surface change; the kitchen food-summary endpoint already
reflects `dish_components` + `food_category`.

## Data model and migrations

New migration: `common/migrations/010_empanado_protein.sql`.

- `UPDATE menu_items SET food_category='protein' WHERE name='Frango Empanado'`.
- `INSERT` "Carne Empanada" if absent.
- Swap/ensure the protein `dish_components` rows for dishes named
  "Prato do Dia", "Parmegiana de Frango", "Parmegiana de Carne".

## Architecture and affected components

Only seed/configuration data. `KitchenService`/`MenuItem` already support the
desired behavior; no PHP change required.

## Security considerations

No secrets, auth, or input validation involved. User-facing names remain in
Portuguese.

## Backward compatibility

Additive for "Carne Empanada". Swaps only the protein component of the three
targeted dishes; all other components unchanged.

## Acceptance criteria

1. `SELECT food_category FROM menu_items WHERE name='Frango Empanado'` → `protein`.
2. `SELECT id, food_category FROM menu_items WHERE name='Carne Empanada'`
   returns one row with `food_category = protein`.
3. `dish_components` for dish "Parmegiana de Frango" contains "Frango Empanado"
   and no longer "Filé de Frango".
4. `dish_components` for dish "Parmegiana de Carne" contains "Carne Empanada"
   and no longer "Filé de Carne".
5. `dish_components` for dish "Prato do Dia" contains "Frango Empanado".
6. Rerunning `bin/migrate` reports "Nenhuma migração pendente".
7. Kitchen food-summary groups include these items under `protein`.

## Implementation plan

1. Write `common/migrations/010_empanado_protein.sql`.
2. Run `php bin/migrate`.
3. Validate with SQL SELECTs and the kitchen summary endpoint.

## Testing and validation strategy

No automated test infrastructure exists for migrations here. Manual verification
via `docker compose exec` MySQL SELECTs and the `/api/kitchen/food-summary`
endpoint.

## Rollout and rollback

- Rollout: `bin/migrate`.
- Rollback: manual SQL to restore prior protein components and remove "Carne
  Empanada"; no automated rollback mechanism.

## Open questions

None.

## Task checklist

- [x] Create spec 007-empanado-protein
- [x] Write migration 010_empanado_protein.sql
- [x] Run bin/migrate and validate kitchen protein display

## Implementation log

- 2026-08-30: Spec created; approach: category fix + new "Carne Empanada" item +
  per-dish protein component swap via a new incremental migration.
- 2026-08-30: Migration applied and validated. "Carne Empanada" got id 65.

## Validation evidence

Ran `docker compose exec web php bin/migrate` → `010_empanado_protein.sql [OK]`;
second run reports "Nenhuma migração pendente" (AC 6).

```
SELECT id,name,price,food_category FROM menu_items
WHERE name IN ('Frango Empanado','Carne Empanada');
```
→ 64 Frango Empanado 13.00 protein; 65 Carne Empanada 15.00 protein (AC 1, AC 2).

```
SELECT ... FROM dish_components ... WHERE dish_id IN (1,3,4) AND food_category='protein'
```
→ 1 Prato do Dia → Frango Empanado; 3 Parmegiana de Frango → Frango Empanado;
4 Parmegiana de Carne → Carne Empanada (AC 3, AC 4, AC 5).

```
SELECT COUNT(*) FROM dish_components ... WHERE dish_id IN (3,4)
AND c.name IN ('Filé de Frango','Filé de Carne');
```
→ 0 (no filé protein left on the parmesan dishes).

No automated migration-test infrastructure exists; verified manually via SQL.
The kitchen endpoint `/api/kitchen/food-summary` groups by food_category, so
these items now appear under "Proteínas".
