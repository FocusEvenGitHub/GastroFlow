# Spec 067 — Documentation structure (configuration, security, backup & restore, troubleshooting)

## Metadata

- Status: Verified
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Documentation structure" (closing order item 3, agreed 2026-10-27). Trello card `[v1.9] Documentation structure` (https://trello.com/c/ivxU6DKl).
- Related branch: `067`

## Context

The roadmap requires, before Community v2, documentation for: README, Installation, Configuration, Architecture, Security, Printing, Backup & Restore, Upgrading, API, Troubleshooting, Contributing — and "documentation should reflect actual behavior". Specs 062 (installation, commands), 063 (upgrading) and 065 (printing) delivered part of it. This spec covers what is still missing, except Contributing, which the closing order puts in item 5 together with `SECURITY.md` and the support policy.

## Problem

Measured on `master` @ `0910b7f` (2026-10-31), by reading files (nothing executed):

| Topic | Today | Gap |
|---|---|---|
| README | `README.md` | — (Documentation table to extend) |
| Installation | `docs/installation.md` | — |
| Configuration | `.env.example` comments; Admin settings described nowhere as a whole | **missing** |
| Architecture | `docs/architecture.md` | — |
| Security | spread over `docs/architecture.md` › Authentication, README › Operating over the LAN, specs 002/012/013/015/018/053 | **missing** (operator-facing) |
| Printing | `docs/printing.md` | — |
| Backup & Restore | one table in `docs/commands.md`, one paragraph in README | **missing**, and incomplete (below) |
| Upgrading | `docs/upgrading.md` | — |
| API | `public/api/docs/openapi.yaml`, served at `/api/docs/`; README › API | — (link from the docs index) |
| Troubleshooting | three separate tables (installation, upgrading, printing) | **missing** as one entry point |
| Contributing | README › Contributing (short) | item 5 of the closing order — out of scope |

Facts found while measuring that the new documents must state, because no document states them today:

1. **The backup covers only the database.** `bin/backup-db` dumps `MYSQL_DATABASE` (`mysqldump … | gzip`, `bin/backup-db:44`). Not in the backup: the uploaded logo — since spec 066 in the `uploads` volume (`docker-compose.yml:35`, `src/Settings.php:131`) — and `.env` (database passwords, `JWT_SECRET`). A restore onto a new server with only the `.sql.gz` loses the logo, and without the old `.env` the operator must set new passwords before MySQL's first start.
2. **Backups are not scheduled and stay on the same disk.** No cron/timer exists anywhere in the repository; `backups/` is inside the install folder (`bin/backup-db:21`).
3. **`bin/restore-db` replaces every table** (dump's `DROP TABLE IF EXISTS`), asks for `RESTAURAR` unless `--yes`, and refuses a non-interactive stdin without `--yes` (`bin/restore-db:90-98`); it validates gzip integrity first (`:80`).
4. **Configuration lives in two places**: `.env` (read at start: `JWT_SECRET` — `src/Routes.php:33`, throws when unset; `CORS_ALLOWED_ORIGIN` default `*` — `src/App.php:76`; `APP_ENV`/`APP_DEBUG`/`APP_TIMEZONE`/`QUEUE_*`/`EVENTS_RETENTION_DAYS` — `src/Settings.php:38-96`; `MYSQL_*`; `COMPOSE_FILE`; `GASTROFLOW_VERSION`) and the `settings` table, edited in Admin › Configurações (`restaurant_name`, `printer_ip`, `printer_port` — `006_settings.sql:15-18`; `discount_max_percent` — `021_order_item_discount.sql:45`; logo upload). MySQL credentials take effect only on the volume's first start (`.env.example`, already documented in installation troubleshooting). `docker-compose.yml:31,42` hard-codes `TZ: America/Sao_Paulo` for the containers, independent of `APP_TIMEZONE`.
5. **Security model, as implemented**: only `/api/admin/*` requires a JWT; `/api/orders*`, `/api/menu`, `/api/kitchen/*` and the cashier/kitchen screens are unauthenticated by design (trusted LAN — `docs/architecture.md:40`). Roles `admin` and `manager` (`src/Routes.php:91-92`). JWT HS256, 8 h, no revocation, password change doesn't invalidate issued tokens. bcrypt passwords, no default user. Login throttling 10/15 min per IP+user, 30 per IP (spec 053). MySQL on `127.0.0.1` only (spec 062). Plain HTTP on port 8080, no TLS anywhere in the stack. `APP_DEBUG=false` hides error details (spec 012). Known open items on the Backlog: CORS default `*`; `display_errors` on in the image (fatal errors shown in the page); audit log secret redaction.
6. **The log has no rotation**: Monolog `StreamHandler` on `logs/app.log` (`src/App.php:43`), in the `logs` volume — it grows forever. Admin has a log viewer (`LogController`).

## Goals

- Every topic in the roadmap list (except Contributing) has a document an operator can follow, and every claim in it matches the code.
- One troubleshooting entry point, without duplicating the existing tables.
- The backup procedure, as documented, lets a restaurant rebuild on a new server: database **and** logo **and** `.env`.

## Non-goals

- Contributing, `SECURITY.md`, license, support policy (closing order items 4–5).
- Changing any behavior: no code, script, compose or schema change. Gaps found (backup scope, no log rotation, CORS `*`, `display_errors`) are documented as they are and tracked on the Backlog — see Open questions 1 and 2.
- Translating existing documents; rewriting the API reference (`openapi.yaml`).
- Developer-facing security review (that is `/security-review`'s job).

## Current behavior

See Problem — each claim cites the file it was read from.

## Proposed behavior

New documents, in English like the rest of `docs/`, written for the person running the restaurant's server (same audience as `docs/installation.md`):

1. **`docs/configuration.md`**
   - Where configuration lives: `.env` (server, needs a restart) × Admin › Configurações (database, immediate).
   - `.env` table: every variable of `.env.example` — required/optional, default, effect, when it takes effect (`docker compose up -d`; MySQL credentials only on first start, with the link to the installation troubleshooting row).
   - Admin settings table: restaurant name, printer IP/port (→ `docs/printing.md`), maximum item discount (0 disables), logo (formats, where it is stored).
   - Time zone: `APP_TIMEZONE` × the containers' `TZ`.
   - Production vs development values (`APP_ENV`, `APP_DEBUG`, `COMPOSE_FILE`).
2. **`docs/security.md`**
   - Deployment model: trusted local network; never expose port 8080 to the internet; only 8080 open, on the private network; MySQL on `127.0.0.1`; no HTTPS (what that means on a shared Wi-Fi).
   - What is protected and what isn't: Admin (JWT, roles `admin`/`manager` and what each can do) vs the cashier/kitchen screens and their API (open to anyone on the network).
   - Accounts: no default user, `bin/create-admin`, 8-character minimum, change password, login throttling, sessions last 8 h and can't be revoked (what to do if a token or password leaks: change `JWT_SECRET` → every session ends).
   - Secrets: `.env` (permissions, never in git/screenshots, `JWT_SECRET` generation), backups contain all data.
   - Production settings: `APP_ENV=production`, `APP_DEBUG=false`, `CORS_ALLOWED_ORIGIN`.
   - Updates: run release tags; security fixes arrive as releases (→ `docs/upgrading.md`); image updates need `--build`.
   - Known limitations, stated plainly: CORS default `*`, `display_errors` in the image, no TLS, no token revocation.
   - Reporting a vulnerability: placeholder line pointing to `SECURITY.md` "(item 5)" — **or** omitted until then (Open question 3).
3. **`docs/backup-and-restore.md`**
   - What a complete backup is: database (`bin/backup-db`) + logo (`uploads` volume) + `.env` — with the commands for each (`docker compose cp web:/var/www/html/public/uploads …`; copying `.env` to a safe place).
   - Copy off the server (another disk, cloud); keeping several.
   - Scheduling: an example cron line for the host (Linux) and a note for Windows Task Scheduler — as an example the operator adds, not something GastroFlow installs.
   - Restore on the same server: `bin/restore-db` (destructive, `RESTAURAR`, `--yes`), then the logo.
   - Restore on a new server: install from `docs/installation.md` with the **old** `.env` (before the first `up`), restore database, restore logo, `bin/migrate --status`.
   - Check a backup (gzip test; a test restore into a separate database is out of reach of the scripts — say so).
   - `docs/commands.md` and README keep their short versions and link here.
4. **`docs/troubleshooting.md`**
   - First checks: `docker compose ps`, `/health/ready` (and `MIGRATIONS_PENDING`), `/version`, `docker compose logs <service>`, Admin › Logs / `logs/app.log`.
   - "Where to look" index by symptom area, linking the existing tables in `installation.md`, `upgrading.md`, `printing.md` instead of copying them.
   - Rows that belong to no existing table: "Conexão perdida"/"Reconectando…" in cashier/kitchen, kitchen not updating, Admin returns to login (expired session), "Sem permissão" (manager on an admin-only page), login 429, disk filling up (`logs/app.log` unrotated, `backups/`, `bin/jobs-prune`/`bin/events-prune` already run by the worker), server rebooted.
5. **Docs pass**
   - README › Documentation table: the four new docs + the API docs (`/api/docs/`).
   - `docs/commands.md`, `docs/installation.md` (troubleshooting, step 11 backup), `docs/upgrading.md` (backup step), `docs/printing.md`: link to the new docs where they overlap.
   - `docs/ROADMAP.md` › Documentation structure: status line.
   - `CHANGELOG.md` entry under v1.9.0; `docs/architecture.md`/`specs/000-project-baseline.md` only if something they say turns out false while writing.
   - Trello: Backlog cards for the gaps Open question 1/2 decide to track.

## Functional requirements

1. FR1 — `docs/configuration.md` exists and lists every variable present in `.env.example` and every key seeded in `settings` (`restaurant_name`, `printer_ip`, `printer_port`, `discount_max_percent`) plus the logo.
2. FR2 — `docs/security.md` exists with the sections of Proposed behavior item 2.
3. FR3 — `docs/backup-and-restore.md` exists and covers database, logo and `.env`, same-server and new-server restore, off-server copy and a scheduling example.
4. FR4 — `docs/troubleshooting.md` exists, links each existing troubleshooting table (installation, upgrading, printing) and has the rows of Proposed behavior item 4 that no existing table covers.
5. FR5 — README's Documentation table lists the four new docs and `/api/docs/`; every topic of the roadmap list except Contributing maps to a linked document.
6. FR6 — No file outside `docs/`, `README.md`, `CHANGELOG.md`, `specs/` changes.

## Non-functional requirements

- Every command shown in the new docs is copy-pasteable and was run once against the development stack (except destructive ones — see Testing).
- Every factual claim about behavior can be traced to a file; where the doc says something the code doesn't do, that is a defect of this spec.
- Same tone and format as `docs/installation.md`: short sections, tables for symptoms, commands in code blocks.

## User flows

- **Operator setting up**: installation → configuration (what each setting does) → security (what to keep closed) → backup (set up a schedule).
- **Something broke**: troubleshooting → first checks → the matching table.
- **Server died**: backup-and-restore › new server.

## API changes

Not applicable — documentation only.

## Data model and migrations

Not applicable — documentation only.

## Architecture and affected components

New: `docs/configuration.md`, `docs/security.md`, `docs/backup-and-restore.md`, `docs/troubleshooting.md`. Edited: `README.md`, `docs/commands.md`, `docs/installation.md`, `docs/upgrading.md`, `docs/printing.md`, `docs/ROADMAP.md`, `CHANGELOG.md`. No `src/`, `bin/`, `public/`, compose or migration change.

## Security considerations

- `docs/security.md` must describe limitations honestly (unauthenticated operational API, no TLS, CORS `*` default, `display_errors`) without turning into an exploitation guide — state the risk and the mitigation, not attack steps.
- The docs must never contain real secrets; examples use placeholders and the `openssl rand -base64 48` generation already in `.env.example`.
- Backup docs must say that the `.sql.gz` and the copied `.env` hold every order and every password hash/secret, and must be stored accordingly.

## Backward compatibility

Not applicable to behavior. Links: existing anchors in `installation.md`/`upgrading.md`/`printing.md` are kept (the new docs link to them, nothing is moved out).

## Acceptance criteria

1. AC1 — For each variable in `.env.example` (`grep -oE '^#? ?[A-Z_]+=' .env.example`), its name appears in `docs/configuration.md`; same for the four `settings` keys' Admin labels.
2. AC2 — `docs/security.md` contains: deployment model (port 8080 LAN only, 3306 localhost), the protected/unprotected split naming `/api/admin`, roles `admin`/`manager`, 8 h sessions without revocation and the `JWT_SECRET` rotation remedy, throttling numbers (10/15 min, 30), the known-limitations list.
3. AC3 — `docs/backup-and-restore.md`'s logo backup and restore commands, run against the development stack, copy the uploaded logo out and back (`cmp` of the file before/after); `bin/backup-db` run produces a `.sql.gz` that passes `gzip -t`.
4. AC4 — `docs/troubleshooting.md` links `installation.md#troubleshooting`, `upgrading.md#troubleshooting`, `printing.md#troubleshooting`, and has rows for: connection lost, Admin back to login, "Sem permissão", login 429, disk filling up.
5. AC5 — A link check over `README.md` and `docs/*.md` (relative links and `#anchors`) finds no broken target.
6. AC6 — `git diff --stat master` lists only files under `docs/`, `specs/`, plus `README.md` and `CHANGELOG.md`.

## Implementation plan

1. `docs/configuration.md` (re-read `.env.example`, `Settings.php`, `SettingsValidator`, Admin settings page for the exact labels).
2. `docs/security.md` (re-read `Routes.php` for which routes each role reaches; `LoginThrottleService` numbers).
3. `docs/backup-and-restore.md`; run the logo copy commands and `bin/backup-db` on the dev stack; record output.
4. `docs/troubleshooting.md` (check exact UI strings: "Conexão perdida", "Sem permissão", 429 message).
5. Docs pass: README table, cross-links, ROADMAP status, CHANGELOG.
6. Link check (small throwaway script in the scratchpad, not committed); `git diff --stat`.
7. Trello: tick items on the card; Backlog cards per Open questions 1–2.

## Testing and validation strategy

Documentation only, so PHPUnit/PHPStan/CS-Fixer are unaffected (CI still runs them on the PR). Validation is:

- AC1/AC2/AC4: `grep` checks recorded in Validation evidence.
- AC3: commands run against the **development** stack (`COMPOSE_FILE=docker-compose.dev.yml`; note that in development `public/uploads` is a bind-mounted folder, not a volume — the `docker compose cp` path is the same, and the difference is recorded). `bin/restore-db` is **not** run: it is destructive on the development database (CLAUDE.md), and its behavior is already validated by spec 045; the doc quotes its prompts from the script.
- AC5: link-check script output.
- AC6: `git diff --stat`.

## Rollout and rollback

Merged with the spec's PR; reverting the PR removes the docs. No runtime effect.

## Open questions

1. **Backup scope (not blocking).** `bin/backup-db` leaves out the logo and `.env`. Proposed: this spec documents the manual steps; a Backlog card proposes extending `bin/backup-db` (logo into the archive; `.env` deliberately *not*, since backups get copied to clouds). Owner may instead want the script change in this spec — it would then stop being docs-only.
2. **Log rotation (not blocking).** `logs/app.log` grows without limit. Proposed: document it in troubleshooting (how to check the size, how to truncate safely) and add a Backlog card.
3. **Vulnerability reporting in `docs/security.md` (not blocking).** Item 5 creates `SECURITY.md`. Proposed: `docs/security.md` gets a one-line "Reporting a vulnerability" section saying it will be defined in `SECURITY.md`, replaced by item 5.
4. **Language (not blocking).** Proposed: English, like every file in `docs/`, although the operators are Brazilian and the UI is Portuguese — UI strings are quoted in Portuguese as they appear.

## Task checklist

- [x] `docs/configuration.md`
- [x] `docs/security.md`
- [x] `docs/backup-and-restore.md` — logo copy commands run by the agent; `bin/backup-db` run by the owner on the host (Validation evidence, AC3)
- [x] `docs/troubleshooting.md`
- [x] Docs pass (README, cross-links, ROADMAP, CHANGELOG)
- [x] Link check + diff scope
- [x] Trello (card + Backlog follow-ups)

## Implementation log

- 2026-10-31 — `/spec-implement` invoked on the Draft with no blocking open question → `Approved` → `In Progress`. Open questions resolved as proposed: 1 and 2 documented as-is + Backlog cards ("[Follow-up 067] bin/backup-db não inclui a logo enviada", "[Follow-up 067] logs/app.log cresce sem limite (sem rotação)"); 3 one-line "Reporting a vulnerability" section pending `SECURITY.md`; 4 English.
- Facts corrected while re-reading the code, against what the spec's Problem section implied:
  - `bin/create-admin` takes an optional role (`admin` default, `manager`, `cashier`, `kitchen` — `bin/create-admin:43`). `cashier`/`kitchen` reach no `/api/admin` route (`src/Routes.php:91-92` allow only `admin`/`manager`) and the cashier/kitchen screens need no login, so those roles are documented as accepted but useless today. Not changed (out of scope); worth a look in a future spec.
  - There is **no password-change screen**: `PATCH /api/admin/account/password` has no caller under `public/`. Documented as API-only.
  - The restaurant name is used only on the printed ticket (`PrintService.php:261`), not on the screens.
  - Admin settings save with one "Salvar Configurações" button; the logo uploads on file selection (`settings.php:57`). Only `settings.updated` is audited for settings — the logo upload is not.
  - `/api/menu/reorder` (PATCH) is also on the unauthenticated group; `docs/security.md` states generally that the cashier/kitchen API is open.
  - Login throttling's per-device count collapses on Docker Desktop (spec 053 caveat) — added to `security.md`.
- **Restore across versions**: the first draft of `backup-and-restore.md` said a backup from an older version could be completed with `docker compose up -d` (pending migrations). Not verified, and likely wrong — a restored older dump rolls back `schema_migrations` while the newer tables, absent from the dump, stay. Replaced by "restore with the release the backup was taken on", consistent with `upgrading.md` › Rollback.
- **Ownership after restoring the logo**: `docker compose cp` into the container leaves files owned by the host user (uid 1000, measured); the Apache user can read them (`644`) but a later logo upload might not overwrite them. The doc adds `docker compose exec web chown -R www-data:www-data public/uploads`, run and checked.
- `docs/printing.md` needed no change (its troubleshooting is linked from `troubleshooting.md`). `docs/architecture.md`/`specs/000-project-baseline.md`: nothing they say became false.
- `/spec-review` (2026-10-31) found two wording issues, fixed before the PR: `configuration.md` said an empty `JWT_SECRET` makes the app "refuse to start" — in fact `Routes::register()` throws on every request (`src/Routes.php:33`), so the containers stay Up and everything answers 500; the Windows scheduling example omitted the logo copy that the Linux one has.
- In development `public/uploads` did not exist (no logo uploaded); the test created it through the bind mount and removed it again afterwards (file + folder), leaving the working tree as before.

## Validation evidence

Development stack (`docker-compose.dev.yml`, `docker compose ps`: `db`, `web`, `print-worker` Up). No PHP was changed, so PHPUnit/PHPStan/CS-Fixer were not run locally; CI runs them on the PR.

**AC1** — every `.env.example` variable and Admin label in `docs/configuration.md`:

```
for v in $(grep -oE '^#? ?[A-Z_]+=' .env.example | tr -d '#= '); do grep -q "`$v`" docs/configuration.md && echo "ok $v" || echo "MISSING $v"; done
ok DB_HOST … ok MYSQL_DATABASE_TEST        (16/16 ok, 0 MISSING)
ok Nome do Restaurante / IP da Impressora / Porta / Desconto máximo por item / Logo
```

**AC2** — `grep -c` in `docs/security.md`: `8080` 2, `127.0.0.1:3306` 1, `/api/admin` 2, `` `admin` `` 1, `` `manager` `` 1, `8 hours` 1, `JWT_SECRET` 3, `10 failed logins in 15 minutes` 1, `30 from the same device` 1, `## Known limitations` 1.

**AC3** — logo round-trip, partially:

```
# test file in the container (dev has no uploaded logo)
docker compose exec -T web sh -c 'mkdir -p public/uploads && cp public/assets/img/logo.png public/uploads/spec067-test.png && chown www-data:www-data …'
docker compose cp web:/var/www/html/public/uploads <scratchpad>/uploads-test
  → Copied restaurant_web:/var/www/html/public/uploads to …/uploads-test
cmp <scratchpad>/uploads-test/spec067-test.png public/assets/img/logo.png → IDENTICAL
docker compose exec -T web rm public/uploads/spec067-test.png
docker compose cp <scratchpad>/uploads-test/. web:/var/www/html/public/uploads/
docker compose exec -T web sh -c 'ls -la public/uploads; cmp …'
  → -rw-r--r-- 1 1000 1000 1438055 spec067-test.png ; IDENTICAL ; read as www-data: 89 50 4e 47 0d 0a 1a 0a
docker compose exec -T web sh -c 'chown -R www-data:www-data public/uploads && ls -la public/uploads'
  → -rw-r--r-- 1 www-data www-data 1438055 spec067-test.png
```

`./bin/backup-db` + `gzip -t`: from the agent it fails inside the sandbox, where `docker compose` can't read `.env` (`open …/.env: permission denied` → `Erro: mysqldump não encontrado no container db.`). Run by the owner on the host, 2026-10-31:

```
./bin/backup-db && gzip -t "$(ls -t backups/*.sql.gz | head -n1)" && echo OK
✔ Backup criado: backups/gastroflow-restaurant-20261031-012018.sql.gz (8,0K)
OK
``` `bin/restore-db` deliberately not run (destructive on the development database); its prompts are quoted from `bin/restore-db`.

**AC4** — `grep -ci` in `docs/troubleshooting.md`: `installation.md#troubleshooting` 1, `upgrading.md#troubleshooting` 2, `printing.md#troubleshooting` 1, `Conexão perdida` 1, `back to the login` 1, `Sem permissão` 1, `Muitas tentativas` 1, `disk filling up` 1.

**AC5** — link check (throwaway script in the scratchpad: relative targets + GitHub-style `#anchors`, code blocks skipped):

```
python3 -I linkcheck.py README.md docs/*.md → 104 links checked, 0 broken
negative control (a missing file + a missing anchor) → 4 links checked, 2 broken
```

**AC6** — `git status`/`git diff --stat`: modified `CHANGELOG.md`, `README.md`, `docs/ROADMAP.md`, `docs/commands.md`, `docs/installation.md`, `docs/upgrading.md`; new `docs/backup-and-restore.md`, `docs/configuration.md`, `docs/security.md`, `docs/troubleshooting.md`, `specs/067-documentation-structure.md`. Nothing outside `docs/`, `specs/`, `README.md`, `CHANGELOG.md`.
