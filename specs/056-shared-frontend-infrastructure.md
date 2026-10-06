# Spec 056 — Shared frontend infrastructure (API client, errors, toasts, theme)

## Metadata

- Status: Implemented
- Created: 2026-10-04
- Updated: 2026-10-05
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Shared frontend infrastructure"; Trello card #22. Related: "Connection awareness" (next v1.9 item) will build on the network-error signal introduced here.
- Related branch: `056`

## Context

Spec 055 removed the CDN dependency; the next v1.9 item asks to centralize repeated frontend behavior — API client, authentication, 401 handling, network errors, loading states, toasts, theme — so that each screen stops implementing its own network behavior.

Spec 051 already did this **for the Admin only**: `public/admin/auth.js` (`GFAdmin`) centralizes login, the Bearer header, 401/403 handling, toasts, the confirm dialog and the theme for the six admin pages. The cashier and kitchen were left out, and each still carries its own copy of the same pieces. This spec extracts the parts that are not Admin-specific into one shared script and makes all three screens use it.

Constraint (unchanged): no build step, no Node in `public/` (`CLAUDE.md`, spec 055) — the shared code is a plain `<script>` defining a global, like `GFAdmin` already is.

## Problem

Measured on `master` @ `59511e2` (2026-10-04):

1. **Every screen calls the API by hand.** `res.json()` appears 40 times across `public/cashier/app.js` (6), `public/kitchen/app.js` (13), `public/admin/app.js` (6), `reports.js` (7), `settings.js` (4), `audit-log.js`, `ingredients.js`, `logs.js` (1 each), `auth.js` (1) — plus `ingredients.js`'s three calls that only check `res.ok`. Each repeats: call, parse, check `res.ok`/`data.error`, build an `Error`. *(Corrected 2026-10-05 by `/spec-review`: the first draft said "41", which counted `version-badge.js`, a non-goal.)*
2. **Network failure shows a browser-internal message.** When the server is unreachable, `fetch` rejects with a `TypeError` whose message is browser-specific (Chrome: `Failed to fetch`), and every `catch` shows `err.message` verbatim in a toast — e.g. the cashier's "Enviar pedido" (`public/cashier/app.js:357-379`).
3. **Non-JSON error responses break parsing.** Most call sites run `await res.json()` before checking `res.ok` (e.g. `public/cashier/app.js:362`, `public/kitchen/app.js:292`). If a proxy/Apache answers with an HTML error page (502, 504), the operator sees a JSON `SyntaxError` (`Unexpected token '<'…`) instead of the server status.
4. **Toasts duplicated three times:** `showMessage()` + the `toasts` array in `public/cashier/app.js:303-310`, `public/kitchen/app.js:~490-497`, `public/admin/auth.js` (`page().showMessage`), with different lifetimes (cashier 5000 ms, Admin 4500 ms); the toast markup is copied in `public/cashier/index.php:88-97`, `public/kitchen/index.php:122-131` and `public/admin/_partials/shell-end.php:16-25`.
5. **Theme duplicated three times:** `darkMode` from `localStorage['gastroflow_darkMode']`, `applyTheme()`, `toggleDarkMode()` in `cashier/app.js:25,431-438`, `kitchen/app.js:23,504-511`, `auth.js` (`page()`).

## Goals

- One shared script, `public/assets/js/gf.js`, defining a global `GF` with: an API client that returns parsed JSON or throws one normalized error; toast state/behavior; theme state/behavior.
- Cashier, kitchen and every Admin page use it — no screen calls `fetch` for `/api/*` directly any more (the listed exceptions aside).
- Network failures and non-JSON error responses produce a readable Portuguese message, the same on every screen.
- One toast markup partial, included by all three screens.
- `GFAdmin` keeps only what is Admin-specific (session, Bearer token, 401/403, confirm dialog), built on top of `GF`.

## Non-goals

- **Connection status UI** ("Conectado / Reconectando / Conexão perdida") — that is the next v1.9 item, "Connection awareness". This spec only marks network errors (`err.network === true`) so that item has a single signal to build on.
- **Authentication for cashier/kitchen.** `/api/orders*`, `/api/menu`, `/api/kitchen/*`, `/api/printer/*` are unauthenticated by design (`docs/architecture.md:40`, baseline). The roadmap's "authentication / 401 handling" bullets are therefore satisfied by what already exists in `GFAdmin.authFetch` — the only screens that authenticate are the Admin's — and stay there.
- **Loading-state helper.** Loading flags (`submitting`, `loading`, …) are per-action page state; a generic wrapper would add an abstraction without removing duplication worth the name. Not centralized.
- The kitchen's SSE (`EventSource`, `public/kitchen/app.js:139-180`) — stays as is; it's the subject of "Connection awareness".
- `public/assets/js/version-badge.js` — fire-and-forget badge loaded on every page, including ones that don't load `gf.js`; keeps its own `fetch`.
- No visual redesign, no API change, no new dependency.

## Current behavior

Confirmed in code (2026-10-04):

- **API error shape** (backend): `src/ApiResponse.php:21` → `{ "success": false, "error": "<mensagem>", "code": "<CODE>" }`; the global error handler (`src/App.php:127-131`) returns `{ "error": "Erro interno do servidor.", "code": "INTERNAL_ERROR" }` (or the exception message outside production). `error` is always a string.
- **Admin** (`public/admin/auth.js`): `GFAdmin.authFetch(url, options)` adds `Authorization: Bearer`, on `401` clears the session and redirects to `/admin/?next=…`, on `403` throws `{ forbidden: true }`; it returns the raw `Response`, and every caller parses and checks it itself. `GFAdmin.page(extra)` provides `toasts`, `showMessage` (4500 ms), `darkMode`/`applyTheme`/`toggleDarkMode`, `askConfirm`/`closeConfirm`, `handleError`. `GFAdmin.login()` does its own `fetch`.
- **Cashier** (`public/cashier/app.js`): direct `fetch` to `/api/printer/status`, `/api/printer/reset`, `/api/menu`, `/api/orders/next-number`, `POST /api/orders`, `PATCH /api/menu/reorder`; own `toasts`/`showMessage` (5000 ms) and theme.
- **Kitchen** (`public/kitchen/app.js`): direct `fetch` to `/api/printer/*`, `/api/menu`, `/api/orders?…`, `/api/kitchen/food-summary`, `/api/orders/{id}/complete|uncomplete|cancel|print|items…`, `PATCH /api/orders/{id}`; own `toasts`/`showMessage` and theme. Background polling errors are deliberately silent (`kitchen/app.js:82`: "Rede instável não deve poluir a tela da cozinha com toasts").
- **Theme bootstrap before paint**: an inline `<script>` in `public/admin/_partials/head.php:37-43` (and equivalents in the cashier/kitchen `index.php`) sets `data-theme` early to avoid a flash; it stays inline (must run before the CSS paints, before any deferred script).
- No frontend unit-test infrastructure; browser behavior is covered by the Playwright suite (`tests/e2e/`, spec 040; 12 tests after spec 055).

## Proposed behavior

### `public/assets/js/gf.js` (new, plain script, global `GF`)

```text
GF.api(url, options?)  → Promise<parsed JSON | null>
  - options as in fetch; if options.json is set, it is JSON-encoded into body and
    Content-Type: application/json is added.
  - fetch rejects (server unreachable)           → throw Error('Sem conexão com o servidor.'), network: true
  - response not ok, body is JSON with `error`   → throw Error(body.error), status, code
  - response not ok, body not JSON / no `error`  → throw Error('Erro do servidor (HTTP <status>).'), status
  - response ok, body JSON with `error`          → throw Error(body.error), status, code   (current call sites treat this as failure)
  - response ok, empty body (204)                → null
  - response ok                                  → parsed JSON

GF.ui()  → plain object to spread into an Alpine x-data:
  toasts, showMessage(text, type = 'info')  (5000 ms, one value for every screen)
  darkMode, applyTheme(), toggleDarkMode()   (same localStorage key: gastroflow_darkMode)
```

### `public/_partials/toasts.php` (new)

The toast markup, taken from the Admin version (the one with `aria-live="polite"` and `aria-label="Fechar"`), included by `public/cashier/index.php`, `public/kitchen/index.php` and `public/admin/_partials/shell-end.php`.

### `GFAdmin` (changed)

- `authFetch` becomes `GFAdmin.api(url, options)`: `GF.api` + Bearer header, with the same 401 (clear session + redirect) and 403 (`forbidden`) behavior; returns parsed JSON like `GF.api`.
- `page(extra)` spreads `GF.ui()` instead of defining its own toasts/theme; keeps `askConfirm`, `handleError`, `guard`, role getters.
- `login()` uses `GF.api`.

### Screens

Every `/api/*` call in `cashier/app.js`, `kitchen/app.js` and `admin/*.js` goes through `GF.api` (cashier/kitchen) or `this.api`/`GFAdmin.api` (Admin). Behavior that is deliberately different stays explicit at the call site — e.g. the kitchen's silent background polling keeps its empty `catch`.

## Functional requirements

1. FR-1 — `public/assets/js/gf.js` defines `GF.api` with exactly the outcomes in "Proposed behavior", and `GF.ui()` with toasts and theme.
2. FR-2 — Thrown errors carry `message` (Portuguese, shown as-is in a toast), `status` (HTTP status, absent on network failure), `code` (from the body, when present) and `network: true` only on network failure.
3. FR-3 — After the change, `grep -rn "fetch(" public --include=*.js` outside `public/vendor/` returns only: `public/assets/js/gf.js` (the one implementation) and `public/assets/js/version-badge.js` (non-goal). No `res.json()` remains in `cashier/`, `kitchen/` or `admin/*.js`.
4. FR-4 — Toast markup exists once (`public/_partials/toasts.php`); `showMessage` exists once (`gf.js`); the theme methods exist once (`gf.js`). The inline pre-paint theme snippets stay.
5. FR-5 — Admin 401/403 behavior is unchanged: 401 → session cleared, redirect to `/admin/?next=<page>`; 403 → "Sem permissão" panel.
6. FR-6 — The kitchen's background printer polling (`/api/printer/status`, every 15 s) still does not show a toast on network failure. *(Corrected 2026-10-05 by `/spec-review`: the original text also listed "order lists on SSE events", but `fetchOrders` showed a toast on failure before this spec and still does — only the printer polling was ever silent.)*
7. FR-7 — `gf.js` is loaded before each screen's own script: cashier/kitchen via `<script>` in `index.php`; Admin via `head.php`, before `auth.js`.

## Non-functional requirements

- No build, no Node, no new dependency; `gf.js` is ES2017+ plain script (same as the existing files), served from `public/assets/js/` with the `?v=<mtime>` cache-busting already used for local assets where the page has it (`$asset()` in the Admin head).
- No API or backend change.
- Net reduction in frontend JS lines (measured with `wc -l` before/after and recorded).

## User flows

- **Cashier, server unreachable, presses "Enviar pedido":** today a toast says `Failed to fetch` (browser text); after: "Sem conexão com o servidor." The order stays on screen to be resent (unchanged).
- **Any screen, proxy returns an HTML 502:** today `Unexpected token '<'…`; after: "Erro do servidor (HTTP 502)."
- **Admin, token expired:** unchanged — back to login, returning to the page afterwards.
- **Kitchen, network blip during polling:** unchanged — no toast.
- **Theme toggle on any screen:** unchanged; same key, so the choice still carries across screens.

## API changes

Not applicable — frontend only; the backend contract (`{ success, error, code }`) is consumed as it is.

## Data model and migrations

Not applicable — no database change.

## Architecture and affected components

- New: `public/assets/js/gf.js`, `public/_partials/toasts.php`.
- Changed: `public/admin/auth.js`, `public/admin/_partials/head.php` (load `gf.js`), `public/admin/_partials/shell-end.php` (include toasts partial), `public/admin/app.js`, `reports.js`, `settings.js`, `audit-log.js`, `ingredients.js`, `logs.js`, `public/cashier/app.js` + `index.php`, `public/kitchen/app.js` + `index.php`.
- Tests: new Playwright spec under `tests/e2e/specs/`.
- No PHP class, Controller, Service or Middleware touched.

## Security considerations

- The Bearer token handling stays in `GFAdmin` only; `GF.api` never adds credentials, so the unauthenticated screens can't leak an Admin token to anything.
- `?next=` open-redirect protection (`GFAdmin.safeNext`) unchanged.
- Server-provided `error` strings are rendered with `x-text` (text, not HTML) — unchanged, keep it that way in the partial.

## Backward compatibility

- Pure frontend refactor; same endpoints, same payloads.
- Visible differences, all intentional: the two error messages above; Admin toasts last 5000 ms instead of 4500 ms.
- Browsers with old cached `app.js` + new HTML: the Admin uses `?v=<mtime>`; cashier/kitchen load `app.js` without a version query today — if `gf.js` is missing from an old cached page the screen would break until reload. Mitigation: add `?v=<mtime>` (`filemtime`) to `gf.js` and each screen's `app.js` in cashier/kitchen `index.php`, the same technique `head.php` uses.

## Acceptance criteria

1. AC-1 — `grep -rn "fetch(" public --include=*.js | grep -v "^public/vendor/"` lists only `public/assets/js/gf.js` and `public/assets/js/version-badge.js`; `grep -rn "\.json()" public/cashier public/kitchen public/admin --include=*.js` returns nothing.
2. AC-2 — `grep -rn "showMessage(text" public --include=*.js` and `grep -rln "gastro-toast-close" public --include=*.php` each return exactly one file (`gf.js`, `public/_partials/toasts.php`).
3. AC-3 — Playwright: on `/cashier/`, with `POST /api/orders` aborted (`route.abort('internetdisconnected')`), submitting an order shows a toast with the exact text "Sem conexão com o servidor." Fails on `master` (shows the browser's own message).
4. AC-4 — Playwright: on `/cashier/`, with `POST /api/orders` fulfilled as `502` + HTML body, the toast reads "Erro do servidor (HTTP 502)." Fails on `master`.
5. AC-5 — Playwright: on `/kitchen/`, with `/api/printer/status` aborted, no toast appears within 5 s (FR-6).
6. AC-6 — The existing Playwright suite (`printer-block`, `realtime-events`, `offline-assets`) passes, and the full CI pipeline is green on the PR.
7. AC-7 — Manual, logged in on 8081 with an admin user: Cardápio (list, create, edit, delete with confirm), Ingredientes, Configurações (save + test print error toast), Logs, Auditoria and Relatórios load and act as before; an expired/invalid token in `localStorage` redirects to login and back; a `manager` user sees the "Sem permissão" panel on Configurações. Observations recorded.
8. AC-8 — `wc -l` over the changed JS files is lower after than before (numbers recorded).

## Implementation plan

1. Create branch `056` from `master`; record `wc -l` baseline (AC-8).
2. Write `public/assets/js/gf.js` (`GF.api`, `GF.ui`).
3. Write `public/_partials/toasts.php`; include it in the three screens, removing the copies.
4. Admin: load `gf.js` in `head.php`; rebuild `GFAdmin` on `GF` (`api`, `page` spreading `GF.ui()`, `login`); migrate `app.js`, `reports.js`, `settings.js`, `audit-log.js`, `ingredients.js`, `logs.js` call sites from `const res = await this.api(); const data = await res.json(); if (!res.ok)…` to `const data = await this.api(...)`.
5. Cashier: load `gf.js` (with `?v=`), spread `GF.ui()`, migrate its 6 call sites.
6. Kitchen: same, 13 call sites, keeping the silent polling catches explicit.
7. Playwright spec for AC-3/4/5; confirm AC-3/4 fail on `master`.
8. Manual Admin pass (AC-7); greps (AC-1/2); `wc -l` (AC-8).
9. Docs pass in the same PR: `CHANGELOG.md` (v1.9.0 section), `docs/ROADMAP.md` status line, `docs/architecture.md` (frontend section: `gf.js` + `GFAdmin` layering), `docs/technical-decisions.md` row if the "no loading helper / no auth on operational screens" calls need recording, `CLAUDE.md` if it describes frontend helpers.

## Testing and validation strategy

- **Browser (automated):** the Playwright suite (spec 040) is the right layer — every behavior here only exists in a browser. New tests for AC-3/4/5 use `page.route` to simulate the failure (no server change); AC-3/4 must be shown red on `master` first.
- **Regression:** existing E2E suite + CI (AC-6). Note the pre-existing intermittent `printer-block.spec.ts` failures in full-suite runs (Trello #77) — a failure there must be compared against `master` before being attributed to this spec.
- **Manual:** AC-7 for the Admin, which the E2E suite does not cover logged-in (it has no admin login helper today).
- **Static:** AC-1/AC-2 greps, AC-8 line counts.
- PHPUnit/PHPStan are unaffected (no PHP logic change beyond `include`), but run in CI anyway.

## Rollout and rollback

- Rollout: merge; static files, no migration, no restart. Cache: `?v=<mtime>` on the new/changed scripts (see Backward compatibility).
- Rollback: revert the PR.

## Open questions

1. (non-blocking) Toast lifetime — unify at 5000 ms (cashier's value) or 4500 ms (Admin's)? Default: 5000 ms.
2. (non-blocking) Location of the shared partial: `public/_partials/` (proposed, mirrors `public/admin/_partials/`) vs. `public/assets/partials/`. Default: `public/_partials/`.

No blocking questions.

## Task checklist

- [x] Branch `056` created; `wc -l` baseline recorded
- [x] `gf.js` written
- [x] Toast partial written and included in the 3 screens
- [x] Admin migrated (`auth.js` + 6 page scripts)
- [x] Cashier migrated
- [x] Kitchen migrated
- [x] Playwright tests (AC-3/4/5), red on `master` for AC-3/4
- [x] AC-1/2/8 checks; AC-7 pass (scripted in a browser against 8081, see evidence)
- [x] CHANGELOG + docs pass (architecture, technical-decisions, ROADMAP; baseline/README/CLAUDE.md checked — nothing in them made false)
- [ ] CI green on the PR
- [x] `/spec-review` — findings (FR-6 wording, "41" count) fixed

## Implementation log

- **2026-10-05 — open questions resolved with their defaults.** Toast lifetime 5000 ms everywhere (the Admin's 4500 ms goes); shared partial at `public/_partials/toasts.php`.
- **`GF.api` contract, as built.** Reads the body as text, then `JSON.parse`; empty body → `null`. One case beyond the spec's table: an **ok** response with a non-JSON body also throws `Erro do servidor (HTTP <status>).` — a 200 with an HTML body is just as unusable as a 502. `options.json` sets `Content-Type` and the body; `FormData` (logo upload) passes through untouched.
- **`GFAdmin.authFetch` → `GFAdmin.api`.** Same 401/403 handling, now applied to the error `GF.api` throws (`err.status`), and it returns parsed JSON. `GFAdmin.login()` uses `GF.api` (not `GFAdmin.api`), so a wrong password's `401` shows the API message ("Credenciais inválidas.") instead of triggering the session-expired redirect.
- **Behavior changes accepted during migration (all in the CHANGELOG/technical-decisions):**
  - Error toasts now show the API's `error` string where some call sites used a fixed text before (e.g. Ingredientes showed "Erro ao adicionar ingrediente"; it now shows what the API says, which for validation is still the English `Validation failed`). The API wording is out of this spec's scope.
  - **Relatórios**: before, each of the seven parallel requests was checked with `if (data.success)` and a failed one left its chart silently empty. Now `Promise.all` rejects on the first failure and the page shows "Erro ao carregar relatórios: <mensagem>". All seven endpoints share the same role guard, so in practice they fail together; a partial server error is now visible instead of silent.
  - Cashier `init`: the suggested order number (`/api/orders/next-number`) stays optional — wrapped in `.catch(() => null)` so its failure doesn't hide the menu, as before.
  - Kitchen `reprintOrder`: the `PRINTING_BLOCKED` check moved from the response body to `err.code`, same effect.
  - Kitchen `fetchOrders` keeps showing a toast on failure (it did before; FR-6 covers only the printer polling, which stays silent).
- **Cache.** `gf.js` and each screen's `app.js` get `?v=<filemtime>` in cashier/kitchen `index.php`; the Admin loads `gf.js` through `$asset()` like its other scripts, before `auth.js`.
- **E2E test data.** First run of `api-errors.spec.ts` timed out clicking a menu card: `restaurant_test` has accumulated thousands of duplicated menu rows from the other suites (`/api/menu` returned 4273 items in hundreds of repeated categories), and the cashier never finished rendering them. Same on `master`'s screens, so not caused by this spec. The test now serves a one-item menu with `page.route` — it is about error handling, not the menu. The polluted test database may also explain Trello #77 (intermittent `printer-block`); noted on that card.
- **First CI run failed (PR #42, run `37405481971`) — test isolation, not app code.** Both cashier tests hit `strict mode violation: locator('.gastro-toast-text') resolved to 2 elements`: the expected toast ("Sem conexão com o servidor." / "Erro do servidor (HTTP 502).") **plus** "Erro ao imprimir o pedido #36". In CI the integration suite runs first against the same database and leaves a failed print job behind; the cashier polls `/api/printer/status` on load and announces it. Locally the test DB had no such failure, so it passed. Fix: the test also serves a clean `/api/printer/status` via `page.route`, the same isolation already applied to `/api/menu`.
- **AC-7 executed as a browser script, not by hand.** Two users were created in `restaurant_test` only, through the documented `bin/create-admin` (`spec056admin`/admin, `spec056mgr`/manager; random password kept in a temp file, deleted afterwards). They remain in the test database. Items/ingredients created during the run were deleted by the same run.

## Validation evidence

Environment: branch `056`, Docker Desktop started for the run, `web-e2e` on port 8081 (`MYSQL_DATABASE=restaurant_test`).

- **AC-1** — `grep -rn "fetch(" public --include=*.js | grep -v "^public/vendor/"` → `public/assets/js/gf.js:17` and `public/assets/js/version-badge.js:2` only. `grep -rn "\.json()" public/cashier public/kitchen public/admin --include=*.js` → no output. ✅
- **AC-2** — `grep -rln "showMessage(text" public --include=*.js` → `public/assets/js/gf.js`; `grep -rln "gastro-toast-close" public --include=*.php` → `public/_partials/toasts.php`. ✅
- **AC-3** — `npx playwright test specs/api-errors.spec.ts` on the branch → `3 passed`. Same spec with `master`'s screens (`git stash push -- public/cashier public/kitchen public/admin`, then `pop`): `Expected: "Sem conexão com o servidor." Received: "Failed to fetch"`. ✅
- **AC-4** — same runs: branch passes; `master` → `Expected: "Erro do servidor (HTTP 502)." Received: "Unexpected token '<', "<html><bod"... is not valid JSON"`. ✅
- **AC-5** — kitchen test (printer status aborted, wait for the `requestfailed` event, then assert zero toasts) passes on the branch. ✅ (it passes on `master` too — it guards against a regression, it doesn't detect an old defect)
- **AC-6** — full local suite `npx playwright test` → `15 passed (1.2m)` (printer-block 5, realtime-events 3, offline-assets 4, api-errors 3). `php -l` inside `restaurant_web_e2e` → `No syntax errors detected` for `cashier/index.php`, `kitchen/index.php`, `admin/_partials/head.php`, `admin/_partials/shell-end.php`, `_partials/toasts.php`; `node --check` clean on `gf.js` and all 9 migrated scripts. CI not yet run (no PR yet). ⏳
- **AC-7** — Playwright script logged in as `spec056admin` on 8081, zero `pageerror`s, results: Cardápio loaded (4273 items); create → "Item adicionado!", item listed; edit → "Item atualizado!"; deactivate → "Item desativado!"; delete through the confirm modal → "Item excluído com sucesso!", item gone; empty form → "Preencha todos os campos obrigatórios."; Ingredientes create/edit/delete → "Ingrediente adicionado!"/"…atualizado!"/"…excluído!", gone after delete; Configurações loaded ("Teste Auditoria"), save → "Configurações salvas com sucesso!", test print without IP → "IP da impressora não configurado. Defina o IP em Configurações antes de testar a impressão." (API message); Logs 167 lines, Auditoria 5 entries, no toast; Relatórios loaded, 3 charts, no toast. Invalid token → `/admin/?next=%2Fadmin%2Freports.php`, token cleared, after login back on `/admin/reports.php`. Wrong password → "Credenciais inválidas.". `spec056mgr` (manager) on Configurações → "Sem permissão" panel visible. A first run misreported two steps (a toast read before a slow 4273-item reload finished; a URL regex that matched `?next=…reports.php` before navigating) — re-run with condition waits, results above. ✅
- **AC-8** — `wc -l` over the 9 migrated JS files: **2017 before → 1828 after**; the new `gf.js` (67) + `toasts.php` (17) included, 1912 — still lower. ✅

Status `Implemented`: AC-6 still needs CI on the PR.
