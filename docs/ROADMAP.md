# Roadmap — GastroFlow v2.0

> Plan for architectural, quality, and infrastructure improvements.
> Organized into Milestones (GitHub Issues) with a defined execution order.

---

## Table of Contents

- [Milestones](#milestones)
- [Labels](#labels)
- [Issues by Milestone](#issues-by-milestone)
  - [v2.0 — Foundation](#v20--foundation)
  - [v2.1 — Tests & Quality](#v21--tests--quality)
  - [v2.2 — Architecture](#v22--architecture)
  - [v2.3 — Frontend & Infra](#v23--frontend--infra)
- [Recommended execution order](#recommended-execution-order)
- [Branch naming convention](#branch-naming-convention)
- [Pull Request Template](#pull-request-template)

---

## Milestones

| Milestone | Estimate | Focus |
|---|---|---|
| **v2.0 — Foundation** | 1-2 weeks | Small, mechanical changes that prepare the ground |
| **v2.1 — Tests & Quality** | 2-3 weeks | PHPUnit + CI/CD (do before large refactors) |
| **v2.2 — Architecture** | 3-4 weeks | Controller, validator, and pagination refactors |
| **v2.3 — Frontend & Infra** | 2 weeks | JS modularization, build tool, more robust SSE |

---

## Labels

| Label | Color | Meaning |
|---|---|---|
| `type: refactor` | `#1d76db` | Code change without altering behavior |
| `type: security` | `#b60205` | CORS, JWT, validation |
| `type: test` | `#0e8a16` | PHPUnit |
| `type: infra` | `#0052cc` | CI/CD, build tool |
| `type: frontend` | `#fbca04` | Alpine.js, CSS, JS |
| `size: S` | `#c2e0c6` | ≤ 1 hour |
| `size: M` | `#fef2c0` | 2-4 hours |
| `size: L` | `#f9d0c4` | 4-8 hours |
| `size: XL` | `#e99695` | > 8 hours |
| `priority: high` | `#e11d21` | Do now |
| `priority: medium` | `#eb6420` | Next sprint |
| `priority: low` | `#cccccc` | When there's time |

---

## Issues by Milestone

### v2.0 — Foundation

#### #12 — Add `declare(strict_types=1)` to all PHP files — ✅ Concluído 
---

#### #8 — Make CORS configurable via environment variable — ✅ Concluído

---

#### #9 — JWT without a hardcoded fallback — ✅ Concluído

---

#### #10 — Absolute paths via Settings (DI) — ✅ Concluído

---

### v2.1 — Tests & Quality

#### #1 — Implement PHPUnit with smoke tests — ✅ Concluído

See `specs/004-phpunit-smoke-tests.md` (Status: Verified).

---

#### #15 — GitHub Actions CI/CD — ⚠️ Implemented, pending a live run

**Labels:** `type: infra` `size: M` `priority: high`

See `specs/005-github-actions-ci.md` (Status: Implemented). Workflow file and README badge are in place; not yet marked done because no GitHub Actions run has actually executed the workflow (needs a push/PR, not yet authorized).

**Acceptance Criteria:**
- [x] Workflow runs on `push` (`master` — this repo's real default branch, not `main` as originally written here) and `pull_request`
- [x] Setup PHP 8.2
- [ ] `vendor/bin/phpunit` passes in CI — not yet observed in a real run
- [x] Status badge in `README.md`

---

### v2.2 — Architecture

#### #2 — Unify DishController and IngredientController

**Labels:** `type: refactor` `size: L` `priority: medium`

**Description:**
`DishController` and `IngredientController` use Eloquent directly. Migrate to Service/Repository.

**Files involved:**
- `src/Controllers/DishController.php`
- `src/Controllers/IngredientController.php`
- `src/Services/MenuService.php`
- `src/Services/IngredientService.php` (create)
- `src/Repositories/IngredientRepository.php` (create)

**Acceptance Criteria:**
- [ ] No Controller calls a Model directly
- [ ] Logic moved to `IngredientService` + `IngredientRepository`
- [ ] Tests keep passing

---

#### #3 — Extract AdminController into smaller controllers

**Labels:** `type: refactor` `size: L` `priority: medium`

**Description:**
`AdminController` mixes settings, logs, logo, test-print. Extract into:
- `SettingsController`
- `LogController`
- `LogoController`

**Files involved:**
- `src/Controllers/` (create new ones, refactor the existing one)
- `src/Routes.php`

**Acceptance Criteria:**
- [ ] Each controller has a single responsibility
- [ ] Routes updated
- [ ] Admin frontend keeps working

---

#### #6 — Validators for admin endpoints

**Labels:** `type: refactor` `type: security` `size: L` `priority: medium`

**Description:**
Create validators: `MenuItemValidator`, `SettingValidator`, `IngredientValidator`.

**Files involved:**
- `src/Validators/MenuItemValidator.php` (create)
- `src/Validators/SettingValidator.php` (create)
- `src/Validators/IngredientValidator.php` (create)

**Acceptance Criteria:**
- [ ] `POST /api/admin/items` validates required fields
- [ ] `PUT /api/admin/settings` validates types
- [ ] Errors in a standardized format

---

#### #7 — Standardized error handling

**Labels:** `type: refactor` `size: M` `priority: medium`

**Description:**
Create an `ApiResponse` helper to standardize success and error responses.

**Error format:**
```json
{
  "success": false,
  "error": "Friendly message",
  "code": "VALIDATION_ERROR"
}
```

**Files involved:**
- `src/Http/ApiResponse.php` (create)
- All controllers (refactor `try/catch`)

**Acceptance Criteria:**
- [ ] Errors always have `success: false`
- [ ] Successes always have `success: true`
- [ ] Standardized codes

---

#### #11 — Pagination on GET /api/orders

**Labels:** `type: refactor` `size: M` `priority: medium`

**Description:**
Add `?page=1&per_page=50` to the order listing.

**Files involved:**
- `src/Controllers/OrderController.php`
- `src/Repositories/OrderRepository.php`

**Acceptance Criteria:**
- [ ] `GET /api/orders?page=2&per_page=25` returns 25 records
- [ ] Response includes `meta` with `page`, `per_page`, `total`, `last_page`
- [ ] Works as today when no parameters are given

---

### v2.3 — Frontend & Infra

#### #4 — Create a shared common.js module

**Labels:** `type: frontend` `size: L` `priority: medium`

**Description:**
Extract duplicated code (toasts, theme, fetch) into `public/assets/js/common.js`.

**Functions to extract:**
- `showMessage(text, type)` → `Alpine.store('toasts')`
- `applyTheme()` / `toggleDarkMode()` → `Alpine.store('theme')`
- `apiFetch(url, options)` — wrapper with token + 401 handling
- `sortPratoDoDiaFirst()` / `sortMenuPratoDoDiaFirst()`

**Files involved:**
- `public/assets/js/common.js` (create)
- `public/cashier/app.js`
- `public/kitchen/app.js`
- `public/admin/app.js`, `reports.js`, `settings.js`, `logs.js`
- Every `*.php` file that loads scripts

**Acceptance Criteria:**
- [ ] `common.js` loaded before the apps on every page
- [ ] Toast system centralized via `Alpine.store`
- [ ] Dark mode managed centrally
- [ ] Fetch wrapper handles token and automatic 401

---

#### #17 — Set up NPM + Vite for the frontend

**Labels:** `type: infra` `type: frontend` `size: XL` `priority: low`

**Description:**
Migrate from CDN to local dependencies with Vite.

**Files involved:**
- `package.json` (create)
- `vite.config.js` (create)
- `Dockerfile` / `docker-compose.yml` (adjust)

**Acceptance Criteria:**
- [ ] `npm install` downloads all dependencies
- [ ] `npm run build` generates a bundle in `public/assets/dist/`
- [ ] PHP pages reference the local bundle
- [ ] Final size smaller than the individual CDN scripts

---

#### #5 — Replace the file-based SSE

**Labels:** `type: refactor` `size: XL` `priority: low`

**Description:**
The SSE event system uses a JSON file in `sys_get_temp_dir()` — no lock, no queue. Replace it with Redis pub/sub or an `events` table in MySQL.

**Files involved:**
- `src/Services/OrderService.php`
- `public/api/events/stream.php`
- `docker-compose.yml` (if Redis)
- Migration `009_events.sql` (if MySQL)

**Acceptance Criteria:**
- [ ] Events aren't lost under concurrency
- [ ] Kitchen receives events within ≤ 2s
- [ ] Backward compatible with the existing frontend

---

## Recommended execution order

| Order | Issue | Branch | Reason |
|---|---|---|---|
| 1 | #12 strict_types | `chore/strict-types` | Mechanical, avoids future conflicts |
| 2 | #8 CORS | `feat/cors-env` | Small, independent |
| 3 | #9 JWT | `fix/jwt-no-fallback` | Small, independent |
| 4 | #10 paths | `refactor/paths-settings` | Touches several files, better done early |
| 5 | #1 PHPUnit | `feat/phpunit` | Tests protect the following refactors |
| 6 | #15 CI/CD | `feat/github-actions` | Depends on #1 |
| 7 | #2 Dish+Ingredient | `refactor/dish-ingredient` | Medium refactor, with tests |
| 8 | #3 AdminController | `refactor/admin-controller` | Medium refactor |
| 9 | #7 error handling | `feat/error-handler` | Touches controllers, better after the splits |
| 10 | #6 validators | `feat/admin-validators` | Complements #7 |
| 11 | #11 pagination | `feat/orders-pagination` | Independent |
| 12 | #4 common.js | `refactor/common-js` | Frontend, independent |
| 13 | #17 NPM+Vite | `feat/vite-setup` | Frontend infra |
| 14 | #5 SSE | `refactor/sse-events` | Most complex, last |

---

## Branch naming convention

```
type/short-description
```

Examples:
- `chore/strict-types`
- `feat/cors-env`
- `fix/jwt-no-fallback`
- `refactor/paths-settings`
- `feat/phpunit`
- `feat/github-actions`
- `refactor/dish-ingredient`
- `refactor/admin-controller`
- `feat/error-handler`
- `feat/admin-validators`
- `feat/orders-pagination`
- `refactor/common-js`
- `feat/vite-setup`
- `refactor/sse-events`

---

## Pull Request Template

```
## Summary
<!-- What this PR does, in 1-2 lines -->

## Related issues
Closes #N

## Type of change
- [ ] Refactor (no behavior change)
- [ ] Feature (new functionality)
- [ ] Fix (bug fix)
- [ ] Chore (config, build, dependencies)

## Checklist
- [ ] Code follows declare(strict_types=1)
- [ ] Tests pass (vendor/bin/phpunit)
- [ ] Manually verified in the browser
- [ ] CHANGELOG.md updated (if applicable)

## Screenshots (if frontend)
```
