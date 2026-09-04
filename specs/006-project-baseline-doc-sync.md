# Spec 006 — Project baseline documentation synchronization

## Metadata

- Status: Verified
- Created: 2026-08-24
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: 014

## Context

`docs/ROADMAP.md` was rewritten (uncommitted, on branch `014`) from a numbered-milestone format (`v2.0 — Foundation` with items `#1`–`#15`, then `v2.1`, `v2.2`, `v2.3`) to a narrative phase format (`v1.6.0` → `v1.7.0` → `v1.8.0` → `v1.9.0` → `v2.0.0`). This is the first sub-item of the new `v1.6.0 — Baseline & Security` phase, "Project baseline synchronization," which explicitly calls for reviewing and synchronizing `README.md`, `CLAUDE.md`, architecture documentation, technical decisions, API documentation, the roadmap, and the current project baseline spec.

The rewrite did not update any of the other repo files that cited the old numbering/milestones by name (`ROADMAP.md #8`, `#9`, `v2.0`, `v2.1`, etc.). Those citations are now dangling — they point at sections that no longer exist. Separately, and independent of the renumbering, `CLAUDE.md` and `specs/000-project-baseline.md` both still describe a hardcoded JWT-secret fallback in `src/Routes.php` that was already removed (spec 002, shipped in `CHANGELOG.md` `v1.5.6`).

## Problem

Two distinct but related problems, both making the documentation lie about the current state of the system:

1. **Dangling roadmap references.** Several files cite specific old-roadmap milestone names or item numbers that no longer exist in `docs/ROADMAP.md`:
   - `docs/architecture.md:56` — "tracked as `ROADMAP.md` #2 and #6"
   - `docs/architecture.md:60` — "`ROADMAP.md` #5 plans to replace it"
   - `docs/architecture.md:105` — "Hardcoded JWT-secret fallback at `src/Routes.php:19` (`ROADMAP.md` #9)."
   - `docs/architecture.md:106` — "CORS currently allows any origin (`ROADMAP.md` #8)."
   - `docs/architecture.md:107` — "No automated test suite, no lint/static-analysis tooling, no CI pipeline (`ROADMAP.md` #1, #15)."
   - `docs/technical-decisions.md:11` — "`ROADMAP.md` #5 plans to replace it"
   - `README.md:154` — "since `ROADMAP.md` #1/#15 landed"
   - `README.md:197` — "Foundation cleanup (`ROADMAP.md` v2.0)"
   - `README.md:198` — "Automated tests + CI (`ROADMAP.md` v2.1)"
   - `README.md:204` — "**Next up** (`ROADMAP.md` v2.2 — Architecture)"
   - `README.md:208` — "**Future ideas** (`ROADMAP.md` v2.3 — Frontend & Infra)"

   Three of these (`architecture.md:105`, `106`, `107`) are also factually stale independent of the renumbering — see point 2.

2. **Stale factual claims that no longer match the code**, confirmed by reading `src/Routes.php` and `src/Middleware/CorsMiddleware.php` on this branch:
   - `src/Routes.php:22` currently reads `$_ENV['JWT_SECRET'] ?? throw new \RuntimeException(...)` — there is **no hardcoded fallback**. This was fixed by spec 002 and shipped in `CHANGELOG.md` `v1.5.6` (2026-08-20), but `CLAUDE.md`'s Security rules section and `specs/000-project-baseline.md` (Current behavior → Authentication, and Security considerations) still assert the fallback exists.
   - `docs/architecture.md:106` says "CORS currently allows any origin" as an unqualified limitation. In reality `CorsMiddleware` reads `CORS_ALLOWED_ORIGIN` from `Settings` (`src/App.php:53`) and only defaults to `*` when unset — it's configurable (spec 001, shipped `v1.5.6`), just permissive by default. The current wording overstates the gap.
   - `docs/architecture.md:107` says "No automated test suite... no CI pipeline" — both now exist: `composer.json` has `phpunit/phpunit ^11` (spec 004) and a GitHub Actions workflow exists and is verified green (spec 005, recent commits `7af7660`, `4f84b78`). Only "no lint/static-analysis tooling" in that sentence remains true today.

## Goals

- Every reference to `docs/ROADMAP.md` milestones/items elsewhere in the repo either points at a section that actually exists in the rewritten roadmap, or is reworded to not depend on roadmap section names/numbers at all.
- `CLAUDE.md` and `specs/000-project-baseline.md` no longer claim the JWT hardcoded-fallback gap exists; both are corrected to reflect that it was fixed by spec 002.
- `docs/architecture.md`'s "Known architectural limitations" section accurately reflects which gaps are still open today (CORS default-permissive, no lint/static-analysis tooling) and which are closed (JWT fallback, no tests/CI).

## Non-goals

- Not doing any other `v1.6.0` sub-item from the roadmap (order terminology cleanup, `APP_ENV`, production error handling, Docker secret handling, DB bootstrap cleanup, administrator bootstrap, authentication hardening, RBAC, dependency reproducibility). Each is a separate future spec.
- Not rewriting `CHANGELOG.md`. Its entries (including the `v1.5.6` entry citing "`ROADMAP.md` v2.0/v2.1") are dated, historical records of what shipped at release time — per `docs/COMMIT_CONVENTION.md`, new entries are added at the top, existing ones are not retroactively edited.
- Not rewriting the `Context` sections of specs `001`–`005`, which cite old roadmap item numbers (`#8`, `#9`, `#10`, `#1`, `#15`) as the motivation that was true *when those specs were written and approved*. They are historical records of already-`Implemented`/`Verified` work, same reasoning as `CHANGELOG.md`.
- Not touching `specs/000-project-baseline.md`'s `Non-goals` section reference to "an untracked `ROADMAP.md`" — that describes the state of the repo when the baseline was written (2026-08-05) and is still accurate as history.
- Not introducing PHPStan/code-style tooling or fixing CORS's permissive default — those are the actual `v1.8.0`/future roadmap work, not a documentation-sync task.

## Current behavior

Confirmed by direct reads on branch `014`:

- `src/Routes.php:22` — `$_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET environment variable is not set.')`. No hardcoded secret string anywhere in the file.
- `src/App.php:53` — `$app->add(new Middleware\CorsMiddleware($this->settings->get('CORS_ALLOWED_ORIGIN', '*')));` — configurable, defaults to `*`.
- `composer.json:27-29` — `"require-dev": {"phpunit/phpunit": "^11.0"}`.
- `git log` — recent commits `7af7660` ("mark CI workflow verified (run #3 green)") and `4f84b78` ("add changelog for v1.5.6") confirm CI is live and the `v1.5.6` release, which included the JWT/CORS/tests/CI work, has already shipped.
- `docs/ROADMAP.md` (uncommitted rewrite) has no `#`-numbered items and no `v2.0`/`v2.1`/`v2.2`/`v2.3` section headers; its sections are `v1.6.0 — Baseline & Security`, `v1.7.0 — Domain & Architecture`, `v1.8.0 — Reliability & Quality`, `v1.9.0 — Community Productization`, `v2.0.0 Release Candidate` (the final release, not a milestone name for already-shipped work).
- The `v1.7.0 — Domain & Architecture` section's subsections "Controller responsibilities" and "Persistence boundaries" describe the same work as `README.md`'s current "Next up (`ROADMAP.md` v2.2 — Architecture)" bullet (split `AdminController`, service+repository for `Dish`/`Ingredient`).
- The `v1.9.0 — Community Productization` section's subsections "Local frontend dependencies" and "Shared frontend infrastructure" describe the same work as `README.md`'s current "Future ideas (`ROADMAP.md` v2.3 — Frontend & Infra)" bullet (bundling, build step, shared `common.js`).
- The `v1.8.0 — Reliability & Quality` section's subsections "Static analysis" and "Code style" describe the still-open "lint/static-analysis" gap; its "Realtime reliability" subsection describes the still-open SSE-replacement plan currently cited as `ROADMAP.md #5`.

## Proposed behavior

After this change:

- Reading any of the affected files and following a `ROADMAP.md` reference leads to a real section of the current roadmap, or the reference is removed in favor of plain prose that doesn't depend on roadmap structure.
- `CLAUDE.md`'s Security rules bullet about `src/Routes.php` accurately describes the current code (no fallback) while preserving its standing instruction (don't reintroduce hardcoded-secret fallbacks, don't silently "fix" security-relevant code outside a spec).
- `specs/000-project-baseline.md` is corrected in place with a dated note, not silently rewritten as if the original snapshot had always been accurate — its `Updated` field and a new `Implementation log` entry record the correction.
- `docs/architecture.md`'s "Known architectural limitations" list only contains gaps that are actually still open.

## Functional requirements

1. `docs/architecture.md:56` no longer cites `ROADMAP.md #2 and #6`; it cites the current roadmap's `v1.7.0 — Domain & Architecture` phase (or is reworded without a specific citation if no single subsection matches cleanly).
2. `docs/architecture.md:60` and `docs/technical-decisions.md:11` no longer cite `ROADMAP.md #5`; both cite `v1.8.0 — Reliability & Quality`'s "Realtime reliability" subsection.
3. `docs/architecture.md`'s "Known architectural limitations" section (lines 104-110) is rewritten so that:
   a. the JWT hardcoded-fallback bullet is removed or replaced with a note that it was fixed (spec 002, `v1.5.6`);
   b. the CORS bullet is reworded to state it defaults to `*` but is configurable via `CORS_ALLOWED_ORIGIN` (spec 001), not presented as an unqualified gap;
   c. the "no automated test suite... no CI pipeline" bullet is removed or replaced with a note that tests + CI exist (specs 004/005), keeping only the still-true "no lint/static-analysis tooling" claim, citing `v1.8.0`'s "Static analysis"/"Code style" subsections.
4. `README.md:154` no longer reads "`ROADMAP.md` #1/#15 landed"; it references the shipped tests/CI work without a dangling number (e.g. "since automated tests + CI landed, see `docs/ROADMAP.md`'s Current Baseline").
5. `README.md:197` and `README.md:198` no longer cite `ROADMAP.md v2.0`/`v2.1` as parenthetical version tags for already-shipped work (those version numbers no longer name a "Foundation"/"Tests & Quality" milestone in the rewritten roadmap — `v2.0.0` now names the final target release). The parenthetical citation is removed or replaced with a citation to `docs/ROADMAP.md`'s "Current Baseline — v1.5.6" section.
6. `README.md:204` ("Next up") cites `docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase instead of `v2.2`.
7. `README.md:208` ("Future ideas") cites `docs/ROADMAP.md`'s `v1.9.0 — Community Productization` phase instead of `v2.3`.
8. `CLAUDE.md`'s Security rules bullet about `src/Routes.php:19` is updated to state the hardcoded fallback was removed (spec 002; the check now lives at `src/Routes.php:22`), while keeping the standing rule against reintroducing hardcoded-secret fallbacks or silently "fixing" security-relevant code outside a spec.
9. `specs/000-project-baseline.md`'s Current behavior → Authentication paragraph and its Security considerations section are corrected to state the JWT fallback was removed (spec 002, `v1.5.6`), with the correction dated and logged rather than silently overwriting the original 2026-08-05 snapshot text.
10. No file in the repository (excluding `CHANGELOG.md` and `specs/001-005-*.md`, per Non-goals) contains the literal strings `ROADMAP.md #` or `ROADMAP.md` followed by `v2.0`, `v2.1`, `v2.2`, or `v2.3` after this change.

## Non-functional requirements

Not applicable — this is a documentation-only change with no runtime, performance, or security behavior change (the underlying JWT/CORS/tests fixes already shipped; this spec only corrects documentation about them).

## User flows

Not applicable — no user-facing behavior changes. This affects repository documentation read by developers (human or AI) working on GastroFlow.

## API changes

Not applicable — no API surface changes.

## Data model and migrations

Not applicable — no data model changes.

## Architecture and affected components

Documentation only: `CLAUDE.md`, `docs/architecture.md`, `docs/technical-decisions.md`, `README.md`, `specs/000-project-baseline.md`. No `src/`, `public/`, or `common/` files are touched.

## Security considerations

None beyond accuracy: correcting `CLAUDE.md` and `specs/000-project-baseline.md` to no longer claim an open JWT-secret vulnerability that was already fixed avoids a future reader (human or AI) wasting effort re-investigating or re-fixing a closed issue, or worse, distrusting the fix and reverting it.

## Backward compatibility

Not applicable — no code or data changes, no API consumers affected.

## Acceptance criteria

1. `grep -rn "ROADMAP.md #" .` (excluding `.git/`, `CHANGELOG.md`, and `specs/001-*.md` through `specs/005-*.md`) returns no matches.
2. `grep -rn "ROADMAP.md\` v2\.[0-3]\b" .` / equivalent search for `ROADMAP.md v2.0`, `v2.1`, `v2.2`, `v2.3` (excluding the same files) returns no matches.
3. `docs/architecture.md`'s "Known architectural limitations" section no longer asserts a hardcoded JWT fallback exists, and no longer asserts no test suite/CI pipeline exists.
4. `CLAUDE.md`'s Security rules section no longer asserts `src/Routes.php:19` currently has a hardcoded fallback.
5. `specs/000-project-baseline.md` has an `Implementation log` entry dated 2026-08-24 (or later) documenting the JWT-fallback correction, and its `Updated` metadata field reflects that date.
6. Every remaining `docs/ROADMAP.md` citation added or kept by this change points at a section heading that actually exists in the current `docs/ROADMAP.md` (verified by reading the target file after the edit).

## Implementation plan

1. Fix `docs/architecture.md`: lines 56, 60, and the three "Known architectural limitations" bullets (104-110).
2. Fix `docs/technical-decisions.md:11`.
3. Fix `README.md`: lines 154, 197, 198, 204, 208.
4. Fix `CLAUDE.md`'s Security rules bullet about `src/Routes.php:19`.
5. Fix `specs/000-project-baseline.md`: Current behavior → Authentication paragraph, Security considerations section, plus an `Implementation log` entry and `Updated` date bump.
6. Re-run the greps from Acceptance criteria 1-2 to confirm no dangling references remain outside the excluded historical files.
7. Manually re-read each edited section in context to confirm the new roadmap citations resolve to real section headings.

## Testing and validation strategy

No automated test infrastructure applies to documentation content — this project's test suite (`vendor/bin/phpunit`) covers PHP application code only. Validation is: the greps in Acceptance criteria 1-2, plus manual cross-checking that each new citation (e.g. "`v1.7.0 — Domain & Architecture`") is an actual heading present in `docs/ROADMAP.md` at implementation time.

## Rollout and rollback

Documentation-only change on branch `014`. No deployment, migration, or feature flag involved. Rollback is a normal `git revert` of the commit(s) if a correction turns out to be wrong.

## Open questions

- **Not blocking**: should `docs/ROADMAP.md` itself eventually be committed as part of this branch, or does that happen separately (e.g. together with a broader v1.6.0 effort)? This spec assumes the roadmap rewrite already present (uncommitted) on branch `014` is the reference version being synced against, regardless of when it's committed.
- **Not blocking**: `docs/architecture.md:56`'s "`ROADMAP.md` #2 and #6" doesn't map as cleanly to a single new subsection as the `#5` (SSE) reference does — item `#2`/`#6` under the old `v2.2 — Architecture` milestone covered "extend Repositories/Validators", which in the new roadmap is split across `v1.7.0`'s "Controller responsibilities" and "Persistence boundaries" subsections. The implementer should cite both, or reword the sentence to avoid over-precise section-matching if it reads awkwardly.

## Task checklist

- [x] `docs/architecture.md` lines 56, 60, 105-107 updated
- [x] `docs/technical-decisions.md` line 11 updated
- [x] `README.md` lines 154, 197, 198, 204, 208 updated
- [x] `CLAUDE.md` Security rules bullet updated
- [x] `specs/000-project-baseline.md` Authentication + Security considerations sections corrected, `Implementation log` + `Updated` date added
- [x] Greps re-run to confirm no dangling `ROADMAP.md #`/`v2.[0-3]` references remain outside excluded historical files
- [x] Each new citation manually confirmed against real `docs/ROADMAP.md` headings

## Implementation log

- 2026-09-03 — Found `docs/technical-decisions.md`'s "Open, not yet resolved" section already missing its two `ROADMAP.md #8`/`#9` bullets (JWT fallback, CORS) and `.claude/settings.json`/`CLAUDE.md`'s Release workflow section already carrying unrelated uncommitted edits, present in the working tree before this implementation pass started. Left the unrelated `.claude/settings.json` change untouched; kept the already-removed technical-decisions.md bullets since removing them serves this spec's own goal (no stale `ROADMAP.md #` citations) and re-added nothing that would reintroduce a stale claim.
- 2026-09-03 — Implemented functional requirements 1-9 as specified. For `docs/architecture.md`'s "Known architectural limitations" (requirement 3), reworded the CORS bullet to state the permissive-by-default gap accurately (per non-goals, not fixing the default itself, only its description) rather than removing it, since it's still genuinely open.
- 2026-09-03 — Deviation beyond the literal task list: also corrected `specs/000-project-baseline.md`'s Non-functional requirements sentence (line ~143), which repeated the same stale hardcoded-secret-fallback claim being fixed elsewhere in the same file, plus its adjacent "no automated tests" claim (also stale — specs 004/005). Not in the original Functional requirements list, but leaving it uncorrected while fixing the two adjacent sections would have left an internal contradiction in the same document.
- 2026-09-03 — Verified `src/Routes.php:22` directly before writing any correction: `$_ENV['JWT_SECRET'] ?? throw new \RuntimeException(...)`, confirming no hardcoded fallback exists, consistent with the spec's Current behavior section.

## Validation evidence

- Acceptance criterion 1 — `grep -rn "ROADMAP\.md\` #\|ROADMAP\.md #" . --include="*.md"` (excluding `.git/`, `CHANGELOG.md`, `specs/006-*.md`, `specs/001-*.md` through `specs/005-*.md`): **0 matches**.
- Acceptance criterion 2 — `grep -rnE "ROADMAP\.md.{0,3}v2\.[0-3]" . --include="*.md"` (same exclusions): **0 matches**.
- Acceptance criterion 3 — `docs/architecture.md`'s "Known architectural limitations" no longer asserts a hardcoded JWT fallback or a missing test suite/CI pipeline (confirmed by reading the edited section back); only the still-true "no lint/static-analysis tooling" and the reworded, accurate CORS-default note remain.
- Acceptance criterion 4 — `CLAUDE.md`'s Security rules bullet now reads "The hardcoded JWT-secret fallback formerly at `src/Routes.php:19` was removed by spec 002 (`v1.5.6`)..." — no longer asserts the fallback currently exists.
- Acceptance criterion 5 — `specs/000-project-baseline.md` has a new Implementation log entry dated 2026-09-03 documenting the JWT-fallback correction, and `Updated: 2026-09-03` in Metadata.
- Acceptance criterion 6 — Read `docs/ROADMAP.md`'s full heading list (`grep -n "^# \|^## " docs/ROADMAP.md`) after all edits; confirmed every new citation used (`v1.6.0 — Baseline & Security`, `v1.7.0 — Domain & Architecture`, its "Controller responsibilities"/"Persistence boundaries" subsections, `v1.8.0 — Reliability & Quality`, its "Static analysis"/"Code style"/"Realtime reliability" subsections, `v1.9.0 — Community Productization`, `Current Baseline — v1.5.6`) matches a real heading present in the file.
- No automated test infrastructure applies to documentation content (per this project's testing strategy) — no `vendor/bin/phpunit` run was relevant here.
