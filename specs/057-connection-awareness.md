# Spec 057 — Connection awareness (cashier and kitchen)

## Metadata

- Status: Implemented
- Created: 2026-10-07
- Updated: 2026-10-07
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Connection awareness". Builds on spec 056 (`GF.api` marks network failures with `err.network === true`).
- Related branch: `057`

## Context

Spec 056 centralized the API client, toasts and theme in `public/assets/js/gf.js` and explicitly left the connection-status UI to this item. The roadmap asks that the cashier and kitchen "clearly communicate backend/network status (Connected / Reconnecting... / Connection lost)" and that "a stale kitchen interface should never silently look healthy".

## Problem

Measured on `master` @ `a1e7606` (2026-10-07):

1. **Nothing on screen reflects the connection.** Neither `public/cashier/index.php` nor `public/kitchen/index.php` has any connection indicator; the nav shows only brand, links and the theme toggle.
2. **The kitchen goes stale silently.** `public/kitchen/app.js:128-171` (`connectSSE()`) only `console.log`s on `connected` and, on `onerror`, waits 30 s and reopens the `EventSource` if it is `CLOSED`. If the server or network drops, the order list stays exactly as it was, looking healthy, and no new orders arrive.
3. **Orders missed during an outage are not refetched after a manual reconnect.** The native `EventSource` reconnection resends `Last-Event-ID` (spec 041), but `connectSSE()`'s manual reconnect creates a *new* `EventSource`, which does not carry it — `public/api/events/stream.php:47-52` then starts from "now", so events in the gap are lost until something else triggers `fetchAll()`.
4. **Background failures are swallowed.** The 15 s printer poll (`refreshPrinterStatus()`, `public/cashier/app.js:46-61`, `public/kitchen/app.js:63-80`) only `console.error`s on failure — on purpose (no toast spam), but it means nothing tells the operator the server is gone.
5. **Cashier started offline stays empty.** If `/api/menu` fails in `init()` (`public/cashier/app.js:80-95`), one toast is shown and the menu stays empty until the operator reloads the page.

## Goals

- Cashier and kitchen show a persistent connection indicator with three states: **Conectado**, **Reconectando…**, **Conexão perdida**.
- The kitchen never shows "Conectado" while its realtime stream is down.
- When the connection is lost, an explicit banner says the data on screen may be out of date (kitchen) / orders cannot be sent (cashier).
- When the connection comes back, the screen resynchronizes on its own (kitchen refetches orders; cashier reloads the menu if it never loaded).
- One shared implementation in `gf.js`, used by both screens.

## Non-goals

- **Admin pages.** The roadmap item names the cashier and kitchen; Admin actions already surface failures through toasts (spec 056).
- **Offline order queueing** (saving orders locally and sending later). Out of scope; an order that fails to send still fails with the existing message.
- **Disabling the cashier's "Enviar pedido" when lost.** The heartbeat lags reality by up to one interval; the request itself is the authority, and its failure already shows "Sem conexão com o servidor." (spec 056).
- **Changing the SSE protocol or `stream.php`.** No backend change in this spec (see Open questions for the one known limitation).
- **New endpoints.** `/health/ready` (spec 044) already exists.

## Current behavior

Confirmed in code:

- `GF` (`public/assets/js/gf.js`) offers `api()`, `readSetting()` and `ui()` (toasts + theme). `api()` throws with `network: true` when `fetch` rejects; otherwise with `status`/`code`.
- `GET /health/live` (no dependency check) and `GET /health/ready` (`select 1` against MySQL; `503 DB_UNAVAILABLE` on failure) are public, no JWT (`src/Routes.php:45-46`, `src/Controllers/HealthController.php`).
- Kitchen realtime: `EventSource('/api/events/stream.php')`, a plain PHP script outside Slim that polls the `events` table every 2 s and sends a `: keepalive` comment every 30 s (`public/api/events/stream.php`).
- The cashier has no realtime stream; only the 15 s printer poll runs in the background.
- Under the CI e2e server (`E2E_PHP_DIRECT=1`), `tests/e2e/router.php` answers `/api/events/*` with 204 on purpose (spec 040), so an `EventSource` there ends `CLOSED` immediately.

## Proposed behavior

### Connection state (shared, `gf.js`)

`GF.ui()` gains a `connection` field — `'connected' | 'reconnecting' | 'lost'`, initially `'connected'` — and a `startConnectionWatch(onRestore)` method:

- **Heartbeat:** `GET /health/ready` every **5 s**, aborted after **4 s** *(changed to 10 s during validation, 2026-10-07 — see Implementation log)*. Success = HTTP 2xx. Anything else (network error, timeout, non-2xx such as the `503` when MySQL is down) = failure. `/health/ready` rather than `/health/live` because a server whose database is down is, for the operator, just as unusable.
- **Extra failure source (kitchen):** the screen can report its realtime stream as down/up (`setStreamUp(false|true)`); the state is "healthy" only when the last heartbeat succeeded **and** the stream (if the screen has one) is up.
- **Transitions:**
  - healthy → `connected`;
  - unhealthy for less than **15 s** → `reconnecting`;
  - unhealthy for **15 s or more** → `lost`;
  - from `reconnecting`/`lost` back to healthy → `connected`, and `onRestore()` is called exactly once per recovery.
- **Faster detection:** the browser's `offline`/`online` window events and any `GF.api` call that fails with `network: true` trigger an immediate heartbeat instead of waiting for the next tick.
- The interval/thresholds are constants at the top of `gf.js`.

### Indicator (both screens)

A shared partial `public/_partials/connection-status.php`, included in the nav of both screens, shows a small pill with `role="status"`:

| State | Look | Text |
|---|---|---|
| `connected` | green dot | Conectado |
| `reconnecting` | amber, spinning icon | Reconectando… |
| `lost` | red | Conexão perdida |

Below the nav, only while `connection === 'lost'` (rendered with `x-if`, not `x-show` — the Bootstrap `!important` utilities issue recorded in spec 039):

- **Kitchen:** red alert — "Sem conexão com o servidor desde HH:MM. A lista de pedidos pode estar desatualizada." (HH:MM = when the outage began).
- **Cashier:** red alert — "Sem conexão com o servidor desde HH:MM. Pedidos não poderão ser enviados até a conexão voltar."

### Screen-specific wiring

- **Kitchen:** `connectSSE()` reports `setStreamUp(true)` on the `EventSource`'s `open`/`connected` event and `setStreamUp(false)` on `onerror`. `onRestore` = `fetchAll()` (resync — covers the events lost by the manual reconnect, problem 3) and, if the `EventSource` is `CLOSED`, `connectSSE()` right away instead of waiting the remaining 30 s. *(Superseded 2026-10-07 during implementation: a `CLOSED` stream keeps the state unhealthy, so `onRestore` could never fire to reopen it; the `onerror` retry of a `CLOSED` stream went from 30 s to 5 s instead — see Implementation log.)*
- **Cashier:** `onRestore` = if `menu` is empty, rerun the menu + next-number load from `init()`; otherwise nothing.

## Functional requirements

1. FR1 — `GF.ui()` exposes `connection` (initially `'connected'`), `startConnectionWatch(onRestore)` and `setStreamUp(up)`.
2. FR2 — `startConnectionWatch` polls `GET /health/ready` every 5 s with a 4 s timeout; HTTP 2xx is success, anything else is failure.
3. FR3 — With a failing heartbeat (or a stream reported down), `connection` becomes `'reconnecting'` on the first failure and `'lost'` once the unhealthy period reaches 15 s.
4. FR4 — The first successful heartbeat with the stream up (if any) sets `connection` to `'connected'` and calls `onRestore` once per recovery; it is not called while already `connected`.
5. FR5 — `window` `offline`/`online` events and a `GF.api` failure with `network: true` trigger an immediate heartbeat.
6. FR6 — Cashier and kitchen nav show the pill from `public/_partials/connection-status.php` with the text "Conectado" / "Reconectando…" / "Conexão perdida" matching `connection`.
7. FR7 — While `lost`, each screen shows its banner (texts above) including the HH:MM the outage began; the banner is removed from the DOM when the state leaves `lost`.
8. FR8 — Kitchen: the SSE `onerror` makes the state unhealthy even if the heartbeat succeeds; on recovery the kitchen calls `fetchAll()`; a `CLOSED` stream is retried every 5 s (was 30 s). *(Wording corrected 2026-10-07 — originally "reopens a `CLOSED` stream immediately on recovery", which could not work; see Implementation log.)*
9. FR9 — Cashier: on recovery, if the menu is empty, the menu and the suggested order number are loaded again.
10. FR10 — The heartbeat does not produce toasts (the existing "no toast spam from background polling" rule of spec 039 stays).

## Non-functional requirements

- No new dependency, no build step (`CLAUDE.md`, spec 055); plain script additions only.
- Load: one `select 1` per open screen every 5 s — negligible for a single-location install.
- Accessibility: the pill uses `role="status"`/`aria-live="polite"` and conveys the state by text, not color alone.
- Works in both light and dark theme (uses the existing CSS tokens).

## User flows

**Kitchen, Wi-Fi drops:** the pill turns amber "Reconectando…" within ~5 s → after 15 s it turns red "Conexão perdida" and the banner "Sem conexão com o servidor desde 12:41…" appears → Wi-Fi returns → within ~5 s the pill is green, the banner disappears and the order list is refetched, including orders created during the outage.

**Kitchen, server restarted (`docker compose restart web`):** same as above; the SSE error alone is enough to leave `connected`.

**Cashier opened while the server is down:** pill "Reconectando…" → "Conexão perdida" + banner, empty menu → server comes back → pill green, menu appears without reloading the page.

**MySQL down, Apache up:** `/health/ready` returns 503 → both screens go to "Reconectando…" then "Conexão perdida".

## API changes

Not applicable — no endpoint is added or changed; the frontend starts consuming the existing `GET /health/ready`.

## Data model and migrations

Not applicable — no data change.

## Architecture and affected components

- `public/assets/js/gf.js` — connection state, heartbeat, `startConnectionWatch`, `setStreamUp`, network-error hook in `api()`.
- `public/_partials/connection-status.php` — **new**, the pill markup (same pattern as `_partials/toasts.php`).
- `public/cashier/index.php`, `public/kitchen/index.php` — include the pill in the nav; add the `lost` banner.
- `public/cashier/app.js` — call `startConnectionWatch()` from `init()`; restore callback.
- `public/kitchen/app.js` — call `startConnectionWatch()`; wire `connectSSE()` to `setStreamUp`; restore callback.
- Shared CSS for the pill — wherever the nav styles (`.gastro-nav`) already live (to be located during implementation, no new stylesheet unless none fits).
- No PHP layer (Controller/Service/Repository) changes.

## Security considerations

`/health/ready` is already public by design (spec 044) and returns a fixed message, never exception detail. No auth boundary crossed, no new data exposed; banner texts are static (no server text rendered).

## Backward compatibility

- No API, schema or data change.
- `GF.ui()` only gains fields; Admin pages spread `GF.ui()` too (via `GFAdmin`) but never call `startConnectionWatch`, so nothing runs there.
- Existing e2e tests mock specific `/api/*` routes; the new heartbeat hits `/health/ready`, which is not mocked and is served normally — on the CI built-in server too (Slim route). Under `E2E_PHP_DIRECT=1` the kitchen's stream answers 204, so the kitchen will show `reconnecting`/`lost` there; the existing kitchen e2e tests must keep passing with the banner present (to be confirmed by running them).

## Acceptance criteria

1. AC1 — Cashier and kitchen, server healthy: the nav pill reads "Conectado" and no connection banner exists in the DOM.
2. AC2 — With `/health/ready` failing (aborted or 503): the pill reads "Reconectando…" within 6 s, and "Conexão perdida" plus the screen's banner (with HH:MM) once 15 s have passed since the first failure.
3. AC3 — After AC2, `/health/ready` succeeding again: within 6 s the pill reads "Conectado" and the banner is gone.
4. AC4 — Kitchen: with `/health/ready` healthy but the SSE stream failing, the pill does not read "Conectado".
5. AC5 — Kitchen: on recovery (AC3), a new `GET /api/orders?status=pending…` request is made, and an order created during the outage appears without reloading the page.
6. AC6 — Cashier opened with `/api/menu` failing: after recovery the menu renders without reloading the page.
7. AC7 — No toast is shown by the heartbeat in any state.
8. AC8 — PHPStan, PHP-CS-Fixer, PHPUnit and the full Playwright suite pass (CI green).

## Implementation plan

1. `gf.js`: add constants, `connection` state, `startConnectionWatch`, `setStreamUp`, heartbeat with `AbortSignal.timeout`, `online`/`offline` listeners, and the network-error hook in `api()`.
2. `public/_partials/connection-status.php` + pill CSS.
3. Kitchen: include pill + banner; wire `connectSSE()` and `onRestore`.
4. Cashier: include pill + banner; `onRestore` reloading an empty menu (extract the menu/next-number load from `init()` into a method both use).
5. Playwright spec `tests/e2e/specs/connection-status.spec.ts` (AC1–AC7), using `page.route` to fail `/health/ready` / the stream; the 15 s threshold is reached by setting `GF.LOST_AFTER_MS = 0` and firing `online` (planned as `page.clock`; changed during implementation — see Implementation log).
6. Run the existing e2e suite against `web-e2e` (8081) and confirm nothing regressed; manual check with `docker compose stop web` / `start web` on the dev instance.
7. Docs pass: `CHANGELOG.md` (v1.9.0 section), `docs/ROADMAP.md` status line, `docs/architecture.md`/`docs/technical-decisions.md` (heartbeat on `/health/ready`, thresholds), `specs/000-project-baseline.md` if it describes the frontend realtime.

## Testing and validation strategy

The project has automated tests (PHPUnit Unit/Integration, Playwright e2e since spec 040) — the template's "no automated test infrastructure" note is outdated. This change is frontend-only, so the evidence belongs in Playwright, per spec 040's admission rule (breaks only in a browser):

- `connection-status.spec.ts`: cashier and kitchen, healthy → pill "Conectado" (AC1); `page.route('**/health/ready', abort)` → "Reconectando…", `GF.LOST_AFTER_MS = 0` → "Conexão perdida" + banner (AC2); unroute → "Conectado", banner gone (AC3); kitchen with the stream route failing → not "Conectado" (AC4); count `/api/orders` requests after recovery (AC5); cashier with `/api/menu` failing then restored (AC6); assert the toast container stays empty (AC7).
- Tests depending on a live SSE stream skip under `E2E_PHP_DIRECT=1`, like `realtime-events.spec.ts`.
- Manual check on the dev instance (8080): `docker compose stop web` with the kitchen open → amber then red + banner; `docker compose start web` → green, list refreshed; `docker compose stop db` → red on both screens.
- AC8: run `phpstan`, `php-cs-fixer --dry-run`, `phpunit`, Playwright; confirm CI on the PR.

## Rollout and rollback

Ships with the normal deploy (static files; no migration). Rollback = revert the commit; no data to undo.

## Open questions

1. *(resolved 2026-10-07: follow-up card)* **Stuck-but-open stream.** If `stream.php` hangs (e.g. a blocked DB query) while Apache and MySQL answer `/health/ready`, the `EventSource` stays `OPEN` and the kitchen would read "Conectado" without receiving events. Detecting it needs a server change (e.g. turning the `: keepalive` comment into a named `ping` event the client can time out on). Proposed: leave out of this spec and record it as a follow-up card, unless you want it included.
2. *(resolved 2026-10-07: 5 s / 15 s kept; timeout raised to 10 s during validation)* **Thresholds** — 5 s heartbeat / 4 s timeout / 15 s to "Conexão perdida". Proposed defaults; change if the kitchen should escalate faster or slower.
3. *(resolved 2026-10-07: `/health/ready`)* **`/health/ready` vs `/health/live`.** The spec uses `ready` so "MySQL down" also shows as lost; if you'd rather the indicator mean strictly "network/server reachable", switch to `live`.

## Task checklist

- [x] Step 1 — `gf.js` connection state + heartbeat
- [x] Step 2 — connection-status partial + CSS
- [x] Step 3 — kitchen wiring (pill, banner, SSE, resync)
- [x] Step 4 — cashier wiring (pill, banner, menu reload)
- [x] Step 5 — Playwright `connection-status.spec.ts` (written; **not yet run** — see Validation evidence)
- [x] Step 6 — e2e + manual stop/start check (manual by the user; e2e partially — see Validation evidence)
- [x] Step 7 — CHANGELOG + docs pass

## Implementation log

- 2026-10-07 — `/spec-implement` invoked on a Draft with no blocking open questions → treated as approval (Draft → Approved → In Progress). The three non-blocking open questions were taken at their proposed defaults: stuck-but-open stream left as a follow-up; 5 s / 4 s / 15 s; `/health/ready`.
- **Deviation from FR8 (reopen a `CLOSED` stream "immediately on recovery").** As specified it deadlocks: while the stream is `CLOSED`, `streamUp` is false, so the state never becomes healthy and `onRestore` never fires — reopening would wait for the old 30 s timer. Instead `connectSSE()`'s `onerror` now retries a `CLOSED` stream after `GF.HEARTBEAT_MS` (5 s) rather than 30 s; `onRestore` (`fetchAll()`) then fires when the reopened stream's `open` arrives. Observable result is the one FR8 asked for (back to "Conectado" within ~5 s of the server returning, then a refetch). `CLOSED` only happens on an HTTP error response (network errors make the browser retry by itself), so the retry costs at most one request per 5 s while the stream is refused.
- `startConnectionWatch()` runs at the top of `init()` (before the first fetch) in both screens, so a server that is down at page load is detected by the first heartbeat instead of after the initial fetches settle.
- Cashier: the menu/next-number load moved out of `init()` into `loadMenu()` so `onRestore` can reuse it. The suggested number is now applied only when `orderNumberAuto` is still true — a number the cashier typed while the page was loading/reconnecting is not overwritten (before, `init()` set it unconditionally and re-set `orderNumberAuto = true`).
- Kitchen `onRestore` also reloads the menu if it is empty (same reason as the cashier: the edit modal's "Adicionar item" list would otherwise stay empty after a start without server). Small addition beyond FR8, same pattern as FR9.
- Phone width (≤ 576 px): the pill shows only the colored dot; its text stays in the DOM, visually hidden, so screen readers still announce it (`role="status"`).
- New state fields in `GF.ui()` are named without a leading underscore (`beatOk`, `streamUp`, `onConnectionRestore`) — checked no conflict with any existing `x-data` field on the cashier, kitchen or Admin (`grep` over `public/`).
- **Deviation — heartbeat timeout 4 s → 10 s, one beat in flight at a time (2026-10-07, during validation).** On the user's Docker Desktop/Windows machine (also busy with other work), `curl` measured `/health/ready` at 2.5–6 s and `/health/live` at 0.6–6 s with the containers idle; the 4 s timeout turned a slow-but-alive server into "Reconectando…" (seen in the e2e run). Slow is not down, so the timeout is now 10 s, and `heartbeat()` skips a tick while the previous one is still pending (`beating`), so a late old response can't overwrite a newer one. Cost: a server that *hangs* (accepts but never answers) is flagged after ~10 s instead of ~4 s; refused connections are still detected immediately.
- **E2E test adjustments during validation:** the cashier recovery test uses a fixed menu (the test DB had accumulated 510 categories / 4,273 items, and even a healthy cashier rendered 0 item cards — pre-existing, unrelated to this spec); the file uses a 20 s `expect` timeout because the kitchen opens its stream only after the first (slow) load.
- E2E tests avoid real waits: the `online` event triggers an immediate heartbeat (FR5) and `GF.LOST_AFTER_MS` is read on every update, so a test sets it to 0 to reach "Conexão perdida" on the next beat.

## Validation evidence

Collected 2026-10-07 on branch `057`, Docker Desktop on Windows, local instances 8080/8081. The machine was also being used for other work (user, 2026-10-07), which explains the request latencies noted in the log.

Run and observed in this session:

- `node --check` on `gf.js`, `kitchen/app.js`, `cashier/app.js` → no errors (also after the timeout change).
- `docker compose exec web php -l` on `public/_partials/connection-status.php`, `public/kitchen/index.php`, `public/cashier/index.php` → `No syntax errors detected` ×3.
- `vendor/bin/phpstan analyse` → `[OK] No errors`.
- `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 110 files that can be fixed`.
- `MYSQL_DATABASE_TEST=restaurant_test vendor/bin/phpunit` → `OK, but some tests were skipped! Tests: 250, Assertions: 603, Skipped: 1`.
- `npx playwright test specs/connection-status.spec.ts --repeat-each=2` (final version of code and test) → `14 passed (2.2m)`.
- Full Playwright suite (before the final fixes) → 19 passed, 3 failed: the cashier recovery test (fixed since — fixed menu), and `printer-block.spec.ts` "cozinha: o botão de reimprimir…" and "as duas telas avisam qual pedido falhou". **The two printer-block tests fail identically with master's `public/`** (A/B: `git stash push -- public/assets public/cashier public/kitchen`, rerun → 2 failed, `git stash pop`) — pre-existing, caused by the bloated test DB (5 s waits on a kitchen loading an 855 KB menu), not by this spec.
- The full suite was **not** re-run after the final fixes (user decision: "n precisa fazer teste").

| AC | Evidence | Result |
|---|---|---|
| AC1 | "caixa: servidor saudável…", "cozinha com SSE real › servidor saudável…" | Met (14/14 run) |
| AC2 | "caixa: queda → Reconectando → Conexão perdida…" (abort) + 503 test. The 15 s threshold itself is exercised with `LOST_AFTER_MS = 0`, not with a real clock | Met, with that caveat |
| AC3 | same test, back to "Conectado", banner count 0 | Met |
| AC4 | "cozinha: stream de tempo real caído nunca aparece como Conectado" | Met |
| AC5 | "pedido criado durante a queda aparece ao reconectar" — the refetched `/api/orders?status=pending` contains the order created during the outage | Met |
| AC6 | "caixa: aberto sem servidor, carrega o cardápio sozinho…" (fixed menu) | Met |
| AC7 | `.gastro-toast` count 0 at the end of the outage test | Met |
| AC8 | PHPStan, CS-Fixer and PHPUnit OK; full Playwright not re-run after the final fixes; 2 pre-existing printer-block failures locally; CI not run yet (no PR) | Partially met — pending CI on the PR |
| manual | `docker compose stop web` / `start web` / `stop db` with both screens open | OK — reported by the user (before the 4 s → 10 s timeout change), not observed in this session |
