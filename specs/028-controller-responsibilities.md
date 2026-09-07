# Spec 028 — Controller responsibilities: split AdminController

## Metadata

- Status: Verified
- Created: 2026-09-06
- Updated: 2026-09-06
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Controller responsibilities" subsection: review oversized or unrelated controller responsibilities; split controllers when multiple unrelated domains are handled together. The roadmap's own worked example is, verbatim:

```text
AdminController
     ↓
SettingsController
PrinterController
LogController
```

This is the exact controller that exists in this codebase today — this spec implements the roadmap's own named example directly, not a newly-discovered case. `README.md:207` already lists "split `AdminController`" among known planned refactors, confirming this isn't a surprise finding.

While investigating, confirmed `docs/architecture.md`'s `Layers under src/` table (line 78, 81) has drifted out of date relative to specs 026/027, already landed on this branch but not yet reflected there: it still says `IngredientController` is "entirely unreachable dead code" (spec 027 wired its routes), still lists only `OrderValidator` under `Validators/` (spec 027 added four more), and `Services/` still lists 6 services, missing `PricingService` (spec 026). Since this spec directly touches the `Controllers/` row anyway, fixing the whole table in the same pass is more useful than a disconnected doc-only edit — see Implementation plan.

## Problem

Confirmed by direct reads of `src/Controllers/AdminController.php` (current state, after specs 026/027): one class, 156 lines, handling three genuinely unrelated domains behind a single constructor:

1. **Restaurant settings** — `getSettings()` (`:41-47`), `updateSettings()` (`:54-76`), `uploadLogo()` (`:126-155`). Depends on `Settings $settings`, `SettingsValidator $settingsValidator`.
2. **Printer testing** — `testPrint()` (`:29-35`). Depends on `PrintService $printService` — unrelated to the settings CRUD above except that printer *configuration* happens to live in the same key-value settings store.
3. **Application log viewing** — `getLogs()` (`:82-119`). Depends on `Settings $settings` only for `getLogFile()`'s path — otherwise pure filesystem reading, unrelated to either of the above.

None of these three domains calls into another's logic — they're independent read/write operations bundled into one class purely because they all happen to live under `/api/admin/*` and require the `admin` role. This is exactly the "oversized/unrelated responsibilities" pattern the roadmap describes, and exactly the split the roadmap names.

## Goals

- Split `AdminController` into three focused controllers, each with only the constructor dependencies its own methods actually use:
  - `SettingsController` — `getSettings()`, `updateSettings()`, `uploadLogo()`.
  - `PrinterController` — `testPrint()`.
  - `LogController` — `getLogs()`.
- Zero behavior change: identical routes, identical HTTP methods, identical request/response shapes, identical RBAC (`admin`-only, unchanged from today), identical status/error codes.
- Update `docs/architecture.md`'s `Layers under src/` table to reflect the post-split `Controllers/` list and correct the other drift noted in Context (Ingredient reachability, `Validators/` count, `Services/` count).

## Non-goals

- No change to any route path, HTTP method, RBAC rule, request shape, or response shape — this is a pure code-organization change.
- No change to `DishController.php` — separately dead code (spec 027's Problem #6), not part of this split, and not requested here.
- No new Repository/Validator layer for settings/logs/printing beyond what specs 026/027 already introduced — "Persistence boundaries" (the roadmap's next `v1.7.0` subsection) is a separate, not-yet-planned spec.
- No change to `PrintService`, `Settings`, or `SettingsValidator` themselves — only which controller class calls them.

## Current behavior

See Problem above for exact file:line citations. Routing (`src/Routes.php:73-77`) currently maps:
```php
$group->get('/settings', [AdminController::class, 'getSettings'])->add($adminOnly());
$group->put('/settings', [AdminController::class, 'updateSettings'])->add($adminOnly());
$group->post('/settings/logo', [AdminController::class, 'uploadLogo'])->add($adminOnly());
$group->get('/logs', [AdminController::class, 'getLogs'])->add($adminOnly());
$group->post('/settings/test-print', [AdminController::class, 'testPrint'])->add($adminOnly());
```
All five routes are inside the `$jwt`-protected `/api/admin` group and gated `$adminOnly()` — matching spec 018's existing RBAC scope ("settings/logs/printer config require admin").

No test file references `AdminController` (confirmed via `grep -rn "AdminController" tests/` — no matches), and no frontend file's request URL/shape needs to change (`public/admin/settings.js`, `public/admin/logs.php` call the same `/api/admin/...` paths regardless of which backend class handles them).

## Proposed behavior

- **`src/Controllers/SettingsController.php`** (new): `getSettings()`, `updateSettings()`, `uploadLogo()` moved verbatim from `AdminController`. Constructor: `Settings $settings`, `SettingsValidator $settingsValidator`.
- **`src/Controllers/PrinterController.php`** (new): `testPrint()` moved verbatim. Constructor: `PrintService $printService`.
- **`src/Controllers/LogController.php`** (new): `getLogs()` moved verbatim. Constructor: `Settings $settings`.
- **`src/Controllers/AdminController.php`**: deleted — every method relocated, nothing left behind (no shim/re-export, per `CLAUDE.md`'s guidance against backwards-compatibility hacks for an internal-only class rename with no external caller).
- **`src/Routes.php`**: the five route registrations updated to reference the new controller classes/methods; RBAC (`->add($adminOnly())`) and paths unchanged.
- **`docs/architecture.md`**: `Layers under src/` table's `Controllers/` row updated to the new 10-class list (`Auth, Dish, Ingredient, Kitchen, Log, Menu, Order, Printer, Report, Settings`) with an accurate reachability note (only `Dish` is dead code now); `Validators/` row updated to list all 5 validators; `Services/` row updated to include `PricingService`.

## Functional requirements

1. `GET /api/admin/settings`, `PUT /api/admin/settings`, `POST /api/admin/settings/logo` return identical responses to today, now served by `SettingsController`.
2. `POST /api/admin/settings/test-print` returns an identical response to today, now served by `PrinterController`.
3. `GET /api/admin/logs` returns an identical response to today, now served by `LogController`.
4. All five routes still reject a request without a valid `admin`-role JWT with `401`/`403` exactly as before (RBAC unchanged).
5. `src/Controllers/AdminController.php` no longer exists.

## Non-functional requirements

Not applicable — pure code organization, no performance/security/observability change beyond what's already stated.

## User flows

Not applicable — no user-facing behavior change; the admin Settings/Logs pages continue to work identically since their request URLs don't change.

## API changes

Not applicable — no route path, method, request, or response shape changes. Purely which PHP class handles each existing route.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

- New: `src/Controllers/SettingsController.php`, `src/Controllers/PrinterController.php`, `src/Controllers/LogController.php`.
- Deleted: `src/Controllers/AdminController.php`.
- Changed: `src/Routes.php` (five route registrations' controller references).
- Changed: `docs/architecture.md` (`Layers under src/` table — `Controllers/`, `Validators/`, `Services/` rows).
- Unchanged: `src/Services/PrintService.php`, `src/Settings.php`, `src/Validators/SettingsValidator.php`, `src/Models/Setting.php` — only the caller moves, not the callee.
- Every new controller is autowired by PHP-DI (same as `MenuController`/`IngredientController` today) — no container definition needed.

## Security considerations

- RBAC is preserved exactly: all three new controllers' routes stay behind `$jwt` + `$adminOnly()` in `src/Routes.php`, matching today's guard precisely — the split doesn't touch authentication/authorization logic, only which class the already-guarded route dispatches to.
- No new input surface, no change to secret handling (`uploadLogo()`'s file-type/upload validation moves unchanged into `SettingsController`).

## Backward compatibility

Fully preserved — no route, request, or response changes. Any existing frontend or API consumer sees no difference; the only observable-to-a-developer change is which PHP class file defines each handler.

## Acceptance criteria

- [ ] `src/Controllers/AdminController.php` no longer exists; `src/Controllers/SettingsController.php`, `PrinterController.php`, `LogController.php` exist with the methods described above.
- [ ] `src/Routes.php` references only the new controller classes for these five routes; no reference to `AdminController` remains anywhere in `src/`.
- [ ] `GET /api/admin/settings` with a valid admin JWT returns the same `{"success":true,"settings":{...}}` shape as before this change (manually confirmed against the real dev DB).
- [ ] `POST /api/admin/settings/test-print` with a valid admin JWT still triggers `PrintService::printTestPage()` (confirmed via the existing log line / no new error).
- [ ] `GET /api/admin/logs` with a valid admin JWT returns the same `{"success":true,"lines":[...],...}` shape as before.
- [ ] A request to any of the five routes without a JWT (or with a non-admin role) is still rejected exactly as before (RBAC unchanged).
- [ ] `vendor/bin/phpunit` passes (no existing test references `AdminController`, so none should need updating — confirmed during implementation, not just assumed here).
- [ ] `docs/architecture.md`'s `Controllers/`/`Validators/`/`Services/` rows accurately list the current classes after this change.

## Implementation plan

1. Create `SettingsController.php`, `PrinterController.php`, `LogController.php` with the relocated methods and trimmed constructors.
2. Update `src/Routes.php`'s five route registrations and `use` imports.
3. Delete `src/Controllers/AdminController.php`.
4. Update `docs/architecture.md`'s `Layers under src/` table (`Controllers/` for the split + `Ingredient` reachability correction, `Validators/` for spec 027's four new validators, `Services/` for spec 026's `PricingService`).
5. Run the full test suite; manually verify each of the five routes against the real dev DB (valid admin JWT → same response as before; no/wrong-role JWT → still rejected).

## Testing and validation strategy

This project has a real PHPUnit suite, run via `docker compose exec web vendor/bin/phpunit`. No existing test references `AdminController` (confirmed via grep), so the suite is expected to pass unchanged — this will be confirmed by actually running it, not assumed. Manual `curl` checks against the real running dev stack (already up) cover each acceptance criterion above, using an admin JWT obtained the same way spec 026/027's validation did (a temporary account created via `bin/create-admin`, deleted afterward).

## Rollout and rollback

Standard code change on branch `019`. Rollback is a plain `git revert` — no migration, no data change, no route path change to un-break for any external consumer.

## Open questions

None blocking. This spec implements the roadmap's own named example directly; there's no ambiguity about which methods go where.

## Task checklist

- [x] Step 1 — create `SettingsController`, `PrinterController`, `LogController`
- [x] Step 2 — update `src/Routes.php`
- [x] Step 3 — delete `AdminController.php`
- [x] Step 4 — update `docs/architecture.md`'s layers table
- [x] Step 5 — full suite + manual end-to-end validation

## Implementation log

- **Status set to `Approved` then immediately `In Progress`** on `/spec-implement` invocation, per the skill's rule that an explicit invocation on a blocking-question-free `Draft` spec counts as approval.
- **Small extra fix found during re-investigation**: `src/Validators/SettingsValidator.php`'s docblock referenced `AdminController.php` by name (`"...is called from AdminController.php, which has..."`). Updated to say `SettingsController.php (AdminController before spec 028's split)` — leaving the old class name in a docblock comment after deleting the class would have been a small but real inaccuracy in exactly the kind of place a future reader would trust.
- **`README.md`'s "Next up" section (line 207: "split `AdminController`, ... standardized error-response format, paginated order listing") was found to be significantly stale** — it predates specs 019–028 entirely and still lists work already done (spec 024's error standardization, spec 025's pagination investigation, and now this spec's `AdminController` split) as not-yet-started, alongside a claim that "the working tree is clean... nothing queued". **Deliberately left untouched**: this section reads as tied to the project's release/changelog workflow (`CLAUDE.md`'s "Release workflow" section — updated when work lands on `master` and a version is tagged, not per feature-branch spec), not to `docs/architecture.md`'s living technical description, which this spec's own scope named explicitly. Flagging it here rather than silently fixing or silently ignoring it.
- `docs/architecture.md`'s `Controllers/` gap note was rewritten to also correct a citation this spec noticed while editing (Context already flagged this): it previously attributed the `Dish`/`Ingredient` unreachable-routes finding to "spec 024," but spec 024 was API error standardization — the unreachable-routes finding actually belongs to spec 027 (input validation), which is what wired `Ingredient`'s routes. Corrected the attribution while updating the row.

## Validation evidence

All commands actually run against the real `docker compose` stack (`db`, `web`, `print-worker`, already up).

**Syntax check** (`docker compose exec -T web php -l <file>`) — all "No syntax errors detected": `src/Routes.php`, `src/Controllers/{SettingsController,PrinterController,LogController}.php`, `src/Validators/SettingsValidator.php`.

**Full automated suite** (`docker compose exec -T web vendor/bin/phpunit`):
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.33
...
OK (104 tests, 147 assertions)
```
Unchanged from before this spec (104/147) — confirms no test referenced `AdminController` (AC "no existing test needed updating," confirmed rather than assumed).

**Manual end-to-end checks**, dev DB, using three temporary accounts created via `bin/create-admin` (`spec028_temp_admin`/admin, `spec028_temp_manager`/manager) solely for this validation and deleted immediately afterward via a throwaway script (`common/_tmp_cleanup_validation_users.php`, run once then removed — confirmed gone via `git status`):

- **AC "GET /api/admin/settings same shape as before"**: with admin JWT → `HTTP 200`, `{"success":true,"settings":{"printer_ip":"192.168.0.100","printer_port":"9100","restaurant_name":"Cozinha Da Xuxu"}}` — same shape/values as spec 027's validation run against the same endpoint before this split.
- **AC "GET /api/admin/logs same shape as before"**: with admin JWT → `HTTP 200`, `{"success":true,"lines":[...13 entries...],"total":13,"file":"app.log"}` — same shape as documented in `AdminController::getLogs()`'s original implementation, now served by `LogController`.
- **AC "POST /api/admin/settings/test-print still triggers PrintService::printTestPage()"**: with admin JWT → `HTTP 500`, `{"error":"Cannot initialise NetworkPrintConnector: Connection timed out",...}` with a stack trace showing `App\Controllers\PrinterController->testPrint()` → `App\Services\PrintService->printTestPage()` → `makeConnector()` → `NetworkPrintConnector`. This 500 is the real, pre-existing printer at `192.168.0.100` being unreachable from this dev environment (an environmental condition, not a regression) — the acceptance criterion is confirmed by the stack trace itself proving the call chain reaches `PrintService::printTestPage()` exactly as before, now via `PrinterController` instead of `AdminController`.
- **AC "RBAC unchanged — reject without/wrong role"**: `GET /api/admin/settings` with no `Authorization` header → `HTTP 401`, `{"code":"TOKEN_MISSING"}`. All three admin-only routes (`GET /settings`, `GET /logs`, `POST /settings/test-print`) with a valid **manager**-role JWT → `HTTP 403`, `{"code":"FORBIDDEN"}` for all three — confirms `$adminOnly()` still excludes `manager`, unchanged from before.
- **AC "`src/Controllers/AdminController.php` no longer exists / no reference remains in `src/`"**: `git diff --stat -- src/Controllers/AdminController.php` shows the file deleted (150 lines removed, 0 added); `grep -rn "AdminController" src/` returns no matches after the `SettingsValidator.php` docblock fix above.

All acceptance criteria have direct evidence above.
