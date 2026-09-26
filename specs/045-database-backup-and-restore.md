# Spec 045 — Database backup and restore commands

## Metadata

- Status: Verified
- Created: 2026-09-26
- Updated: 2026-09-26
- Owner: Henry
- Related issue: Not applicable (no GitHub Issue filed; tracked directly via `docs/ROADMAP.md`'s v1.8.0 "Backup & restore" item and its matching Trello card)
- Related branch: 045

## Context

`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` milestone includes:

> Document and test database recovery. Provide clear procedures for: backup, restore. Before v2.0, perform an actual restore test. A backup that has never been restored is not considered verified.

The user asked directly for "a backup command of db", then, mid-session, for a paired command to "take most recent backup (or named) to actual db" — i.e. a restore command that defaults to the newest backup but also accepts an explicit file. This matches the roadmap item exactly: a backup that can never be restored isn't verified, so the two commands are one unit of work, not two.

Before writing this spec, the mechanism was investigated and a decision was confirmed with the user (`AskUserQuestion`): the `web`/`print-worker` containers have no `mysqldump`/`mysql` client installed (only the `pdo_mysql` PHP extension — confirmed by `docker compose exec web sh -c 'command -v mysqldump; command -v mysql'`, both empty). The `db` service, however, runs the official `mysql:8.0` image, which ships the full client toolset. The user chose: a **host-run script that shells out to `docker compose exec db mysqldump`/`mysql`**, not a new PHP `bin/` script (which would need a new system dependency added to the `web` image — against `CLAUDE.md`'s "don't add new dependencies unless explicitly asked") and not a hand-rolled PHP dump (reimplementing what `mysqldump` already does correctly, for a feature where correctness matters most).

## Problem

GastroFlow has no command to take a backup of the `restaurant` database, and no command to restore one. `docs/ROADMAP.md`'s own `v2.0 Verification Matrix` lists "Database backup succeeds" / "Database restore succeeds" / "GastroFlow works after restore" as ungated release checks with nothing behind them today.

## Goals

- A `bin/backup-db` command that produces a timestamped, compressed SQL dump of the configured database, using the `db` container's own `mysqldump`.
- A `bin/restore-db` command that restores a backup into the configured database — defaulting to the most recently created backup when no file is named, or accepting an explicit file/filename otherwise — using the `db` container's own `mysql` client.
- Neither command requires any new dependency: no new Composer package, no new `apt`/system package in any Dockerfile, no code path that reads or prints `.env` from the host side (both commands lean on environment variables the `db` container already receives from `docker-compose.yml`, so credentials never need to be parsed by the script itself).
- Restore is explicitly destructive (a straight `mysqldump` dump includes `DROP TABLE IF EXISTS` for every table via `--opt`'s default `--add-drop-table`), so `bin/restore-db` must require explicit confirmation before touching the database, with a non-interactive escape hatch (`--yes`) for scripted/CI use.
- The round-trip (backup → restore) is actually exercised once against a throwaway database, not just implemented — an untested backup is exactly what the roadmap item warns against.

## Non-goals

- A `docs/backup-restore.md` (or equivalent full documentation page) with supported-scenario write-ups, disaster-recovery runbooks, etc. That belongs to `v1.9.0`'s "Documentation structure" item (which explicitly lists a future "Backup & Restore" doc page). This spec only produces the two commands plus the in-script usage header (matching every existing `bin/` script's own documentation convention) and the `CLAUDE.md` command-list entry — enough to actually run and verify the commands, not a full ops manual.
- Automatic/scheduled backups (a cron-like `bin/backup-db` invocation, backup retention/pruning). The roadmap item asks for "procedures," not automation; `bin/jobs-prune`/`bin/events-prune`'s retention pattern exists for a different reason (bounding table growth) and doesn't apply here. Can be a follow-up.
- Encrypting backup files, or shipping them off-host (S3, etc.). Out of scope; the roadmap item's own language ("backup", "restore") doesn't ask for this.
- A PHP `bin/` script or any change to the `web`/`print-worker` `Dockerfile`. Decided against in the Context section above.
- A restore path that merges/upserts into an existing database without dropping tables first. `mysqldump`'s own default behavior is drop-and-recreate; changing that would mean overriding `--opt`'s defaults for reasons unrelated to this spec's goal, and would leave stale rows behind if the backup has fewer rows than the live table — a worse, silently-wrong outcome for a *restore* command specifically.
- A native Windows (PowerShell/`.bat`) equivalent. Both commands are POSIX shell scripts, consistent with this session's own working environment (Git Bash on Windows) and every other host-run command already documented in `CLAUDE.md` (all assume a POSIX-ish shell). Flagged as a real limitation for a non-Git-Bash Windows operator, not silently ignored.

## Current behavior

- `docker-compose.yml`'s `db` service (lines 2-13) receives `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` as container environment variables, sourced from the host's `.env` by Compose itself — the container already has everything needed to run `mysqldump`/`mysql` against itself with no script-side secret handling.
- The `web` and `print-worker` containers have `pdo_mysql` (confirmed in `Dockerfile:15`) but no MySQL client binary — confirmed empirically this session via `docker compose exec web sh -c 'command -v mysqldump; command -v mysql'`, both empty.
- The `db` container does have `gzip` (confirmed this session: `docker compose exec -T db sh -c 'command -v gzip'` → `/usr/bin/gzip`), part of the base `mysql:8.0` image — no extra package needed for compression, and compression/decompression can happen entirely inside the container, so the host script needs no `gzip`/`gunzip` binary of its own (only `cat`/`docker compose`, universally present wherever Compose itself runs).
- Every existing `bin/*` script (`bin/migrate`, `bin/create-admin`, `bin/worker`, `bin/jobs-status`, `bin/jobs-prune`, `bin/events-prune`) is a `#!/usr/bin/env php` script meant to run **inside** the `web` container (they default `DB_HOST` to `db`, the Compose service name, which only resolves on the Compose network) — none of them is a host-run script today. This spec introduces the first two.
- `.gitignore` already has a `*.sql.gz` entry (line ~43) — anticipating exactly this kind of artifact, though no directory or script produces one yet.
- `MYSQL_USER` (`restuser` by convention) has full privileges on `MYSQL_DATABASE` only (granted by the official MySQL image's own entrypoint) — confirmed indirectly by `CLAUDE.md`'s own note that "the app user cannot create databases" (used for the integration-test database's one-time setup). This is enough to run `mysqldump`/`mysql` against that one database, but not enough to create a second, throwaway database for this spec's own restore validation — that step needs `MYSQL_ROOT_PASSWORD`, read the same way (as an env var already present inside the `db` container), never from the host `.env`.

## Proposed behavior

- `bin/backup-db` (bash, `chmod +x`, host-run):
  1. Resolves the repo root (so it can be invoked from any working directory) and ensures a `backups/` directory exists there.
  2. Builds a filename `backups/gastroflow-<database>-<YYYYmmdd-HHMMSS>.sql.gz` (database name taken from the `db` container's own `MYSQL_DATABASE`, not hardcoded, via `docker compose exec -T db sh -c 'echo "$MYSQL_DATABASE"'`).
  3. Runs `docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u"$MYSQL_USER" "$MYSQL_DATABASE" | gzip'`, redirecting stdout to that file. `MYSQL_PWD` (rather than `mysqldump -p"$MYSQL_PASSWORD"`) keeps the password out of the container's own process list.
  4. On success, prints the resulting file path and its size in a short Portuguese confirmation line; on failure (non-zero exit from `docker compose exec`), prints an error to stderr and exits non-zero **without leaving a partial/empty backup file behind** (write to a temp name, then `mv` into place only on success).
- `bin/restore-db [--yes] [arquivo]` (bash, `chmod +x`, host-run):
  1. Argument parsing: `--yes` (order-independent) skips the confirmation prompt; an optional positional argument names a backup file — a bare filename is resolved inside `backups/`, anything containing a `/` (or an absolute path) is used as-is.
  2. When no file argument is given, selects the most recently modified `backups/*.sql.gz` (via `ls -t`); if `backups/` has no matching file, exits non-zero with a clear message instead of doing anything.
  3. Without `--yes`: prints the resolved file, its size, and the target database name, then requires the operator to type the literal word `RESTAURAR` to proceed; anything else (including EOF/non-interactive stdin) aborts with no change made and a non-zero exit.
  4. Runs `cat "$file" | docker compose exec -T db sh -c 'gunzip | MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"'`. Decompression happens inside the container (via the `gzip` already confirmed present there), so the host needs no `gzip`/`gunzip` binary itself.
  5. On success, prints a short Portuguese confirmation naming the file and database restored; on failure, prints the `docker compose exec` failure to stderr and exits non-zero.

## Functional requirements

1. `bin/backup-db` run against a healthy `db` container produces a new, non-empty `backups/gastroflow-<database>-<timestamp>.sql.gz` file and exits `0`.
2. `bin/backup-db` prints the created file's path on success.
3. `bin/restore-db --yes` with no file argument restores the most recently modified file under `backups/` (by mtime).
4. `bin/restore-db --yes <name>` restores exactly the named file, whether given as a bare filename (resolved under `backups/`) or a path.
5. `bin/restore-db` invoked without `--yes` and with no `RESTAURAR` confirmation typed (including non-interactive/empty stdin) performs **no** database change and exits non-zero.
6. `bin/restore-db` with no matching backup file available (no argument, empty `backups/`) exits non-zero with a clear message and performs no database change.
7. Neither script requires the `web`/`print-worker` image to gain any new package, and neither script contains a hardcoded password or reads `.env` directly — both rely solely on environment variables already present inside the running `db` container.

## Non-functional requirements

- No new Composer dependency, no new Dockerfile/`apt` package, no Docker/Compose version bump.
- No database migration — this feature only reads/writes data through `mysqldump`/`mysql`, never through the app's own schema-owned migration path.
- The backup file must never be committed to Git (`.gitignore` coverage, see Data model section below — nothing to add there since `*.sql.gz` is already ignored, but the new `backups/` directory itself should be ignored too, so an accidentally-`git add -A`'d empty directory or a future non-`.gz` artifact doesn't leak in).
- Password material must not appear in any process's argv (`MYSQL_PWD`, not `-p<password>`) or in either script's own output.

## User flows

- **Operator taking a manual backup**: runs `./bin/backup-db` from the repo root before a risky operation (e.g. before a migration, before an upgrade per `v1.9.0`'s future "Upgrade process"), gets back a file path to keep somewhere safe.
- **Operator restoring after data loss / rolling back**: runs `./bin/restore-db`, is shown which file (the most recent, since none was named) and which database it's about to overwrite, types `RESTAURAR` to confirm, and the database is restored to that backup's state.
- **Operator restoring a specific, older backup**: runs `./bin/restore-db gastroflow-restaurant-20260901-030000.sql.gz` (or a full path) to restore that exact file instead of the newest one.
- **Scripted/CI use** (e.g. a future automated restore-test): `./bin/restore-db --yes <file>` restores without an interactive prompt.

## API changes

Not applicable — these are CLI scripts, no HTTP endpoint is introduced or changed.

## Data model and migrations

Not applicable — no schema, table, or migration file changes. `.gitignore` gains one new entry (`/backups/`) so the directory these scripts create is never accidentally committed; this is a repo-hygiene change, not a data model change.

## Architecture and affected components

- `README.md` — a short "Backup & restore" subsection under "Getting started", giving the two commands and their basic usage. Every other `bin/*` script (`migrate`, `worker`, `jobs-prune`, etc.) is documented **only** in `CLAUDE.md`, not README — but backup/restore is safety-critical, operator-facing functionality in a way "prune old jobs" isn't, so it earns a short, concrete mention in the user-facing doc too. This is not the full runbook/doc page the Non-goals section excludes (no disaster-recovery scenarios, no troubleshooting) — just enough for an operator reading the README to know the commands exist and how to call them.
- New `bin/backup-db` — bash script, host-run (outside any container), following the *spirit* of the existing `bin/*` scripts (single-purpose, documented header comment) but not their `#!/usr/bin/env php` shape, since this one deliberately runs on the host and shells out to Compose rather than running inside `web`.
- New `bin/restore-db` — same pattern.
- `.gitignore` — one new line (`/backups/`).
- `CLAUDE.md` — two new lines under "Commands actually available", matching how every other `bin/*` script is documented there.
- No changes to `src/`, `common/migrations/`, `docker-compose.yml`, or any Dockerfile.

## Security considerations

- Both scripts avoid ever having the database password appear in a process's command-line arguments (visible via `ps` to any other process/user on the same host or inside the container) by using the `MYSQL_PWD` environment variable instead of `mysqldump -p.../mysql -p...`.
- Neither script reads, parses, or prints the host's `.env` file — all credentials are read from the `db` container's own already-injected environment (`docker-compose.yml`'s `environment:` block for that service), which Compose itself populates from `.env`. This keeps the scripts themselves free of any direct secret handling.
- `bin/restore-db`'s confirmation gate (typed `RESTAURAR`, or explicit `--yes`) exists specifically because the underlying `mysql` invocation will execute `DROP TABLE IF EXISTS` statements from the dump — this is standard `mysqldump`/`mysql` behavior being surfaced clearly to the operator, not a new risk this spec introduces.
- Backup files contain full restaurant data (orders, settings, user password hashes, etc.) and must never be committed — covered by the new `.gitignore` entry.

## Backward compatibility

Purely additive — two new files, one new `.gitignore` line, one new `CLAUDE.md` documentation entry. No existing command, endpoint, or workflow changes.

## Acceptance criteria

1. Running `./bin/backup-db` against the local Compose stack exits `0`, prints a file path under `backups/`, and that file exists, is non-empty, and is valid gzip (`gzip -t` succeeds on it, run against the container's own `gzip` or confirmed by successfully decompressing it).
2. The decompressed dump contains real SQL (`CREATE TABLE`, `INSERT INTO`) for the configured database's tables.
3. Running `./bin/restore-db --yes` with no argument selects the most recent file under `backups/` (verified by creating two backups a few seconds apart and confirming the second is the one restore picks — checked via the script's own printed confirmation, not by inspecting mtimes by hand only).
4. Running `./bin/restore-db --yes <specific-file>` restores exactly that file, verified by restoring into a throwaway validation database (not `restaurant`) and comparing row counts of a known table against the source backup.
5. Running `./bin/restore-db` with stdin non-interactive (e.g. `</dev/null`) and no `--yes` exits non-zero and performs no database write (verified against the throwaway validation database: row count unchanged after the attempt).
6. Running `./bin/restore-db` with `backups/` empty (or the named file missing) exits non-zero with a message, no `docker compose exec` is even attempted.
7. `bash -n bin/backup-db` and `bash -n bin/restore-db` (syntax-only check) pass.
8. `docker compose exec web sh -c 'command -v mysqldump'` / `mysql` remain empty after this change — confirming no dependency was actually added to the `web`/`print-worker` image.

## Implementation plan

1. Write `bin/backup-db`: repo-root resolution, `backups/` directory creation, filename construction, the `docker compose exec -T db ... | gzip` pipeline, atomic write (temp file + `mv`), success/error output.
2. Write `bin/restore-db`: argument parsing (`--yes`, optional file), most-recent-file selection, the confirmation gate, the `cat | docker compose exec -T db ... gunzip | mysql` pipeline, success/error output.
3. `chmod +x` both scripts.
4. Add `/backups/` to `.gitignore`.
5. Add both commands to `CLAUDE.md`'s "Commands actually available" list.
6. Manually validate the full round-trip against a throwaway database (never `restaurant`/`restaurant_test`): create it and grant `MYSQL_USER` access using the `db` container's own `MYSQL_ROOT_PASSWORD` env var (never read from host `.env`), restore a real backup into it, spot-check row counts, then drop it.
7. Update `docs/ROADMAP.md`'s "Backup & restore" item with a `**Status (spec 045)**` line reflecting exactly what was/wasn't done (restore *tested*, but against a throwaway DB, not a full disaster-recovery drill — that distinction matters and will be stated plainly).
8. Update `README.md`'s "In progress"/"Next up" lines and `CHANGELOG.md`'s `v1.8.0` section.

## Testing and validation strategy

This project's automated test suites (PHPUnit `Smoke`/`Unit`/`Integration`) test **application code**; these two scripts are host-level shell scripts with no PHP entry point, so they fall outside that suite entirely (there is no meaningful way to unit-test a script whose entire job is to shell out to `docker compose exec`). Validation here is real command execution against the actual local Docker Compose stack:
- `bash -n` on both scripts for a syntax check.
- An actual `./bin/backup-db` run against the real running `db` service (read-only from the database's perspective — `mysqldump` takes a consistent snapshot, doesn't write).
- An actual `./bin/restore-db` run — but targeted at a **throwaway database created and dropped solely for this validation**, never at `restaurant` (the development data) or `restaurant_test` (the integration suite's own database), per `CLAUDE.md`'s "never run destructive database operations" — restoring a `mysqldump`-produced file is inherently destructive (`DROP TABLE IF EXISTS`) to whatever database it targets, so the target for validation purposes must not be one that matters.
- The non-interactive-refusal and empty-`backups/` paths are checked by actually running the script in those conditions and observing the exit code / that no query reached the database (inferred from the throwaway database's row count being unchanged, and from `docker compose exec` never appearing to run in the second case).

## Rollout and rollback

Additive, no feature flag needed — two new opt-in scripts an operator chooses to run. Rollback is deleting the two files and the `.gitignore` line; no data or schema to unwind. `backups/` itself (being gitignored) never enters version control, so removing the feature never touches committed history.

## Open questions

- Should `bin/restore-db` also verify the backup file's integrity (e.g. `gzip -t`) *before* asking for confirmation, so a corrupt file is caught before the operator commits to the destructive step? **Non-blocking** — worth doing, folded into the implementation itself (not deferred) since it's a small addition to the same script, not a separate follow-up; noted here only because it wasn't in the user's original two-line request.
- Should there be a `--list`/no-argument-at-all mode for `bin/restore-db` that just shows available backups without restoring anything? **Non-blocking**, not requested; can be a trivial follow-up if the operator finds themselves guessing filenames often.

## Task checklist

- [x] `bin/backup-db` added and executable
- [x] `bin/restore-db` added and executable
- [x] `.gitignore` updated (`/backups/`)
- [x] `CLAUDE.md` commands list updated
- [x] Backup run against the real local `db` and verified (gzip-valid, contains real SQL)
- [x] Restore round-trip validated against a throwaway database — **not** dropped afterward: the `DROP DATABASE` was blocked by this repo's AI safety hook, and the user explicitly chose to leave it rather than have it force-executed (see Implementation log)
- [x] Non-interactive-refusal and empty-`backups/` paths verified
- [x] `docs/ROADMAP.md`, `README.md`, `CHANGELOG.md` updated

## Implementation log

- Implemented as planned, with one refinement discovered during real execution: the first `bin/backup-db` run against the local stack printed a `mysqldump` warning (`Access denied; you need ... PROCESS privilege(s) ... when trying to dump tablespaces`) — `MYSQL_USER` has no global `PROCESS` privilege (matches the spec's own Current behavior note that it's scoped to its database only). Fixed by adding `--no-tablespaces` to the `mysqldump` invocation in `bin/backup-db`; re-ran and the warning is gone. Not a functional bug (the backup still succeeded either way), but a real one for operator trust in the tool's output.
- Resolved the first Open question during implementation, not deferred: `bin/restore-db` does verify gzip integrity (`gunzip -t`, run inside the `db` container so the host needs no `gzip`/`gunzip` of its own) before asking for confirmation, exactly as the open question suggested.
- **Line-ending hygiene, unplanned but necessary**: editing `.gitignore` and `.gitattributes` initially produced a huge unintended diff (the whole file, not just the intended one line) — investigation showed the committed blobs for `.gitignore`/`.gitattributes`/every existing `bin/*` script are pure LF, but this Windows working tree (with `core.autocrlf=true`, per the still-open "Line endings" roadmap item) checks them out as CRLF for anything `.gitattributes` doesn't explicitly force to `eol=lf`. Fixed by surgically appending only the new line(s) via `head`/`tail`/`printf` instead of a full-file rewrite, keeping the diff to exactly the intended line(s). Did **not** fix the pre-existing mixed line endings in these files generally — that's the separate, still-open "Line endings" roadmap item, and fixing it here would be unrelated scope creep.
- Added a small, targeted `.gitattributes` rule (`bin/backup-db text eol=lf`, `bin/restore-db text eol=lf`) not in the original plan: these two new files are bash scripts, where a CRLF shebang line is a real, fatal bug (unlike the existing PHP `bin/*` scripts, which parse fine either way) — confirmed empirically that every existing extension-less `bin/*` script already carries CRLF in this working tree despite an LF-only committed blob. This protects only the two files this spec adds; it does not touch or attempt to fix any pre-existing file.
- **Validation methodology for the destructive restore path**: `bin/restore-db` has no built-in way to target a database other than whatever `MYSQL_DATABASE` the `db` container reports (by design — no such flag was in scope). To validate the actual restore mechanism without ever touching `restaurant`/`restaurant_test`, a throwaway database (`restore_validation_045`) was created using the `db` container's own `MYSQL_ROOT_PASSWORD` (read from that container's environment, never from the host `.env`), and the **exact same pipeline** `bin/restore-db` uses was run via `docker compose exec -e MYSQL_DATABASE=restore_validation_045 db ...` — proving the mechanism, though not literally invoking the wrapper script's own binary against that target (the wrapper itself was separately validated end-to-end for every non-destructive path: argument parsing, most-recent-file selection, both confirmation-refusal paths).
- **README addition, not in the original plan**: mid-implementation, the user asked directly whether the commands were documented in the user-facing README (they weren't — only `CLAUDE.md`'s internal command list covers `bin/*` scripts today). Added a short "Backup & restore" subsection to `README.md` under "Getting started" rather than a full doc page, since that stays within this spec's stated Non-goals (no runbook, no troubleshooting guide — just "here are the two commands and how to call them").
- **Full docs pass, prompted by `/spec-review`'s own finding plus a direct user request**: `/spec-review` caught that `docs/ROADMAP.md`'s spec-045 status line claimed the throwaway validation database was dropped, when the `Implementation log` above already disclosed it wasn't (blocked by the safety hook). Fixed to match reality. The user then asked for a full docs pass across the project and for any pitfalls found in the code to be documented, not just the files this spec originally listed:
  - `docs/architecture.md`: added `bin/backup-db`/`bin/restore-db` to the "Persistence" section and the `Project structure` tree (which was still listing only `migrate, worker` under `bin/`, missing `create-admin`/`jobs-status`/`jobs-prune`/`events-prune` too — a pre-existing gap, fixed in the same pass since it was already being touched); corrected the migration count (stale at "13 files … up to `014_...`", actually 17 files up to `018_audit_log.sql` — dated in place, not silently rewritten, per this doc's own convention). Added two new "Known architectural limitations" bullets documenting real pitfalls found while validating: (1) `bin/restore-db` has no `--database` override, so validating it required reproducing its pipeline by hand against a throwaway database rather than running the shipped script end-to-end; (2) the scripts are bash-only (no native Windows shell support), and `mysqldump` against `MYSQL_USER` prints an expected-but-alarming-looking `PROCESS` privilege warning unless `--no-tablespaces` is passed (which `bin/backup-db` already does).
  - `docs/technical-decisions.md`: found and fixed an unrelated stale claim while reviewing this file — the "Signal-file SSE" row still said replacing it was merely *planned* by `v1.8.0`, when spec 041 had already shipped that replacement. Corrected in place (struck through, dated), not deleted. Added a new decision row for the host-run-script choice this spec itself made.
  - `specs/000-project-baseline.md`: added a dated "Known commands" entry for the two new scripts, matching this file's own established correction convention. Deliberately **did not** touch this file's own stale migration-file count (still says "8 files … up to `008_order_items_price.sql`") or its "no formal seeding mechanism beyond the one admin row in `001_schema.sql`" claim (contradicted elsewhere in the same file — spec 015 removed that row) — both predate this spec, are unrelated to backup/restore, and this file is an explicitly frozen point-in-time snapshot (per its own header and per `/spec-review`'s standing instructions) that only gets corrected for claims actively misleading enough to matter, not for every stale count. Flagged to the user rather than silently fixed or silently left unmentioned.
- **Cleanup left undone, disclosed rather than forced**: dropping `restore_validation_045` afterward was blocked by this repo's own AI safety hook ("Blocked by GastroFlow AI safety policy: destructive operation"). Rather than find a way around the hook, this was surfaced to the user directly (`AskUserQuestion`), who chose to leave the throwaway database in place for now rather than have it dropped. It contains only a copy of already-backed-up development data and isn't referenced anywhere in the app; it remains on the local `db` container as a known, disclosed leftover.

## Validation evidence

- **AC1/AC2** (backup succeeds, valid gzip, contains real SQL): `./bin/backup-db` → `✔ Backup criado: backups/gastroflow-restaurant-20260926-012318.sql.gz (12K)` (12K again on a second run: `...012452...`). `cat "$f" | docker compose exec -T db sh -c 'gunzip -t'` → exit 0 (no output = valid). `cat "$f" | docker compose exec -T db sh -c 'gunzip' | grep -Ec "^(CREATE TABLE|INSERT INTO)"` → `30`.
- **AC3** (most-recent-file selection): created a second backup ~90s after the first (`...012318...` then `...012450...`), then ran `echo "n" | ./bin/restore-db`, which printed `conteúdo de: backups/gastroflow-restaurant-20260926-012450.sql.gz` — the newer of the two, confirmed correct.
- **AC4** (named-file restore actually restores correct data): created `restore_validation_045` via the `db` container's own root credentials; ran `cat backups/gastroflow-restaurant-20260926-012450.sql.gz | docker compose exec -T -e MYSQL_DATABASE=restore_validation_045 db sh -c 'gunzip | MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"'` → exit 0. Compared row counts: `restaurant` had `menu_items=77, categories=6`; `restore_validation_045` after restore had the identical `menu_items=77, categories=6`.
- **AC5** (non-interactive refusal): `./bin/restore-db </dev/null` → printed the file/database it would target, then `Erro: confirmação necessária, mas a entrada não é interativa. Use --yes para pular o prompt.`, exit 1. Also observed via `echo "n" | ./bin/restore-db` (a pipe is likewise non-interactive stdin), same refusal.
- **AC6** (missing/empty backups refusal): `./bin/restore-db arquivo-que-nao-existe.sql.gz` → `Erro: arquivo de backup não encontrado: backups/arquivo-que-nao-existe.sql.gz`, exit 1. With `backups/*.sql.gz` moved aside temporarily (restored immediately after): `./bin/restore-db` → `Erro: nenhum backup encontrado em backups/ e nenhum arquivo foi informado.`, exit 1.
- **AC7** (syntax check): `bash -n bin/backup-db` → no output, exit 0. `bash -n bin/restore-db` → no output, exit 0.
- **AC8** (no dependency added to `web`): `docker compose exec -T web sh -c 'command -v mysqldump; command -v mysql; echo done'` → only `done` printed — both still absent, confirming no dependency was added to that image.
- Not independently re-verified by a separate reviewer pass yet — `/spec-review` still pending, per this project's standing rule to always run it before considering a spec finished.
