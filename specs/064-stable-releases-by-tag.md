# Spec 064 — Stable installs follow release tags, not `master`

## Metadata

- Status: Implemented
- Created: 2026-10-27
- Updated: 2026-10-27
- Owner:
- Related issue: correction to spec 063 (`docs/upgrading.md`) and spec 062 (`docs/installation.md`), raised by an external review the owner shared on 2026-10-27. Feeds `docs/ROADMAP.md` › `v1.9.0` › "Release automation".
- Related branch: `064`

*Reduced spec (CLAUDE.md › Spec workflow: small, obvious fix): problem, expected result and validation are complete; sections that don't apply say so.*

## Context

Spec 063 recorded the owner's answer "`master` is the final version" and wrote it into `docs/upgrading.md`: "`master` is the released version of GastroFlow — restaurants follow it." An external review, shared by the owner on 2026-10-27, pointed out that this is false today and dangerous for Community v2. The owner agreed and chose how to handle the transition (see Implementation log).

## Problem

Measured 2026-10-27:

1. `gh release list` → latest release **`v1.8.3`** (2026-10-03); `git rev-list --count v1.8.3..origin/master` → **51** commits. `master` carries half of the `v1.9.0` milestone and work no tag has named — exactly what the project's own release rule forbids calling a release (CLAUDE.md › Release workflow: a milestone's tag is cut only when every item is complete).
2. `docs/upgrading.md:42-45` tells restaurants to `git pull` on `master`; `docs/installation.md:22` and `README.md:155` clone and stay on `master`. A user who installs "the stable version" gets unreleased code.
3. **Transition trap:** `v1.8.3`'s `Dockerfile` still has `COPY legacy/` (`git show v1.8.3:Dockerfile` line 39) — the defect spec 062 fixed — so a fresh clone checked out at the latest release does **not** build. Until the next tag, no release is installable from scratch, and none contains `docs/installation.md`/`docs/upgrading.md`.

## Goals

- Stable = a release tag (GitHub Release). `master` = the next version, in development.
- Installation and upgrade guides check out a tag; they say plainly what to do until the first release they cover exists.
- `/version` lets an operator tell a release from unreleased code.

## Non-goals

- Cutting any tag now (owner's choice: no new tag until the v1.9 milestone closes).
- Maintenance branches / backports (`v1.8.x` from `v1.8.3`) — rejected by the owner for now.
- Release automation (GitHub Release artifacts, versioned Docker images) — its own roadmap item; this spec only makes the guides compatible with it.

## Current behavior

See Problem. `GET /version` (`src/Controllers/VersionController.php`) returns `git describe --tags --always`: exactly `vX.Y.Z` on a tagged commit, `vX.Y.Z-N-gSHA` on unreleased code (observed: `v1.8.3-47-ga8b28d6` on the dev server).

## Proposed behavior

1. `docs/upgrading.md` step 3: `git fetch --tags` → `git checkout vX.Y.Z` (the newest release on the GitHub Releases page); `master` described as development. Rollback: `git checkout <previous tag>`. Troubleshooting adapted (`git checkout` instead of `git pull`). `/version` explained: a bare tag = a release; `-N-g…` = unreleased code.
2. `docs/installation.md` step 2 and README quickstart: clone, then `git checkout <newest release tag>`.
3. A **transition note** in both guides: the first release they cover is `v1.9.0`; until it is published (it is cut when the v1.9 milestone closes), `v1.8.3` cannot be installed from scratch (`COPY legacy/`) and has none of these guides — anyone installing now uses `master` knowing it is pre-release.
4. The pilot install may keep following `master`: documented as a testing install that receives unreleased code.
5. Record the model: `docs/technical-decisions.md` row; CLAUDE.md › Release workflow line; ROADMAP status lines corrected with dated notes; CHANGELOG entry of spec 063 corrected with a dated note; spec 063's Implementation log notes the revised decision.
6. Carry spec 063's pending CI evidence (AC6).

## Functional requirements

1. FR1 — No document tells a restaurant to follow `master` as the stable version: `grep -rn "released version" docs README.md` returns nothing describing `master`.
2. FR2 — `docs/installation.md` and `README.md` install with `git checkout <tag>`; `docs/upgrading.md` upgrades with `git fetch --tags` + `git checkout <tag>` and rolls back to the previous tag.
3. FR3 — Both guides carry the transition note naming `v1.9.0` as the first covered release and the `v1.8.3` build problem. *(Corrected 2026-10-27 by `/spec-review`: the `v1.8.3` build problem is stated only in `docs/installation.md` — it affects a fresh install only; an upgrade **from** `v1.8.3` checks out a newer tag whose `Dockerfile` no longer copies `legacy/`. Both guides do name `v1.9.0` as the first covered release.)*

## Non-functional requirements

Docs only — no code, dependency, Docker or schema change.

## User flows

**Operator:** Releases page → newest tag → clone + `git checkout vX.Y.Z` → install; later `git fetch --tags` + `git checkout vNEW` → upgrade steps. **Developer / pilot install:** `master`.

## API changes

Not applicable — no endpoint changes.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

`docs/upgrading.md`, `docs/installation.md`, `README.md`, `docs/technical-decisions.md`, `CLAUDE.md`, `docs/ROADMAP.md`, `CHANGELOG.md`, `specs/063-upgrade-process.md`.

## Security considerations

Pinning installs to tags means a restaurant only receives code that went through a closed milestone; security fixes still need a release to reach them — which "Release automation"/"Community 2.x Maintenance" must make cheap.

## Backward compatibility

Existing installs on `master` keep working; the guide tells them how to move to a tag (`git fetch --tags && git checkout vX.Y.Z`) once one that is newer than their code exists. Never check out a tag **older** than the code already running — migrations are forward-only.

## Acceptance criteria

1. AC1 — `grep -rn "released version" docs README.md` → no match about `master`.
2. AC2 — `grep -n "git checkout" docs/installation.md README.md docs/upgrading.md` shows the tag checkout in install, upgrade and rollback; `grep -n "git pull" docs/upgrading.md` no longer prescribes `git pull` as the upgrade step.
3. AC3 — `grep -n "v1.9.0" docs/installation.md docs/upgrading.md` → the transition note in both.
4. AC4 — CI green on the PR.

## Implementation plan

1. Guides (`upgrading`, `installation`, README).
2. Decision record (technical-decisions, CLAUDE.md), dated corrections (ROADMAP, CHANGELOG, spec 063), CHANGELOG entry.
3. Spec 063 CI evidence.

## Testing and validation strategy

Docs-only: the greps of the acceptance criteria, a read of each changed section, and CI (AC4). The tag-checkout flow itself cannot be rehearsed end-to-end until a tag newer than `v1.8.3` exists — that is the v1.9 Exit Gate's job once `v1.9.0` is cut.

## Rollout and rollback

Docs take effect on merge; revert the commit to roll back.

## Open questions

None — the owner chose "guides by tag + transition note" on 2026-10-27.

## Task checklist

- [x] Step 1 — guides
- [x] Step 2 — decision record + dated corrections + CHANGELOG
- [x] Step 3 — spec 063 CI evidence (and spec 063 → `Verified`: all its criteria now have evidence)

## Implementation log

- 2026-10-27 — External review shared by the owner: `master` is not a release (51 commits after `v1.8.3`). The owner **revised spec 063's decision (1)** ("`master` = final version") and chose, among three options: **guides by tag + transition note** — not a `v1.8.4` backport branch, and not tagging current `master` (it would name a release from an open milestone).

## Validation evidence

Collected 2026-10-27 on branch `064` (docs only — no code, so no test suite run locally; CI covers AC4).

| AC | Command / evidence | Result |
|---|---|---|
| AC1 | `grep -rn "released version" docs README.md` → no match | Met |
| AC2 | `grep -n "git checkout"`: `docs/installation.md:24` (`git checkout vX.Y.Z`), `README.md:157`, `docs/upgrading.md:49` (upgrade, after `git fetch --tags`), `:110` (rollback, `git checkout <old-version>`). `grep -n "git pull" docs/upgrading.md` → only line 11, the transition note for installs that deliberately follow `master` | Met |
| AC3 | `grep -c "v1.9.0"` → `docs/installation.md` 1 line (transition note, incl. the `v1.8.3` `"/legacy": not found` warning), `docs/upgrading.md` 4 lines | Met |
| AC4 | CI on the PR | Pending CI |

Status `Implemented`: AC4 waits for CI. Not rehearsable yet: installing/upgrading by tag end to end needs a tag newer than `v1.8.3` — left to the v1.9 Exit Gate once `v1.9.0` is cut.
