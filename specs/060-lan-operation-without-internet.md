# Spec 060 — LAN operation without internet

## Metadata

- Status: Implemented
- Created: 2026-10-07
- Updated: 2026-10-26
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "LAN operation without internet". Builds on spec 055 (local frontend assets) and spec 057 (connection indicator).
- Related branch: `060`

## Context

The roadmap item asks to **verify** GastroFlow's core workflow with WAN access disabled — cashier, kitchen, administration, database, printing, local assets — so that "a restaurant with a functioning local network can continue normal GastroFlow operation during an internet outage".

Two earlier specs did most of the code work: 055 removed every CDN from the operational screens, and 057 made the screens say when the server is unreachable. What has never been done is the verification itself: running the whole flow from **other devices on the LAN** with the internet actually off, and documenting how to operate that way.

## Problem

Measured on `master` @ `8f6adda` (2026-10-07):

1. **Never verified end-to-end without WAN.** `tests/e2e/specs/offline-assets.spec.ts` blocks off-origin requests, but only for 4 pages (`/cashier/`, `/kitchen/`, `/admin/index.php`, `/admin/reports.php`) and only on `localhost`. No run has ever covered: a second device reaching the server by LAN IP, creating an order, the kitchen receiving it, the ticket printing, the Admin, and a backup — with the internet disconnected.
2. **Four Admin pages are outside the offline test:** `settings.php`, `ingredients.php`, `logs.php`, `audit-log.php`. A grep today finds no external `<script>`/`<link>` in them, but nothing stops one from being added later.
3. **LAN access is undocumented.** `README.md` (“Getting started”, “Using the app”) only mentions `localhost:8080`. Nothing explains reaching the server from a tablet by IP, keeping that IP fixed, allowing port 8080 through the host firewall, or configuring the printer by IP rather than hostname.
4. **What *does* need internet is undocumented.** Building the image (`docker compose build`, `composer install`), pulling `mysql:8.0`, upgrades, Swagger UI at `/api/docs/` (still on unpkg by decision of spec 055) and, in v2.1, NFC-e. An operator who runs `docker system prune` or `docker compose down --rmi all` during an outage cannot bring the app back until the internet returns.
5. **`db` and `web` have no restart policy.** In `docker-compose.yml`, only `print-worker` has `restart: unless-stopped`. After the server reboots (an internet outage often comes with a power blip), `db` and `web` stay down until someone runs `docker compose up -d`. Not an internet dependency, but it decides whether "normal operation continues" in practice.

Confirmed **not** a problem (investigated, no change needed):

- **Backend makes no outbound calls.** No `curl_init`, `file_get_contents('http…')`, Guzzle, `fsockopen` or external URL anywhere in `src/`, `bin/`, `common/`. JWT validation is local (`firebase/php-jwt`), the version badge reads the local `.git`, `db` is reached through Docker's internal DNS.
- **Printing is LAN-only.** `PrintService` opens a `NetworkPrintConnector($ip, $port, 5)` to the configured `printer_ip`/`printer_port` (`src/Services/PrintService.php:75`, `:187-188`).
- **No secure-context-only browser APIs.** Opened as `http://192.168.x.x:8080`, a page is not a secure context (only `localhost`/HTTPS are), so APIs such as `crypto.randomUUID`, `navigator.clipboard`, service workers or Notifications would silently fail there — while every test on `localhost` passes. A grep over `public/` (outside `public/vendor/`) finds none of them in use today.
- **CORS** defaults to `*` (`.env.example`, `CorsMiddleware`), so access by IP is not blocked.

## Goals

- A recorded, reproducible verification that the full workflow runs over the LAN with the internet disconnected.
- The offline-assets browser test covers every operational page.
- `README.md` explains how to operate over the LAN and what needs internet.
- `db` and `web` come back on their own after a server reboot.

## Non-goals

- **HTTPS on the LAN** (certificates, reverse proxy). Plain HTTP is the current model; out of scope.
- **Swagger UI offline.** Stays on the CDN by spec 055's decision (developer page).
- **Offline order queueing on the client** (spec 057 non-goal; the server is on the LAN, so it is reachable whenever the LAN is up).
- **Automating the WAN-off run in CI.** A real WAN-off run needs a second device and a router; the automated part is limited to what a browser on one host can prove (below).
- **NFC-e** — the roadmap's named exception (v2.1).

## Current behavior

See "Problem" — confirmed in code: `tests/e2e/specs/offline-assets.spec.ts` (`PAGES`, 4 entries), `docker-compose.yml` (`restart:` only on `print-worker`, line 73), `README.md` (no LAN/offline section), `src/Services/PrintService.php` (LAN printer connector), `src/Middleware/CorsMiddleware.php` (origin from `CORS_ALLOWED_ORIGIN`, default `*`).

## Proposed behavior

1. `offline-assets.spec.ts` checks all six Admin pages, the cashier and the kitchen.
2. `docker-compose.yml`: `restart: unless-stopped` on `db` and `web` (same policy `print-worker` already has).
3. `README.md` gains a short section, **“Operating over the LAN (no internet)”**:
   - give the server a fixed IP (DHCP reservation on the router); open `http://<server-ip>:8080/cashier/`, `/kitchen/`, `/admin/` from the other devices;
   - allow inbound TCP 8080 on the host firewall (Windows: one `New-NetFirewallRule` line);
   - configure the printer by IP (Admin › Configurações), not hostname — local name resolution may depend on the router;
   - what needs internet: installing/upgrading, `docker compose build`/`pull`, `composer install`, `/api/docs/`; **do not** prune images or run `docker compose down --rmi …` while offline;
   - the connection indicator (spec 057) is the signal that the *server*, not the internet, is unreachable.
4. `docs/technical-decisions.md`: record the rule "operational screens must not rely on secure-context-only browser APIs — they are served over plain HTTP on the LAN".
5. The spec's Validation evidence records a real WAN-off run (procedure below).

## Functional requirements

1. FR1 — `offline-assets.spec.ts` covers `/cashier/`, `/kitchen/` and `/admin/{index,reports,settings,ingredients,logs,audit-log}.php`; for each, no request leaves the server's origin and Alpine, Bootstrap CSS, Font Awesome and Inter load.
2. FR2 — `docker-compose.yml` declares `restart: unless-stopped` for `db`, `web` and `print-worker`.
3. FR3 — `README.md` contains the LAN section with the five points of "Proposed behavior" item 3.
4. FR4 — With the server's internet disconnected and the LAN up, from a device other than the server: the cashier creates an order, the kitchen shows it without reload, the ticket prints on the LAN printer, the Admin logs in and saves a change, Reports loads, and `bin/backup-db` produces a file.
5. FR5 — With the internet still disconnected, after a server reboot (or `docker compose restart` if a reboot isn't practical), the app answers again without anyone running `docker compose up`.

## Non-functional requirements

- No new dependency, no Docker/Composer version change (`CLAUDE.md`).
- No application-code change unless the WAN-off run finds a defect — in that case the defect is fixed in this spec and recorded in the Implementation log.

## User flows

**WAN-off verification (FR4/FR5), on the real install:**

1. Server running, LAN up, a second device (tablet/phone/notebook) on the same network, printer configured by IP.
2. Disconnect the internet **upstream** (router WAN cable out, or disable the WAN on the router). Confirm on the server: `ping 8.8.8.8` fails, `curl -I https://www.google.com` fails.
3. Second device: open `http://<server-ip>:8080/cashier/` → pill "Conectado"; create an order with "Imprimir" on.
4. Another tab/device: `/kitchen/` → the order appears without reload; ticket comes out of the printer.
5. `/admin/` → log in; change something reversible (e.g. toggle an item's availability) and revert; open Relatórios.
6. Server: `bin/backup-db` → file under `backups/`.
7. Reboot the server (or `docker compose restart`); without running `up`, reopen the cashier → "Conectado".
8. Reconnect the internet.

## API changes

Not applicable — no endpoint changes.

## Data model and migrations

Not applicable — no data change.

## Architecture and affected components

- `tests/e2e/specs/offline-assets.spec.ts` — 4 more pages in `PAGES`.
- `docker-compose.yml` — `restart:` on `db` and `web`.
- `README.md` — new LAN section, right below "Using the app". *(Corrected 2026-10-26 by `/spec-review`: originally said "linked from" it; it is placed under it, no separate link.)*
- `docs/technical-decisions.md`, `docs/architecture.md` (if it describes runtime/deployment), `CHANGELOG.md`, `docs/ROADMAP.md` — docs pass.
- No PHP layer changes.

## Security considerations

- The README firewall rule must open **only** TCP 8080, and say to scope it to the private network profile (`-Profile Private`) — never expose the app to the internet.
- `db` publishes `3306:3306` on every interface today (`docker-compose.yml:13-14`); the LAN section must **not** tell anyone to open 3306. Whether to bind it to `127.0.0.1` is noted as an open question, not changed silently.
- No secrets in the README or the spec evidence (IPs of the test LAN are fine; credentials are not).

## Backward compatibility

- `restart: unless-stopped` changes behavior only after a reboot or Docker restart: containers that were running come back. A container stopped by hand (`docker compose stop`) stays stopped — same semantics `print-worker` already has.
- The e2e suite gains 4 tests (30 → 34); the new pages, unauthenticated, redirect to the login after loading, like `index.php`/`reports.php` already do.

## Acceptance criteria

1. AC1 — `npx playwright test specs/offline-assets.spec.ts` lists and passes 8 tests (cashier, kitchen, 6 Admin pages), with `blocked` empty in each.
2. AC2 — `docker compose config` shows `restart: unless-stopped` for `db`, `web` and `print-worker`.
3. AC3 — `README.md` has the LAN section covering: fixed IP + URLs by IP, firewall rule for 8080 (private profile only), printer by IP, what needs internet (including "don't prune while offline"), and the role of the connection indicator.
4. AC4 — Validation evidence records, for the WAN-off run: proof the WAN was off (`ping`/`curl` failing), and each step of FR4 observed from a non-server device (order created, kitchen updated, ticket printed, Admin change saved, Reports loaded, backup file name).
5. AC5 — Validation evidence records the app answering after reboot/restart with WAN still off, without `docker compose up`.
6. AC6 — CI green on the PR (PHPStan, CS-Fixer, PHPUnit, Playwright).

## Implementation plan

1. Add the 4 Admin pages to `PAGES` in `offline-assets.spec.ts`; run the file.
2. `restart: unless-stopped` on `db` and `web`; `docker compose config` check.
3. README LAN section; technical-decisions entry (no secure-context APIs).
4. **WAN-off run on the real install** (user flow above) — needs the user: the router/WAN and a second device are physical. Record the results in Validation evidence; fix and log any defect found.
5. Docs pass: `CHANGELOG.md`, `docs/ROADMAP.md` status, `docs/architecture.md` if affected.

## Testing and validation strategy

The project has automated tests (PHPUnit, Playwright since spec 040) — the template's "no automated test infrastructure" note is outdated. Split:

- **Automated (what one browser on one host can prove):** `offline-assets.spec.ts` with all 8 pages (AC1); full suite + CI (AC6).
- **Config:** `docker compose config` (AC2).
- **Manual, real hardware (the actual roadmap requirement):** the WAN-off run (AC4, AC5) — executed by the user on the restaurant/dev network with a second device and the thermal printer, results recorded with the commands/outputs and what was observed on screen. This cannot be simulated honestly in CI: blocking WAN inside one browser is what AC1 already does.

## Rollout and rollback

Ships with the normal deploy. `restart:` takes effect on the next `docker compose up -d`. Rollback = revert the commit (then `docker compose up -d` to drop the restart policy).

## Open questions

1. *(non-blocking)* **Restart policy in scope?** Problem 5 isn't strictly an internet dependency. Proposed: include it — two lines, and it's what makes "normal operation continues" true after the power blip that often comes with an outage.
2. *(non-blocking)* **MySQL port 3306 published on all interfaces.** Any device on the LAN can reach MySQL (password-protected). Binding it to `127.0.0.1:3306:3306` would close that without affecting the app (which uses the internal `db` hostname). Proposed: **not** in this spec — security-relevant change, would affect anyone using a host DB client remotely; record as a follow-up card unless you want it here.
3. *(non-blocking)* **WAN-off run logistics.** Needs you at the router with a second device and the printer. If a router-level WAN cut isn't practical, an acceptable substitute is disabling the server's internet route while keeping the LAN (e.g. removing the default gateway) — the evidence must say which method was used.

## Task checklist

- [x] Step 1 — 4 Admin pages in `offline-assets.spec.ts`
- [x] Step 2 — restart policy on `db` and `web`
- [x] Step 3 — README LAN section + technical decision
- [~] Step 4 — WAN-off run on real hardware (user) — **partial** (cashier + order creation without internet; rest accepted untested by owner decision, 2026-10-26)
- [x] Step 5 — CHANGELOG + docs pass

## Implementation log

- 2026-10-07 — `/spec-implement` invoked on a Draft with no blocking open questions → treated as approval (Draft → Approved → In Progress). Open questions taken at their proposed defaults: restart policy **included**; MySQL 3306 **not** changed here (follow-up card); WAN-off method to be recorded with the evidence.
- README section written in English (the README's language); the firewall rule is scoped to `-Profile Private` and the text explicitly says not to open 3306.
- Docs pass also fixed a stale line in `specs/000-project-baseline.md` (SSE "driven by a temp-file signal" — false since spec 041), flagged by spec 057's review, with a dated note.
- Same PR carries spec 057's move to `Verified` (CI evidence of PR #43), per the one-PR-per-spec rule (no standalone PR for it).
- 2026-10-26 — **Renumbered 058 → 060.** While this work sat uncommitted on a local branch `058`, the number was taken upstream by spec 058 (per-item discount, PR #44) and 059 (PHP2Shell, PR #45). Work moved onto branch `060` from `master` @ `dbd8578`; every "spec 058" reference this work had added (CHANGELOG, compose comment, ROADMAP, architecture, technical-decisions, baseline, spec 057 note, e2e comment) now says 060. The e2e count in `docs/architecture.md` was recomputed: 22 + 8 (`item-discount.spec.ts`, spec 058 — not recorded there by that spec) + 4 = 34. "Measured on `master` @ `8f6adda`" in Problem is left as it was — it is when the investigation ran.
- 2026-10-26 — **WAN-off method.** The user has no separate WAN to cut at the router. First attempt, `Remove-NetRoute -DestinationPrefix 0.0.0.0/0` on the server, made the phone unable to reach the app — most likely because without a gateway Windows no longer identifies the network, the profile becomes Public and the `-Profile Private` inbound rule stops applying (not reproduced: the route came back via DHCP before it could be inspected). An artifact of the test method, not of GastroFlow: in a real outage the router stays up and the network keeps its profile. Second method, used for the evidence: `New-NetFirewallRule -DisplayName "GastroFlow teste sem internet" -Direction Outbound -Action Block -RemoteAddress 0.0.0.0-126.255.255.255,128.0.0.0-172.15.255.255,172.32.0.0-192.168.0.255,192.168.2.0-223.255.255.255` (blocks every outbound destination except the LAN `192.168.1.0/24`, loopback and the Docker/WSL ranges).
- 2026-10-26 — During the run, the first `POST /api/orders` (19:34:34) returned 500: `Unknown column 'discount_type'` — migration `021_order_item_discount.sql` (spec 058) had not been applied to this database yet. Applied at 19:35:04; the retry at 19:35:06 returned 201. Not a defect of this spec (the CHANGELOG already warns to run `bin/migrate`); the failed attempt left no partial order (id 130 rolled back, no order without items in `orders`/`order_items`).
- 2026-10-26 — **Owner decision: WAN-off testing ends here.** Accepted without further evidence: kitchen, Admin change, Reports and `bin/backup-db` during the outage (AC4 remainder), restart with WAN off (AC5), and printing on real hardware (no printer available). Rationale given: no runtime internet dependency exists (investigation above + the outage run). The spec therefore stays **`Implemented`**, not `Verified` — AC4 is partially met and AC5 has no evidence; the gaps are recorded, not papered over.
- No application code changed: the investigation found no runtime internet dependency to fix. If the WAN-off run finds one, it gets fixed here and logged.
- 2026-10-26 — **Defect found during the WAN-off run (not an internet dependency):** `bin/backup-db` run inside the `web` container (`docker exec -it restaurant_web bash`) failed with "mysqldump não encontrado no container db. O serviço está no ar?" — the real cause was `docker: command not found` (the script is host-run, spec 045), so the message pointed at the wrong thing. Fixed in `bin/backup-db` and `bin/restore-db`: a `command -v docker` check first, saying to run it on the host. Verified: `bash -n` on both; `PATH=/usr/bin:/bin ./bin/backup-db` → "Erro: comando docker não encontrado. Rode no host (fora do container web): ./bin/backup-db", exit 1.

## Validation evidence

Collected 2026-10-07 on branch `058`, local Docker Desktop (Windows).

| AC | Command / evidence | Result |
|---|---|---|
| AC1 | `npx playwright test specs/offline-assets.spec.ts` (against `web-e2e`, 8081) → `8 passed (21.6s)`: `/cashier/`, `/kitchen/`, `/admin/{index,reports,settings,ingredients,logs,audit-log}.php`, each with no off-origin request and Bootstrap/Font Awesome/Inter loaded | Met |
| AC2 | `docker compose config` → `restart: unless-stopped` under `db`, `print-worker` and `web` | Met |
| AC3 | `README.md` › "Operating over the LAN (no internet)": fixed IP + URLs by IP; firewall rule for 8080 with `-Profile Private`, "don't open 3306"; printer by IP; what needs internet + "don't prune while offline"; restart after reboot; connection pill meaning | Met (by reading) |
| AC4 | WAN-off run 2026-10-26, server `192.168.1.7`, Android 10 phone on the same Wi-Fi (identified by User-Agent: Docker Desktop shows every client as `172.18.0.1`). **Method:** outbound-block firewall rule on the server (details in the Implementation log), active **19:33:36 → 19:35:43** per the Windows Firewall event log (events 2097/2052); with it on, `Test-Connection 8.8.8.8` failed (`0x2b2a`) and the Ethernet profile stayed `Private` (output pasted by the user). Observed in the `web`/`app.log` logs inside that window: 19:34:19 `GET /cashier/` 200 + `/api/menu`, `/api/orders/next-number`, `/version` 200 + `/health/ready` 200 every 5 s; **19:35:06 `POST /api/orders` → 201**, order nº 1 (id 131) stored with its item. **Not covered yet:** kitchen receiving it, Admin change, Reports, backup during the outage. **Printing: not verified with hardware** (no printer available) — the worker tried the configured LAN address `192.168.0.100:9100` (`Connection refused`; also a different subnet from the LAN), no external access, and the failure did not affect the order | Partially met |
| AC5 | Reboot/restart with WAN off, no `docker compose up` | **Pending — needs the user** (planned: quit/reopen Docker Desktop with the outbound block on; a real reboot would not keep the block's intent tested) |
| AC6 | CI run `40409496152` on `682549a` (PR #46), raw log: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 113 files`; Unit `OK (238 tests, 474 assertions)`; Integration `OK (69 tests, 319 assertions)`; Playwright `29 passed / 5 skipped` (34 = the expected total), all 8 `offline-assets.spec.ts` tests ✓ — AC1 re-confirmed after the rebase onto `dbd8578` | Met |

Not run in this session (by choice — no code under `src/` or `public/` changed): PHPStan, PHP-CS-Fixer, PHPUnit, the full Playwright suite; CI covers them.
