# Spec 048 — Migration hash-divergence detection and upgrade testing

## Metadata

- Status: Verified
- Created: 2026-09-27
- Updated: 2026-09-27
- Owner: Henry
- Related issue: Not applicable (no GitHub Issue filed; tracked via `docs/ROADMAP.md`'s v1.8.0 "Migration reliability" item and Trello card "[v1.8] Migration reliability", https://trello.com/c/BOHcUNUq)
- Related branch: 048

## Context

`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` milestone lists "Migration reliability" as still open. The item was refined on 2026-09-26 (external review, recorded as a comment on the Trello card) to three concrete gaps in `App\Database\MigrationRunner`, forward-only by design and not up for replacement:

1. **Hash-divergence detection**: each applied migration's md5 is recorded but never compared afterward — an already-applied migration file edited in place goes undetected.
2. **Fresh-install test**: CI already runs `common/sql/001_schema.sql` + `bin/migrate` against an empty database, but this isn't named as an intentional test.
3. **A real upgrade test, `v1.7.x → HEAD`**: CI always starts from an empty database; nothing exercises "existing data survives a real upgrade."

Rollback sophistication is explicitly de-prioritized per the roadmap's own wording — forward-only stays correct.

This session's investigation (see Current behavior) found that a naive hash-divergence check would **immediately misfire** on this project's own migration history, for a reason unrelated to tampering: `.gitattributes` (spec 034) declared `*.sql text eol=lf`, which normalizes every `.sql` file's line endings to LF on checkout. Several migration files were committed with CRLF before that rule existed and were never re-committed since, so their **working-tree bytes today (LF, via the attribute) differ from whatever bytes were hashed and recorded the first time they were actually applied** (CRLF, if that's what the working tree held back then). This is a real, concrete case the design below must not treat as a false alarm.

## Problem

- `MigrationRunner::markAsRun()` (`src/Database/MigrationRunner.php:102`) stores `md5($sql)` per applied migration, but nothing ever reads that `hash` column back for comparison. A migration file edited after being applied (accidentally, or by a bad merge — see the "Related but out of scope" note below for a real example already sitting in this repository) is silently invisible to `bin/migrate`.
- CI's fresh-install coverage (`.github/workflows/ci.yml:62-81`) is real but its step names ("Wait for MySQL and load base schema", "Run migrations") don't say that this is the fresh-install test the roadmap asks for.
- No test starts from an older, tagged schema/migration state and upgrades it to `HEAD` — every existing CI run and every `IntegrationTestCase`-based test (`tests/Integration/IntegrationTestCase.php:94-129`) builds the schema fresh, once per process, always at `HEAD`. Nothing proves that historical data survives the migrations added since `v1.7.1`.

## Goals

- `bin/migrate` refuses to apply pending migrations when an already-tracked migration file's current content no longer matches what was recorded at apply time, **without** treating line-ending-only differences as divergence.
- A deliberate, explicit, non-default way to re-baseline recorded hashes to current disk content, for the one legitimate case where files legitimately changed bytes (line-ending normalization) without changing meaning.
- CI's existing fresh-install coverage is named as such, in the workflow file itself, so it reads as an intentional test rather than an accident of step ordering.
- A new automated test builds the database as it existed at `v1.7.1`, inserts a representative historical row, runs the current `MigrationRunner` against it, and confirms both success and that the historical row survives unchanged.

## Non-goals

- No `down()`/rollback mechanism, migration reversal, or "undo" tooling — forward-only stays correct per the roadmap's own instruction.
- No fix for the pre-existing duplicate migration-number filenames already in `common/migrations/` (`009_dishes_default_menu.sql` next to `009_menu_item_position.sql`; `010_empanado_protein.sql` next to `010_default_main_dishes.sql` — see Current behavior). Renaming an already-applied migration file would make `MigrationRunner` treat it as a brand-new pending migration in any database where it's already tracked by filename — actively unsafe. Left as a separate, undecided follow-up (see Open questions).
- No change to `common/sql/001_schema.sql` itself, and no new schema/migration file for GastroFlow's own tables — this spec only changes how `MigrationRunner` verifies and tests itself.
- No disaster-recovery runbook or upgrade documentation page — that's `v1.9.0`'s "Documentation structure" / "Upgrade process" items. This spec only needs the mechanism to exist and be tested.
- Not investigating or fixing the two duplicate-numbered spec files found on `master` during this investigation (`specs/006-dishes-default-menu.md` colliding with `specs/006-project-baseline-doc-sync.md`; `specs/007-empanado-protein.md` colliding with `specs/007-cashier-kitchen-print-adjustments.md`) — unrelated to migration reliability, flagged separately to the user, not part of this spec.

## Current behavior

- `MigrationRunner::run()` (`src/Database/MigrationRunner.php:21-53`) builds the `migrations` control table if missing, computes pending files via `getPendingFiles()` (filters by filename already present in the `migrations` table, `strcmp`-sorted), executes each pending file's raw SQL via `Capsule::connection()->unprepared($sql)`, and calls `markAsRun($name, md5($sql))`. **Confirmed in code**: the `hash` column is written but never read back anywhere in this class or elsewhere in `src/`.
- `common/migrations/` currently holds 18 filenames (`001` through `018`), with two numbering collisions: `009_dishes_default_menu.sql` / `009_menu_item_position.sql`, and `010_empanado_protein.sql` / `010_default_main_dishes.sql`. Ordering between colliding pairs is decided by `strcmp` on the full filename (alphabetical after the shared numeric prefix), which happens to produce a safe order today but is fragile. Git history shows `009_dishes_default_menu.sql` and `010_empanado_protein.sql` were added post-`v1.7.1` by a single commit (`130450e`, message "migraations Xuxu", author `user <user@temp>`) that also added two oddly-numbered spec files colliding with existing spec numbers (see Non-goals) — this looks like content merged in from a different, parallel history that didn't know this repo's own numbering was already taken. Confirmed via `git show --stat 130450e` and `git ls-tree`.
- `.gitattributes` (spec 034) declares `* text=auto` plus `*.sql text eol=lf` among others. Confirmed via `diff -q <(git show v1.7.1:common/migrations/<file>) common/migrations/<file>` for every migration filename present at `v1.7.1`: **10 of 14** files (`005_dining_option.sql`, `006_settings.sql`, `007_jobs.sql`, `008_order_items_price.sql`, `009_menu_item_position.sql`, `011_user_roles.sql`, `012_order_number_integrity.sql`, `013_order_cancellation.sql`, `014_order_item_name_snapshot.sql`, `015_build_your_own_dish.sql`) differ between the `v1.7.1` tag and today's working tree — confirmed (spot-checked on `007_jobs.sql`) to be a pure CRLF→LF line-ending difference, not a content change. This means **any database that ran these migrations before spec 034 introduced `eol=lf`** has a recorded hash computed against CRLF bytes that will never again match the LF bytes `bin/migrate` reads from a checkout today. A hash check that doesn't account for this would treat every such real database as corrupted on its very first run after this spec ships.
- `tests/Integration/IntegrationTestCase.php:94-129` (`buildSchema()`) always builds the schema by running `common/sql/001_schema.sql` (once per process, guarded by a `categories` table check) followed by `(new MigrationRunner())->run()` against the **current** `common/migrations/` directory — there is no code path anywhere that builds an older, historical schema state.
- `.github/workflows/ci.yml:62-81`: loads `common/sql/001_schema.sql` into a freshly-created `restaurant` database, then runs `php bin/migrate` — this is a fresh-install run in substance, but neither step name nor any comment says so.
- No test file anywhere references `MigrationRunner` directly (confirmed: no `tests/Unit/*Migration*` and no `tests/Integration/*Migration*` file exists today).
- `docs/technical-decisions.md:9` already documents the forward-only, no-rollback decision; nothing there yet documents hash tracking or a normalization policy.

## Proposed behavior

1. **Hash comparison, normalized.** `MigrationRunner` computes and compares hashes over line-ending-normalized content (`\r\n` → `\n` before `md5()`), on both the write path (`markAsRun()`) and the new comparison path. This is a policy decision made once, here, rather than deferring to the still-open "LF/CRLF policy" Trello card — the two are related but this spec cannot wait for that one to close.
2. **Divergence check runs first.** Before computing or applying any pending file, `run()` iterates every row already in the `migrations` table whose filename still exists in `common/migrations/`, recomputes today's normalized hash, and compares it to the recorded one. If any tracked, still-present file diverges, `run()` throws a new `App\Database\MigrationHashMismatchException` listing every divergent filename (recorded vs. current hash, both truncated to 8 hex chars for readability) **before executing any pending migration in that invocation** — a partial run must not happen on top of an unverified history.
3. **Explicit, non-default reconciliation.** `bin/migrate --trust-current-hashes` runs a distinct path: for every tracked, still-present migration whose normalized hash differs from the recorded one, update the stored `hash` to match today's content, print what was reconciled, and exit `0` **without** running any pending migration in the same invocation (mirrors this repo's existing "explicit, loud, non-default" pattern for risky one-off operations, e.g. `bin/restore-db`'s confirmation gate — here inverted, since this operation *removes* a safety block rather than causing data loss, but still deserves to never be silent or automatic). A second, ordinary `php bin/migrate` run then proceeds normally.
4. **Fresh-install test, named.** `.github/workflows/ci.yml`'s existing schema-load and `bin/migrate` steps are renamed and commented to state plainly that they constitute the fresh-install test this roadmap item asks for — no new step, no new script.
5. **A real upgrade test.** A new Integration-suite test builds the database exactly as it stood at git tag `v1.7.1` (base schema + every migration file that existed at that tag, read via `git show v1.7.1:<path>` so the historical bytes are exact rather than assumed), inserts one representative historical row, then runs the **current** `MigrationRunner` (pointed at today's real `common/migrations/`) to bring that database to `HEAD`, and asserts both success and that the historical row is unchanged afterward.

## Functional requirements

1. Given a `migrations` table row for a file still present on disk, and that file's current (LF-normalized) content hash matches the recorded hash, `MigrationRunner::run()` behaves exactly as it does today (no regression).
2. Given the same setup but the file's line endings alone differ from what was originally hashed (CRLF ⇄ LF, no other byte change), `run()` does **not** report divergence and proceeds normally.
3. Given the same setup but the file's actual SQL content differs from what was recorded (a real edit), `run()` throws `MigrationHashMismatchException` naming the file, and **no pending migration in that invocation is executed** — verified by a pending migration in the same run not being reflected in the database afterward.
4. `php bin/migrate --trust-current-hashes` updates the recorded hash for every currently-divergent, still-present migration to match current content, prints each filename it reconciled, and exits `0`. A subsequent plain `php bin/migrate` then completes normally (no divergence reported).
5. `php bin/migrate` (no flag) against a database with no divergence and pending migrations available behaves exactly as today: applies them in deterministic (`strcmp`-on-filename) order and records their hash.
6. `.github/workflows/ci.yml`'s schema-load and migration steps have names/comments that explicitly identify them as the fresh-install test (grep-checkable: a step name or adjacent comment contains "fresh install" or "fresh-install").
7. A new automated test builds the `v1.7.1`-tagged schema/migration state, inserts one row into a table that existed at that tag (e.g. an `orders` row), runs the current `MigrationRunner` against it, and asserts: (a) the run completes without throwing; (b) the inserted row is still present with unchanged column values; (c) at least one table/column introduced by a post-`v1.7.1` migration exists afterward (proving the upgrade actually happened, not a no-op).
8. No `down()`, rollback, or migration-reversal method exists anywhere in `MigrationRunner` or `bin/migrate` after this change.

## Non-functional requirements

- No new Composer dependency, no Docker/Compose version bump — `git show` (already a host/CI-available binary) is the only new external call, and only from a test, never from `MigrationRunner`/`bin/migrate` themselves (production code must not shell out to `git`).
- The new Integration test must not affect or share state with the existing `restaurant_test` database used by `tests/Integration/IntegrationTestCase.php` subclasses — it needs its own dedicated database (see Architecture section) so building an old schema state in it can never corrupt the shared, always-at-`HEAD` schema the rest of the Integration suite depends on.
- `MigrationHashMismatchException` must extend `\RuntimeException` (or a suitable SPL exception), consistent with the rest of `src/` not defining a custom exception hierarchy beyond ad hoc `\RuntimeException` usage (confirmed pattern e.g. in `src/Routes.php:22`).

## User flows

- **Operator running `bin/migrate` normally** (no divergence): unchanged from today — pending migrations apply, output unchanged.
- **Operator running `bin/migrate` after this ships, against a database migrated before spec 034's line-ending normalization**: sees `MigrationHashMismatchException` naming the affected files, is told (via the exception message and/or `CLAUDE.md` documentation) to run `bin/migrate --trust-current-hashes` once, does so, sees the reconciled filenames printed, then re-runs `bin/migrate` normally.
- **Operator/developer discovering a genuinely edited already-applied migration file**: `bin/migrate` refuses to proceed, naming the file — the correct response is to restore the file to its originally-applied content (or, if the edit was intentional and safe, to knowingly run `--trust-current-hashes`, understanding that this accepts the new content as the new baseline without re-running it).
- **CI**: fresh-install and upgrade paths both run automatically on every push/PR, same as the rest of the suite.

## API changes

Not applicable — `MigrationRunner`/`bin/migrate` have no HTTP surface.

## Data model and migrations

Not applicable — no new table, column, or `common/migrations/*.sql` file. The existing `migrations` table's `hash` column (`src/Database/MigrationRunner.php:67`) is read for the first time but not altered.

## Architecture and affected components

- `src/Database/MigrationRunner.php` — add normalized hashing, the divergence check in `run()`, and a reconciliation path (either a `run(bool $trustCurrentHashes = false)` parameter or a separate public method — implementation's call, not a spec-level decision).
- New `src/Database/MigrationHashMismatchException.php` (or equivalent naming implementation prefers, as long as it's a dedicated, catchable type — not a bare `\RuntimeException`).
- `bin/migrate` — parse a `--trust-current-hashes` CLI flag and wire it to the reconciliation path.
- `.github/workflows/ci.yml` — rename/comment the existing fresh-install steps; add a new step creating a dedicated upgrade-test database (parallel to the existing "Create integration test database" step at line 88), and pass its name to the new test via environment variable.
- New `tests/Unit/MigrationRunnerTest.php` — SQLite in-memory (matching the existing Unit-suite pattern, e.g. `tests/Unit/JobServiceTest.php:83`), covering functional requirements 1-5 without needing a MySQL connection.
- New `tests/Integration/MigrationUpgradeTest.php` — MySQL-backed, its own dedicated database via a new env var (proposed name `MYSQL_DATABASE_TEST_UPGRADE`), following `IntegrationTestCase`'s skip-if-unset / fail-if-equal-to-a-real-database safety pattern but **not** extending `IntegrationTestCase` itself, since it deliberately builds an old schema state rather than the shared always-at-`HEAD` one.
- `CLAUDE.md` — extend the existing `bin/migrate` line under "Commands actually available" to mention `--trust-current-hashes`.
- `docs/technical-decisions.md` — add a row/note documenting the normalized-hash-comparison policy next to the existing forward-only decision (`docs/technical-decisions.md:9`).
- `docs/ROADMAP.md` — add a `Status (spec 048)` line under "Migration reliability" once implemented, per this project's own documented discipline.
- `CHANGELOG.md`, Trello card checklist — updated as the work actually lands, per `CLAUDE.md`'s changelog/board workflow (not part of this spec's own file list, but required by process).

## Security considerations

- Detecting post-apply edits to already-applied SQL is an integrity control appropriate for a single self-hosted install (not a multi-tenant trust boundary) — it protects against accidental corruption or a bad merge silently changing schema history, not against a malicious operator with filesystem access (who could edit the `hash` column directly; out of scope, consistent with this being a reliability feature, not a security boundary).
- No secrets are read, logged, or exposed by any part of this change.

## Backward compatibility

This is the one place this spec introduces a real, deliberate behavior change: **`bin/migrate` will refuse to proceed on its very next real run against any database that already applied one or more of the 10 migration files identified in Current behavior**, because their recorded hash was computed against pre-normalization (CRLF) bytes. This is expected to include this project's own development database. The fix is the documented one-time `bin/migrate --trust-current-hashes` step — this must be called out explicitly in the CHANGELOG (and, if `v1.9.0`'s upgrade docs exist by then, the upgrade documentation) as a required one-time action, not silently handled. See Open questions for confirming this is the intended default.

## Acceptance criteria

1. `tests/Unit/MigrationRunnerTest.php` passes, covering: normal apply; idempotent re-run with no changes; a CRLF-only change tolerated (no divergence reported); a genuine content change detected, raising `MigrationHashMismatchException` and leaving a same-run pending migration unapplied; `--trust-current-hashes`-equivalent reconciliation clearing a previously-detected divergence.
2. `php bin/migrate --trust-current-hashes` run against a real (e.g. local Docker) database with at least one deliberately mismatched recorded hash updates that hash and exits `0`; a following plain `php bin/migrate` then exits `0` with no divergence reported — both observed directly, not assumed.
3. `.github/workflows/ci.yml`'s fresh-install steps are named/commented as such (`grep -i "fresh.install" .github/workflows/ci.yml` finds a match).
4. `tests/Integration/MigrationUpgradeTest.php` passes in CI (dedicated database created, test not skipped) and fails/skips safely (matching the existing safety pattern) when its dedicated env var is unset.
5. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff` both pass clean against every new/changed file.
6. The full `vendor/bin/phpunit` suite (`Smoke,Unit,Integration`) passes with these additions included.
7. This repository's own development database (or the CI database, observed directly) is confirmed to require and successfully complete the one-time `--trust-current-hashes` reconciliation predicted in Backward compatibility — turning a predicted consequence into an observed one.

## Implementation plan

1. Add normalized-hash computation to `MigrationRunner` (`markAsRun()` and a new comparison path); add `MigrationHashMismatchException`.
2. Add the divergence check to the start of `run()`, before pending-file execution; add the `--trust-current-hashes` reconciliation path.
3. Wire `bin/migrate`'s argument parsing for `--trust-current-hashes`.
4. Write `tests/Unit/MigrationRunnerTest.php` covering functional requirements 1-5 (SQLite, no MySQL needed).
5. Rename/comment the fresh-install steps in `.github/workflows/ci.yml`.
6. Add the dedicated upgrade-test database creation step to `.github/workflows/ci.yml` and its env var.
7. Write `tests/Integration/MigrationUpgradeTest.php` (builds `v1.7.1` state via `git show`, seeds a historical row, runs current `MigrationRunner`, asserts survival + upgrade).
8. Run `bin/migrate --trust-current-hashes` against this project's own real (local Docker) database once, observe and record the output — this is the validation for acceptance criterion 7 and must happen for real, not be assumed.
9. Update `CLAUDE.md`, `docs/technical-decisions.md`, `docs/ROADMAP.md` (status line), `CHANGELOG.md`, and the Trello card, in the same PR.
10. `/spec-review`.

## Testing and validation strategy

This project has real automated test infrastructure today (contrary to older specs' boilerplate note that none exists): PHPUnit `Smoke`/`Unit`/`Integration` suites (`phpunit.xml`), PHPStan (level 5), PHP-CS-Fixer (PSR-12), and GitHub Actions CI running all of them in order (`.github/workflows/ci.yml`). This spec's own testing fits directly into that:

- **Unit** (`tests/Unit/MigrationRunnerTest.php`): SQLite in-memory Capsule connection (matching `tests/Unit/JobServiceTest.php`'s existing pattern) plus a temp directory of small, throwaway `.sql` fixture files — fast, no MySQL, exercises the hashing/divergence/reconciliation logic directly.
- **Integration** (`tests/Integration/MigrationUpgradeTest.php`): real MySQL, its own dedicated database, exercises the actual upgrade path against real historical migration bytes fetched via `git show v1.7.1:...`.
- **CI-only, structural**: the fresh-install step renaming (requirement 6) is verified by reading the workflow file, not by running anything new.
- **Manual, one-time**: running `bin/migrate --trust-current-hashes` against this project's real development database (acceptance criterion 7) — must be actually run and its output recorded during `/spec-implement`, per this project's standing rule against claiming untested things pass.

## Rollout and rollback

- Rollout: lands on `master` via the normal spec/PR workflow. The one operationally meaningful step is the required one-time `--trust-current-hashes` run against any already-migrated database (this project's own dev database, and CI's persistent state if any) — documented in the CHANGELOG entry for this spec, not hidden in a code comment.
- Rollback: reverting the PR restores today's behavior (no hash check at all) with no data-level side effect, since the reconciliation path only ever updates the `hash` column of the `migrations` table, never touches migrated schema/data.

## Open questions

- **Resolved (2026-09-27).** Was: is "refuse to proceed by default, with an explicit `--trust-current-hashes` opt-in to reconcile" the right default, given it is known to trip on this project's own real development database on the very next run? User approved proceeding with `/spec-implement` on this draft as-is (no auto-reconcile alternative requested) — treated as confirmation of the recommended default.
- **Non-blocking.** Exact CLI flag name/shape (`--trust-current-hashes` vs. something shorter) — implementation's call.
- **Non-blocking.** The duplicate migration-number filenames (`009`/`010` collisions, see Non-goals) are a real fragility in "deterministic order" but out of scope here since fixing them (renaming files) is itself unsafe for already-migrated databases. Worth its own follow-up card/spec once a safe migration-renumbering strategy (if any) is decided.
- **Non-blocking.** A tracked migration whose file was **deleted** (not edited) from `common/migrations/` is not detected by this design (only present-but-changed files are checked) — a related but distinct gap, left for a future iteration if wanted.

## Task checklist

- [x] Add normalized hashing + `MigrationHashMismatchException` to `MigrationRunner`
- [x] Add divergence check to `run()`
- [x] Add `--trust-current-hashes` reconciliation path
- [x] Wire `bin/migrate` CLI flag
- [x] `tests/Unit/MigrationRunnerTest.php`
- [x] Rename/comment fresh-install CI steps
- [x] Add dedicated upgrade-test database to CI
- [x] `tests/Integration/MigrationUpgradeTest.php`
- [x] Reconcile this project's real dev database with `--trust-current-hashes`, record output
- [x] Update `CLAUDE.md`, `docs/technical-decisions.md`, `docs/ROADMAP.md`, `CHANGELOG.md`, Trello card
- [x] `/spec-review`

## Implementation log

- `run(bool $trustCurrentHashes = false)`: chose a boolean parameter over a separate public method (Architecture section left this open) — keeps `bin/migrate`'s call site a one-liner and mirrors how the existing code already reads `$argv` before deciding what to do.
- `trackedFilesOnDisk()` is a generator yielding `path => ['name' => ..., 'hash' => ...]`, shared by both `assertNoHashDivergence()` and `reconcileHashes()` — avoids duplicating the "tracked row + file still exists" iteration logic between the two.
- **Real, predicted backward-incompatibility confirmed and resolved during implementation, not just anticipated in the draft**: running the full suite after adding the divergence check failed 51 tests — every `IntegrationTestCase`-based test — because the shared `restaurant_test` database's `migrations` table held hashes recorded (by an earlier, unnormalized `md5($sql)`) against line endings that no longer match today's `.gitattributes`-normalized checkout, for `005`, `006`, `007`, `008`, `009_menu_item_position`, `011`-`015`, plus `016_job_reliability.sql` (not one of the 10 files this spec's Context section identified against the `v1.7.1` tag diff — `restaurant_test`'s own history diverged from that comparison independently, same root cause). Ran `bin/migrate --trust-current-hashes` against `restaurant_test` (11 files reconciled) and separately against the real development database `restaurant` (2 files reconciled: `006_settings.sql`, `007_jobs.sql`) — both observed directly, see Validation evidence. This is exactly the scenario the spec's Backward compatibility section predicted; the blocking open question's resolution (explicit opt-in, no silent auto-reconcile) held.
- Reconciling the real `restaurant` database also surfaced that `009_dishes_default_menu.sql` and `010_empanado_protein.sql` (added post-`v1.7.1` by the anomalous `130450e` commit, see Non-goals) had never actually been applied there — a plain `bin/migrate` run afterward applied them for real. This is ordinary, expected `bin/migrate` behavior (applying pending migrations), not a side effect of this spec's changes, and is disclosed here only because it happened during this session's validation.
- `MigrationUpgradeTest` shells out to `git show`/`git ls-tree` and needed `-c safe.directory=*` scoped to each invocation (not a global git config change) — the repo is bind-mounted into the `web` container from a different-UID host, which git's dubious-ownership check otherwise refuses.
- `.github/workflows/ci.yml`'s checkout step needed `fetch-depth: 0` — the default shallow clone would not contain the `v1.7.1` tag's commit, which `MigrationUpgradeTest` reads via `git show`.
- The historical `orders` row inserted by `MigrationUpgradeTest` only sets columns confirmed present at `v1.7.1` (`order_number`, `business_date`, `status`, `created_at` — no `table_number`, no money/total column on `orders` itself, confirmed by reading `common/migrations/012_order_number_integrity.sql` and `008_order_items_price.sql` at that tag) rather than assuming a shape.
- `README.md`'s "In progress" list and "Next up" line still named "migration reliability" as future work — found during the docs pass (not listed in this spec's own Architecture section originally) and corrected in the same PR, per `CLAUDE.md`'s "the changelog pass and the docs pass are the same pass" rule.

## Validation evidence

All commands run inside the local Docker stack (`docker compose exec web ...`), 2026-09-27.

1. **Unit tests** (`tests/Unit/MigrationRunnerTest.php`):
   ```
   $ docker compose exec -T web vendor/bin/phpunit --testsuite Unit --filter MigrationRunnerTest
   .....                                                               5 / 5 (100%)
   OK (5 tests, 10 assertions)
   ```
   Covers: normal apply, idempotent re-run, CRLF-only change tolerated, genuine content change throws `MigrationHashMismatchException` before a same-run pending migration applies, `--trust-current-hashes` reconciles and unblocks the next run.

2. **`--trust-current-hashes` against a real database**, run twice — first showing the reconciliation, then a plain run proceeding cleanly:
   ```
   $ docker compose exec -T -e MYSQL_DATABASE=restaurant_test web php bin/migrate --trust-current-hashes
   ✔ Hash atualizado para: 005_dining_option.sql, 006_settings.sql, 007_jobs.sql, 008_order_items_price.sql,
     009_menu_item_position.sql, 011_user_roles.sql, 012_order_number_integrity.sql, 013_order_cancellation.sql,
     014_order_item_name_snapshot.sql, 015_build_your_own_dish.sql, 016_job_reliability.sql

   $ docker compose exec -T -e MYSQL_DATABASE=restaurant_test web php bin/migrate
   ▶ Executando 009_dishes_default_menu.sql ... [OK]
   ▶ Executando 010_empanado_protein.sql ... [OK]
   ✓ Concluído.
   ```
   (No divergence reported on the second run — confirms reconciliation cleared it.)

3. **CI fresh-install steps named explicitly**:
   ```
   $ grep -i "fresh install" .github/workflows/ci.yml
   - name: Fresh install - wait for MySQL and load base schema
   ...
   - name: Fresh install - run migrations
   ```

4. **`tests/Integration/MigrationUpgradeTest.php`**, run three times to confirm the safety pattern and the actual upgrade path:
   ```
   $ docker compose exec -T -e MYSQL_DATABASE_TEST=restaurant_test -e MYSQL_DATABASE_TEST_UPGRADE=restaurant_test_upgrade \
       web vendor/bin/phpunit --testsuite Integration --filter MigrationUpgradeTest
   .                                                                   1 / 1 (100%)
   OK (1 test, 5 assertions)

   # Re-run against the same, now non-empty database — confirms it rebuilds cleanly, not just once:
   OK (1 test, 5 assertions)

   # MYSQL_DATABASE_TEST_UPGRADE unset:
   S                                                                   1 / 1 (100%)
   OK, but some tests were skipped!  Tests: 1, Assertions: 0, Skipped: 1.

   # MYSQL_DATABASE_TEST_UPGRADE = MYSQL_DATABASE_TEST (collision):
   F  MYSQL_DATABASE_TEST_UPGRADE ("restaurant_test") collides with another real/shared database.
      Refusing to run: this test drops and rebuilds every table in it.
   ```
   The passing run's 5 assertions are: the historical order survives with unchanged `order_number`/`business_date`/`status`, and `audit_log` (a post-`v1.7.1`, spec 043 table) exists afterward — proving the upgrade reached `HEAD`, not a no-op. This run also exercised the real CRLF/LF divergence case end-to-end (see Implementation log) without a false positive.

5. **PHPStan, whole project**:
   ```
   $ docker compose exec -T web vendor/bin/phpstan analyse --no-progress
   [OK] No errors
   ```

6. **PHP-CS-Fixer, whole project**:
   ```
   $ docker compose exec -T web vendor/bin/php-cs-fixer fix --dry-run --diff
   Found 2 of 107 files that can be fixed
   ```
   Both flagged files (`bin/worker`, `bin/create-admin`) are pre-existing CRLF drift unrelated to this spec — confirmed via `git status --short` showing them as unmodified (git already treats their CRLF working-tree bytes as matching the LF-normalized blob). Zero new/changed files from this spec are flagged.

7. **Full suite**:
   ```
   $ docker compose exec -T -e MYSQL_DATABASE_TEST=restaurant_test -e MYSQL_DATABASE_TEST_UPGRADE=restaurant_test_upgrade \
       web vendor/bin/phpunit --testsuite Smoke,Unit,Integration
   OK (235 tests, 559 assertions)
   ```

8. **Backward-compatibility prediction, turned into an observed fact** (also acceptance criterion 7): both the shared `restaurant_test` database and this project's real development database (`restaurant`) required and successfully completed the one-time `--trust-current-hashes` reconciliation — see item 2 above for `restaurant_test`, and the Implementation log for the `restaurant` run (`006_settings.sql`, `007_jobs.sql` reconciled).

Not independently re-verified here: `composer validate --strict` / `composer audit` (unaffected by this change — no `composer.json` edit) and the browser/Playwright suite (unaffected — no frontend file touched). CI will still run both on the PR.
