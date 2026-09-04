# Spec 017 — Dependency reproducibility

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Dependency reproducibility" subsection, requires: version `composer.lock`; run `composer validate --strict`; run `composer audit`; ensure development, CI, and Docker use the same dependency graph.

Investigation found the core problem immediately: **`composer.lock` is `.gitignore`'d and was never tracked in git** (confirmed independently by `specs/000-project-baseline.md`'s original baseline investigation, and re-confirmed now: `git ls-files composer.lock` returns nothing; `.gitignore:55` lists `composer.lock`). This is not a cosmetic gap — it means dependency versions are not actually reproducible across environments today, only coincidentally consistent on this one developer's machine.

## Problem

Confirmed by direct reads and real command execution:

1. `.gitignore:55` excludes `composer.lock`. A genuinely fresh `git clone` of this repository has **no `composer.lock` at all**. `Dockerfile:30`'s `COPY composer.json composer.lock* ./` uses a wildcard specifically because the file isn't guaranteed to exist in the build context from a fresh checkout.
2. Because Docker's build context is the local filesystem (not git-tracked state), this developer's own local, untracked `composer.lock` happens to reach `docker compose build` today — which is why local Docker builds have, by coincidence, matched local dev's installed versions so far. This does **not** hold for: a fresh clone by anyone else, a CI runner (which always starts from a clean `actions/checkout@v4`, confirmed in `.github/workflows/ci.yml`), or this developer's own machine if the untracked file is ever deleted.
3. `.github/workflows/ci.yml:46` runs `composer install --no-interaction --prefer-dist` with no lock file ever available to it (never committed) — **CI has been resolving its own independent dependency graph on every run**, silently, with no guarantee it matches what's actually running in this developer's local containers. This is a real, currently-live reproducibility gap, not a hypothetical one.
4. `composer validate --strict` (run for real against the live container) **fails**, exit code `1`:
   ```
   ./composer.json is valid, but with a few warnings
   # General warnings
   - No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.
   ```
   `composer.json` has no `license` field at all (confirmed by reading the file).
5. `composer audit` (run for real) **passes clean**: `No security vulnerability advisories found.`
6. No top-level `LICENSE` file exists in the repository (confirmed: `Glob LICENSE*` matches only files under `vendor/`, none at the repo root) — the project has no declared license yet at all, at any level.
7. `Dockerfile:33`'s `composer install --no-dev --no-interaction --optimize-autoloader --no-scripts` and CI's `composer install --no-interaction --prefer-dist` (no `--no-dev`) intentionally differ in one respect: production excludes dev dependencies (`phpunit/phpunit`), CI/local dev include them (needed to run the test suite). This is a correct, deliberate asymmetry, not a reproducibility bug — it's about which *set* of packages gets installed, not which *versions* of the shared required packages get resolved. Once a lock file is tracked, both commands resolve identical versions for every package they do install.

## Goals

- `composer.lock` is tracked in git, so every environment (local dev, CI, Docker build) installs from the exact same resolved dependency graph rather than each re-resolving independently against `composer.json`'s version constraints.
- `composer validate --strict` exits `0`.
- `composer audit` is confirmed clean as of this spec (already true — no code change needed for this specific check, just verification).

## Non-goals

- **Not choosing the project's actual open-source/proprietary license.** `docs/ROADMAP.md`'s `v1.9.0 — Community Productization` phase, "Open-source readiness" subsection, explicitly lists "choose an explicit license" as its own, later, more consequential decision (real license text, `LICENSE` file, `CONTRIBUTING.md`, etc.). This spec sets `composer.json`'s `license` field to `"proprietary"` — the exact placeholder Composer's own warning suggests for closed-source software not yet ready to declare an open-source license — purely to satisfy `composer validate --strict` now, without pre-empting that later decision. This is a narrow, technical placeholder, not the project's real licensing decision.
- **Not adding `composer audit`/`composer validate` as a new permanent CI pipeline step.** `docs/ROADMAP.md`'s `v1.8.0 — Reliability & Quality` phase, "CI quality pipeline" subsection, already plans a `composer validate → dependency/security audit → static analysis → code style → tests` pipeline as its own, later work. This spec only performs the one-time verification the roadmap's `v1.6.0` wording literally asks for ("run composer validate --strict", "run composer audit") — it does not wire either into `.github/workflows/ci.yml` permanently.
- **Not running `composer update`.** The existing, already-installed, already-tested `composer.lock` content is committed as-is — no dependency version changes, per `CLAUDE.md`'s "don't bump Composer/Docker versions unless explicitly asked" and the `ask`-gated `composer update` permission rule already in `.claude/settings.json`.
- **Not changing `Dockerfile`'s or `.github/workflows/ci.yml`'s install commands.** Both already correctly resolve to the tracked lock file's exact versions automatically, the moment it exists in git — no command-line flag changes needed (see Problem point 7 for why the existing `--no-dev` asymmetry is intentional and correct).
- **Not committing anything myself.** Per this project's established workflow (and the general rule against committing without explicit request), this spec only removes `composer.lock` from `.gitignore` so the file *can* be committed — staging/committing it is left to the user.

## Current behavior

Confirmed by direct reads and real command execution on the current working tree:

- `.gitignore:55` — `composer.lock` is listed, meaning `git status`/`git add` never surfaces it as trackable.
- `git ls-files composer.lock` → no output (confirmed untracked).
- `composer.json` — no `license` field; `require`/`require-dev` blocks are otherwise unremarkable (Slim 4, Eloquent, PHPUnit, etc., matching `CLAUDE.md`'s confirmed stack).
- `docker compose exec web composer validate --strict` → exit `1`, "No license specified" warning (the only warning).
- `docker compose exec web composer audit` → "No security vulnerability advisories found."
- `Dockerfile:30` — `COPY composer.json composer.lock* ./` (wildcard, tolerates absence).
- `.github/workflows/ci.yml:46` — `composer install --no-interaction --prefer-dist`, no reference to a lock file (none available to it today).
- No `LICENSE` file exists at the repository root.

## Proposed behavior

After this change:

- `composer.lock` is removed from `.gitignore` and left in the working tree as an untracked-but-visible file, ready for the user to `git add`/commit when they choose.
- `composer.json` gains `"license": "proprietary"`.
- `composer validate --strict` exits `0`.
- Once `composer.lock` is committed (by the user, in their own commit), any fresh `git clone` + `docker compose build` + CI run all resolve the exact same dependency versions — no more independent, potentially-divergent resolution in any of the three places.

## Functional requirements

1. `.gitignore` no longer lists `composer.lock`.
2. `composer.json` contains `"license": "proprietary"`.
3. `composer validate --strict` (run against the live container) exits `0`.
4. `composer audit` (run against the live container) reports no vulnerabilities (already true; re-confirmed after this spec's changes to prove nothing regressed).
5. `composer.lock`'s content is unchanged by this spec (no `composer update` run) — the existing, already-installed dependency graph is what becomes trackable, not a newly-resolved one.

## Non-functional requirements

Not applicable — this is a reproducibility/tooling change with no runtime behavior impact.

## User flows

Not applicable — no user-facing behavior change. This affects only the development/deployment workflow (what a fresh `git clone` + `docker compose build` or CI run actually installs).

## API changes

Not applicable.

## Data model and migrations

Not applicable — no database changes.

## Architecture and affected components

- `.gitignore` — remove the `composer.lock` line.
- `composer.json` — add `"license": "proprietary"`.
- No `Dockerfile`, `docker-compose.yml`, or `.github/workflows/ci.yml` changes (both already correctly consume a tracked lock file automatically).
- No `src/`, `public/`, `common/` changes.

## Security considerations

`composer audit` is confirmed clean as of this spec — no known vulnerable dependency versions today. Tracking `composer.lock` is itself a security-adjacent improvement: it prevents an untested, newly-resolved transitive dependency version from silently entering a production Docker build or a fresh install without the same version having been exercised in CI/local dev first.

## Backward compatibility

None — purely additive/corrective (untracking removal + one metadata field). No stored data, API, or runtime behavior is affected. Once the user commits `composer.lock`, subsequent `composer update` runs will need to explicitly re-generate and re-commit it going forward (a normal, expected part of dependency management from this point on, not a new burden this spec introduces — it's the reproducibility the roadmap asked for).

## Acceptance criteria

1. `grep -n "composer.lock" .gitignore` returns no matches.
2. `git status --short composer.lock` shows it as untracked (`??`), confirming it's no longer excluded and is now visible to be added.
3. `grep -n '"license"' composer.json` shows `"license": "proprietary"`.
4. `docker compose exec web composer validate --strict` exits `0`.
5. `docker compose exec web composer audit` reports no vulnerabilities.
6. `docker compose exec web vendor/bin/phpunit` passes with the same count as before this spec (confirms `composer.json`'s edit didn't affect autoloading/dependencies).

## Implementation plan

1. Remove the `composer.lock` line from `.gitignore`.
2. Add `"license": "proprietary"` to `composer.json` (placed near the top, after `"description"`, matching common `composer.json` field ordering conventions).
3. Run `composer validate --strict` and `composer audit` for real against the live container; confirm both pass (Acceptance criteria 4-5).
4. Run `vendor/bin/phpunit` to confirm no regression (Acceptance criterion 6).
5. Confirm `composer.lock` now shows as untracked in `git status` (Acceptance criterion 2) — do not `git add` or commit it myself.

## Testing and validation strategy

No automated test infrastructure applies to dependency/tooling configuration. Validation is: real `composer validate --strict`/`composer audit` runs against the live container (not assumed), a real `git status`/`grep` check that `composer.lock` is no longer ignored, and the existing PHPUnit suite for a basic regression check that `composer.json`'s edit didn't break anything.

## Rollout and rollback

No migration, no container change, no new dependency. Rollback is a plain `git revert` of the `.gitignore`/`composer.json` changes (the user's own separate commit of `composer.lock`, once made, would need its own separate revert if ever desired). Committing `composer.lock` itself is the user's action, not part of this spec's own changes — see Non-goals.

## Open questions

- **Not blocking**: once `composer.lock` is committed, should future `composer update`/`require`/`remove` runs be accompanied by a reminder to re-commit the updated lock file? This is a workflow habit, not a technical gap this spec needs to close — `CLAUDE.md`'s existing `ask`-gated permissions for `composer update`/`require`/`remove` already mean the user is in the loop for those operations regardless.

## Task checklist

- [x] `composer.lock` removed from `.gitignore`
- [x] `composer.json` gains `"license": "proprietary"`
- [x] `composer validate --strict` confirmed exit `0`
- [x] `composer audit` confirmed clean
- [x] `vendor/bin/phpunit` confirmed no regression
- [x] `composer.lock` confirmed untracked/visible in `git status` (not staged or committed)

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Re-verified `.gitignore:55` and `composer.json` immediately before editing — unchanged since `/spec-plan`, no conflict to report.
- 2026-09-03 — Removed the `composer.lock` line from `.gitignore`; added `"license": "proprietary"` to `composer.json`, placed right after `"type"` (matching common `composer.json` field ordering). No deviations from the plan. Did not run `composer update` — the existing, already-installed lock file content is what's now trackable, unchanged.
- 2026-09-03 — Deliberately did not `git add` or stage `composer.lock` — left it as a plain untracked file in the working tree, per this spec's own Non-goals and this project's established convention of leaving all changes for the user to review/stage/commit themselves.

## Validation evidence

- Acceptance criterion 1 — `grep -n "composer.lock" .gitignore` → no matches. **Confirmed.**
- Acceptance criterion 2 — `git status --short composer.lock` → `?? composer.lock` (untracked, visible). **Confirmed.**
- Acceptance criterion 3 — `grep -n '"license"' composer.json` → `"license": "proprietary",`. **Confirmed.**
- Acceptance criterion 4 — `docker compose exec web composer validate --strict` → `./composer.json is valid` (exit `0`) — compare to the pre-change run in this spec's own Problem section, which exited `1` with a "No license specified" warning. **Confirmed, fixed.**
- Acceptance criterion 5 — `docker compose exec web composer audit` → `No security vulnerability advisories found.` (exit `0`) — same clean result as before this spec, confirming nothing regressed. **Confirmed.**
- Acceptance criterion 6 — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)` — same count as before this spec. **Confirmed.**
- `git diff -- .gitignore composer.json` reviewed — scope confirmed minimal and exact: one line removed from `.gitignore`, one line added to `composer.json`.
