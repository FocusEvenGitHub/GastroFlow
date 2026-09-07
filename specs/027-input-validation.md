# Spec 027 — Input validation (menu items, ingredients, settings, authentication)

## Metadata

- Status: Verified
- Created: 2026-09-06
- Updated: 2026-09-06
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Input validation" subsection: add dedicated validation where external input modifies application state; priority domains named are orders, menu items, ingredients, settings, authentication, users; "Validators validate input shape. Services enforce business rules."

Orders already has this (`OrderValidator`, spec 022, `Verified`). This spec covers the remaining named domains that actually have a reachable HTTP input surface today.

While investigating, confirmed a real, currently-broken feature relevant to scope: **`public/admin/ingredients.js` calls `/api/admin/ingredients` and `/api/admin/ingredients/{id}`, but `src/Routes.php` never registers these routes** — `IngredientController` exists but is completely unreachable. This was already flagged as an open, non-blocking question in `specs/000-project-baseline.md` ("is ingredient management reachable today, and if so, how?"). Confirmed by user decision (asked directly during this spec's planning): **wire the missing routes as part of this spec**, since validating an unreachable endpoint has no value on its own.

## Problem

Confirmed by direct reads:

1. **`src/Controllers/MenuController.php::store()`/`updateItem()`** (`:29-76`) validate inline, ad hoc: `store()` checks `name`/`price`/`category_name` are present and `price` is numeric; `updateItem()` checks the payload isn't empty and `price` (if present) is numeric. No length limits (`menu_items.name`/`categories.name` are `VARCHAR(100)` — `common/sql/001_schema.sql:21,12` — a longer value would fail at the DB layer with an unclean error, not a clean 400), no non-negative price check, no type check on `available`.
2. **`src/Controllers/IngredientController.php`** (`:11-63`): **not reachable at all** — no `/api/admin/ingredients*` route exists in `src/Routes.php` (confirmed: `grep -n "IngredientController" src/Routes.php` → no matches). `public/admin/ingredients.php`/`ingredients.js` actively call these endpoints (`ingredients.js:24,41,68,89`) — every such call 404s today. Where reachable in principle, validation is minimal: `store()` only checks `name`/`unit` are non-empty (no length checks against `ingredients.name VARCHAR(100)`/`unit VARCHAR(20)`/`category VARCHAR(50)` — `common/migrations/001_ingredients.sql:11-13`); `update()` has **zero validation** — `$ingredient->update($data)` runs directly on whatever the request body contains (scoped safely to `$fillable = ['name','unit','category']` at the Eloquent level, so no mass-assignment risk, but no shape/type/length checking at all).
3. **`src/Controllers/AdminController.php::updateSettings()`** (`:52-70`): accepts an arbitrary `settings` object and calls `Setting::setValue($key, $value)` for every key present, with no validation of value shape. The only settings actually read anywhere in the app (confirmed via `grep -rn "Setting::(get|set)Value"` across `src/`) are `restaurant_name`, `printer_ip`, `printer_port` (`src/Services/PrintService.php:53-55`). A non-numeric `printer_port` is silently accepted, then silently becomes `9100` via `(int) (Setting::getValue('printer_port') ?: 9100)`'s falsy-string fallback at print time (`PrintService.php:53`) — nothing tells the admin the value they saved was nonsensical.
4. **`src/Controllers/AuthController.php::login()`/`changePassword()`** (`:21-77`): check field *presence* (`isset()`) but not *type*. A non-string `username`/`password`/`current_password`/`new_password` (e.g. a JSON array or object in the request body) reaches `User::where('username', $data['username'])` or `password_verify($data['password'], ...)` unguarded — Eloquent's `where()` with a non-scalar value or `password_verify()` with a non-string argument raises a `TypeError`, which the production error handler (spec 012) sanitizes into a generic `500`, not the clean `400 VALIDATION_FAILED` a bad-shape request should get.
5. **`Users` has no HTTP input surface.** No `/api/admin/users*` route exists anywhere in `src/Routes.php`; the only way to create a user is `bin/create-admin`, a CLI script with its own prompt-based validation already covered by spec 015 (`Verified`). Nothing to add here — see Non-goals.
6. **`src/Controllers/DishController.php` is separately dead code**, unrelated to the ingredients routing gap above: no frontend file references `/api/admin/dishes/*` anywhere (confirmed via `grep -rn "api/admin/dishes" public/` — no matches); the live dish-component editing path is `/api/admin/items/{id}/components`, which routes to `MenuController::getComponents()`/`updateComponents()` (`src/Routes.php:64-65`), already wired and already covered by requirement 1 above. This spec does not touch `DishController.php` — flagged here only so it isn't confused with the ingredients gap.

## Goals

- Register `GET /api/admin/ingredients`, `POST /api/admin/ingredients`, `PUT /api/admin/ingredients/{id}`, `DELETE /api/admin/ingredients/{id}` in `src/Routes.php`, gated the same way as menu management (`admin`/`manager` — matching `docs/ROADMAP.md` spec 018's existing "menu management ... allow admin or manager" precedent, since ingredient management is the same operational domain).
- Introduce dedicated Valitron-based validators — `MenuItemValidator`, `IngredientValidator`, `SettingsValidator`, `AuthValidator` — mirroring `OrderValidator`'s existing shape and conventions (spec 022), and wire each into its controller in place of today's ad hoc inline checks.
- Every currently-valid request to these endpoints continues to succeed identically; only requests that were already malformed (wrong type, over length, negative price, unknown-shape settings value) newly receive a clean `400 VALIDATION_FAILED` (the same `ApiResponse::error()` shape `OrderController` already uses) instead of either silently succeeding with bad data or crashing into a generic `500`.

## Non-goals

- `DishController.php` — unreferenced dead code, not touched (Problem #6).
- No new `/api/admin/users*` endpoint — no request for user management over HTTP; `bin/create-admin`'s existing CLI validation (spec 015) is unaffected.
- No login throttling — explicitly deferred to its own future spec (spec 016's "Authentication hardening" log).
- No change to `Setting`'s underlying free-form key-value schema. Only the three currently-used keys (`restaurant_name`, `printer_ip`, `printer_port`) get shape rules; an unrecognized key in the `settings` payload still passes through to `Setting::setValue()` unchanged, preserving the deliberate schema-less design rather than freezing it to today's three keys.
- No business-rule validation (e.g., verifying a `printer_ip` is actually reachable, or that a `category_name` refers to an existing category) — those are already correctly handled as service-layer/DB concerns (`firstOrFail()`, `testPrint()`), per `CLAUDE.md`'s "Validators validate input shape. Services enforce business rules."

## Current behavior

See Problem #1–6 above for exact file:line citations of each gap.

## Proposed behavior

- **Routing (`src/Routes.php`)**: inside the existing `/api/admin` group (already `$jwt`-protected), add:
  ```php
  $group->get('/ingredients', [IngredientController::class, 'index'])->add($adminOrManager());
  $group->post('/ingredients', [IngredientController::class, 'store'])->add($adminOrManager());
  $group->put('/ingredients/{id}', [IngredientController::class, 'update'])->add($adminOrManager());
  $group->delete('/ingredients/{id}', [IngredientController::class, 'destroy'])->add($adminOrManager());
  ```
  matching `IngredientController`'s own route comments (`// POST /api/admin/ingredients`, etc.) and the exact method names already implemented.
- **`MenuItemValidator`** (`src/Validators/MenuItemValidator.php`): `validateCreate(array $data): bool` — `name` required, string, `lengthMax` 100; `category_name` required, string, `lengthMax` 100; `price` required, numeric, `min` 0 (rejects negative prices — a menu item cannot cost less than free); `description` optional, string, `lengthMax` 1000 ("a reasonable limit," matching spec 022's own documented precedent for values the roadmap doesn't itself specify — `description` is `TEXT` in the DB, no hard limit, but an unbounded value is not a reasonable menu description); `available` optional, must be boolean-ish if present (`is_bool` or the exact strings/ints the frontend actually sends — confirmed during implementation). `validateUpdate(array $data): bool` — same rules, all `optional` (only fields present in a PATCH are checked), plus rejecting a fully empty payload (preserving `updateItem()`'s existing `EMPTY_PAYLOAD` check).
- **`IngredientValidator`** (`src/Validators/IngredientValidator.php`): `validateCreate(array $data): bool` — `name` required, string, `lengthMax` 100; `unit` required, string, `lengthMax` 20; `category` optional, string, `lengthMax` 50. `validateUpdate(array $data): bool` — same fields, all `optional`, rejecting a fully empty payload.
- **`SettingsValidator`** (`src/Validators/SettingsValidator.php`): `validate(array $settings): bool` — iterates the given associative array; for each of the three known keys present (`restaurant_name`: string, `lengthMax` 100 to match `PrintService`'s printed-header usage; `printer_ip`: string, `lengthMax` 45 — enough for an IPv4 or hostname; `printer_port`: numeric, integer, `min` 1, `max` 65535), applies the matching rule; keys outside this known set are not rejected (Non-goals) but are still required to be scalar (string/int/float/bool), not an array/object, since `Setting::setValue()` (`src/Models/Setting.php`) is a flat key-value store.
- **`AuthValidator`** (`src/Validators/AuthValidator.php`): `validateLogin(array $data): bool` — `username` required, string, `lengthMax` 50 (matches `users.username VARCHAR(50)`); `password` required, string. `validatePasswordChange(array $data): bool` — `current_password` required, string; `new_password` required, string, `lengthMin` 8 (preserves `changePassword()`'s existing rule, moved into the validator).
- Each controller replaces its inline `isset()`/`is_numeric()` checks with a call to the matching validator method, returning `ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $validator->errors()])` on failure — the exact pattern `OrderController::store()` already uses (`src/Controllers/OrderController.php:40-43`).

## Functional requirements

1. `POST /api/admin/ingredients`/`PUT /api/admin/ingredients/{id}` are reachable (no longer 404) for a request carrying a valid JWT with `admin` or `manager` role.
2. A `POST /api/admin/items` (menu item) with `name` longer than 100 characters, or a negative `price`, is rejected with `400 VALIDATION_FAILED` before reaching `MenuService`/`MenuRepository`.
3. A `POST /api/admin/ingredients` with a `unit` longer than 20 characters is rejected with `400 VALIDATION_FAILED` before reaching `Ingredient::create()`.
4. A `PUT /api/admin/settings` body containing `{"settings": {"printer_port": "not-a-number"}}` is rejected with `400 VALIDATION_FAILED`, not silently stored.
5. A `POST /api/login` body with a non-string `username` (e.g. `{"username": ["a"], "password": "x"}`) is rejected with `400 VALIDATION_FAILED`, not a `500`.
6. Every example request already accepted by the current implementation (valid menu item, valid ingredient once routes exist, valid settings update, valid login) continues to succeed identically after this change.

## Non-functional requirements

Not applicable beyond what's already stated — no new performance/observability requirement.

## User flows

Not applicable — admin-only API validation; no new user-facing flow, only a previously-404ing admin page (Ingredients) starting to work as its own UI already assumes.

## API changes

- **New, previously-nonexistent routes** (now reachable, matching `IngredientController`'s already-implemented request/response shapes exactly — no change to those shapes, only to whether they're reachable):
  - `GET /api/admin/ingredients` → `200`, array of ingredients.
  - `POST /api/admin/ingredients` → `201` on success, `400 VALIDATION_FAILED` on bad shape.
  - `PUT /api/admin/ingredients/{id}` → `200` on success, `400 VALIDATION_FAILED` on bad shape, `404 INGREDIENT_NOT_FOUND` if missing.
  - `DELETE /api/admin/ingredients/{id}` → `200` on success, `404 INGREDIENT_NOT_FOUND` if missing.
- **Changed response for previously-unvalidated bad input** on existing endpoints (`POST`/`PATCH /api/admin/items*`, `PUT /api/admin/settings`, `POST /api/login`, `PATCH /api/admin/account/password`): a request shaped the way these new rules describe as invalid now gets `400 VALIDATION_FAILED` where it previously might have partially succeeded (e.g. an over-length name silently truncated or DB-erroring) or crashed into a generic `500`. No change to any already-valid request's response.

## Data model and migrations

Not applicable — no schema change; this spec only adds application-layer shape validation and route registration.

## Architecture and affected components

- New: `src/Validators/MenuItemValidator.php`, `src/Validators/IngredientValidator.php`, `src/Validators/SettingsValidator.php`, `src/Validators/AuthValidator.php`.
- Changed: `src/Routes.php` (register the four missing `/api/admin/ingredients*` routes).
- Changed: `src/Controllers/MenuController.php` (`store()`, `updateItem()` — use `MenuItemValidator`).
- Changed: `src/Controllers/IngredientController.php` (`store()`, `update()` — use `IngredientValidator`).
- Changed: `src/Controllers/AdminController.php` (`updateSettings()` — use `SettingsValidator`).
- Changed: `src/Controllers/AuthController.php` (`login()`, `changePassword()` — use `AuthValidator`).
- Unchanged: `src/Services/MenuService.php`, `src/Repositories/MenuRepository.php`, `src/Models/Ingredient.php`, `src/Services/PrintService.php` — business-rule/persistence logic untouched, per `CLAUDE.md`'s layering ("Validators validate input shape. Services enforce business rules.").
- Every new validator is autowired by PHP-DI into its controller's constructor (same pattern as `OrderController`'s existing `OrderValidator $validator` parameter) — no container definition needed.

## Security considerations

- The newly-wired `/api/admin/ingredients*` routes are gated by `$adminOrManager()` (`RoleMiddleware`) and sit inside the `$jwt`-protected `/api/admin` group from the moment they're registered — they are never reachable without a valid JWT and the right role, so wiring them up introduces no unauthenticated surface (consistent with spec 018's RBAC scope).
- `SettingsValidator`'s "must be scalar" rule for unrecognized keys is itself a light security measure: without it, a value like `{"settings": {"restaurant_name": {"$ne": null}}}` would previously have been passed straight into `Setting::setValue()` as an array/object, stored as an unexpected PHP-serialized-looking value — rejecting non-scalar values closes that, without needing to know every key in advance.
- No change to authentication/authorization mechanisms themselves (JWT verification, password hashing) — only the shape checks around their inputs.

## Backward compatibility

- Every request shape that succeeds today against `MenuController`, `AdminController::updateSettings()`, and `AuthController` continues to succeed identically — the new rules only reject inputs that were already wrong (over length, wrong type, negative price), which no legitimate existing consumer could have been relying on.
- `IngredientController`'s endpoints were completely unreachable before this spec (100% 404) — there is no existing consumer behavior to preserve; whatever contract `ingredients.js` already assumes (confirmed by reading it) is what gets honored once wired up.

## Acceptance criteria

- [ ] `GET/POST /api/admin/ingredients` and `PUT/DELETE /api/admin/ingredients/{id}` return `IngredientController`'s existing implemented behavior instead of `404`, for a request with a valid admin/manager JWT.
- [ ] `POST /api/admin/items` with `name` of 101 characters → `400 VALIDATION_FAILED`.
- [ ] `POST /api/admin/items` with `price: -5` → `400 VALIDATION_FAILED`.
- [ ] `POST /api/admin/ingredients` with `unit` of 21 characters → `400 VALIDATION_FAILED`.
- [ ] `PUT /api/admin/settings` with `{"settings": {"printer_port": "abc"}}` → `400 VALIDATION_FAILED`.
- [ ] `POST /api/login` with `{"username": ["a"], "password": "x"}` → `400 VALIDATION_FAILED`, not `500`.
- [ ] A valid `POST /api/admin/items` (name/price/category_name as before), a valid `POST /api/admin/ingredients` (name/unit), a valid `PUT /api/admin/settings`, and a valid `POST /api/login` all still succeed exactly as before, confirmed manually against the real dev DB.
- [ ] `vendor/bin/phpunit` passes, including new unit tests for each of the four new validators.

## Implementation plan

1. Register the four missing `/api/admin/ingredients*` routes in `src/Routes.php`, matching `IngredientController`'s existing method signatures and `IngredientController`'s own route-comment paths exactly.
2. Create `MenuItemValidator`; wire into `MenuController::store()`/`updateItem()`, replacing the existing inline checks.
3. Create `IngredientValidator`; wire into `IngredientController::store()`/`update()`.
4. Create `SettingsValidator`; wire into `AdminController::updateSettings()`.
5. Create `AuthValidator`; wire into `AuthController::login()`/`changePassword()`.
6. Add `tests/Unit/MenuItemValidatorTest.php`, `IngredientValidatorTest.php`, `SettingsValidatorTest.php`, `AuthValidatorTest.php`, mirroring the structure of any existing `OrderValidatorTest.php`.
7. Run the full suite; manually verify the newly-reachable ingredients endpoints and each rejection/acceptance case listed in Acceptance criteria against the real dev DB.

## Testing and validation strategy

This project has a real PHPUnit suite (`tests/Unit`, run via `docker compose exec web vendor/bin/phpunit`, also run in CI). Each acceptance criterion maps to:

- New validator unit tests (pure, no DB) for each of the four new classes — valid input passes, each documented invalid shape fails with the expected error key.
- Manual `curl` calls against the real running dev stack (`docker compose up -d`, already running) for the routing fix and each end-to-end 400/200 case listed in Acceptance criteria — actually run, not assumed, per `CLAUDE.md`'s rule against claiming a test passed without running it. Any manual test order/menu-item/ingredient created for verification will be cleaned up afterward (ingredient/menu item deleted, or order cancelled) so it doesn't pollute real operational data, the same way spec 026's validation did.

## Rollout and rollback

Standard code change on branch `019`. Rollback is a plain `git revert` — no migration, no data change. The only behavior change with any real rollback consideration is the newly-reachable `/api/admin/ingredients*` routes; reverting simply makes them 404 again, back to today's (broken) state.

## Open questions

None blocking. The one real open question from planning — whether to fix the missing ingredients routes in this spec, in a separate spec, or not at all — was resolved directly with the user before writing this spec: fix them here, since validating an unreachable endpoint has no value.

## Task checklist

- [x] Step 1 — register missing `/api/admin/ingredients*` routes
- [x] Step 2 — `MenuItemValidator`, wired into `MenuController`
- [x] Step 3 — `IngredientValidator`, wired into `IngredientController`
- [x] Step 4 — `SettingsValidator`, wired into `AdminController`
- [x] Step 5 — `AuthValidator`, wired into `AuthController`
- [x] Step 6 — unit tests for all four validators
- [x] Step 7 — full suite + manual end-to-end validation

## Implementation log

- **Status set to `Approved` then immediately `In Progress`** on `/spec-implement` invocation, per the skill's rule that an explicit invocation on a blocking-question-free `Draft` spec counts as approval.
- **Re-investigation before editing found a real constraint the spec's `SettingsValidator` design didn't account for**: `Setting::setValue(string $key, ?string $value): void` (`src/Models/Setting.php:35`) is declared `?string`, and `AdminController.php` has `declare(strict_types=1)`. Since PHP enforces scalar parameter types strictly based on the *calling* file's `strict_types` declaration, **any non-string, non-null value passed to `Setting::setValue()` from `AdminController::updateSettings()` already throws an uncaught `TypeError` today** — not just for unrecognized keys, and not only the "must be scalar" the spec described. The spec's plan to accept "scalar (string/int/float/bool)" for unrecognized keys would let an `int`/`bool`/`float` value pass validation only to crash downstream with the same `TypeError` this spec is meant to close.
  - **Deviation**: `SettingsValidator` requires every value (known key or not) to be a `string` or `null`, not merely "scalar," matching `Setting::setValue()`'s actual signature. This is strictly a compatibility-safe tightening: the real frontend (`settings.js`) only ever sends strings for the three known keys today, so no currently-valid request is affected — it only turns a pre-existing uncaught `500` into a clean `400 VALIDATION_FAILED` for a non-string value, for *any* key, not only the three known ones.
- **`AuthController` is not autowired** — it's constructed manually in two route closures in `src/Routes.php` (`/api/login`, `PATCH /api/admin/account/password`) because it needs the JWT `$secret` string, which isn't a DI-registered service. Adding `AuthValidator` as a constructor dependency required updating both `new AuthController($secret)` call sites to `new AuthController($secret, new AuthValidator())` — not listed in the spec's Architecture section, found during re-investigation (the same category of gap as spec 026's `PrintOrderJob.php` discovery).
- **Design decision — consolidating `AuthController`'s ad hoc error codes into `VALIDATION_FAILED`**: the spec's Proposed behavior described `AuthValidator` methods but didn't explicitly say whether `login()`'s existing `MISSING_CREDENTIALS` and `changePassword()`'s `MISSING_REQUIRED_FIELDS`/`PASSWORD_TOO_SHORT` codes should survive alongside the new type checks, or be absorbed into the single `VALIDATION_FAILED` response `OrderController` already uses for the equivalent presence+shape+length checks. Chose to absorb them (both methods now do a single `$this->validator->validate*($data)` call covering presence, type, and length together), matching `OrderValidator`'s own precedent exactly and staying consistent with spec 024's already-`Verified` API error standardization — introducing new one-off codes for "field missing"/"field too short" would have cut against that completed work. This does change the specific `code` value returned for a request that was already rejected before this spec (still `400`, still an error, just a different `code` string) — called out explicitly here since it's a real, if narrow, response-shape change, not hidden as a pure bug fix.
- **`MenuItemValidator::validateUpdate()`/`IngredientValidator::validateUpdate()` do not enforce the "reject a fully empty payload" rule internally** (unlike what the spec's Proposed behavior literally described for each). Implemented instead as the controller's own `EMPTY_PAYLOAD` check, run *before* calling the validator — cleaner than encoding "the whole array is empty" as a fake per-field rule inside a Valitron validator, and it preserves the existing, more specific `EMPTY_PAYLOAD` code (`MenuController::updateItem()` already had this; the same check was added to `IngredientController::update()`, which previously had no such guard at all).

## Validation evidence

All commands actually run against the real `docker compose` stack (`db`, `web`, `print-worker`, already up).

**Syntax check** (`docker compose exec -T web php -l <file>`), every changed/new file — all "No syntax errors detected": `src/Routes.php`, `src/Validators/{MenuItemValidator,IngredientValidator,SettingsValidator,AuthValidator}.php`, `src/Controllers/{MenuController,IngredientController,AdminController,AuthController}.php`, and the four new `tests/Unit/*ValidatorTest.php` files.

**Full automated suite** (`docker compose exec -T web vendor/bin/phpunit`):
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.33
...
OK (104 tests, 147 assertions)
```
(Was 64 tests/102 assertions before this spec — the 40 new tests are the four new validator test files: 13 `MenuItemValidatorTest` + 9 `IngredientValidatorTest` + 9 `SettingsValidatorTest` + 9 `AuthValidatorTest`.) Maps to Acceptance criterion "vendor/bin/phpunit passes, including new unit tests for each of the four new validators."

**Manual end-to-end checks**, dev DB, using two temporary accounts created via `bin/create-admin` solely for this validation (`spec027_temp_validator`/manager, `spec027_temp_admin`/admin) and deleted immediately afterward via a throwaway script (`common/_tmp_cleanup_validation_users.php`, run once then removed — confirmed gone via `git status`):

- **AC "ingredients routes reachable"**: `GET /api/admin/ingredients` with a manager JWT → `HTTP 200` (was `404` before this spec). `POST /api/admin/ingredients` with `{"name":"Spec027 Temp Ingredient","unit":"g"}` → `201`-equivalent success body with a real `id`. `PUT /api/admin/ingredients/{id}` with a valid body → `200`, updated fields returned. `DELETE /api/admin/ingredients/{id}` → `200`, `{"success":true}` (also served as this test's own cleanup).
- **AC "menu item name over 100 chars → 400"**: `POST /api/admin/items` with a 101-char `name` → `HTTP 400`, `{"code":"VALIDATION_FAILED","messages":{"name":["Name must not exceed 100 characters"]}}`.
- **AC "menu item negative price → 400"**: `POST /api/admin/items` with `price: -5` → `HTTP 400`, `{"code":"VALIDATION_FAILED","messages":{"price":["Price must be at least 0"]}}`.
- **AC "ingredient unit over 20 chars → 400"**: `POST /api/admin/ingredients` with a 21-char `unit` → `HTTP 400`, `{"code":"VALIDATION_FAILED","messages":{"unit":["Unit must not exceed 20 characters"]}}`. Also re-confirmed on `PUT .../ingredients/{id}` with the same oversized `unit`.
- **AC "settings printer_port non-numeric → 400"**: `PUT /api/admin/settings` with `{"settings":{"printer_port":"not-a-number"}}` → `HTTP 400`, `{"code":"VALIDATION_FAILED","messages":{"printer_port":["...must be numeric","...must be an integer","...must be at least 1","...must be no more than 65535"]}}` (Valitron reports every failed rule for the field, not just the first).
- **AC "login with non-string username → 400, not 500"**: `POST /api/login` with `{"username":["a"],"password":"x"}` → `HTTP 400`, `{"code":"VALIDATION_FAILED",...}` — confirmed not a `500`.
- **AC "valid requests still succeed"**: valid `POST /api/admin/items` (name/price/category_name) → `201`, created then deleted (`DELETE /api/admin/items/{id}` → `200`) to avoid leaving test data in the menu. Valid `POST /api/admin/ingredients` (used above) → success, deleted. Valid `PUT /api/admin/settings` with `{"printer_port":"9100"}` (the pre-existing value, confirmed via `GET /api/admin/settings` before the write) → `200`, no actual data change. Valid `POST /api/login` with the temporary account's real credentials → `200` with a `token` in the body.

**What was not separately re-tested**: `changePassword()`'s new validation path (`AuthValidator::validatePasswordChange()`) was exercised only by its unit tests (`AuthValidatorTest::testValidPasswordChangePasses`/`testPasswordChangeWithShortNewPasswordFails`/`testPasswordChangeWithNonStringNewPasswordFails`), not manually via a live `PATCH /api/admin/account/password` call — doing so would have required actually changing the temporary test account's password mid-validation for no additional signal beyond what the unit tests already cover for this pure-function validator.

All acceptance criteria have direct evidence above, except the `changePassword()` manual case, which is covered by unit tests only (noted above, not hidden).
