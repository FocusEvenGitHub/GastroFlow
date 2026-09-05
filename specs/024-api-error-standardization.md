# Spec 024 — API error standardization

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "API error standardization" subsection: create one consistent API error format (`{"success": false, "error": "...", "code": "..."}`) instead of each controller inventing its own.

## Problem

Full inventory of every controller and middleware (`src/Controllers/*.php`, `src/Middleware/JwtMiddleware.php`, `src/Middleware/RoleMiddleware.php`) confirms the roadmap's complaint is accurate and, in one case, hides an actual status-code bug, not just a cosmetic inconsistency:

1. **No two controllers use the same error shape.** `AdminController`/`MenuController`/`OrderController` each have their own private `errorResponse()` method (identical implementation, copy-pasted three times) used only for the generic uncaught-`\Throwable` case — but every one of their *specific*, anticipated errors (missing fields, not-found, conflict) still uses an ad hoc `json_encode(['error' => '...'])` with no `success`/`code` at all. `AuthController`, `IngredientController`, `DishController`, `KitchenController` have no error helper of any kind. `ReportController` has a `json()` helper for *success* responses only. `JwtMiddleware`/`RoleMiddleware` (401/403) use a bare `{'error': message}` too, so a 401 can't even be told apart programmatically as "no token" vs. "expired" vs. "invalid."
2. **A real bug, not just inconsistency:** `DishController::show()`/`::update()` and `IngredientController::update()`/`::destroy()` call `findOrFail()` with no surrounding `try/catch` at all — the resulting `ModelNotFoundException` bubbles uncaught to `App.php`'s global error handler (`src/App.php:58-104`). That handler's `$isHttpSpecialized = $exception instanceof \Slim\Exception\HttpSpecializedException` is `false` for Eloquent's `ModelNotFoundException`, so `$statusCode` defaults to `500`, not `404` — a nonexistent dish/ingredient today returns `500 Internal Server Error` (with the confusing "Erro interno do servidor" message in production, or a raw stack trace in debug mode) instead of a clean `404`.
3. **`App.php`'s global handler (confirmed by reading it in full) already produces exactly the target shape** for the generic, unanticipated-exception case: `{"success": false, "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR"}` in production, full details in debug mode — logged the same way the three duplicated `errorResponse()` methods do. Those three methods are therefore fully redundant with behavior the app already has centrally, not a necessary pattern to preserve and multiply further.
4. **`src/Controllers/DishController.php:10`** has a dead, self-contradicting import: `use Illuminate\Support\Facades\DB;  // Actually use Illuminate\Database\Capsule\Manager as DB` — a wrong-namespace Laravel Facade import (this project never uses Facades, per `CLAUDE.md`'s confirmed stack) that is never referenced anywhere in the file, with its own comment admitting it's wrong. Found while reading this exact file for its error handling; not otherwise related to this spec's core scope, but too small and too directly in-scope to leave for a separate spec.

## Goals

- One shared helper produces every *specific*, anticipated error response (validation, not-found, conflict, unauthorized, forbidden) across every controller and the two auth middlewares, in the `{"success": false, "error": "...", "code": "..."}` shape.
- Every specific error site gets a real, resource/case-appropriate `code` — not a single generic one reused everywhere, which would defeat the purpose of a machine-readable code.
- Fix the real `DishController`/`IngredientController` 404-vs-500 bug (Problem, point 2) as part of giving those controllers their first explicit error handling.
- Remove the three duplicated `errorResponse()` methods (`AdminController`, `MenuController`, `OrderController`) and their `catch (\Throwable $e)` blocks — the generic case is already handled correctly, centrally, by `App.php`'s existing error middleware (Problem, point 3). Any now-unused `Settings`/`LoggerInterface` constructor dependencies these methods existed to support are removed too.

## Non-goals

- **Not standardizing success response shapes.** The roadmap's title and example are specifically about errors. Success responses vary today (`{success:true,...}`, raw resource arrays like `IngredientController::index()`'s bare `json_encode($ingredients)`, `ReportController`'s `{success:true,data:...}`) and are consumed directly, unwrapped, by `public/cashier/*`, `public/kitchen/*`, `public/admin/*`'s JS — changing any of those shapes would be a real frontend-breaking change this spec has no reason to make.
- **Not changing `App.php`'s global error handler.** It already produces the target shape for the generic/unanticipated case (Problem, point 3) — this spec adds explicit, more specific handling *in front of* it for known cases, not a replacement for it.
- **Not adding new validation rules or business logic.** Every fix here is about *how an existing error is reported*, never about *which requests are rejected* — no behavior changes beyond status codes for the one confirmed bug (Problem, point 2) and the `DishController` dead-import removal (Problem, point 4).
- **Not touching `ReportController`** beyond leaving it as-is — it has no error paths of its own to standardize (no `try/catch` anywhere in it; any failure already goes straight to the global handler, which is already correct for it).

## Current behavior

See Problem, points 1-4, with citations.

## Proposed behavior

New `App\ApiResponse` (`src/ApiResponse.php`) — flat top-level namespace, matching this project's existing small-utility precedent (`App\Settings`, `App\Database`, `App\Money`):

```php
final class ApiResponse
{
    public static function error(Response $response, int $status, string $code, string $message, array $extra = []): Response
    {
        $payload = array_merge(['success' => false, 'error' => $message, 'code' => $code], $extra);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
```

`$extra` covers the one existing case that carries more than a message today — `OrderController`'s validation failures, which also return a Valitron `messages` array (`['messages' => $errors]`), preserved via `ApiResponse::error($response, 400, 'VALIDATION_FAILED', 'Validation failed', ['messages' => $errors])`.

**`JSON_UNESCAPED_UNICODE`** is added here (matching `App.php`'s global handler and `ReportController`'s existing `json()` helper, both of which already use it) — every other current inline `json_encode(['error' => ...])` call escapes non-ASCII characters (e.g. `"não encontrado"` → `"não encontrado"`); this makes every error response's bytes match those two already-established precedents. Still valid, semantically identical JSON either way — a byte-level readability improvement, not a behavior change for any real consumer.

**Every specific error site across every controller and both auth middlewares switches to `ApiResponse::error(...)`**, each with a real, case-specific `code` (full mapping below) — no site reuses a generic code where a specific one is meaningful. **The three duplicated `errorResponse()` methods and their `catch (\Throwable $e) { return $this->errorResponse(...); }` blocks are removed** — an unanticipated exception now simply propagates to `App.php`'s existing global handler, which already produces the identical production-safe shape (Problem, point 3), so nothing is lost.

**`DishController`/`IngredientController` gain their first explicit error handling** — `ModelNotFoundException` → `404` with a real code, fixing the 500-instead-of-404 bug (Problem, point 2) as a direct, necessary consequence of giving these controllers *any* standardized error handling at all.

**Code taxonomy** (new `code` values; existing message text is kept as-is everywhere except where noted — this spec standardizes the *envelope*, not the wording, per Non-goals):

| Controller/Middleware | Case | Status | Code |
|---|---|---|---|
| `OrderController::store` | validation failed | 400 | `VALIDATION_FAILED` |
| `OrderController::store` | menu item not found/unavailable | 400 | `INVALID_ORDER_ITEM` |
| `OrderController::store`/`update` | order_number taken | 409 | `ORDER_NUMBER_TAKEN` |
| `OrderController::complete`/`uncomplete`/`cancel`/`update`/`addItem`/`updateItem`/`removeItem`/`print` | order not found | 404 | `ORDER_NOT_FOUND` |
| `OrderController::complete` | order cancelled | 409 | `ORDER_CANCELLED` |
| `OrderController::uncomplete` | order cancelled | 409 | `ORDER_CANCELLED` |
| `OrderController::cancel` | already cancelled | 409 | `ORDER_ALREADY_CANCELLED` |
| `OrderController::update` | validation failed | 400 | `VALIDATION_FAILED` |
| `OrderController::addItem` | validation failed | 400 | `VALIDATION_FAILED` |
| `OrderController::addItem` | order or menu item not found | 404 | `ORDER_OR_MENU_ITEM_NOT_FOUND` |
| `OrderController::addItem` | menu item unavailable | 400 | `MENU_ITEM_UNAVAILABLE` |
| `OrderController::updateItem` | validation failed | 400 | `VALIDATION_FAILED` |
| `OrderController::updateItem`/`removeItem` | item not found in order | 404 | `ORDER_ITEM_NOT_FOUND` |
| `OrderController::removeItem` | last item can't be removed | 409 | `LAST_ORDER_ITEM` |
| `MenuController::store` | missing required fields | 400 | `MISSING_REQUIRED_FIELDS` |
| `MenuController::updateItem` | empty payload | 400 | `EMPTY_PAYLOAD` |
| `MenuController::updateComponents` | missing components field | 400 | `MISSING_REQUIRED_FIELDS` |
| `MenuController::reorder` | missing fields | 400 | `MISSING_REQUIRED_FIELDS` |
| `MenuController::reorder` | category not found | 404 | `CATEGORY_NOT_FOUND` |
| `MenuController::delete` | item not found | 404 | `MENU_ITEM_NOT_FOUND` |
| `MenuController::delete` | item linked to existing orders | 409 | `MENU_ITEM_IN_USE` |
| `AdminController::updateSettings` | missing settings object | 400 | `MISSING_REQUIRED_FIELDS` |
| `AdminController::uploadLogo` | missing/invalid file | 400 | `INVALID_UPLOAD` |
| `AdminController::uploadLogo` | unsupported format | 400 | `UNSUPPORTED_FILE_TYPE` |
| `AuthController::login` | missing credentials | 400 | `MISSING_CREDENTIALS` |
| `AuthController::login` | invalid credentials | 401 | `INVALID_CREDENTIALS` |
| `AuthController::changePassword` | missing fields | 400 | `MISSING_REQUIRED_FIELDS` |
| `AuthController::changePassword` | password too short | 400 | `PASSWORD_TOO_SHORT` |
| `AuthController::changePassword` | current password incorrect | 401 | `CURRENT_PASSWORD_INCORRECT` |
| `IngredientController::store` | missing fields | 400 | `MISSING_REQUIRED_FIELDS` |
| `IngredientController::update`/`destroy` | not found (**new — closes the 500 bug**) | 404 | `INGREDIENT_NOT_FOUND` |
| `DishController::show`/`update` | not found (**new — closes the 500 bug**) | 404 | `DISH_NOT_FOUND` |
| `JwtMiddleware` | no token | 401 | `TOKEN_MISSING` |
| `JwtMiddleware` | expired token | 401 | `TOKEN_EXPIRED` |
| `JwtMiddleware` | invalid token | 401 | `TOKEN_INVALID` |
| `RoleMiddleware` | role not allowed | 403 | `FORBIDDEN` |

Codes are per *catch site*, not per exact interpolated message — e.g. `OrderController::store`'s `\DomainException` catch covers both "item not found" and "item unavailable" sub-cases (the thrown message text still differs and is still shown), matching this project's existing exception style (a plain `\DomainException` with a human message, not a typed exception hierarchy — introducing one now would be new architecture this spec doesn't need).

## Functional requirements

1. Every specific (non-generic-500) error response from every controller and `JwtMiddleware`/`RoleMiddleware` is `{"success": false, "error": "...", "code": "..."}`, per the table above.
2. `DishController::show()`/`::update()` and `IngredientController::update()`/`::destroy()` return `404` (not `500`) for a nonexistent id.
3. An unanticipated exception in any controller still returns the existing, already-correct production-safe `500` shape — now solely via `App.php`'s global handler, with identical behavior to before.
4. `OrderController::store`'s validation-failure response still includes the Valitron `messages` array alongside the new `code`.
5. No success response shape changes anywhere.
6. `src/Controllers/DishController.php`'s dead `use Illuminate\Support\Facades\DB;` import is removed.

## Non-functional requirements

Not applicable — response-shape and status-code correctness only, no performance implication.

## User flows

Not applicable — no user-facing behavior change (the cashier/kitchen/admin frontends already only ever display `data.error` as a string; none of them read `code` today, so this is purely additive from their perspective).

## API changes

Every error response gains a `success: false` and `code` field it didn't have before (additive, non-breaking for any consumer reading `error`). `DishController`/`IngredientController` not-found responses change from `500` to `404` (Problem, point 2) — a status-code *correction*, not a new breaking change, since `500` was never the intended/documented behavior for a missing resource.

## Data model and migrations

Not applicable.

## Architecture and affected components

- `src/ApiResponse.php` (new).
- `src/Controllers/OrderController.php`, `MenuController.php`, `AdminController.php` — every specific error site switched to `ApiResponse::error()`; `errorResponse()` method and `catch (\Throwable $e)` blocks removed; now-unused `Settings`/`LoggerInterface` constructor params removed where nothing else in the class uses them (confirmed per-file before removing any — `AdminController` keeps `Settings` for `getLogFile()`/`getPublicAssetsImgDir()`/`isDebug()`, which are still called from `getLogs()`/`uploadLogo()`; `OrderController` keeps `Settings` too, since nothing else in this list removes an active dependency without checking first).
- `src/Controllers/AuthController.php`, `IngredientController.php`, `DishController.php`, `KitchenController.php` — first explicit error handling for `IngredientController`/`DishController`; `AuthController`/`IngredientController`'s existing inline checks switched to `ApiResponse::error()`. `KitchenController` has no error paths of its own to change (`foodCategorySummary()` has no conditional failure case) — confirmed by inspection, not modified.
- `src/Middleware/JwtMiddleware.php`, `RoleMiddleware.php` — switched to `ApiResponse::error()`.
- Tests: new `tests/Unit/ApiResponseTest.php`; existing tests that assert on error response shape (none currently do at the HTTP-response level — this project's tests are unit-level against Services/Repositories/Validators, not full HTTP request/response cycles) are unaffected.

## Security considerations

Not applicable — no new input surface, no secrets. `JwtMiddleware`'s three distinct codes (`TOKEN_MISSING`/`TOKEN_EXPIRED`/`TOKEN_INVALID`) are already distinguishable today by message text alone, so this doesn't newly reveal anything an attacker couldn't already infer.

## Backward compatibility

Additive for every response body (new `success`/`code` fields alongside the existing `error`). The two real behavior changes are corrections, not regressions: `DishController`/`IngredientController` not-found moves from an incorrect `500` to the correct `404` (Problem, point 2), and every error response's JSON bytes become UTF-8-unescaped instead of `\uXXXX`-escaped (semantically identical JSON, not a wire-format break for any real JSON consumer).

## Acceptance criteria

1. `curl -X POST /api/orders -d '{"items":[]}'` → `400`, body includes `"success":false` and `"code":"VALIDATION_FAILED"`.
2. `curl -X POST /api/orders/999999/cancel` → `404`, `"code":"ORDER_NOT_FOUND"`.
3. `DishController::show()` called with a nonexistent id → `404`, `"code":"DISH_NOT_FOUND"` — previously would have been `500` per Problem point 2's reasoning. **Verified by direct controller instantiation, not `curl`** — see Implementation log for why (a real routing gap found during this spec makes this endpoint unreachable via HTTP at all today).
4. `IngredientController::update()` called with a nonexistent id → `404`, `"code":"INGREDIENT_NOT_FOUND"` — same direct-instantiation verification, same reason.
5. `curl` with no `Authorization` header against any `/api/admin/*` route → `401`, `"code":"TOKEN_MISSING"`.
6. An order-number collision (`POST /api/orders` with a manually duplicated `order_number`) → `409`, `"code":"ORDER_NUMBER_TAKEN"`.
7. A genuinely unanticipated exception (e.g. temporarily break a query to force one) still returns the existing production-safe `500` shape, unchanged.
8. `vendor/bin/phpunit` passes.
9. `grep -rn "class OrderController\|class MenuController\|class AdminController" -A 20 src/Controllers/*.php | grep -c "function errorResponse"` → `0` (the three duplicated methods are gone).

## Implementation plan

1. Write `src/ApiResponse.php` and `tests/Unit/ApiResponseTest.php`.
2. `OrderController` — replace every specific error site per the table; remove `errorResponse()`/`catch(\Throwable)`/now-unused constructor deps.
3. `MenuController` — same.
4. `AdminController` — same (keeping `Settings`, since it's used elsewhere).
5. `AuthController`, `IngredientController` (including the new 404 handling), `DishController` (including the new 404 handling and the dead-import removal).
6. `JwtMiddleware`, `RoleMiddleware`.
7. Run `vendor/bin/phpunit`.
8. Manually verify the full acceptance-criteria matrix against the real running stack, including the specific before/after comparison for the `DishController`/`IngredientController` bug fix.

## Testing and validation strategy

Unit-level: `ApiResponseTest` asserts the exact JSON shape/status for a representative call. This project has no HTTP-request-level test harness (confirmed — tests exercise Services/Repositories/Validators directly, never a full Slim request cycle), so the actual per-endpoint status/code/shape correctness (Acceptance criteria 1-7) is verified by real `curl` calls against the running dev stack, including a genuine before/after comparison for the `DishController`/`IngredientController` fix (checking out the pre-fix code briefly, or simply trusting the already-documented reasoning in Problem point 2 plus confirming the fixed behavior — the pre-fix behavior was already established by direct code reading of `App.php`'s handler, not assumed).

## Rollout and rollback

No feature flag, no migration — pure application-code change across controllers/middleware. Rollback is a plain revert; no stored data or schema involved.

## Open questions

None blocking. The per-site `code` values (table above) are this spec's own design, not dictated by the roadmap beyond its one example (`MENU_ITEM_NOT_FOUND`) — reasonable to adjust later without being a "product decision" in the same sense as specs 020/021's choices.

## Task checklist

- [x] `src/ApiResponse.php` + `ApiResponseTest.php` written
- [x] `OrderController` migrated, `errorResponse()` removed
- [x] `MenuController` migrated, `errorResponse()` removed (plus 4 additional latent 404-vs-500 sites found and fixed — see Implementation log)
- [x] `AdminController` migrated, `errorResponse()` removed
- [x] `AuthController` migrated
- [x] `IngredientController` migrated (+ new 404 handling)
- [x] `DishController` migrated (+ new 404 handling, dead import removed)
- [x] `JwtMiddleware`/`RoleMiddleware` migrated
- [x] `vendor/bin/phpunit` passing
- [x] Full acceptance-criteria matrix verified against the real running stack (except Dish/Ingredient — see below)

## Implementation log

- 2026-09-05 — Full inventory pass across all 8 controllers + both auth middlewares before writing any code (Problem, points 1-4) — confirmed by direct reads, not assumed from `docs/architecture.md`'s summary table.
- 2026-09-05 — While migrating `MenuController`, found the same 404-vs-500 bug class (Problem point 2) in **three more places** not originally catalogued: `updateItem()`, `getComponents()`, and `updateComponents()` all call `findOrFail()` (via `MenuRepository`) but only caught generic `\Throwable` — a nonexistent item id in any of these returned the generic 500-shaped `errorResponse()`, not a `404`. Also found a fourth: `store()`'s `MenuRepository::addItem()` calls `Category::where(...)->firstOrFail()` for `category_name`, with the same gap. All four fixed alongside the two originally planned (`reorder()`, `delete()`), each with its own specific `ModelNotFoundException` catch and code (`MENU_ITEM_NOT_FOUND`/`CATEGORY_NOT_FOUND`).
- 2026-09-05 — **Real, more significant discovery**: while trying to `curl` Acceptance criteria 3-4 against real `/api/admin/dishes/{id}` and `/api/admin/ingredients/{id}` routes, both returned Slim's own routing `404` (not `ApiResponse`'s) — a `grep` of `src/Routes.php` confirmed `DishController` and `IngredientController` have **no route registered anywhere**, and a repo-wide `grep` confirmed neither class is referenced by any other file either. Both controllers are entirely unreachable dead code today, not merely under-layered as `docs/architecture.md`'s controller table implied. This is outside this spec's scope to fix (adding routes is a product/architecture decision — are these superseded by `MenuController`'s existing dish-components endpoints, or a half-finished feature meant to be wired up? — not something to silently decide here) — flagged to the user directly, and `docs/architecture.md`'s `Controllers/`/`Repositories/` rows corrected to say so plainly instead of listing them as ordinary, reachable controllers with a smaller "no repository layer" gap.
- 2026-09-05 — Given the above, Acceptance criteria 3-4 were verified by directly instantiating `DishController`/`IngredientController` and calling their methods with a real PSR-7 request/response pair, bypassing HTTP/routing entirely — the only way to exercise this code at all today.
- 2026-09-05 — Also found and fixed, while reading `docs/architecture.md`'s `Layers under src/` table for the above: it listed `Middleware/` as "3 (PSR-15): Cors, JsonBodyParser, Jwt", omitting `RoleMiddleware` (added by spec 018, before this session) entirely — corrected to 4.
- 2026-09-05 — Created a temporary admin user directly in the dev DB (bcrypt-hashed, not via `bin/create-admin`'s interactive prompt) to obtain a real JWT for live verification, since no known password existed for any pre-existing admin account — deleted immediately after use, restoring the `users` table to its prior 8 rows.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found.

## Validation evidence

- **Acceptance criterion 1** — `curl -X POST /api/orders -d '{"items":[]}'` → `400 {"success":false,"error":"Validation failed","code":"VALIDATION_FAILED","messages":{"items":["Items Invalid"]}}`. **Confirmed.**
- **Acceptance criterion 2** — `curl -X POST /api/orders/999999/cancel` → `404 {"success":false,"error":"Pedido não encontrado","code":"ORDER_NOT_FOUND"}`. **Confirmed.**
- **Acceptance criterion 3** — direct `DishController::show()` call with id `999999` → `404 {"success":false,"error":"Dish not found.","code":"DISH_NOT_FOUND"}`. **Confirmed** (see Implementation log for why not `curl`).
- **Acceptance criterion 4** — direct `IngredientController::update()` call with id `999999` → `404 {"success":false,"error":"Ingredient not found.","code":"INGREDIENT_NOT_FOUND"}`. **Confirmed**, same caveat.
- **Acceptance criterion 5** — `curl -X GET /api/admin/settings` with no `Authorization` header → `401 {"success":false,"error":"Token não fornecido.","code":"TOKEN_MISSING"}`. **Confirmed.**
- **Acceptance criterion 6** — `curl -X POST /api/orders` twice with the same manual `order_number` → second call `409 {"success":false,"error":"Número da senha já utilizado hoje. Peça um novo número.","code":"ORDER_NUMBER_TAKEN"}`. **Confirmed.**
- **Acceptance criterion 7** — Not independently re-forced live (deliberately not manufacturing a real crash against the shared dev DB); confirmed by direct, unmodified-by-this-spec reading of `App.php`'s global error handler (`src/App.php:58-104`), which already produces `{"success":false,"error":"Erro interno do servidor.","code":"INTERNAL_ERROR"}` for exactly this case. **Confirmed by code inspection**, not a fresh live reproduction — noted plainly rather than overclaimed.
- **Acceptance criterion 8** — `vendor/bin/phpunit` → `OK (49 tests, 83 assertions)` (46 pre-existing + 3 new `ApiResponseTest`). **Confirmed.**
- **Acceptance criterion 9** — `grep -c "function errorResponse" src/Controllers/*.php` → `0` for every file. **Confirmed.**
- Also verified live: a normal authenticated `GET /api/admin/settings` with a valid token still succeeds unchanged, confirming the `JwtMiddleware`/`RoleMiddleware` rewrite didn't break the happy path.
- `php -l` clean on every changed PHP file.
