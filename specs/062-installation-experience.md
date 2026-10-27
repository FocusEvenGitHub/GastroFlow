# Spec 062 — Installation experience

## Metadata

- Status: Implemented
- Created: 2026-10-26
- Updated: 2026-10-26
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Installation experience" (and the v1.9 Exit Gate). Builds on spec 061 (clean initial database).
- Related branch: `062`

## Context

The roadmap item: a new user follows documentation — clone → copy environment template → configure → start Docker → run migrations → create administrator → open GastroFlow — with **no source-code editing**. The roadmap's own pass/fail test (added 2026-09-26): someone other than the author downloads, configures, creates an admin, brings up `web` + MySQL + `print-worker`, configures the printer, takes a backup and upgrades — without the author touching their terminal.

Spec 061 made a fresh install start clean. This spec makes the path to that fresh install work by the documentation alone. Upgrading is the next roadmap item ("Upgrade process") and is only linked from here.

## Problem

Measured on `master` @ `cf07102` (2026-10-26), following `README.md` › "Getting started" › "Installation" (`README.md:143-166`) literally:

1. **The app does not start: `vendor/` is empty.** `docker-compose.yml` bind-mounts `./vendor:/var/www/html/vendor` into `web` and `print-worker`. On a fresh clone `./vendor` does not exist (`.gitignore:4`), Docker creates it empty, and it hides the `vendor/` the image built (`Dockerfile`: `composer install --no-dev`). `public/index.php:4` then fails on `require vendor/autoload.php`, and `print-worker` crash-loops. *(Measured in the clean-room run: the response is a PHP "Fatal error" page with HTTP status **200**, not 500 — including `/health/ready` — so a status-only health check reports a broken install as healthy.)* The README mentions `composer install` only in passing, after the block (`README.md:166`), not as a step.
2. **No `bin/migrate` step.** The block goes from `docker compose up -d` straight to `bin/create-admin`. Without migrations the database has only `001_schema.sql`'s tables (`categories`, `menu_items`, `orders`, `order_items`, `users`) — no `settings`, `jobs`, `events`, `audit_log`, `login_attempts`… (created by migrations 006/007/017/018/020) The app is unusable, and since spec 061 the marker-driven cleanup (`022`) never runs either.
3. **A custom database name silently breaks the install.** `common/sql/001_schema.sql:1-5` opens with `CREATE DATABASE IF NOT EXISTS restaurant; … USE restaurant;`. The MySQL entrypoint already runs init scripts with `--database="$MYSQL_DATABASE"` (`/usr/local/bin/docker-entrypoint.sh` `docker_process_sql`, checked in the `mysql:8.0` container), but the script's own `USE restaurant` overrides it. With `MYSQL_DATABASE=gastroflow` in `.env`, the tables land in an extra `restaurant` database, `gastroflow` stays empty, and `bin/migrate` fails on the first `ALTER TABLE`. Works today only because `.env.example` uses `restaurant`.
4. **`.env` order trap.** `docker-compose.yml` bind-mounts `./.env` as a file. Running `docker compose up` before creating `.env` makes Docker create a **directory** named `.env`, after which `cp .env.example .env` fails confusingly. The README has the right order but doesn't say why it matters.
5. **Unsafe defaults for a restaurant.** `.env.example` ships `APP_ENV=development` and `APP_DEBUG=true` (`.env.example`), so an install that only fills `JWT_SECRET` runs in debug mode (`src/App.php:93` error handler uses `Settings::isDebug()` to decide whether to expose error details) and lets `bin/seed-demo` run on a database with orders. Passwords default to `changeme`, and nothing in the README says to change them.
6. **Missing steps for the milestone test:** configuring the restaurant (name, printer IP/port, test print — they exist: Admin › Configurações, `POST /api/admin/settings/test-print`, `src/Routes.php:110`), the empty menu after spec 061 (create items, or `bin/seed-demo` / `bin/seed-client-1`), the first backup (`bin/backup-db` is host-run and needs a POSIX shell — Git Bash on Windows), and how to check the install is healthy (`GET /health/ready`, `src/Routes.php:46`).
7. **Windows line endings must be fixed before cloning.** `README.md` › "Line endings (Windows)" asks for `git config core.autocrlf false`, but placed *after* the install section; a Windows user who clones first gets CRLF scripts (`bin/*`) that fail in the Linux containers.
8. The README sends the reader to `CLAUDE.md` (AI-assistant rules) for "every other command" (`README.md:166`) — not an operator document.

## Goals

- One document an operator can follow top to bottom on Linux, macOS or Windows and end with a working GastroFlow: admin created, printer configured, menu in place, first backup taken.
- The three install-breaking defects (1–3) fixed or made impossible to miss.
- Safe defaults in the environment template.

## Non-goals

- The upgrade procedure — next roadmap item ("Upgrade process"); this spec only links to it.
- Full printer documentation (ESC/POS expectations, troubleshooting) — roadmap item "Printer documentation".
- An install script/wizard (Open question 3).
- Publishing images or release artifacts ("Release automation").
- The v1.9 Exit Gate itself (a *different* person installing) — this spec prepares it; it does not replace it.

## Current behavior

See Problem — confirmed in: `docker-compose.yml` (`./vendor` and `./.env` bind mounts on `web`/`print-worker`; `3306:3306` published on all interfaces), `Dockerfile` (`composer install --no-dev` into the image), `.gitignore:4,10`, `public/index.php:4`, `common/sql/001_schema.sql:1-5`, the `mysql:8.0` entrypoint's `docker_process_sql`, `.env.example`, `src/App.php:93`, `src/Routes.php:46,110`, `README.md:137-195`.

## Proposed behavior

1. **`docs/installation.md`** (new) — the operator guide, in English like the rest of `docs/`, numbered steps:
   1. Prerequisites: Docker Desktop / Docker Engine + Compose v2, Git; on Windows also Git Bash (for `bin/backup-db`) and `git config --global core.autocrlf false` **before cloning**.
   2. Clone.
   3. `cp .env.example .env` **before** any `docker compose` command (why: the bind mount).
   4. Edit `.env`: set `MYSQL_ROOT_PASSWORD`/`MYSQL_PASSWORD`, `JWT_SECRET` (`openssl rand -base64 48`, plus a PowerShell alternative), keep `APP_ENV=production`/`APP_DEBUG=false`. *(Corrected 2026-10-26 by `/spec-review`: no PowerShell variant was written — on Windows the guide runs everything in Git Bash, which ships `openssl`; see the Implementation log.)*
   5. `docker compose up -d --build`.
   6. `docker compose exec web composer install --no-dev` (why: the `vendor` bind mount).
   7. `docker compose exec web php bin/migrate`.
   8. `docker compose exec web php bin/create-admin <username>`.
   9. Check: `curl http://localhost:8080/health/ready` → `{"success":true,"status":"ready"}` (`src/Controllers/HealthController.php:49`); open `http://<server-ip>:8080/admin/`.
   10. Configure the restaurant in Admin › Configurações: name, logo, printer IP/port → "Imprimir teste".
   11. Menu: create items in Admin, or for a demo `bin/seed-demo`.
   12. First backup: `./bin/backup-db` (host, Git Bash on Windows).
   13. Daily operation pointers: LAN section (spec 060), backup/restore, upgrade (link, pending item).
   - **Troubleshooting** table for the known traps: HTTP 500 / "vendor/autoload.php" → step 6; `.env` is a directory → remove it, redo step 3; missing tables / `Table … doesn't exist` → step 7; `JWT_SECRET` RuntimeException → step 4; changed `MYSQL_DATABASE` after first start → the `db_data` volume keeps the old one.
2. **`README.md` › Installation** becomes a short, correct quickstart (steps 3–8) that links to `docs/installation.md`; the "Line endings (Windows)" note moves above it; "every other command → CLAUDE.md" becomes a link to the installation doc (CLAUDE.md stays the developer reference).
3. **`common/sql/001_schema.sql`**: remove the `CREATE DATABASE … restaurant` / `USE restaurant;` header, so the schema lands in whatever `MYSQL_DATABASE` the entrypoint selected. `IntegrationTestCase`/`MigrationUpgradeTest` already strip those lines; CI already pipes the file into `"$MYSQL_DATABASE"`.
4. **`.env.example`**: `APP_ENV=production`, `APP_DEBUG=false` by default, with a comment saying developers set `development`/`true`; a comment on each password saying to change it.
5. **`docs/installation.md` is listed** in README › Documentation table.

## Functional requirements

1. FR1 — Following `docs/installation.md` verbatim on a fresh clone, with a fresh Docker volume, reaches: `GET /health/ready` → 200; Admin login with the created user; `print-worker` running (not restarting); first `bin/backup-db` file created — with no file edited except `.env`.
2. FR2 — The same run with `MYSQL_DATABASE=gastroflow` (not `restaurant`) ends with all tables in `gastroflow` and no `restaurant` database created.
3. FR3 — `.env.example` has `APP_ENV=production` and `APP_DEBUG=false`.
4. FR4 — `docs/installation.md` contains steps 1–13 and the troubleshooting table of Proposed behavior item 1; README's install block includes `composer install` and `bin/migrate` and links to it.
5. FR5 — Existing installs are unaffected: no migration changes; `001_schema.sql` only runs on a new volume.

## Non-functional requirements

- No new dependency, no Docker/Composer version change, no `docker-compose.yml` structural change unless Open question 4 is accepted.
- Docs reflect actual behavior: every command in `docs/installation.md` is one that was run in the validation (FR1).

## User flows

**New operator:** prerequisites → clone → `.env` → `up -d --build` → `composer install` → `migrate` → `create-admin` → `/health/ready` → Admin: settings + test print → menu → backup.

**Developer (unchanged):** same, then sets `APP_ENV=development`/`APP_DEBUG=true` in `.env` and `composer install` (with dev deps) for the test tooling.

## API changes

Not applicable — no endpoint changes.

## Data model and migrations

Not applicable — no schema change. `001_schema.sql` loses its two-line database-selection header only; table definitions and seed rows are untouched.

## Architecture and affected components

- New `docs/installation.md`.
- `README.md` (Installation, Line endings placement, Documentation table), `.env.example`, `common/sql/001_schema.sql` (header).
- Docs pass: `CHANGELOG.md`, `docs/ROADMAP.md` (status), `docs/architecture.md`/`specs/000-project-baseline.md` if they describe the install path, `CLAUDE.md` (if the `.env.example` defaults are mentioned).
- No PHP code under `src/`, `bin/` or `public/` changes.
- *(Added during implementation, recorded by `/spec-review` 2026-10-26)* `docs/commands.md` (owner's request); `docker-compose.yml` (MySQL on `127.0.0.1`, Open question 4); `Dockerfile`, `docker-compose.yml`, `docker-compose.e2e.yml` (dead `legacy/` references — defect 4, found by the clean-room run; this is the "structural change" the Non-functional requirements allowed only for Open question 4, justified in the Implementation log); `tests/Integration/IntegrationTestCase.php` (comment only).

## Security considerations

- Production-safe template defaults (`APP_DEBUG=false`, `APP_ENV=production`): an operator who copies the template no longer exposes error details by default.
- The guide tells the operator to replace `changeme` passwords and generate `JWT_SECRET`; it never prints example secrets that look real.
- `3306:3306` publishes MySQL on every interface of the server (LAN-reachable, password-protected) — Open question 4.
- Validation must not touch the development database or the real `.env` (CLAUDE.md): the clean-room run uses its own clone, its own `.env` and its own Docker project/volume.

## Backward compatibility

- Existing installs: no change (`001_schema.sql` runs only on an empty volume; no migration touched).
- Developers who copy `.env.example` again get production defaults and must set `APP_ENV=development`/`APP_DEBUG=true` themselves — documented in the guide and in CLAUDE.md. Their existing `.env` is not affected.
- `IntegrationTestCase`, `MigrationUpgradeTest` and CI keep working: they already strip / do not rely on the removed header.

## Acceptance criteria

1. AC1 — **Clean-room run** on this machine: a fresh `git clone` into a scratch directory, a separate Docker Compose project (own volume), every command copied from `docs/installation.md` in order, recorded with its output: `/health/ready` 200; Admin login 200 with the created user; `docker compose ps` shows `print-worker` `Up` (not `Restarting`); `bin/backup-db` prints `✔ Backup criado: …`. `git status` in the scratch clone shows only `.env` (ignored) — no tracked file modified.
2. AC2 — In that run `MYSQL_DATABASE=gastroflow`: `SHOW DATABASES` lists `gastroflow` and not `restaurant`; `SHOW TABLES FROM gastroflow` includes `users`, `settings`, `jobs`, `migrations`.
3. AC3 — `grep -E '^APP_(ENV|DEBUG)=' .env.example` → `APP_ENV=production`, `APP_DEBUG=false`.
4. AC4 — `docs/installation.md` has the 13 steps and the troubleshooting table; README's block contains `composer install` and `bin/migrate` and links the guide; the Windows line-endings note precedes the install block.
5. AC5 — Reproduce defect 1 and 3 before the fix in the same clean-room setup (on `master`): without `composer install`, `GET /` → 500 with `vendor/autoload.php` in the log; with `MYSQL_DATABASE=gastroflow` and the old schema, `bin/migrate` fails. (Proves the guide and the header fix address real failures, not assumed ones.)
6. AC6 — CI green on the PR.

## Implementation plan

1. `001_schema.sql`: drop the database-selection header.
2. `.env.example`: production defaults + comments.
3. `docs/installation.md`: guide + troubleshooting.
4. `README.md`: quickstart, line-endings note moved, links.
5. Clean-room validation (AC1, AC2, AC5) — needs the user's go-ahead (see Testing).
6. Docs pass (CHANGELOG, ROADMAP status, CLAUDE.md/baseline/architecture where affected).

## Testing and validation strategy

The project has PHPUnit and Playwright (specs 035/040) — the template's "no automated test infrastructure" note is outdated; but an installation is validated by **doing one**:

- **Clean-room run (AC1, AC2, AC5):** scratch clone outside the working tree, `docker compose -p gastroflow-clean …`. `docker-compose.yml` hard-codes host ports `8080` and `3306`, so the development stack must be **stopped** (`docker compose stop` in the working tree — reversible, data kept in its volume) during the run, then restarted. At the end the scratch project is removed with `docker compose -p gastroflow-clean down -v` (deletes only that project's volume). Both steps need the user's explicit approval before running.
- **Automated:** existing suites in CI (AC6) — they build from `001_schema.sql` and cover the header removal.
- **The real milestone test** — someone else installing on their own machine — stays in the v1.9 Exit Gate.

## Rollout and rollback

Docs and template take effect on merge; the `001_schema.sql` change only affects new volumes. Rollback: revert the commit.

## Open questions

1. *(non-blocking)* **Where the guide lives.** Proposed: `docs/installation.md` (the roadmap's "Documentation structure" lists Installation as its own document), with a short quickstart in README.
2. *(non-blocking)* **Production defaults in `.env.example`.** Proposed: yes (`APP_ENV=production`, `APP_DEBUG=false`); developers opt in.
3. *(non-blocking)* **An install script (`bin/install`)?** Proposed: no — the roadmap asks for documentation; a script is one more thing to maintain and to break on Windows. Revisit if the Exit Gate shows people still fail.
4. *(non-blocking)* **Bind MySQL to `127.0.0.1:3306:3306`.** Today any LAN device can reach MySQL (password-protected). The app talks to `db` over the Docker network, and host tools on the server keep working via localhost; only a DB client on *another* machine would lose access. Proposed: **include it here** — a fresh restaurant install should not expose its database on the LAN — unless you prefer it as its own spec.
5. *(non-blocking)* **`composer install --no-dev` in step 6.** Proposed: `--no-dev` for operators (smaller, no test tooling); the developer flow in the guide says plain `composer install`.

## Task checklist

- [x] Step 1 — `001_schema.sql` header
- [x] Step 2 — `.env.example` defaults
- [x] Step 3 — `docs/installation.md`
- [x] Step 3b — `docs/commands.md` (added by the owner)
- [x] Step 3c — MySQL on `127.0.0.1:3306` (Open question 4, taken at its proposed default)
- [x] Step 4 — README quickstart
- [x] Step 5 — clean-room validation (two clones; see Validation evidence)
- [x] Step 6 — docs pass

## Implementation log

- 2026-10-26 — `/spec-implement` on a Draft with no blocking open questions → Draft → Approved → In Progress. Open questions at their proposed defaults: guide in `docs/installation.md`; production defaults in `.env.example`; no install script; **MySQL bound to `127.0.0.1:3306`** (included here); `composer install --no-dev` for operators.
- **Scope added by the owner:** `docs/commands.md`, every command by task (services, health, database, users, backup & restore, print queue, sample data, development). CLAUDE.md keeps its developer list and now points to both new documents, with a note to keep them in sync.
- **Deviation from Proposed behavior item 2:** instead of moving README's "Line endings (Windows)" section above the install block, the install block now *starts* with `git config --global core.autocrlf false` (Windows only); the detailed section stays where it was. Same effect — the setting comes before `git clone` — without reordering the README.
- `--global` in the guide, not the repo-local `git config core.autocrlf false` the README's section uses: before cloning there is no repository to configure.
- On Windows the guide runs every command in **Git Bash** (already required by `bin/backup-db`, and it ships `openssl` and `cp`), so there is no PowerShell variant to keep in sync.
- Checked while writing, not assumed: `composer.lock` is **tracked** (the baseline said gitignored — corrected with a dated note), so its bind mount is not a second `.env`-style trap; `APP_DEBUG=true` really exposes the exception message and stack trace in API responses (`src/App.php` error handler); `/health/ready` answers `{"success":true,"status":"ready"}`; the Settings page labels ("Nome do Restaurante", "IP da Impressora", "Salvar Configurações", "Imprimir Teste") and "Reativar impressão" (cashier/kitchen) are quoted from `public/`.
- `IntegrationTestCase` keeps stripping a `CREATE DATABASE`/`USE` header (now a no-op guard; comment updated); `MigrationUpgradeTest` still needs it, since it reads v1.7.1's schema, which has the header.
- 2026-10-26 — **Clean-room run, with the owner's approval.** The project's safety hook blocks `docker compose down`/`down -v` when run by the assistant; the owner ran those commands himself (dev stack down — containers only, `gastroflow_db_data` kept — and `down -v` of each scratch project). The scratch clones' `.env` was generated with `sed` from `.env.example` and random values, and never read (a permission rule denies reading any `.env`).
- **Defect 1 is worse than the spec said:** without `vendor/`, every page — `/health/ready` included — answers **HTTP 200** with a PHP "Fatal error … vendor/autoload.php" page: the official PHP image has no `php.ini`, so `display_errors` is on and output starts before the fatal error. A status-only health check reports the broken install as healthy. The guide now says to check the body (`{"success":true,"status":"ready"}` exactly) and the troubleshooting row was rewritten. The `display_errors` default itself (it also prints file paths with `APP_DEBUG=false`) is out of scope: recorded as a Backlog follow-up.
- **Defect 4, found only by the clean-room run:** `docker compose up -d --build` **fails on a fresh clone** — `Dockerfile` had `COPY legacy/ ./legacy/`, and `legacy/` was deleted from the repository long ago (commit `b4c9573`); it only existed on the author's machine because the `./legacy` bind mounts re-create it empty on every `up`. The first clean-room run (on `master`) missed it because `up -d` without `--build` reused the locally cached image. Nothing references `legacy/` (grep over code and configs). Removed the `COPY` line and the three `./legacy` mounts (`docker-compose.yml` x2, `docker-compose.e2e.yml`); `docker compose config --quiet` valid for both files. CI never builds the Docker image, which is why it never failed there.
- Docs pass: CHANGELOG (Segurança: MySQL port, `.env` defaults; Novidades: guides; Correções: database name), ROADMAP status, CLAUDE.md, baseline (two dated notes), README LAN section (3306 on localhost).

## Validation evidence

Collected 2026-10-26 on branch `062`. Clean-room clones under the session scratchpad (outside the working tree), Docker Desktop on Windows, `MYSQL_DATABASE=gastroflow`. The development database was not touched (its containers were removed and its volume kept, by the owner).

| AC | Command / evidence | Result |
|---|---|---|
| AC5 (defects before the fix) | Clone of `master` @ `cf07102`, old README order (`cp .env.example .env`, edit, `docker compose up -d`): `vendor/` created empty; `GET /` and `GET /health/ready` -> **200** with body `Fatal error: Uncaught Error: Failed opening required '/var/www/html/public/../vendor/autoload.php'`; `print-worker` `Restarting (255)` with the same error from `bin/worker:19`. After `composer install --no-dev`: `bin/migrate` -> `Executando 001_ingredients.sql ... [ERRO] SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'menu_items'`; `SHOW DATABASES` lists both `gastroflow` (2 tables: `ingredients`, `migrations`) and `restaurant` (6 tables) | Met — defects 1 and 3 reproduced |
| AC1 | Fresh clone of `master` + branch-062 changes applied (`git diff HEAD` + new files), then `docs/installation.md` in order. The first attempt at step 5 found **defect 4** (`failed to calculate checksum ... "/legacy": not found`); after the fix: step 5 `Image gastroflow-web Built`, 3 containers started; step 6 `Generating autoload files`; step 7 on the first try, `Executando 022_clean_fresh_install.sql ... [OK]`, `Concluído.`; step 8 `Usuário "admin" (admin) criado com sucesso.`; step 9 `{"success":true,"status":"ready"}`, `docker compose ps`: `db`/`web`/`print-worker` all `Up`, `/admin/` 200, `POST /api/login` 200; step 11 `Cardápio de demonstração criado.` + 65 orders; step 12 `Backup criado: backups/gastroflow-gastroflow-20261026-211734.sql.gz (4.0K)`. `git status`: the only tracked changes are the 11 files of the applied branch patch; the install itself produced only ignored paths (`.env`, `vendor/`, `backups/`). **Not done:** step 10 (no printer here — "Imprimir Teste" not exercised) | Met, except step 10 |
| AC2 | Same run: `SHOW DATABASES` -> `gastroflow, information_schema, mysql, performance_schema, sys` (no `restaurant`); 16 tables in `gastroflow`, including `jobs, migrations, settings, users` | Met |
| AC3 | `grep -nE '^APP_(ENV\|DEBUG)=' .env.example` -> `APP_ENV=production`, `APP_DEBUG=false` | Met |
| AC4 | `docs/installation.md`: steps 1–13 + Troubleshooting; README block has `composer install --no-dev` and `bin/migrate`, links the guide and `docs/commands.md`, and starts with `git config --global core.autocrlf false` (deviation recorded above) | Met |
| AC6 | Local: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 114 files`; Unit `OK (238 tests, 474 assertions)`; Integration `OK (70 tests, 330 assertions)` (run before the `legacy` fix, which touches no PHP). MySQL binding: `docker compose config` -> `host_ip: 127.0.0.1`; clean-room `db` container `127.0.0.1:3306->3306/tcp`. CI not run yet | Pending CI |

Status `Implemented`: AC1 lacks step 10 (no printer) and AC6 waits for CI.
