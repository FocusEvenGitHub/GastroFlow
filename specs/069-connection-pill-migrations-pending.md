# Spec 069 — Connection indicator: "Atualização incompleta" instead of "sem conexão" on pending migrations

## Metadata

- Status: Implemented
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner:
- Related issue: pilot client report, 2026-10-31 (hotfix). Also carries spec 068's post-merge evidence (AC5).
- Related branch: `069`

## Context

The pilot client reported that the "sem conexão" warning shows **constantly on both PCs** — the cashier, which is also the server, and the kitchen, which reaches it over Tailscale — while everything works: orders go through, the kitchen receives them.

Measured from here over Tailscale on 2026-10-31 (read-only GETs):

```
GET http://100.118.154.42:8080/health/ready  → 503 {"success":false,"error":"Atualização incompleta: há migrações pendentes. Rode bin/migrate.","code":"MIGRATIONS_PENDING"}  (1.7 s)
5 more, 5 s apart                            → 503 1.9 s · 503 1.8 s · 503 1.1 s · 503 1.4 s · 503 1.7 s
GET /health/live                             → 200
GET /version                                 → {"success":true,"version":"v1.8.3-54-gfc37f2e"}
```

`fc37f2e` (2026-10-27, merge of PR #50) ships migrations up to `022_clean_fresh_install.sql` (spec 061), which changes nothing on an install with data (it only acts when the `fresh_install` marker table exists and there are no orders). Most likely `022` was never applied there: nothing breaks, only `/health/ready` says 503. Which file is pending can only be confirmed on the server (`bin/migrate --status`) — the 503 doesn't name it.

Spec 063 made `/health/ready` fail on pending migrations knowingly: *"Consequence, accepted: the cashier/kitchen connection pill (spec 057) shows 'Reconectando…'/'Conexão perdida' until the upgrade is finished — the screens would fail on the first new column anyway"* (`specs/063-…md`, Proposed behavior 2b). The pilot shows the other case: a pending migration that breaks nothing, and an operator told to look for a network problem that doesn't exist.

## Problem

`GF.ui().heartbeat()` (`public/assets/js/gf.js:162-180`) reduces the answer to `ok = res.ok`. A 503 `MIGRATIONS_PENDING` from a server that is up and answering in under 2 s is treated exactly like no answer: after 15 s the pill says "Conexão perdida" (`public/_partials/connection-status.php:10`) and both screens show the red banner "Sem conexão com o servidor desde HH:MM" (`public/cashier/index.php:82-87`, `public/kitchen/index.php:105-110`). `docs/troubleshooting.md:43` sends the operator to check the network.

## Goals

- When the server answers `MIGRATIONS_PENDING`, the screens say the system works but an update is incomplete, and to tell whoever looks after it — in plain words, no command (the fix, `bin/migrate` on the server, is in `docs/troubleshooting.md`).
- Every other unhealthy case keeps today's behavior.

## Non-goals

- Changing `/health/ready` (still 503 `MIGRATIONS_PENDING`, spec 063 — monitors and `docs/upgrading.md` rely on it).
- Running migrations automatically on the pilot's old layout (production does since spec 066).
- The Admin (it doesn't run the connection watch).
- Fixing the pilot install itself: that is an operator step (`bin/migrate` there), outside the code — see Rollout.

## Current behavior

- `heartbeat()`: `fetch('/health/ready', {cache: 'no-store', signal: AbortSignal.timeout(10000)})` → `beatOk = res.ok`; network error/timeout → `beatOk = false`. Then `updateConnection()`.
- `updateConnection()`: healthy = `beatOk && streamUp` → `connected` (and calls `onConnectionRestore` once when coming back); otherwise `reconnecting`, then `lost` after `LOST_AFTER_MS` (15 s).
- Pill: `connected` → "Conectado"; `reconnecting` → "Reconectando…" (spinning icon, yellow); else → "Conexão perdida" (red). CSS classes `.gf-conn.connected|reconnecting|lost` (`public/assets/css/style.css:295-297`).
- Banners: `x-if="connection === 'lost'"` in cashier and kitchen.
- Restore callbacks: cashier reloads an empty menu (`public/cashier/app.js:102`); kitchen refetches orders and an empty menu (`public/kitchen/app.js:54`).
- E2E: `tests/e2e/specs/connection-status.spec.ts` covers healthy, drop → reconnecting → lost → connected, 503 without a code → reconnecting, cashier opened without server, kitchen stream down.

## Proposed behavior

A fourth state, **`outdated`**:

1. `heartbeat()` keeps a reason: `ok` (200), `migrations` (503 whose JSON body has `code: "MIGRATIONS_PENDING"`), or `down` (anything else: no answer, timeout, `DB_UNAVAILABLE`, non-JSON error page, any other status). The body is read only on a non-OK response; a body that fails to parse is `down`.
2. `updateConnection()`:
   - `down`, or the kitchen stream down → today's path (`reconnecting`, then `lost` after 15 s).
   - else `migrations` → `outdated`, **immediately** (no 15 s wait — the server answered).
   - else → `connected`.
   - `onConnectionRestore` runs when the server becomes reachable again from `reconnecting`/`lost` — into `connected` **or** `outdated` — so the kitchen refetches after a real drop even if the server came back mid-upgrade. Going `outdated` → `connected` doesn't call it again (the server was reachable throughout).
   - `connectionDownSince` is cleared on entering `outdated` (it measures unreachability).
3. Pill: `outdated` → **"Atualização incompleta"**, warning colors (same as `reconnecting`), icon `fa-exclamation-triangle`, not spinning.
4. Banner, cashier and kitchen, `x-if="connection === 'outdated'"`, `alert-warning`:
   **"Atualização do sistema incompleta."** followed by "O sistema continua funcionando, mas algumas funções podem falhar. Avise o responsável pelo sistema." — same text in cashier and kitchen.
   **Non-technical on purpose** (owner, 2026-10-31): the screens are for cashier and kitchen staff, so no command, file or database term appears on them. The technical step (`bin/migrate`) lives in `docs/troubleshooting.md` and in `/health/ready`'s own message, for whoever looks after the server.
   The red "Sem conexão…" banner is not shown in `outdated`.
5. Docs: `docs/troubleshooting.md` (new row for "Atualização incompleta"; the existing "Conexão perdida" row stays), `docs/architecture.md` (Connection awareness: the fourth state, dated note), `docs/technical-decisions.md` (the spec 063 row: dated revision — the "accepted consequence" is no longer true), `docs/upgrading.md` troubleshooting row that says cashier/kitchen show "Conexão perdida" when `migrate` failed (now "Atualização incompleta" if the old `web` is still up — check the exact case while implementing), `CHANGELOG.md` (v1.9.0 › Correções).
6. Spec 068: AC5 evidence (`gh api repos/FocusEvenGitHub/GastroFlow --jq .license.spdx_id` → `Apache-2.0`, PR #55 merged 2026-10-31 `0eed1b5`) → `Verified`.

## Functional requirements

1. FR1 — `/health/ready` 503 with `code: MIGRATIONS_PENDING` → pill text "Atualização incompleta" within one heartbeat; the "Sem conexão com o servidor desde" banner is absent; the warning banner "Atualização do sistema incompleta." is present (cashier and kitchen).
2. FR2 — 503 with any other code or no JSON body, 500, network failure, timeout → unchanged (`Reconectando…` → `Conexão perdida` after 15 s).
3. FR3 — `outdated` → 200 → "Conectado", warning banner gone.
4. FR4 — Kitchen with the stream down and `/health/ready` saying `MIGRATIONS_PENDING` → `reconnecting`/`lost` (stream down wins).
5. FR5 — `onConnectionRestore` is called on `lost` → `outdated` and on `lost` → `connected`; not on `outdated` → `connected`.
6. FR6 — The heartbeat still never produces a toast.

## Non-functional requirements

- No new dependency, no build step (plain script, as spec 056). No backend change.
- Text visible on screens in Portuguese; code comments as the surrounding code.

## User flows

- **Pilot after the fix, before `bin/migrate`**: cashier and kitchen show the yellow pill "Atualização incompleta" and the yellow banner telling them to warn whoever looks after the system → that person runs `bin/migrate` (`docs/troubleshooting.md`) → within 5 s, "Conectado".
- **Real outage**: unchanged.

## API changes

Not applicable — the client now reads the `code` field `/health/ready` already returns; no endpoint changes.

## Data model and migrations

Not applicable — no data model changes.

## Architecture and affected components

- `public/assets/js/gf.js` (`heartbeat`, `updateConnection`, new state), `public/_partials/connection-status.php` (text/icon), `public/assets/css/style.css` (`.gf-conn.outdated`), `public/cashier/index.php` and `public/kitchen/index.php` (banner).
- `tests/e2e/specs/connection-status.spec.ts` (new cases).
- Docs listed in Proposed behavior 5; `CHANGELOG.md`; `specs/068-apache-license.md`. *(Added 2026-10-31 by `/spec-review`: `README.md` › Operating over the LAN, which lists the pill's states — it gains the fourth.)*

## Security considerations

Not applicable — no auth, input or secret involved; the screen shows a fixed text, not the server's message.

## Backward compatibility

- `/health/ready` unchanged; monitors unaffected.
- Screens: one more state; the three existing ones behave as before (existing E2E cases must stay green unchanged).

## Acceptance criteria

1. AC1 — E2E, cashier: `page.route('**/health/ready')` → 503 `{success:false, code:'MIGRATIONS_PENDING', error:'…'}` + `online` event → `.gf-conn` text "Atualização incompleta"; `.alert` with "Atualização do sistema incompleta." visible; `.alert` with "Sem conexão com o servidor desde" count 0 — even with `GF.LOST_AFTER_MS = 0`.
2. AC2 — E2E, same page: unroute + `online` → "Conectado", both banners count 0.
3. AC3 — E2E, kitchen: same as AC1 with the stream up; and with `**/api/events/stream.php` aborted + `MIGRATIONS_PENDING` → pill not "Atualização incompleta" (reconnecting/lost).
4. AC4 — The five existing tests in `connection-status.spec.ts` pass unchanged, including "banco fora do ar (503 em /health/ready)" (503 without a code → "Reconectando…").
5. AC5 — Full Playwright suite (port 8081) and PHPUnit/PHPStan/CS-Fixer (unchanged backend) green; CI green on the PR.
6. AC6 — Pilot, after it gets this code: with a migration pending its screens show "Atualização incompleta"; after `bin/migrate`, "Conectado" and `/health/ready` 200 over Tailscale. *(Depends on the pilot updating — may be recorded later.)*
7. AC7 — `specs/068-apache-license.md` → `Verified` with its AC5 evidence.

## Implementation plan

1. `gf.js`: reason in `heartbeat()`, `outdated` in `updateConnection()`, restore rule (FR5).
2. Pill partial + CSS.
3. Banners in cashier and kitchen.
4. E2E cases (AC1–AC3); run the connection spec, then the full suite.
5. Docs pass + CHANGELOG + spec 068 → Verified; Trello (hotfix card).

## Testing and validation strategy

The Playwright suite (spec 040) covers this, since the behavior only exists in the browser: new cases simulate the 503 with `page.route`, as the existing ones do — nothing changes on the server. Backend unchanged, but PHPUnit/PHPStan/CS-Fixer run anyway (CI does). AC6 is a field check over Tailscale (read-only `GET /health/ready`), possible only after the pilot updates.

## Rollout and rollback

- **The pilot doesn't get this fix by itself.** It runs `fc37f2e` in the pre-spec-066 layout; taking current `master` means the "First upgrade to v1.9" steps (`docs/upgrading.md`). **The pilot's warning goes away today only by running `bin/migrate` there** (`docker compose exec web php bin/migrate`, after `./bin/backup-db`) — independent of this spec.
- Restaurants get it with v1.9.0. Rollback: revert the PR; the screens go back to "sem conexão" on pending migrations.

## Open questions

1. ~~*(not blocking)* **Wording.**~~ **Resolved 2026-10-31 by the owner:** non-technical text on the screens, no command — pill "Atualização incompleta", banner "Atualização do sistema incompleta. O sistema continua funcionando, mas algumas funções podem falhar. Avise o responsável pelo sistema."
2. *(not blocking)* **Pilot update.** Run `bin/migrate` on the pilot now (proposed — fixes it today), and schedule its move to current `master` separately.

## Task checklist

- [x] `gf.js` state + restore rule
- [x] Pill partial + CSS
- [x] Banners (cashier, kitchen)
- [x] E2E cases + full suite — run locally in the Playwright Docker image (see Validation evidence); 5 `printer-block` tests can't run there (they shell out to `docker`)
- [x] Docs pass, CHANGELOG, spec 068 → Verified, Trello

## Implementation log

- 2026-10-31 — Banner wording changed before implementation, at the owner's request: **no command on the screens** ("não quero que coloquem o comando para o usuário, deixe um texto não técnico"). Pill "Atualização incompleta"; banner "Atualização do sistema incompleta. O sistema continua funcionando, mas algumas funções podem falhar. Avise o responsável pelo sistema." The `bin/migrate` step lives in `docs/troubleshooting.md` (new row) and in `/health/ready`'s own message. `/spec-implement` then counted as approval → `Approved` → `In Progress`.
- `gf.js`: `heartbeat()` reads the body only when `!res.ok`, inside the same `try` and timeout; `res.json().catch(() => null)` makes an HTML proxy page or empty body a plain drop. New flag `beatOutdated`; `updateConnection()` treats `(beatOk || beatOutdated) && streamUp` as reachable → `connected` or `outdated`; `onConnectionRestore` only when the previous state was `reconnecting`/`lost` (the old code called it on any non-`connected` → `connected`, which was the same set of states until now).
- CSS: `.gf-conn.outdated` shares `.gf-conn.reconnecting`'s yellow. Icon `fa-exclamation-triangle` (already in the vendored Font Awesome 6.4.0, used by the cashier's printer banner).
- E2E (`tests/e2e/specs/connection-status.spec.ts`), four new tests: cashier outdated with `LOST_AFTER_MS = 0` (no "sem conexão" banner, no `bin/migrate` text, back to "Conectado", no toast); cashier opened without server that comes back outdated → menu reloaded (FR5); kitchen with stream aborted + `MIGRATIONS_PENDING` → never "Atualização incompleta" (FR4); kitchen with **real SSE** outdated → banner, back to connected (inside the `cozinha com SSE real` describe, skipped in CI's `E2E_PHP_DIRECT` mode like its siblings).
- `docs/upgrading.md` checked: its "Conexão perdida" row is the production case where `migrate` failed and the new `web` never started — truly unreachable, so it stays. The pilot's case (code updated, `migrate` not run, old layout) is the new `troubleshooting.md` row.
- *(Superseded the same day — see next entry.)* **Playwright could not run on this PC.** `web-e2e` was started (`docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e`; `/health/ready` inside it → 200), but there is no Node on the host (`npx: comando não encontrado` — Node runs only in Docker here, per the owner's global setup) and no Playwright browser cache; and the agent's sandbox has its own network namespace, so `localhost:8081` isn't the host's port. CI runs the suite (`ci.yml:147-200`, PHP built-in server, `E2E_PHP_DIRECT=1`).
- **Playwright run in Docker instead** (owner: "pode instalar node e playwright, eu não me importo"). Following this PC's rule that Node runs only in Docker, nothing was installed on the host: the official image `mcr.microsoft.com/playwright:v1.63.0-noble` (same version as `tests/e2e/package-lock.json`, `@playwright/test` 1.63.0) runs the existing `tests/e2e/node_modules` with `--network host` against `web-e2e` (8081, `restaurant_test`), as user 1000:
  `docker run --rm --network host --ipc=host -u 1000:1000 -e HOME=/tmp -v …/tests/e2e:/work -w /work mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test`
  Limitation: `printer-block.spec.ts` sets the printer state by calling `docker compose … exec` from inside the test (`printer-block.spec.ts:70`); the image has no Docker CLI, so those 5 tests fail with `spawnSync docker ENOENT` there — environment, not this change (they don't touch the connection code; CI runs them its own way).
- **The new tests catch the bug.** With the five screen files stashed (`git stash push -- public/…`, then `git stash pop`), the 4 new tests against the *old* code: 3 fail exactly as the pilot saw it (pill "Conexão perdida"/"Reconectando…" with `MIGRATIONS_PENDING`), 1 passes (the kitchen-stream-down guard, which must pass on both).

## Validation evidence

**AC1–AC4** — `npx playwright test specs/connection-status.spec.ts` (Playwright Docker image, against `web-e2e`):

```
Running 11 tests using 1 worker
  ✓  1 caixa: servidor saudável mostra "Conectado" e nenhum aviso
  ✓  2 caixa: queda → Reconectando → Conexão perdida → Conectado, sem toast
  ✓  3 caixa: banco fora do ar (503 em /health/ready) também sai de "Conectado"
  ✓  4 caixa: migração pendente mostra "Atualização incompleta", nunca "sem conexão"      ← AC1, AC2
  ✓  5 caixa: servidor volta com migração pendente → recarrega o cardápio                  ← FR5
  ✓  6 cozinha: stream caído com migração pendente continua sendo queda                    ← AC3 (2nd half), FR4
  ✓  7 caixa: aberto sem servidor, carrega o cardápio sozinho quando a conexão volta
  ✓  8 cozinha: stream de tempo real caído nunca aparece como "Conectado"
  ✓  9 cozinha com SSE real › servidor saudável mostra "Conectado"
  ✓ 10 cozinha com SSE real › migração pendente com o stream no ar mostra "Atualização incompleta"   ← AC3 (1st half)
  ✓ 11 cozinha com SSE real › pedido criado durante a queda aparece ao reconectar
  11 passed (14.5s)
```

The 7 pre-existing tests (1–3, 7–9, 11) pass unchanged (AC4). Against the old code (stashed): tests 4, 5, 10 fail (`Expected "Atualização incompleta"`, `Received "Conexão perdida"` / `"Reconectando…"`), test 6 passes — see Implementation log.

**AC5** — partially:

- Full Playwright suite in the same image → `33 passed`, `5 failed` — all 5 in `printer-block.spec.ts`, all `Error: spawnSync docker ENOENT` at `setPrinterState` (`printer-block.spec.ts:70`): the test shells out to `docker compose`, absent in the image. Environment; to be covered by CI.
- `docker compose exec -T -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --no-progress` → `OK, but some tests were skipped! Tests: 322, Assertions: 826, Skipped: 2.` (backend unchanged; same counts as spec 066).
- `php -l` on the three PHP views → no syntax errors. PHPStan/CS-Fixer not run locally (no PHP file under `src/`, `bin/`, `tests/` changed; CI runs them).
- CI on PR #56: `test` pass (1m55s, run `41217555499`) — `composer validate`/`audit`, PHPStan, CS-Fixer, PHPUnit and the Playwright suite (built-in PHP server, `E2E_PHP_DIRECT=1`; `printer-block.spec.ts` included, SSE-real tests skipped there as designed) — and GitGuardian pass. With the local run above, every Playwright test has passed in at least one of the two environments.

**AC6** — pending: the pilot must update first (Rollout).

**AC7** — spec 068: AC5 evidence recorded there, status `Verified`.

Link check over `README.md` and `docs/*.md` → `111 links checked, 0 broken`.
