# Spec 049 — LF/CRLF line-ending policy for Windows clones

## Metadata

- Status: Implemented
- Created: 2026-09-27
- Updated: 2026-09-27
- Owner: Henry
- Related issue: Not applicable (no GitHub Issue filed; tracked via `docs/ROADMAP.md`'s v1.8.0 "Line endings (LF vs CRLF)" item and Trello card "[v1.8] LF / CRLF — política consistente no Windows", https://trello.com/c/TLOjN15T)
- Related branch: 049

## Context

`docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` milestone's last open item: `.gitattributes` (spec 034) declares `*.php text eol=lf` (and other extensions), but a Windows clone still ends up with CRLF in practice, `checkout`/`pull`/branch switching get blocked by changes with no real content difference, and at least "two blobs" were found committed with CRLF while siblings were LF.

Spec 048 (Migration reliability, merged just before this one) hit a concrete consequence of this: 10 migration files' working-tree bytes differed from their `v1.7.1`-tagged bytes by line endings alone, which `MigrationRunner`'s new hash check had to specifically not treat as tampering. That investigation assumed the stored *blobs* had been CRLF and were somehow fixed later. **This spec's own investigation (below) found that assumption wrong**: the actual mechanism is broader and different from what both the roadmap and spec 048 assumed — see Problem.

## Problem

A full repo audit (`git -c core.autocrlf=false ls-files --eol`, 255 tracked files excluding `tests/e2e/node_modules`) on this session's real Windows checkout found **two distinct, unrelated problems**, both real:

**A. 8 files genuinely committed with CRLF blobs** (confirmed via `git show HEAD:<path> | grep -cU $'\r'`, independent of any local checkout state):
`.dockerignore`, `Dockerfile`, `common/config.php`, `common/db.php`, `common/sql/001_schema.sql`, `public/.htaccess`, `public/cashier/app.js`, `public/index.php` — plus `.gitignore`, which has **mixed** CRLF/LF within the same blob. All predate spec 034's `.gitattributes` and were never re-committed since, so the `eol=lf` clean filter (four of the eight already have `eol=lf` set) never got a chance to normalize them — attributes only affect files touched by `git add` *after* the attribute exists, never retroactively.

**B. 66 files whose working-tree copy is CRLF right now on this machine, despite an already-correct LF blob** — including files with an explicit `eol=lf` attribute (e.g. `common/sql/001_schema.sql` itself has both problems: CRLF blob *and*, after that's fixed, would still need a correct checkout). Confirmed concretely and reproducibly: `src/Database/MigrationRunner.php` — a file freshly written by an editor this same session — is correctly LF on disk, while `bin/migrate`, committed with an LF blob and never touched since spec 048's own edits, is CRLF on disk right now (`file bin/migrate` → "with CRLF line terminators"; `git diff --stat bin/migrate` → empty, git considers it unmodified). This is **not a repository defect** — nothing to commit — it's a *checkout*-time artifact: this repo's local `core.autocrlf` is currently `false` (correct), but the affected files were materialized onto disk at an earlier point (this machine's global/system git config reports `core.autocrlf=true`, the Git-for-Windows installer default) and nothing has re-checked them out since. `.gitattributes`'s `eol=lf` does not retroactively fix a file already on disk; only a fresh checkout (or explicit re-normalize) does.

**C. No CI check exists** (confirmed: `grep -in "crlf\|eol\|line.ending\|autocrlf" .github/workflows/ci.yml` → no match) to stop a future CRLF blob from being committed again — CI's own checkout is Linux, where this class of bug is invisible.

**Not a problem, ruled out during investigation:** the "duplicate migration numbering" and "specs/006, specs/007 numbering collision" issues flagged during spec 048 are unrelated to line endings — not touched here (see Non-goals).

## Goals

- `.gitattributes` alone governs line endings — no policy that "only works when each developer configures their machine correctly" (the roadmap's own standard).
- Every currently-ungoverned tracked text path gets an explicit `eol=lf` rule (closing the gap that let problem A happen in the first place).
- The 8 CRLF blobs + 1 mixed blob (problem A) are fixed for real, in a dedicated commit containing only line-ending changes.
- A CI check fails the build if a tracked text file's committed blob has CRLF/mixed line endings, so problem A cannot recur unnoticed.
- Documented, concrete setup guidance for a Windows clone (recommended `core.autocrlf` value + what to do if a working tree is already affected by problem B).

## Non-goals

- Not fixing problem B on every Windows contributor's machine — that's inherently per-machine and can't be done by a repository change. This spec documents the remediation command and, since this session's own machine is demonstrably affected, applies it here as a real validation of that documentation — but cannot apply it anywhere else.
- Not adding `.editorconfig` — not asked for by the roadmap item or the Trello card; a separate, independent tool from `.gitattributes` with its own scope.
- Not adding a `.git-blame-ignore-revs` file to hide the renormalization commit from `git blame` — a reasonable convention, but unrequested; left as a follow-up if wanted.
- Not touching the duplicate migration-number filenames or the `specs/006`/`specs/007` numbering collision (spec 048's Non-goals already excluded these; unrelated to line endings).
- Not touching binary files (`*.png`, `*.ico`, etc.) — already correctly left alone by git's own binary detection under `* text=auto`; confirmed none of the 8 CRLF-blob files or the 66 working-tree-only files are binary.

## Current behavior

See Problem — every claim there is confirmed in this session against the real repository, not assumed. Additional confirmed facts:
- `.git/config` (this clone, unversioned) already has `core.autocrlf = false` at the local scope, overriding this machine's system-wide `core.autocrlf = true`. This local override does nothing for a different developer's machine or a fresh clone.
- `.github/workflows/ci.yml`'s `actions/checkout@v4` step already has `fetch-depth: 0` (added by spec 048, for an unrelated reason — reading the `v1.7.1` tag). Not relevant to this spec's own CI check, which only needs the current tree.
- `CONTRIBUTING.md` does not exist yet (planned for `v1.9.0`'s "Open-source readiness"). `docs/` has no installation page yet either (`v1.9.0`'s "Documentation structure"). The precedent for "where does a short, concrete setup note belong today" is `README.md`'s `## Getting started` section (spec 045 added "Backup & restore" there the same way).

## Proposed behavior

1. Extend `.gitattributes` with explicit `eol=lf` rules for every currently-ungoverned text path found in the audit: `*.css`, `*.js`, `*.html`, `*.yaml`, `*.ini`, `*.webmanifest`, `*.sh` by extension; `.gitattributes`, `.gitignore`, `.dockerignore`, `.env.example`, `Dockerfile`, `composer.lock`, `phpunit.xml`, `public/.htaccess` and the six extensionless `bin/*` scripts by explicit path.
2. Run `git add --renormalize .` (with this clone's already-correct `core.autocrlf=false`) and commit only what changes — expected to be exactly the 8 CRLF + 1 mixed files from problem A, now normalized to LF, plus possibly newly-covered paths from step 1 that also happen to have a stale CRLF blob. Review the diff before committing to confirm it is line-ending-only (no content change) for every touched file.
3. Add a CI step (`.github/workflows/ci.yml`) that runs `git -c core.autocrlf=false ls-files --eol` and fails if any tracked file's index/blob column reports `crlf` or `mixed` — placed early (alongside the existing fast, DB-independent checks), no new dependency.
4. Add a `### Line endings (Windows)` subsection under `README.md`'s `## Getting started`, documenting: `.gitattributes` is authoritative; a Windows clone should also set `git config core.autocrlf false` (repo-local or global) so checkout doesn't fight the attribute; and the remediation for a working tree already affected by problem B (`git rm --cached -r . && git reset --hard`, after setting `core.autocrlf=false`) — explicitly labeled as discarding no real work (only re-materializing already-tracked, already-unmodified-per-git files) but still a `reset --hard`, so stated plainly as such.
5. As a real validation of step 4's documented remediation (not just written guidance), apply it to this session's own affected working tree — **only after explicit confirmation**, since it is a `git reset --hard` (see Open questions).

## Functional requirements

1. After step 1, `git -c core.autocrlf=false ls-files --eol` reports `attr/text eol=lf` (never bare `attr/text=auto`) for every one of the specific paths listed in Proposed behavior's step 1.
2. After step 2, `git -c core.autocrlf=false ls-files --eol | grep -cE '^i/(crlf|mixed)'` returns `0`.
3. Every file touched by step 2 differs from its pre-renormalization content **only** in line endings — verified via `git diff --ignore-space-at-eol --stat` (or equivalent) showing no remaining semantic diff, i.e. a normalized comparison shows no content change.
4. A CI run against a deliberately reintroduced CRLF blob (tested locally by staging one, not by breaking real CI) fails the new step with a message naming the offending file(s).
5. A CI run against the repository's real, fixed state (after steps 1-2) passes the new step.
6. `README.md` contains a `Line endings (Windows)` subsection naming the recommended `core.autocrlf` value and the remediation command for an already-affected working tree.
7. This session's own working tree, independently re-checked after applying the documented remediation, shows `git -c core.autocrlf=false ls-files --eol | grep -cE 'w/(crlf|mixed)'` returning `0` (no working-tree-only CRLF remaining either) — the one machine this spec can actually prove the fix on.

## Non-functional requirements

- No new dependency, no Composer/Docker version bump — the CI check is pure `git`.
- The renormalization commit (step 2) must contain **no** content/logic change — enforced by manual diff review before committing, not just assumed.
- Everything here is idempotent: re-running the CI check or `git add --renormalize .` again after this spec lands must be a no-op.

## User flows

- **New Windows contributor**: clones the repo, reads `README.md`'s new "Line endings (Windows)" note, runs the one recommended `git config` command, never hits the "permanently modified with no real diff" problem the roadmap describes.
- **Existing Windows contributor with an already-affected working tree** (this session's own machine, concretely): follows the same README note's remediation command to fix it.
- **Any contributor accidentally committing a CRLF file** (e.g. from an editor that ignores `.gitattributes`): CI's new step fails the PR, naming the file, instead of the blob silently entering history the way problem A's 9 files did.

## API changes

Not applicable — no HTTP surface touched.

## Data model and migrations

Not applicable — no schema, table, or `common/migrations/*.sql` file changes. (The 5 `.sql` migration files touched by the working-tree-only problem B are not modified in content; only this machine's on-disk copy is re-materialized, which is not a repository change at all.)

## Architecture and affected components

- `.gitattributes` — extended per Proposed behavior step 1.
- `.dockerignore`, `Dockerfile`, `common/config.php`, `common/db.php`, `common/sql/001_schema.sql`, `public/.htaccess`, `public/cashier/app.js`, `public/index.php`, `.gitignore` — re-normalized to LF (content unchanged), via the dedicated commit from step 2.
- `.github/workflows/ci.yml` — one new step.
- `README.md` — one new subsection under "Getting started", following the "Backup & restore" precedent (spec 045).
- No `src/`, `common/migrations/`, or test file changes — this spec is infrastructure/repo-hygiene only.

## Security considerations

Not applicable — no auth, input validation, or secret-handling surface touched. The CI check only reads tracked files' line-ending metadata; it does not execute file content.

## Backward compatibility

Purely additive for CI and documentation. The renormalization commit changes 9 files' bytes (line endings only, confirmed no content change) — any local branch with uncommitted changes to those specific 9 files, rebased onto this commit, would see a merge/rebase conflict purely on whitespace; none is known to exist (checked: no open local branches touch these files' content beyond what's already on `master`).

## Acceptance criteria

1. `git -c core.autocrlf=false ls-files --eol | grep -E '^i/(crlf|mixed)'` returns no output (exit status reflects zero matches) after this spec's changes land.
2. `git -c core.autocrlf=false ls-files --eol` shows `attr/text eol=lf` for every path named in Proposed behavior's step 1 (spot-checked, not just asserted).
3. The renormalization commit's diff, inspected directly, shows only line-ending changes for its 9 files — no line has different non-whitespace content before/after.
4. `.github/workflows/ci.yml`'s new step is present and, run locally against the fixed repository state, exits `0`.
5. The same CI check, run locally against a deliberately CRLF-reintroduced copy of one file (a throwaway local test, not a real commit), exits non-zero and names that file.
6. `README.md` has the new subsection with the exact recommended command(s).
7. On this session's own machine, `git -c core.autocrlf=false ls-files --eol | grep -cE 'w/(crlf|mixed)'` is `0` after applying the documented local remediation (only if explicitly confirmed — see Open questions; if not confirmed, this criterion is explicitly reported as not executed, not silently skipped).
8. `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff` both still pass clean (confirming the renormalization didn't break anything CS-Fixer independently also cares about).

## Implementation plan

1. Extend `.gitattributes` with the full path/extension list from Proposed behavior step 1.
2. Run `git add --renormalize .`, inspect the diff (`git diff --cached`), confirm it is exactly the 9 expected files with only line-ending changes, commit as a dedicated, content-free commit.
3. Add the CI line-ending check step to `.github/workflows/ci.yml`.
4. Add the `README.md` "Line endings (Windows)" subsection.
5. Ask the user to confirm before running the local working-tree remediation (`git rm --cached -r . && git reset --hard`) on this session's own machine — a `git reset --hard` needs explicit confirmation per this project's own operating rules, regardless of the assessed low risk. If confirmed, run it and record the before/after `ls-files --eol` counts as evidence for acceptance criterion 7. If not confirmed, mark that criterion as not executed in Validation evidence, not silently passed.
6. Run `vendor/bin/phpstan analyse` and `vendor/bin/php-cs-fixer fix --dry-run --diff`.
7. Update `CLAUDE.md` (nothing currently documents a line-ending policy — add one line), `docs/ROADMAP.md` (status line under "Line endings (LF vs CRLF)" — this closes the `v1.8.0` milestone), `docs/technical-decisions.md` (if warranted), `CHANGELOG.md`, Trello card.
8. `/spec-review`.

## Testing and validation strategy

This project has real automated test infrastructure (PHPUnit, PHPStan, PHP-CS-Fixer, GitHub Actions CI) but this spec's own subject (git line-ending policy) isn't something PHPUnit tests — validation is:
- **Direct git inspection** (`git ls-files --eol`, `git diff`) — objective, scriptable, exactly what the acceptance criteria check.
- **CI step logic tested locally** before trusting it in CI: simulate a bad file locally (e.g. write a temp file with `\r\n`, `git add -f`/stage it in a throwaway way, run the exact check command, confirm it fails; then remove the temp file) rather than deliberately breaking real CI.
- **PHPStan/PHP-CS-Fixer** re-run to confirm the renormalization didn't introduce a style regression (it shouldn't, since PHP-CS-Fixer's own `line_ending` rule already wants LF).
- **The one thing only this specific machine can validate**: whether the documented Windows remediation actually fixes an affected working tree. This session's own machine is a real, currently-affected instance — using it as validation evidence is legitimate and exactly what "don't claim untested things pass" calls for, contingent on the explicit confirmation gate in Implementation plan step 5.

## Rollout and rollback

- Rollout: normal spec/PR workflow. The renormalization commit is the only consequential part — reviewable in isolation since it's a dedicated commit with no other changes mixed in.
- Rollback: reverting the `.gitattributes`/CI-check commits is trivial (no data, no schema). Reverting the renormalization commit would reintroduce the 9 files' CRLF/mixed blobs — there's no reason to want that, but it's a plain `git revert` if ever needed.

## Open questions

- **Resolved (2026-09-27).** User confirmed explicitly (`AskUserQuestion`): yes, run the local working-tree remediation on this machine as real validation for acceptance criterion 7.
- **Non-blocking.** Whether to also add a `.git-blame-ignore-revs` file pointing at the renormalization commit (a common, low-effort convention for exactly this situation) — not requested, left as a follow-up.
- **Non-blocking.** `.claude/hooks/security-guard.sh` and other `.claude/` files were found with the same working-tree-only CRLF pattern (problem B) — included in the general fix (`*.sh text eol=lf` added), no special handling needed.

## Task checklist

- [x] Extend `.gitattributes`
- [x] Renormalization commit (9 files, line-endings only)
- [x] CI line-ending check step
- [x] `README.md` "Line endings (Windows)" subsection
- [ ] Local working-tree remediation on this machine — **blocked**: user confirmed via `AskUserQuestion`, but this repo's own AI-safety hook refuses `git rm --cached -r .` / `git reset --hard` outright (`PreToolUse:Bash hook error: Blocked by GastroFlow AI safety policy: destructive operation`), regardless of in-session confirmation. Not bypassed (no `--no-verify`, no hook-disabling). Left for the user to run manually if wanted — command is documented in `README.md`'s new subsection.
- [x] PHPStan + PHP-CS-Fixer re-run (PHPStan clean; PHP-CS-Fixer reports 3 pre-existing, unrelated working-tree-only files — see Implementation log)
- [x] Update `CLAUDE.md`, `docs/ROADMAP.md`, `docs/technical-decisions.md`, `CHANGELOG.md`, Trello card
- [ ] `/spec-review`

## Implementation log

- Split the work into two dedicated commits on purpose (the spec's Proposed behavior described them as one "step 1 / step 2" sequence, but committing separately keeps the `.gitattributes` content change and the pure-whitespace renormalization independently reviewable — a `git blame` on either commit stays meaningful): `c30c806` (`.gitattributes` extension) and `021de2c` (the 9-file renormalization, 792 insertions/792 deletions — exactly symmetric, confirming no net content change).
- **Root cause corrected mid-investigation.** The roadmap item and spec 048 both assumed the historical CRLF came from committed *blobs* that got fixed by spec 034's `.gitattributes`. Direct investigation (`git show HEAD:<path> | grep -cU $'\r'`) found this only true for 8 files (+1 mixed) — genuinely committed before spec 034 and never re-touched since. The much larger, separate phenomenon (66-75 files depending on how many paths `.gitattributes` covers) is this machine's **working tree** being CRLF right now despite an already-correct LF blob — confirmed concretely on `bin/migrate`, a file edited during spec 048's own session, still CRLF on disk (`file bin/migrate` → "with CRLF line terminators") while `git diff` shows it as unmodified. No amount of committing fixes a working-tree-only artifact; only a fresh checkout does. This is reflected in the spec's two-part Problem section already, but is called out here because it changed the implementation from "just fix some blobs" to "fix blobs *and* explain/attempt the separate working-tree remediation."
- **CI check design verified with a real test, not assumed.** A naive test (committing a CRLF file normally) failed to reproduce the bug: `git add`'s own clean filter converts CRLF→LF at add-time whenever `.gitattributes` already has the rule, so a normal `git add`+`commit` can never produce a CRLF blob once the attribute exists — exactly why the 9 real files only got their CRLF blobs from being committed *before* the rule existed. To actually test the CI check, a CRLF blob was forced directly into a throwaway repo's index via `git hash-object -w --stdin` + `git update-index --cacheinfo` (bypassing the clean filter entirely, matching how a real bad blob could enter history — e.g. a merge, a binary patch, or exactly what happened historically here). The check correctly flagged it.
- **Local remediation blocked by this repo's own AI-safety hook.** Step 5 (`git rm --cached -r . && git reset --hard`) was explicitly approved by the user via `AskUserQuestion` beforehand. Both `git status` (clean) and the command's own safety (no tracked, uncommitted work exists to lose) were confirmed first. The command was still refused outright: `PreToolUse:Bash hook error: Blocked by GastroFlow AI safety policy: destructive operation`. Not bypassed (no `--no-verify`, no disabling the hook) — this project's own rules treat hook feedback as authoritative and something to work within, not around. Left as a manual step for the user, documented in `README.md`.
- **Direct, disclosed consequence of the above**: `vendor/bin/php-cs-fixer fix --dry-run --diff` still reports `bin/migrate`, `bin/worker`, `bin/create-admin` needing a fix — all three confirmed via `git status --short` to be unmodified per git (pre-existing working-tree artifacts, not something this spec's own diff touched or introduced). Only the blocked remediation would clear these.
- `docs/ROADMAP.md`'s v1.8.0 milestone intro note was **not** changed to declare the milestone's exit gate met — verifying every other subsection's roadmap `Status` line reflects real, current completion is outside this spec's own scope (it's specifically about line endings), and several v1.8.0 subsections were observed during spec 048's own investigation to lack an explicit `Status` line despite the corresponding work clearly having shipped. Flagged to the user directly instead of silently declared.

## Validation evidence

All commands run directly against this repository/machine, 2026-09-27.

1. **No CRLF/mixed blobs remain**:
   ```
   $ git -c core.autocrlf=false ls-files --eol | grep -cE '^i/(crlf|mixed)'
   0
   ```
   **Met.**

2. **Every named path now has an explicit `eol=lf` attribute** (spot-checked; the full audit — no remaining `attr/text=auto` outside binaries — is criterion 1's own basis):
   ```
   $ git -c core.autocrlf=false ls-files --eol | grep 'attr/text=auto' | awk -F'\t' '{print $2}' | sort
   public/android-chrome-192x192.png
   public/android-chrome-512x512.png
   public/apple-touch-icon.png
   public/assets/img/logo.png
   public/assets/img/tela_admin.png
   public/assets/img/tela_cashier.png
   public/assets/img/tela_kitchen.png
   public/assets/img/tela_relatorio.png
   public/favicon-16x16.png
   public/favicon-32x32.png
   public/favicon-48x48.png
   public/favicon-64x64.png
   public/favicon.ico
   ```
   Only binaries remain uncovered — correct (they should never get an `eol` rule). **Met.**

3. **Renormalization commit is line-ending-only**: for each of the 9 files, `git diff --cached --ignore-space-at-eol -- <file>` produced 0 lines of output before committing. Commit `021de2c` itself: `9 files changed, 792 insertions(+), 792 deletions(-)` — exactly symmetric. **Met.**

4. **CI check passes against the real, fixed repository**:
   ```
   $ bad=$(git -c core.autocrlf=false ls-files --eol | grep -E '^i/(crlf|mixed)' || true); [ -z "$bad" ] && echo PASS
   No CRLF/mixed line endings found in tracked files.
   PASS
   ```
   **Met** (evidence is the check's own logic run directly — the real GitHub Actions run happens once this lands in a PR; not independently re-verified there yet).

5. **CI check fails against a genuinely CRLF blob**: a throwaway repo (outside this project) had a CRLF blob forced directly into its index via `git hash-object -w --stdin` + `git update-index --cacheinfo` (bypassing the clean filter — a plain `git add` of a CRLF file does *not* reproduce the bug once `.gitattributes` has the rule, since the clean filter converts at add-time; this only reproduces the original, pre-attribute-era bug). Result: `i/crlf ... forced-crlf.txt`, and the exact check command printed it and would `exit 1`. **Met.**

6. **`README.md` subsection**: present, titled "Line endings (Windows)", under "Getting started", with `git config core.autocrlf false` and the `git rm --cached -r . && git reset --hard` remediation. **Met.**

7. **This machine's own working tree, post-remediation**: **Not executed.** User approved via `AskUserQuestion`, but the repository's own AI-safety hook refused `git rm --cached -r .`/`git reset --hard` outright (`Blocked by GastroFlow AI safety policy: destructive operation`), regardless of the in-session confirmation. Before-state is recorded for whoever runs it manually:
   ```
   $ git -c core.autocrlf=false ls-files --eol | grep -vE 'tests/e2e/(node_modules|playwright-report|test-results)/' | grep -cE 'w/(crlf|mixed)'
   75
   ```
   **Not met** — explicitly not silently marked as passing.

8. **PHPStan and PHP-CS-Fixer**:
   ```
   $ docker compose exec -T web vendor/bin/phpstan analyse --no-progress
   [OK] No errors
   ```
   ```
   $ docker compose exec -T web vendor/bin/php-cs-fixer fix --dry-run --diff
   Found 3 of 107 files that can be fixed
   ```
   PHPStan: **Met.** PHP-CS-Fixer: **Not fully met** — `bin/migrate`, `bin/worker`, `bin/create-admin` are flagged, all three confirmed via `git status --short bin/migrate bin/worker bin/create-admin` (empty output) to be pre-existing working-tree-only artifacts unrelated to this spec's diff, and exactly what criterion 7's blocked remediation would fix. Not a regression introduced here.

**Overall**: 6 of 8 acceptance criteria fully met with direct evidence; 2 (7 and 8's PHP-CS-Fixer half) are explicitly blocked by this repository's own safety hook, not by any gap in the implementation itself, and are reported here rather than hidden.
