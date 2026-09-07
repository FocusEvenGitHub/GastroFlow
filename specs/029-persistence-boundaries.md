# Spec 029 — Persistence boundaries: IngredientRepository + IngredientService

## Metadata

- Status: Verified
- Created: 2026-09-07
- Updated: 2026-09-07
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Persistence boundaries" subsection: HTTP concerns and persistence logic should remain separated where useful; preferred direction `Controller → Service → Repository/Model`; repositories should exist when they provide a useful persistence/domain boundary; do not introduce empty abstraction layers only for architectural symmetry.

This is the last unaddressed `v1.7.0` subsection — specs 019–028 (this branch) covered every other one (order numbering, lifecycle, money, historical snapshots, validation, error standardization, query performance, pricing domain, controller responsibilities). `docs/architecture.md`'s `Layers under src/` table (updated by spec 028) already names this exact gap: *"`Dish`/`Ingredient` controllers call Eloquent models directly — no repository layer for them (still true for `Ingredient`, now that it's reachable — tracked as the roadmap's next `v1.7.0` item, 'Persistence boundaries')."*

## Problem

Confirmed by direct reads of `src/Controllers/IngredientController.php` (current state, after spec 027's validation wiring): every method calls `App\Models\Ingredient` directly —

- `index()` (`:19-24`): `Ingredient::orderBy('category')->orderBy('name')->get()`.
- `store()` (`:27-41`): `Ingredient::create([...])`.
- `update()` (`:44-62`): `Ingredient::findOrFail((int)$args['id'])`, then `$ingredient->update($data)`.
- `destroy()` (`:65-75`): `Ingredient::findOrFail((int)$args['id'])`, then `$ingredient->delete()`.

This is the only controller among the four reachable, non-Order/non-Menu admin domains left with zero persistence boundary. By contrast:
- `MenuController` → `MenuService` → `MenuRepository` (`src/Services/MenuService.php`, `src/Repositories/MenuRepository.php`).
- `OrderController` → `OrderService` → `OrderRepository`.
- `IngredientController` → *(nothing)* → `Ingredient` model, directly, in the controller.

`DishController.php` has the same gap but is separately dead code (spec 027's Problem #6, spec 028's `docs/architecture.md` note) — not part of this spec, since introducing a persistence layer for an unreachable controller would be exactly the kind of layer the roadmap says not to add "only for architectural symmetry."

## Goals

- Introduce `src/Repositories/IngredientRepository.php`, encapsulating the four Eloquent calls above.
- Introduce `src/Services/IngredientService.php`, a thin pass-through to the repository — mirroring `MenuService`'s exact structure (every one of `MenuService`'s methods is a one-line delegation to `MenuRepository`, e.g. `addItem(array $data): MenuItem { return $this->menuRepo->addItem($data); }`), not inventing a different pattern for this one domain.
- `IngredientController` depends on `IngredientService` instead of `App\Models\Ingredient` directly.
- Zero behavior change: identical routes, request/response shapes, status codes, and `ModelNotFoundException` → `404 INGREDIENT_NOT_FOUND` handling (the exception still propagates up to the controller, which still catches it — matching `MenuController`'s existing pattern of catching `ModelNotFoundException` at the controller, not inside the repository).

## Non-goals

- No change to `DishController.php` — stays dead code, untouched, per Problem above.
- No new validation — `IngredientValidator` (spec 027) already covers input shape; this spec only relocates *persistence* calls, not validation.
- No change to the `ingredients` table or `Ingredient` model's `$fillable`/casts.
- No change to any route, request shape, or response shape.
- Not adding a Service/Repository layer to any other still-direct-to-Eloquent controller (e.g. `KitchenController`, `ReportController`'s use of raw query builders) — those are separate, unreviewed cases outside this spec's named scope (`IngredientController` is the one the roadmap and `docs/architecture.md` explicitly name).

## Current behavior

See Problem above for exact file:line citations. No test file references `IngredientController` directly with mocked dependencies (confirmed via `grep -rn "IngredientController" tests/` — no matches; `tests/Unit/IngredientValidatorTest.php` tests the validator only, not the controller).

## Proposed behavior

- **`src/Repositories/IngredientRepository.php`**:
  ```php
  public function getAll(): \Illuminate\Support\Collection
  {
      return Ingredient::orderBy('category')->orderBy('name')->get();
  }

  public function create(array $data): Ingredient
  {
      return Ingredient::create([
          'name' => $data['name'],
          'unit' => $data['unit'],
          'category' => $data['category'] ?? null,
      ]);
  }

  public function update(int $id, array $data): Ingredient
  {
      $ingredient = Ingredient::findOrFail($id);
      $ingredient->update($data);
      return $ingredient;
  }

  public function delete(int $id): void
  {
      $ingredient = Ingredient::findOrFail($id);
      $ingredient->delete();
  }
  ```
  (`findOrFail()` still throws `Illuminate\Database\Eloquent\ModelNotFoundException` on a missing id — not caught here, exactly like `MenuRepository::updateItem()`/`deleteItem()`.)
- **`src/Services/IngredientService.php`**: one-line delegation per method to `IngredientRepository`, mirroring `MenuService`:
  ```php
  public function getAll(): \Illuminate\Support\Collection { return $this->ingredientRepo->getAll(); }
  public function create(array $data): Ingredient { return $this->ingredientRepo->create($data); }
  public function update(int $id, array $data): Ingredient { return $this->ingredientRepo->update($id, $data); }
  public function delete(int $id): void { $this->ingredientRepo->delete($id); }
  ```
- **`IngredientController`**: constructor gains `IngredientService $ingredientService` alongside the existing `IngredientValidator $validator`; each method calls the service instead of `Ingredient::` directly; the existing `try { ... } catch (ModelNotFoundException $e) { 404 }` blocks in `update()`/`destroy()` stay exactly where they are (in the controller), just wrapping a service call instead of a direct model call.

## Functional requirements

1. `GET /api/admin/ingredients` returns the same JSON array, same ordering (`category`, then `name`), as before this change.
2. `POST /api/admin/ingredients` with a valid body creates a row identically (same fields persisted) and returns the same `201` response shape.
3. `PUT /api/admin/ingredients/{id}` on an existing id updates identically and returns the same `200` response shape; on a missing id, still returns `404 INGREDIENT_NOT_FOUND`.
4. `DELETE /api/admin/ingredients/{id}` on an existing id still deletes and returns `{"success":true}`; on a missing id, still returns `404 INGREDIENT_NOT_FOUND`.
5. `IngredientController` no longer imports or references `App\Models\Ingredient` directly.

## Non-functional requirements

Not applicable — pure code organization, no performance/security/observability change.

## User flows

Not applicable — no user-facing behavior change; `public/admin/ingredients.js` calls the same URLs regardless of backend layering.

## API changes

Not applicable — no route, request, or response shape changes.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

- New: `src/Repositories/IngredientRepository.php`, `src/Services/IngredientService.php`.
- Changed: `src/Controllers/IngredientController.php` (constructor + all four methods delegate to `IngredientService`).
- Changed: `docs/architecture.md`'s `Layers under src/` table (`Repositories/`, `Services/` rows — `Ingredient` added to both; the `Repositories/` row's gap note, which currently names this exact gap, updated to reflect it's closed).
- Unchanged: `src/Models/Ingredient.php`, `src/Validators/IngredientValidator.php`, `src/Routes.php` (all four controllers/services/repositories in this app are autowired by PHP-DI — no container definition needed for the new classes, same as `MenuService`/`MenuRepository` today).

## Security considerations

Not applicable — no new input surface, no RBAC change; `/api/admin/ingredients*` stays behind the same `$jwt` + `$adminOrManager()` guard from spec 027, untouched by this spec.

## Backward compatibility

Fully preserved — no route, request, or response changes; a pure internal refactor.

## Acceptance criteria

- [ ] `src/Repositories/IngredientRepository.php` and `src/Services/IngredientService.php` exist with the four methods described above.
- [ ] `IngredientController` no longer contains `use App\Models\Ingredient;` or any `Ingredient::` call.
- [ ] `GET /api/admin/ingredients` with a valid admin/manager JWT returns the same array shape as before this change (manually confirmed against the real dev DB).
- [ ] `POST /api/admin/ingredients` with a valid body creates a row and returns `201` with the same shape as before.
- [ ] `PUT /api/admin/ingredients/{id}` on a nonexistent id still returns `404 INGREDIENT_NOT_FOUND`.
- [ ] `DELETE /api/admin/ingredients/{id}` on a nonexistent id still returns `404 INGREDIENT_NOT_FOUND`; on an existing id, deletes it and returns `{"success":true}`.
- [ ] `vendor/bin/phpunit` passes (no existing test references `IngredientController`, confirmed rather than assumed).
- [ ] `docs/architecture.md`'s `Repositories/`/`Services/` rows list `Ingredient` and no longer describe it as missing a repository layer.

## Implementation plan

1. Create `IngredientRepository` with the four methods.
2. Create `IngredientService`, delegating to the repository.
3. Update `IngredientController`'s constructor and four methods to use the service; remove the direct `Ingredient` model import/calls.
4. Update `docs/architecture.md`'s `Layers under src/` table.
5. Run the full test suite; manually verify all four ingredient endpoints (success and 404 cases) against the real dev DB.

## Testing and validation strategy

This project has a real PHPUnit suite, run via `docker compose exec web vendor/bin/phpunit`. No existing test references `IngredientController`, so the suite is expected to pass unchanged — confirmed by actually running it. Manual `curl` checks against the real running dev stack (already up) cover each acceptance criterion, using a temporary admin/manager account created via `bin/create-admin` and deleted afterward, the same approach used in specs 026–028's validation.

## Rollout and rollback

Standard code change on branch `019`. Rollback is a plain `git revert` — no migration, no data change, no route change.

## Open questions

None blocking. This is the roadmap's last-named `v1.7.0` gap and the pattern to follow (`MenuService`/`MenuRepository`) already exists in the codebase, so there's no ambiguity about shape.

## Task checklist

- [x] Step 1 — create `IngredientRepository`
- [x] Step 2 — create `IngredientService`
- [x] Step 3 — wire `IngredientController` to the service
- [x] Step 4 — update `docs/architecture.md`
- [x] Step 5 — full suite + manual end-to-end validation

## Implementation log

- **Status set to `Approved` then immediately `In Progress`** on `/spec-implement` invocation, per the skill's rule that an explicit invocation on a blocking-question-free `Draft` spec counts as approval.
- **Deviation from the literal `Proposed behavior` code sample for `update()`**: the sample showed `IngredientController::update()` calling `$this->ingredientService->update($id, $data)` directly, wrapped in a single try/catch — but the *original* `IngredientController::update()` (before this spec) checked existence (`findOrFail`) **before** the `EMPTY_PAYLOAD`/`VALIDATION_FAILED` checks, not after. Implementing the sample literally would have silently reordered these checks — for the edge case of a request with both a nonexistent id *and* an empty/invalid payload, the response would change from `404 INGREDIENT_NOT_FOUND` to `400 EMPTY_PAYLOAD`/`VALIDATION_FAILED`. Caught this while re-investigating the current file before editing (skill step 5) — the spec's own Goals/Functional requirements promise "zero behavior change," which the literal sample would have broken in this one narrow case. Fixed by adding `IngredientRepository::findOrFail()`/`IngredientService::findOrFail()` (not in the original plan) and calling it as a separate pre-check in the controller, restoring the exact original order: existence → empty-payload → validation → update. `destroy()` needed no such fix (its check order was already a single existence-check-then-action, unaffected by the refactor).

## Validation evidence

All commands actually run against the real `docker compose` stack (`db`, `web`, `print-worker`, already up).

**Syntax check** (`docker compose exec -T web php -l <file>`) — all "No syntax errors detected": `src/Repositories/IngredientRepository.php`, `src/Services/IngredientService.php`, `src/Controllers/IngredientController.php`.

**Full automated suite** (`docker compose exec -T web vendor/bin/phpunit`):
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.33
...
OK (104 tests, 147 assertions)
```
Unchanged from before this spec (104/147) — confirms no test referenced `IngredientController` directly.

**Manual end-to-end checks**, dev DB, using one temporary manager account created via `bin/create-admin` (`spec029_temp_manager`) solely for this validation and deleted immediately afterward via a throwaway script (`common/_tmp_cleanup_validation_users.php`, run once then removed — confirmed gone via `git status`):

- **AC "GET returns same shape"**: `HTTP 200`, array of 13 real ingredients (id/name/unit/category), same shape as before.
- **AC "POST creates identically, 201"**: `HTTP 201`, `{"name":"Spec029 Temp Ingredient","unit":"g","category":null,"id":15}`.
- **AC "PUT on nonexistent id → 404"**: `HTTP 404`, `{"code":"INGREDIENT_NOT_FOUND"}` — also re-confirmed with a *simultaneously empty* payload (`{}`) on the same nonexistent id, still `404` (not `400 EMPTY_PAYLOAD`), proving the original check-order (existence before payload/shape checks) survived the refactor — this is exactly the edge case flagged in the Implementation log's deviation entry.
- **AC "PUT on existing id updates identically"**: `HTTP 200`, `{"id":15,"name":"Spec029 Temp Ingredient Updated","unit":"g","category":null}`.
- **AC "DELETE on nonexistent id → 404"**: `HTTP 404`, `{"code":"INGREDIENT_NOT_FOUND"}`.
- **AC "DELETE on existing id deletes, 200"**: `HTTP 200`, `{"success":true}` (also served as this test's own cleanup for the temp ingredient created above).
- **AC "no `App\Models\Ingredient` reference in `IngredientController`"**: confirmed via the diff (`git diff -- src/Controllers/IngredientController.php`) — `use App\Models\Ingredient;` removed, no `Ingredient::` call remains.
- **AC "`docs/architecture.md`'s `Repositories/`/`Services/` rows list `Ingredient`"**: confirmed via the diff (`git diff -- docs/architecture.md`) — both rows updated.

All acceptance criteria have direct evidence above.
