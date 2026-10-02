# Spec 051 — Admin UI redesign

## Metadata

- Status: Verified
- Created: 2026-10-02
- Updated: 2026-10-02
- Owner: Henry
- Related issue: Not applicable (client request)
- Related branch: `051`

## Context

The client asked for a refactor of the Admin UI and chose the **full redesign** scope (all admin pages, shared layout, one login). It's client work outside the milestones, the same bucket as spec 050 (`v1.8.1`). It builds on spec 050, which is already merged: the Admin menu shows the "Embalagem Simples/VIP" badge.

## Problem

Confirmed by reading `public/admin/*`:
- **Navigation is inconsistent.** Each of the 6 pages hand-writes its own header buttons, and each links a different subset:
  - `index.php:106-114`: Configurações, Relatórios, Logs.
  - `settings.php:87-93`: Cardápio, Relatórios, Logs.
  - `reports.php:97-100`: Cardápio, Logs.
  - `ingredients.php:56-57`: Relatórios, Logs.
  - `logs.php`: Relatórios, Auditoria (`:64-69`).
  - `audit-log.php`: Logs (`:64`).
  - **Ingredientes** isn't linked from any page. **Auditoria** is only reachable from Logs.
- **Login and auth are duplicated three times.** There are three separate login forms with the same code: `app.js:35-67`, `settings.js:21-62` and `reports.js:35-80`. Logs, Auditoria and Ingredientes have no form and redirect to `/admin/` (`logs.php:142`, `audit-log.php:139`, `ingredients.js:15`).
- **401 handling is copy-pasted** into every `fetch`, and **403 isn't handled at all**: a `manager` opening Configurações/Logs/Auditoria (admin-only in `src/Routes.php:94-99`) just gets a generic error.
- **Every page repeats the whole document head**: Bootstrap, Font Awesome, `style.css`, the dark-mode snippet, the top navbar and the toast markup. Only `index.php` includes `version-badge.js`.
- **The Cardápio page has usability problems**:
  - The "Adicionar Novo Item" form is always open above the list (`index.php:120-158`).
  - There's no category filter.
  - Delete uses the browser's `confirm()` (`app.js:270`).

## Goals

- **One shared admin layout:** a sidebar listing all 6 pages with the current one highlighted. On phones it collapses into an offcanvas.
- **One login and one auth helper for all pages.**
  - Login lives only at `/admin/`, and after logging in the user returns to the page they came from.
  - 401 logs out and redirects to the login.
  - 403 shows a clear "no permission" message.
  - Admin-only pages are hidden from the sidebar for `manager`.
- **Cardápio:** a toolbar (search + category filter + grid/list), "Novo item" in a modal, and delete confirmed in a modal.
- **Same endpoints, same payloads, same features** on every page.

## Non-goals

- No API, controller, route or database change.
- No new dependency. Same CDNs and versions: Bootstrap 5.3.0, Font Awesome 6.4.0, Alpine 3 (unpkg), Tom Select 2. No build step.
- The cashier and kitchen screens stay unchanged. They share `public/assets/css/style.css`, so admin styles go in a new file.
- No password-change UI, even though `PATCH /api/admin/account/password` exists without one. Possible follow-up.
- No per-page functional redesign of Relatórios/Logs/Auditoria/Ingredientes/Configurações beyond moving them into the new layout and the shared auth (their cards, tables and filters stay as they are).
- The follow-up from spec 050 ("Editar Pedido" refreshing prices) is a separate branch.

## Current behavior

- `public/.htaccess` serves `public/admin/*.php` directly, outside Slim, as plain PHP view scripts (`CLAUDE.md`). So a PHP `include` of a shared partial works with no routing change.
- Pages and their scripts:
  - `index.php` + `app.js` (Cardápio, 320 + 325 lines)
  - `settings.php` + `settings.js`
  - `reports.php` + `reports.js`
  - `ingredients.php` + `ingredients.js`
  - `logs.php` (inline script)
  - `audit-log.php` (inline script)
- **Session state:** stored in `localStorage` as `admin_token` and `admin_username`, plus `gastroflow_darkMode` (shared with cashier/kitchen) and `adminViewMode`.
- **Login response:** `POST /api/login` returns `{ token, user: { username, role } }` (`src/Controllers/AuthController.php:41-53`), but no page stores `role`.
- **Role gates:**
  - `adminOrManager`: menu, items, ingredients, reports.
  - `adminOnly`: settings, logs, audit-log, test-print.

## Proposed behavior

- **Layout partials:**
  - `public/admin/_partials/head.php` emits `<head>` contents. It takes `$pageTitle` and optional `$extraHead` and covers the existing CDN links, `style.css`, the new `admin.css`, and the dark-mode pre-paint snippet.
  - `public/admin/_partials/shell-start.php` emits the top bar (brand, link to Caixa/Cozinha, dark toggle, user name, Sair) and the sidebar. It takes `$activePage`.
  - `public/admin/_partials/shell-end.php` emits the shared toast container, Bootstrap/Alpine scripts, `auth.js` and `version-badge.js`.
  - Each page keeps its own `x-data` root and its own JS file.
- **Sidebar entries, in order:** Cardápio (`index.php`), Relatórios, Ingredientes, Configurações\*, Logs\*, Auditoria\*.
  - Entries marked \* are admin-only and hidden when the stored role is `manager`.
  - With no stored role (sessions from before this spec), all entries show and the server still enforces access.
- **`public/admin/auth.js`** defines a global `GFAdmin` object:
  - **`token`, `username`, `role`:** read from `localStorage`.
  - **`login(username, password)`:** calls `POST /api/login` and stores the token, username and role.
  - **`logout()`:** clears the three keys and goes to `/admin/`.
  - **`requireLogin()`:** redirects to `/admin/?next=<current path>` when there's no token.
  - **`authFetch(url, options)`:** adds the Bearer header. On 401 it logs out and redirects to `/admin/?next=...`. On 403 it throws `Error('Sem permissão para esta área.')`. Otherwise it returns the `Response`.
  - **Redirect after login:** the login at `/admin/` goes to `next` only if it starts with `/admin/` and contains no `//` (open-redirect guard); otherwise it stays on Cardápio.
- **Pages:**
  - `settings.php` and `reports.php` lose their own login forms and call `GFAdmin.requireLogin()` instead.
  - Every page replaces its hand-written `fetch` + `Authorization` + 401 code with `GFAdmin.authFetch`.
- **403 on an admin-only page:** the page shows an inline "Sem permissão para esta área" panel instead of its content.
- **Cardápio page:**
  - **Toolbar:** search, category filter pills (Todos + each category), grid/list toggle, and a "Novo item" button.
  - **Modals:** "Novo item" opens a modal with the current form fields. Delete opens a confirmation modal naming the item. The existing edit modal (with Tom Select components) stays.
  - **Items:** each card keeps its price, the availability toggle and the spec 050 packaging badge, plus a visual "Indisponível" state.
- **Visual language:** reuse the `style.css` tokens (`--primary`, `--surface`, `--radius`, …) and the dark theme (`[data-theme=dark]`). The new `public/assets/css/admin.css` covers only the shell and sidebar layout and the toolbar.

## Functional requirements

1. FR1 — Every admin page renders the same sidebar with all 6 entries, and the entry for the current page is marked active (`aria-current="page"`).
2. FR2 — On viewports < 992px the sidebar is hidden and opens as a Bootstrap offcanvas from a menu button in the top bar.
3. FR3 — With `admin_role = manager` in `localStorage`, Configurações, Logs and Auditoria are not rendered in the sidebar. With `admin` or no stored role, they are.
4. FR4 — Only `/admin/` shows a login form. Opening any other admin page without a token redirects to `/admin/?next=/admin/<page>.php`, and after a successful login the browser lands on that page.
5. FR5 — A `next` that doesn't start with `/admin/` or contains `//` is ignored; the user stays on Cardápio.
6. FR6 — A 401 from any admin API call clears `admin_token`/`admin_username`/`admin_role` and redirects to the login with `next`.
7. FR7 — A 403 on an admin-only page shows the "Sem permissão para esta área." panel; no raw error toast.
8. FR8 — Login stores `admin_role` from `data.user.role`. Logout (the "Sair" button in the top bar) clears all three keys.
9. FR9 — Cardápio: "Novo item" opens a modal; submitting it calls `POST /api/admin/items` with the same body as today; on success the modal closes and the list reloads.
10. FR10 — Cardápio: delete opens a confirmation modal naming the item; confirming calls `DELETE /api/admin/items/{id}`. `confirm()` is no longer used anywhere in `public/admin/`.
11. FR11 — Cardápio: the category pills filter the list; they combine with the name search.
12. FR12 — Settings, Reports, Logs, Audit log, Ingredients make the same API calls as before, with the same endpoints, methods and bodies, and show the same data.
13. FR13 — The dark mode toggle keeps working on every admin page and shares `gastroflow_darkMode` with cashier/kitchen. The version badge appears on every admin page.

## Non-functional requirements

- No new dependency, no CDN or version change, no build step.
- No horizontal page scroll at 375px width on any admin page.
- `public/assets/css/style.css` isn't modified, so cashier and kitchen are visually unchanged.
- Basic accessibility:
  - The sidebar is a `<nav aria-label="Admin">`.
  - The modals have titles and close buttons.
  - Focusable controls keep a visible focus ring.

## User flows

- **Admin:** opens `/admin/reports.php` while logged out → redirected to `/admin/?next=/admin/reports.php` → logs in → lands on Relatórios → navigates through the sidebar → "Sair" → back to the login.
- **Manager:** logs in and sees only Cardápio, Relatórios and Ingredientes. Typing `/admin/logs.php` directly shows "Sem permissão para esta área."
- **Admin on Cardápio:**
  - "Novo item" → fill in → Salvar → the item appears.
  - Filter "Viagem" → sees the two packaging items with their badges.
  - Delete an item → the confirmation modal names it → Excluir → the item is removed.
- **Admin on a phone:** taps the menu icon → the offcanvas opens with the sidebar → taps Configurações.

## API changes

Not applicable — no endpoint, method, payload or status code changes.

## Data model and migrations

Not applicable — UI only.

## Architecture and affected components

- **New:**
  - `public/admin/_partials/head.php`
  - `public/admin/_partials/shell-start.php`
  - `public/admin/_partials/shell-end.php`
  - `public/admin/auth.js`
  - `public/assets/css/admin.css`
- **Changed:**
  - `public/admin/index.php` + `app.js`
  - `settings.php` + `settings.js`
  - `reports.php` + `reports.js`
  - `ingredients.php` + `ingredients.js`
  - `logs.php`
  - `audit-log.php`
- **Untouched:** `src/`, `public/cashier/`, `public/kitchen/`, `public/assets/css/style.css`, and all tests apart from the possible e2e test below.
- The partials are plain PHP includes, the same "view script" model `CLAUDE.md` describes. No Slim involvement.

## Security considerations

- **Authorization is unchanged and stays server-side** (`JwtMiddleware` + `RoleMiddleware`). Hiding sidebar entries by role is cosmetic. The stored `admin_role` can be edited by the user, but that only reveals links, never data.
- **Open redirect:** `next` is validated (FR5) to stay under `/admin/`.
- **Token storage:** stays in `localStorage`, as today. No change in exposure.
- **XSS in partials:** `$pageTitle` and `$activePage` are set by the page itself, never from the request; they're still escaped with `htmlspecialchars`.

## Backward compatibility

- **Existing sessions:** a stored `admin_token` keeps working, and a missing `admin_role` shows all entries (FR3).
- **Old bookmarks:** URLs stay the same (`/admin/settings.php`, …), so bookmarks keep working.
- **Logins on settings/reports:** a user who used to log in directly there is now redirected to `/admin/` and brought back (FR4).

## Acceptance criteria

1. AC1 — Each of the 6 pages, loaded logged in as admin in a browser, shows the sidebar with 6 entries and exactly one with `aria-current="page"` matching the page.
2. AC2 — At 375×800, each of the 6 pages: the sidebar is not visible, the menu button opens an offcanvas with the entries, and `document.documentElement.scrollWidth <= 375`.
3. AC3 — Logged in as a `manager` test user: the sidebar has 3 entries (Cardápio, Relatórios, Ingredientes), and `/admin/logs.php` shows "Sem permissão para esta área."
4. AC4 — Logged out, opening `/admin/settings.php` lands on `/admin/?next=/admin/settings.php`; after login the URL is `/admin/settings.php`. With `?next=https://example.com` or `?next=//evil` the user lands on `/admin/` (Cardápio).
5. AC5 — With an invalid token in `localStorage`, opening any admin page ends on the login screen and the three keys are cleared.
6. AC6 — Cardápio: creating an item through the modal → `POST /api/admin/items` → 2xx and the item appears in the list. Deleting through the confirmation modal → `DELETE /api/admin/items/{id}` → the item disappears. `grep -rn "confirm(" public/admin` returns nothing.
7. AC7 — Cardápio: selecting the "Viagem" pill shows only that category, including the two packaging items with their badges, and typing "Simples" narrows the list further.
8. AC8 — Settings save, report loading, logs refresh, audit-log listing and ingredient CRUD each produce the same requests (method + URL + body) as on `master`, observed in the browser's network log, and render data.
9. AC9 — `git diff master -- src public/cashier public/kitchen public/assets/css/style.css` is empty.
10. AC10 — `phpunit` and `phpstan` still pass (no PHP under `src/` changes, but this is the CI gate). PHP-CS-Fixer runs over `src/`/`bin/`/`tests/` only, which this spec doesn't touch, so it should stay at 0. `php -l` passes on every changed `.php` file.

## Implementation plan

1. Write `auth.js` (the `GFAdmin` object) and `admin.css`.
2. Write the three partials.
3. Migrate `index.php`/`app.js` (Cardápio). Login uses `GFAdmin`, plus the toolbar, the "Novo item" modal, the delete modal and the category filter.
4. Migrate `settings.php`/`settings.js` and `reports.php`/`reports.js`: drop their login forms, use the partials and `authFetch`, and add the 403 panel on settings.
5. Migrate `logs.php`, `audit-log.php` (with the 403 panel) and `ingredients.php`/`ingredients.js`.
6. Browser verification of every AC (Chrome extension), light and dark, desktop and 375px. Then run the checks.

## Testing and validation strategy

- **What's automated:** PHPUnit, PHPStan and PHP-CS-Fixer exist (specs 004/034/035), but none of them cover `public/admin/` HTML/JS. Playwright (spec 040) covers only cashier and kitchen printer flows.
- **Automated checks still run:** `php -l` on changed files; PHPUnit and PHPStan unchanged as the CI gate (AC10); AC9 via `git diff`.
- **Main validation is manual in a real browser** (Chrome via the Claude extension), on `localhost:8080/admin/`, logged in by the user (no password typed by the assistant):
  - AC1–AC8, each recorded with the observed URL, DOM and network evidence.
  - AC2 at a 375px viewport.
- **AC3 (manager role):** needs a `manager` user. Created with a random password on the test instance (8081, `restaurant_test`), the same pattern spec 050 used, and removed afterward. Never on the dev database.
- **Data safety:** mutating checks (create/delete an item, save settings) run on the 8081 test instance, not the dev database. Settings save sends the same values back unchanged.
- **Optional Playwright test:** adding one for the sidebar/offcanvas/role hiding is optional and decided during implementation (it would be a browser-only behavior, which fits spec 040's admission rule).

## Rollout and rollback

Static files only, so deploy is the merge. Rollback is a revert. There's no data or state migration; the only new `localStorage` key, `admin_role`, is harmless to the old pages.

## Open questions

- Non-blocking: visual direction. The default is the existing GastroFlow look: Inter, the `--primary` coral, light/dark tokens from `style.css`, with a sidebar shell. If the client wants a distinctly different brand look, it should be said before implementation starts.
- Non-blocking: whether to add the optional Playwright test (see Testing). Decided during implementation, recorded in the log.
- Non-blocking: password-change UI for the existing endpoint stays out of scope; follow-up candidate.

## Task checklist

- [x] `auth.js` + `admin.css`
- [x] Partials (head, shell-start, shell-end)
- [x] Cardápio (index.php/app.js): shell, toolbar, modals, filter
- [x] Configurações + Relatórios migrated (login removed, authFetch, 403 panel)
- [x] Logs + Auditoria + Ingredientes migrated
- [x] Browser verification AC1–AC8 (desktop/375px, light/dark)
- [x] Checks (php -l, phpunit, phpstan, AC9 diff)

## Implementation log

- 2026-10-02 — Approved by `/spec-implement` invocation (Draft, no blocking open questions) → In Progress.
- 2026-10-02 — `GFAdmin.page(extra)` mixin in `auth.js` carries the state/methods every page needs (toasts, theme, `canSeeAdminOnly`, `forbidden`, `handleError`, `askConfirm`); merged with `Object.defineProperties(getOwnPropertyDescriptors)` so the page's getters (e.g. `filteredMenu`) aren't evaluated at merge time. The shell partials rely on it.
- 2026-10-02 — All scripts load from `head.php` with `defer`, Alpine last (it starts as soon as it runs, so page `x-data` functions must already exist). Consequence: Logs/Auditoria inline `<script>`s moved to `logs.js`/`audit-log.js` (new files, same code) — deviation from the spec's component list, needed for ordering.
- 2026-10-02 — Browser check showed Chrome running the cached old `app.js` against the new HTML (`categoryFilter is not defined`). Added `?v=<filemtime>` to local admin assets in `head.php` — same problem would hit real users after deploy. `style.css` (shared, untouched) left as is.
- 2026-10-02 — `confirm()` also existed in `ingredients.js` (not only `app.js`); replaced by the shared confirmation modal so AC6's grep holds. A single shared modal (`askConfirm`, in `shell-end.php`) serves Cardápio and Ingredientes.
- 2026-10-02 — Ingredientes' edit modal used `x-show` on a Bootstrap `.modal` (Bootstrap CSS keeps it `display:none`), so it likely never opened; switched to the `x-effect` pattern the Cardápio already used. To be confirmed in the browser.
- 2026-10-02 — `canSeeAdminOnly` = no stored role **or** `admin`; so `cashier`/`kitchen` roles (which can't log into admin APIs anyway, `RoleMiddleware`) are treated like `manager`. Slight generalization of FR3, same intent.
- 2026-10-02 — Browser check found the "Sem permissão" panel stretched to full width: `.gf-main > div { max-width: 1280px }` out-ranked `.gf-forbidden { max-width: 460px }`; selector changed to `.gf-main > .gf-forbidden`. Also themed the search icon box (`.input-group-text`) for dark mode.
- 2026-10-02 — Optional Playwright test (Open questions) **not added**: the flows were exercised with an ad-hoc Playwright script outside the repo (see evidence). A permanent one would need the duplicated-category workaround for `restaurant_test`, which belongs in a fix of that test data first.
- 2026-10-02 — Sidebar also shows Caixa/Cozinha links below 768px (the top bar hides them there to fit); not counted as admin entries in AC1.

## Validation evidence

All run on 2026-10-02. Read-only checks ran on the dev instance (8080), logged in by the user in Chrome (Claude extension). Everything that writes data ran on the test instance (8081 → `restaurant_test`) through a headless Playwright script kept outside the repo, with a throwaway `admin` and a throwaway `manager` created with random per-run passwords and deleted afterwards. `restaurant_web_e2e` was started for this and removed again at the end. Leftovers afterwards: `users LIKE 's051%'` = 0, `menu_items LIKE 'spec051%'` = 0, `ingredients LIKE 'spec051%'` = 0.

Note: `restaurant_test` repeats each seed category many times, the same pre-existing test-data problem seen in spec 050. The script merged same-name categories in the `/api/admin/menu` response before the page saw it, without touching any values.

- **AC1** — 8080, each page loaded in Chrome:
  - `/admin/`: 6 admin entries, `aria-current` = Cardápio.
  - `reports.php`: 6, Relatórios (4 stat cards, 3 canvases).
  - `ingredients.php`: 6, Ingredientes (13 rows).
  - `settings.php`: 6, Configurações (form loaded).
  - `logs.php`: 6, Logs (200 lines).
  - `audit-log.php`: 6, Auditoria (2 rows, confirmed against `GET /api/admin/audit-log` = 2 entries; the first count of 0 was taken before the request finished).
- **AC2** — Chrome is maximized at 2560px and `resize_window` didn't apply, so each page was loaded in a 375×800 iframe instead (real viewport `innerWidth` = 375).
  - On all 6 pages: `scrollWidth` = 360 (≤ 375), the `#gfSidebar` computed visibility is `hidden`, and the menu button is visible.
  - Clicking the menu button opens the offcanvas (`.show`, visible) with Cardápio, Relatórios, Ingredientes, Configurações, Logs, Auditoria plus Caixa and Cozinha. Screenshots were checked, open and closed.
- **AC3** — 8081, logged in as the throwaway `manager`:
  - Stored `admin_role` = `manager`.
  - Sidebar: `["Cardápio","Relatórios","Ingredientes"]`.
  - `/admin/logs.php` shows "Sem permissão para esta área." (screenshot).
  - The panel width was fixed afterwards and re-checked on 8080 by setting `forbidden = true` locally: computed `max-width: 460px`.
- **AC4**
  - 8080, logged out: opening `/admin/settings.php` lands on `/admin/?next=%2Fadmin%2Fsettings.php` with the login visible.
  - `GFAdmin.safeNext()` returns null for `https://example.com`, `//evil`, `/admin//evil.com` and `/admin/`, and returns `/admin/settings.php` for itself.
  - 8081, real login: `/admin/settings.php` → `/admin/?next=…` → login form submitted → `/admin/settings.php`.
  - 8080 with a session: `/admin/?next=%2Fadmin%2Fsettings.php` lands on `/admin/settings.php`.
- **AC5** — 8080, `admin_token` set to `invalid.token.value`, then `/admin/reports.php` opened. It ends on `/admin/?next=%2Fadmin%2Freports.php` with `admin_token`, `admin_username` and `admin_role` all null and the login visible. The user's session was backed up first and restored afterwards.
- **AC6** — 8081:
  - "Novo item" opens the modal (`#newItemModal.show`). Submitting sends `POST /api/admin/items {"name":"spec051 item teste","price":1.23,"category_name":"Viagem","description":""}`; the modal closes and the item appears in the list.
  - Delete opens the confirmation modal: "Tem certeza que deseja excluir "spec051 item teste"? Esta ação não pode ser desfeita." Excluir sends `DELETE /api/admin/items/5440` and the item disappears.
  - No native dialog was fired (a Playwright `dialog` listener stayed empty).
  - `grep -rn "confirm(" public/admin` → no matches.
- **AC7** — 8080:
  - The "Viagem" pill shows only the Viagem section: Bandeja Ovos, Cartela de Ovo 17, Embalagem Especial, Embalagem Simples, Manteiga de Garrafa, with badges "Embalagem VIP" and "Embalagem Simples".
  - Typing "Simples" leaves only "Embalagem Simples".
- **AC8** — 8081. Observed requests, compared against the `master` code of each page:
  - **Settings:** `PUT /api/admin/settings {"settings":{"restaurant_name":…,"printer_ip":…,"printer_port":…}}` with the values it had loaded (saved back unchanged), toast "Configurações salvas com sucesso!".
  - **Reports:** the same 7 GETs: sales, top-items, main-dishes, dining-options, peak-hours, prep-time, month-comparison. 4 stat cards rendered.
  - **Logs:** `GET /api/admin/logs?lines=200`, 51 lines.
  - **Audit:** `GET /api/admin/audit-log?limit=100`, 4 rows.
  - **Ingredients:**
    - `POST /api/admin/ingredients {"name","unit","category"}`
    - `PUT /api/admin/ingredients/15 {"id","name","unit","category"}`. The edit modal opened (`#editModal.show`) and the row was renamed.
    - `DELETE /api/admin/ingredients/15` through the confirmation modal; the row is gone.
  - No page errors on any page.
- **AC9** — `git diff --stat master -- src public/cashier public/kitchen public/assets/css/style.css` → empty.
- **AC10**
  - `php -l` passes on all 9 changed or new `.php` files under `public/admin/`, and `node --check` passes on all `public/admin/*.js`.
  - `vendor/bin/phpunit` → `Tests: 240, Assertions: 367, Skipped: 52`.
  - `vendor/bin/phpstan analyse` → `[OK] No errors`.
  - `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 107 files that can be fixed`.
- **Theme** — 8080 Cardápio: the toggle sets `data-theme="dark"` and `gastroflow_darkMode=true` (screenshot), and toggling again sets it back to light, which is the user's own preference.

**Not exercised** (no AC covers them; code paths changed only from `fetch` + manual header to `this.api()`):
- Logo upload and "Imprimir Teste" on Configurações.
- Editing an item and the availability toggle on Cardápio.
- Dark mode was checked visually only on Cardápio; the other pages use the same tokens and shell.
