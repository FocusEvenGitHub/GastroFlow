# Spec 063 — Upgrade process

## Metadata

- Status: Verified
- Created: 2026-10-27
- Updated: 2026-10-27 (Verified + decision revision recorded in spec 064's PR)
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Upgrade process". Builds on spec 062 (installation guide, `docs/commands.md`).
- Related branch: `063`

## Context

The roadmap item: "Document the supported update procedure — Backup → Update source/image → Install dependencies → Run migrations → Restart services → Validate installation. Existing restaurant data must remain intact." It is also part of the v1.9 milestone test ("…and upgrade to a newer version — all without the original author touching their terminal").

Today the procedure lives in scattered `⚠️` notes in `CHANGELOG.md` and in a provisional line in `docs/installation.md` step 13 (spec 062). Three real upgrades in this project went wrong or nearly did:

- **Spec 058** (migration `021`): the pilot install created an order before `bin/migrate` ran → `POST /api/orders` **HTTP 500**, `Unknown column 'discount_type'` (observed 2026-10-26, spec 060's Implementation log).
- **Spec 059** (PHP 8.2.35): pulling the code is not enough — the image must be rebuilt (`CHANGELOG.md` "Para aplicar, é preciso reconstruir a imagem"); the pilot container was still on PHP 8.2.33 days later (observed in spec 060).
- **Spec 062** (MySQL on `127.0.0.1`): takes effect only when the `db` container is recreated.

This spec also carries two pieces of pending evidence (one PR per spec): spec 062's CI run and spec 061's AC2 observation on the development database.

## Problem

Measured on `master` @ `a8b28d6` (2026-10-27):

1. **No upgrade document.** The order, the commands and the checks exist only as fragments: CHANGELOG notes per release, `docs/installation.md:13` ("until then, take a backup, `git pull`, …"), `docs/commands.md`.
2. **The CHANGELOG's instruction cannot be followed.** Three entries say "Rodar `bin/migrate` **antes de subir o código novo**" (`CHANGELOG.md:7,47,69`, specs 058, 053, 050). But `docker-compose.yml` bind-mounts `public/`, `src/`, `bin/`, `common/` into `web` and `print-worker`: **the moment `git pull` finishes, the new code is live**, and its migrations are pending until someone runs `bin/migrate` — which itself runs from the new code. There is no "before". The safe order is different and has to be stated: do it with the restaurant closed, and run `bin/migrate` immediately after the pull.
3. **Nothing tells the operator a migration is pending.** `GET /health/ready` (`src/Controllers/HealthController.php`) checks only `select 1`; `bin/migrate` has no read-only mode (`bin/migrate` accepts only `--trust-current-hashes`; `MigrationRunner::getPendingFiles()` is private). The only symptom of a forgotten migration is a 500 on the first request that touches the new column.
4. **When the image must be rebuilt is implicit.** `docker compose up -d` reuses the cached `gastroflow-web` image; a changed `Dockerfile` (specs 059, 062) needs `--build`. The operator has no rule to know.
5. **`.env` drift.** `.env` is never updated by `git pull`; new or changed variables only appear in `.env.example` (e.g. spec 062's production defaults). No step says to compare them.
6. **No rollback story.** Migrations are forward-only (`docs/architecture.md`, Known limitations). Going back means restoring the backup taken before the upgrade and checking out the previous version — written nowhere.
7. **Which version to run** is unstated: tags (`v1.8.3`…) or `master`. `GET /version` (`src/Controllers/VersionController.php`) reports `git describe --tags --always`, so the running version is checkable, but nobody is told to check it.

## Goals

- `docs/upgrading.md`: one procedure, in order, that keeps the restaurant's data, works for every past upgrade (code-only, migration, image rebuild, compose change), and says how to roll back.
- A read-only way to see pending migrations, used by the procedure's validation step.
- The CHANGELOG stops telling operators to do something impossible.

## Non-goals

- Zero-downtime upgrades (the restaurant is closed during the upgrade; GastroFlow is a single-server install).
- Automatic migrations on container start (Open question 2).
- Down-migrations / automatic rollback (forward-only stays).
- Publishing images or release artifacts ("Release automation" item).

## Current behavior

Confirmed in code: bind mounts in `docker-compose.yml` (`./public`, `./src`, `./bin`, `./common`, `./vendor`…); `bin/migrate` → `MigrationRunner::run()` (`src/Database/MigrationRunner.php:28`), pending list `getPendingFiles()` (`:180`, private), hash check `assertNoHashDivergence()` (`:75`); `HealthController::ready()` = `select 1` only; `VersionController::describe()` = `git describe --tags --always`; tags `v1.7.1 … v1.8.3`; between `v1.8.3` and `master`: `Dockerfile`, `docker-compose.yml`, `.env.example` changed, `composer.lock` did not, migrations `021`, `022` added. `MigrationUpgradeTest` (spec 048) already proves, in CI, a v1.7.1 → HEAD schema upgrade keeps a historical order and the menu (spec 061).

## Proposed behavior

1. **`docs/upgrading.md`** (English, like `docs/`), linked from README, `docs/installation.md` step 13 (replacing the provisional line) and `docs/commands.md`:
   1. **Before:** read the target version's CHANGELOG section (its `⚠️` notes); pick a time with the restaurant closed; check the current version (`curl …/version`).
   2. **Backup:** `./bin/backup-db`, and copy the file off the server.
   3. **Update the code:** `git pull` on `master` — `master` is the released, final version (owner decision, Open question 1); a tag (`git checkout vX.Y.Z`) only to pin or go back to a specific version; then `git diff <old>..<new> --stat -- Dockerfile docker-compose.yml .env.example composer.lock` tells which of the next steps apply.
   4. **`.env`:** if `.env.example` changed, add/adjust the new variables by hand (`git diff <old>..<new> -- .env.example`).
   5. **Rebuild/recreate:** `docker compose up -d --build` — always; it is a no-op when nothing changed and it is what applies `Dockerfile`/compose changes (PHP updates, port bindings).
   6. **Dependencies:** `docker compose exec web composer install --no-dev`.
   7. **Migrations:** `docker compose exec web php bin/migrate`, right after step 5 — explain why there is no "before".
   8. **Validate:** `bin/migrate --status` → nothing pending; `/health/ready` body; `/version` shows the new version; `docker compose ps` all `Up`; open the cashier and create a test order (and cancel it).
   9. **Rollback:** restore the step-2 backup with `./bin/restore-db <file>`, `git checkout <old>`, `docker compose up -d --build`, `composer install --no-dev`.
   - **Troubleshooting:** 500 + `Unknown column` → step 7 skipped; `/version` old or PHP version unchanged → step 5 without `--build`; `bin/migrate` refuses with a hash mismatch → spec 048's `--trust-current-hashes` only if the change is line endings; a DB client on another machine stopped connecting → spec 062's `127.0.0.1` binding.
2. **`bin/migrate --status`**: read-only; prints applied/pending counts and the pending file names; **exit code 1 when something is pending**, 0 otherwise, so scripts can use it. Implemented by making the runner expose its pending list (e.g. a public `pending(): list<string>`), no schema change.
2b. **`GET /health/ready` answers 503 `MIGRATIONS_PENDING`** while any migration is pending (owner decision, Open question 3), with a fixed message telling the operator to run `bin/migrate`. Consequence, accepted: the cashier/kitchen connection pill (spec 057) shows "Reconectando…"/"Conexão perdida" until the upgrade is finished — the screens would fail on the first new column anyway.
3. **CHANGELOG wording:** the "Rodar `bin/migrate` antes de subir o código novo" notes are corrected **with a dated note** (not silently rewritten) to point at `docs/upgrading.md`; the convention for future `⚠️` notes is recorded in `docs/COMMIT_CONVENTION.md` › Release & Changelog Workflow.
4. **Pending evidence carried here:** spec 062's AC6 (CI run `40426356892`), spec 061's AC2 observation (dev database through `022`: 95 orders / 73 items unchanged).

## Functional requirements

1. FR1 — `docs/upgrading.md` contains steps 1–9 and the troubleshooting rows of Proposed behavior item 1 *(corrected 2026-10-27 by `/spec-review`: the guide numbers them as 7 steps + "Rollback" — dependencies and migrations share step 6 so they are never separated, validation is step 7, rollback its own section; same content)*; README, `docs/installation.md` step 13 and `docs/commands.md` link to it.
2. FR2 — `bin/migrate --status` prints the pending migration file names and exits 1 when at least one is pending; prints that nothing is pending and exits 0 otherwise; writes nothing to the database (the `migrations` table row count is unchanged).
3. FR3 — Following `docs/upgrading.md` from an old **`master` (`8f6adda`)** to the branch head on a database holding orders and a menu: every order and menu item present before is present after (same ids, numbers, totals); `bin/migrate --status` exits 0; `/health/ready` ready; a new order can be created.
4. FR4 — Following the rollback section after FR3's upgrade restores the pre-upgrade data (same row counts) and `/version` reports the old version.
5. FR5 — No CHANGELOG line keeps saying to migrate "before" uploading the code without the dated correction.
6. FR6 — `GET /health/ready` → 503 with `code: MIGRATIONS_PENDING` while a migration file in `common/migrations/` is not recorded in `migrations`; 200 `{"success":true,"status":"ready"}` once none is pending.

## Non-functional requirements

- No new dependency, no Docker/Composer version change, no schema change.
- `--status` must not create the `migrations` table on a database that lacks it (read-only means read-only); report it as "never migrated".

## User flows

**Operator upgrading:** closed restaurant → check version → backup → checkout/pull → `.env` diff → `up -d --build` → `composer install --no-dev` → `migrate` → `migrate --status` → health/version → test order → reopen.

**Operator rolling back:** restore backup → checkout old → `up -d --build` → `composer install --no-dev` → health.

## API changes

Not applicable — no endpoint changes (`/health/ready`, `/version` used as they are).

## Data model and migrations

Not applicable — no schema change; `--status` only reads the `migrations` table.

## Architecture and affected components

- `src/Database/MigrationRunner.php` — expose the pending list read-only.
- `src/Controllers/HealthController.php` — `ready()` also checks pending migrations (FR6).
- *(Recorded by `/spec-review` 2026-10-27)* `tests/Integration/MigrationStatusTest.php` (new); `docs/technical-decisions.md` (the `/health/ready` decision and its cost) and `docs/architecture.md` (connection-awareness paragraph) in the docs pass.
- `bin/migrate` — `--status` flag.
- `tests/Unit` or `tests/Integration` — test for `--status`/pending list (there is integration coverage for the runner already: `MigrationUpgradeTest`).
- New `docs/upgrading.md`; `docs/installation.md` (step 13), `docs/commands.md`, `README.md`, `CHANGELOG.md` (dated corrections + entry), `docs/COMMIT_CONVENTION.md`, `CLAUDE.md` (command list), `docs/ROADMAP.md` (status).
- `specs/061-clean-initial-database.md`, `specs/062-installation-experience.md` — pending evidence.

## Security considerations

- The procedure's backup is the only rollback; the guide says to copy it off the server before upgrading.
- `--status` prints file names and counts only — no credentials, no SQL.
- Validation must not touch the development database or the real `.env`: the rehearsal (AC3/AC4) uses a scratch clone and its own Compose project, as in spec 062 (the owner runs `docker compose down`/`down -v`, which the project's safety hook blocks for the assistant).

## Backward compatibility

- `bin/migrate` without flags behaves exactly as today.
- `MigrationRunner::run()` unchanged.
- Existing CHANGELOG text is corrected with dated notes, not deleted.

## Acceptance criteria

1. AC1 — `docs/upgrading.md` exists with steps 1–9 + troubleshooting; `grep` finds links to it in `README.md`, `docs/installation.md`, `docs/commands.md`.
2. AC2 — On a database with a pending migration: `bin/migrate --status` lists it and exits 1; `SELECT COUNT(*) FROM migrations` is equal before and after. On an up-to-date database: exits 0. Covered by an automated test.
3. AC3 — **Upgrade rehearsal:** scratch clone at an old `master`, `8f6adda` (after spec 057, before 058 — the state the pilot client was in when migration `021`'s 500 happened; owner: the client stays on `master`), installed (its `001_schema.sql` + migrations seed the pilot menu), with orders from that version's `bin/seed-demo`, backup taken; between the pull and `bin/migrate`, `GET /health/ready` → 503 `MIGRATIONS_PENDING` and `bin/migrate --status` → exit 1; then `docs/upgrading.md` followed to the branch head. Before/after: `SELECT COUNT(*), SUM(id), MAX(order_number)` on `orders`, count and checksum of `menu_items (id, name, price)` identical; `bin/migrate --status` exit 0; `/health/ready` ready; `/version` shows the new version; `docker compose exec web php -v` shows 8.2.35; `docker ps` shows `db` on `127.0.0.1:3306`; `POST /api/orders` → 201.
4. AC4 — **Rollback rehearsal** right after AC3: `./bin/restore-db --yes <backup>`, `git checkout 8f6adda`, `up -d --build`, `composer install --no-dev` → row counts equal to the pre-upgrade snapshot; `/version` reports `8f6adda`'s describe.
5. AC5 — `grep -n "antes de subir o código novo" CHANGELOG.md` → every hit carries the dated correction.
6. AC6 — CI green on the PR.

## Implementation plan

1. `MigrationRunner` pending list (read-only) + `bin/migrate --status` + test.
2. `docs/upgrading.md`.
3. Links: README, `docs/installation.md` step 13, `docs/commands.md`, CLAUDE.md.
4. CHANGELOG dated corrections + entry; `docs/COMMIT_CONVENTION.md` note; ROADMAP status.
5. Pending evidence in specs 061/062.
6. Upgrade + rollback rehearsal (AC3/AC4) — needs the owner to stop the dev stack and remove the scratch project.

## Testing and validation strategy

The project has PHPUnit (Unit + MySQL Integration) and Playwright — the template's "no automated test infrastructure" note is outdated.

- **Automated:** a test for the pending list / `--status` (AC2), in the Integration suite against `restaurant_test_upgrade` (it already builds old migration states) or with a temp migrations directory; full suite in CI (AC6).
- **Rehearsal (AC3/AC4):** scratch clone at `8f6adda`. Note: that `Dockerfile` still has `COPY legacy/` (fixed only by spec 062), so a fresh `--build` of that tag fails on a clean clone; the rehearsal creates an empty `legacy/` in the scratch clone to stand in for what every pre-062 install already had — recorded as such.
- **Not automatable here:** the pilot client's real upgrade (their server, their data) — the guide is what they will follow.

## Rollout and rollback

Docs and the `--status` flag ship with the merge. Rollback: revert the commit (no data or schema involved).

## Open questions

1. *(non-blocking)* **Tags or `master` for restaurants?** Proposed: the guide recommends checking out a **tag**; `master` is documented as the development line. Note that client patches (v1.8.x) are tags too.
2. *(non-blocking)* **Run migrations automatically when `web` starts?** Would remove the "forgot `bin/migrate`" failure, but a failed migration would then block the app from starting, and two containers (`web`, `print-worker`) would race. Proposed: **no**, keep it explicit; `--status` + the guide cover it.
3. *(non-blocking)* **Make `/health/ready` fail while migrations are pending?** The cashier/kitchen connection pill (spec 057) polls it, so the screens would say "Conexão perdida" — arguably right (the app is broken), but confusing. Proposed: **no** here; consider with the `display_errors` follow-up.
4. *(non-blocking)* **Pilot client's current version.** Knowing it would let the guide's first real use be rehearsed exactly. Proposed: rehearse from `v1.8.3` (last tag) unless you tell me otherwise.

## Task checklist

- [x] Step 1 — pending list + `bin/migrate --status` + `/health/ready` 503 + test
- [x] Step 2 — `docs/upgrading.md`
- [x] Step 3 — links
- [x] Step 4 — CHANGELOG corrections, COMMIT_CONVENTION, ROADMAP, technical-decisions, architecture
- [x] Step 5 — pending evidence (specs 061/062)
- [x] Step 6 — upgrade + rollback rehearsal

## Implementation log

- 2026-10-27 — Owner's answers to the open questions, then `/spec-implement` (Draft → Approved → In Progress): (1) **`master` is the final version** — restaurants upgrade with `git pull` on `master`; tags only to pin/go back. (2) No automatic migrations — as proposed. (3) **"Ajuste"**: `/health/ready` must fail while migrations are pending — added as FR6/Proposed behavior 2b, removed from Non-goals. (4) The pilot client stays on `master` → the rehearsal starts from an old `master` (`8f6adda`) instead of `v1.8.3`.
- `MigrationRunner::pending()` is public and read-only: it lists `*.sql` names minus the recorded ones and **never creates the `migrations` table** (on a database without it, everything is pending). `getPendingFiles()` now derives from it, so `run()` keeps its exact order (`sort(SORT_STRING)` = the previous `strcmp` sort).
- `HealthController` takes the runner through a promoted `private readonly` constructor parameter defaulting to `new MigrationRunner()` — PHP-DI still autowires it, and the test injects a runner pointed at a temp directory. The 503 message is fixed text, never the file names (same rule as spec 044's `DB_UNAVAILABLE`). The frontend heartbeat only reads `res.ok`, so no JS change was needed for the pill to go "Reconectando…"/"Conexão perdida".
- New `tests/Integration/MigrationStatusTest.php` (3 tests): nothing pending on the migrated test DB + `/health/ready` 200; a temp dir with every real file plus `999_not_applied.sql` → `pending()` returns exactly that name and the `migrations` row count is unchanged; `HealthController` with that runner → 503 `MIGRATIONS_PENDING`. The CLI exit-1 path is exercised in the rehearsal (it needs a really pending migration).
- `docs/upgrading.md` example output of `/version` was taken from the running dev server (`v1.8.3-47-ga8b28d6`), not invented; `bin/restore-db` accepts both `backups/<file>` and `<file>` (checked in the script).
- 2026-10-27 — **Rehearsal** (owner stopped the dev stack; scratch clone `up1`). Found while rolling back: `8f6adda`'s `Dockerfile` has `COPY legacy/`, and my `git stash -u` (needed only because the branch-063 patch was applied by hand in the scratch clone) removed the empty `legacy/` folder → the rollback build failed with `"/legacy": not found`. A real pre-v1.9 install keeps that empty folder on disk (its old bind mounts created it, and the new compose never deletes it), but an operator rolling back on a machine without it hits the same wall — added a troubleshooting row to `docs/upgrading.md` (`mkdir legacy`, rebuild). Also noted: `8f6adda`'s `Dockerfile` uses the floating `php:8.2-apache`, which already resolves to 8.2.35, so this rehearsal cannot show a PHP version change; the image rebuild itself is shown by the recreated containers and the `db` port binding.
- CHANGELOG: the three "antes de subir o código novo" notes (specs 050, 053, 058) got a dated correction appended, not rewritten; the rule for future `⚠️` notes went to `docs/COMMIT_CONVENTION.md`.

## Validation evidence

Collected 2026-10-27 on branch `063`, Docker Desktop on Windows. Rehearsal in a scratch clone (`up1`) outside the working tree; the development stack was stopped by the owner (`docker compose down`, volume kept).

| AC | Command / evidence | Result |
|---|---|---|
| AC1 | `docs/upgrading.md`: "Why the order matters", steps 1–7, Rollback, Troubleshooting (9 rows). Linked from `README.md` (Documentation table + Installation), `docs/installation.md` step 13, `docs/commands.md` (intro + `bin/migrate`) | Met |
| AC2 | `tests/Integration/MigrationStatusTest.php`: up-to-date DB → `pending() === []` and `/health/ready` 200; temp dir with every real migration + `999_not_applied.sql` → `pending() === ['999_not_applied.sql']`, `migrations` row count unchanged; `HealthController` with that runner → 503 `MIGRATIONS_PENDING`. Integration suite `OK (73 tests, 336 assertions)`. CLI: dev DB → `✓ Nenhuma migração pendente.`, exit 0; rehearsal (AC3) → 2 pending listed, exit 1 | Met |
| AC3 | **Install old `master` `8f6adda`** (`v1.8.3-22-g8f6adda`): `up -d --build`, `composer install --no-dev`, `bin/migrate` (up to `020`), `create-admin`, `bin/seed-demo` → pilot menu (74 items) + 65 orders. Fingerprint (count, `SUM(id)`, MD5 of every row): `orders 65 2145 fc2385ec…`, `order_items 138 9591 6731c234…`, `menu_items 74 2775 81cfd17d…`, `users 1 1 65af559a…`, `settings 3 097959bb…`. `./bin/backup-db` → `backups/gastroflow-restaurant-20261027-013514.sql.gz (12K)`. **Upgrade per the guide:** step 3 to `a8b28d6` + branch-063 changes (`git diff --stat` showed `.env.example`, `Dockerfile`, `docker-compose.yml`, migrations `021`, `022`); step 5 `up -d --build` → `web`/`print-worker` Recreated. **Before step 6:** `/health/ready` → `HTTP=503` `{"success":false,"error":"Atualização incompleta: há migrações pendentes. Rode bin\/migrate.","code":"MIGRATIONS_PENDING"}`; `bin/migrate --status` → `2 migração(ões) pendente(s)`: `021_order_item_discount.sql`, `022_clean_fresh_install.sql`, exit 1. Step 6: `composer install` → `Nothing to install, update or remove`; `bin/migrate` → `021 … [OK]`, `022 … [OK]`. Step 7: `--status` exit 0; `/health/ready` ready; `/version` `v1.8.3-47-ga8b28d6`; `db`/`web`/`print-worker` Up, `db` on `127.0.0.1:3306`. **Data:** fingerprints of `orders`, `order_items`, `menu_items`, `users` **identical**; `settings` 3 → 4 rows = the new `discount_max_percent=100` inserted by `021`, and the other three rows' MD5 equals the pre-upgrade one (`097959bb…`). Test order: `POST /api/orders` (Prato do Dia, `viagem_simples`) → 201, id 66. Not shown: a PHP version change (both images resolve to 8.2.35 — see Implementation log) | Met |
| AC4 | **Rollback per the guide:** `./bin/restore-db --yes backups/gastroflow-restaurant-20261027-013514.sql.gz` → `Banco 'restaurant' restaurado`; `git checkout 8f6adda`; `up -d --build` — first attempt failed (`"/legacy": not found`, cause in the Implementation log), after `mkdir legacy`: Recreated, `db` back on `0.0.0.0:3306` (the old compose); `composer install`. Fingerprint after rollback **identical** to the pre-upgrade one (all 5 rows, including `settings`; test order 66 gone, as the guide warns); last migration `020_login_attempts.sql`; `order_items.discount_type` column absent; `/version` `v1.8.3-22-g8f6adda`; `/health/ready` ready | Met |
| AC5 | `grep -c "Correção 2026-10-27, spec 063" CHANGELOG.md` → 3 = the 3 lines containing "antes de subir o código novo" | Met |
| AC6 | Local: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 115 files`; Unit `OK (238 tests, 474 assertions)`; Integration `OK (73 tests, 336 assertions)`. CI not run yet | Pending CI |

### CI evidence (PR #49, recorded 2026-10-27 in spec 064's PR)

CI run `40451137145` on `7b082fa` (branch `063`), conclusion `success`, raw log: PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 115 files`; Unit `OK (238 tests, 474 assertions)`; Integration `OK (73 tests, 336 assertions)`; Playwright `29 passed`. PR #49 merged as `116a9d4`. **AC6 → Met.**

Every acceptance criterion (AC1–AC6) now has recorded evidence → **`Verified`** (2026-10-27). The decision revised below changes the guide's step 3 wording, not any criterion: the rehearsal was an upgrade between two commits and stays valid.

### Decision revised after merge (2026-10-27)

Decision (1) — "`master` is the final version; restaurants upgrade with `git pull` on `master`" — was **revised by the owner** after an external review (`master` had 51 unreleased commits after `v1.8.3`). Spec 064 rewrote `docs/upgrading.md` step 3 to `git fetch --tags` + `git checkout <release tag>`.
