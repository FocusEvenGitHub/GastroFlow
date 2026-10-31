# Spec 072 — Release automation: GitHub Release and Docker image from a tag

## Metadata

- Status: Implemented
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner: Henry (FocusEven)
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Release automation" (closing order item 6). Trello card `[v1.9] Release automation`.
- Related branch: `072`

## Context

The v1.9 closing order has items 1–5 done (specs 065–068, 071). Item 6 is "Release automation": the roadmap's pipeline is `Git tag → CI → quality checks → tests → build → release artifacts → GitHub Release`, with versioned Docker image tags (`2.0.0`, `2.0`, `2`) and not relying only on `latest`. Spec 064 made restaurants run release tags, so this item is what makes shipping a fix to a restaurant cheap.

Owner decisions, 2026-10-31:

- A pushed tag `v*` triggers: the same CI as `master`/PRs → a **GitHub Release** → a **Docker image on GHCR**.
- **Release notes** are copied from the tag's section of `CHANGELOG.md` (`## vX.Y.Z …`). No section → the release fails; a release never goes out without notes.
- **Image:** `ghcr.io/focusevengithub/gastroflow`, **amd64 only**, tags `X.Y.Z`, `X.Y`, `X` (e.g. `1.9.0`, `1.9`, `1`). A pre-release tag (`v2.0.0-rc.1`) gets only its exact tag (`2.0.0-rc.1`), nothing floating. No `latest`.
- `/version` inside the image answers the tag (`GASTROFLOW_VERSION` build arg, spec 066).
- **The installation and upgrade guides keep building from source** (`docker compose up -d --build`). The image is published and documented as available; switching the guides to it is a possible future spec.

Also carried in this PR (its own PR #58 is merged): spec 071's CI (run `41226824001`) and AC7 (GitHub reports `isSecurityPolicyEnabled: true` and the two contact links) → spec 071 `Verified`.

## Problem

- Publishing a release is manual and partial: tags are created and pushed by hand (`docs/COMMIT_CONVENTION.md` › "Release Steps"), GitHub Releases `v1.7.1`…`v1.8.3` were created by hand, and nothing re-runs the test suite on the tagged commit — `.github/workflows/ci.yml` only triggers on `push` to `master` and `pull_request`.
- No Docker image is published; every install builds its own.
- `docs/COMMIT_CONVENTION.md` › "Release & Changelog Workflow" is stale and contradicts `CLAUDE.md`: it says `git checkout main`, "decide the next version based on commits since the last tag (see SemVer table)", and a changelog format with `Added`/`Fixed` headings; the project uses `master`, takes the version from `docs/ROADMAP.md`, and writes `## vX.Y.Z (date) — title` with Portuguese headings.

## Goals

- Pushing a release tag produces, with no further manual step: a green CI run on that exact commit, a GitHub Release with the CHANGELOG notes, and versioned images on GHCR.
- Nothing is published if the tests fail or the CHANGELOG has no finished section for that version.
- Floating image tags never move backwards (a later patch on an older line doesn't steal `1`).
- The release procedure in the docs matches what the project actually does.

## Non-goals

- Switching `docs/installation.md` / `docs/upgrading.md` to pulling the image (future spec, owner decision).
- Multi-arch (arm64) images.
- Creating tags automatically, deciding version numbers, or editing `CHANGELOG.md` automatically — tags stay a manual, owner-confirmed step (`CLAUDE.md` › Release workflow).
- Pinning `composer:latest` in the `Dockerfile` (floating; recorded as an open question — no version bump without a request).
- Signing images / SBOM / provenance attestations.
- Cutting `v1.9.0` itself — that happens after the milestone closes (item 7, Exit Gate).

## Current behavior

Confirmed in the repository and on GitHub:

- `.github/workflows/ci.yml`: one workflow, `on: push: branches: [master]` and `pull_request`; one job `test` (MySQL service, line endings, PHP 8.2, `composer validate --strict`, `composer audit`, PHPStan, CS-Fixer, fresh install + migrations, PHPUnit Unit, integration + migration-upgrade databases, PHPUnit Integration, Node 22, Playwright). `fetch-depth: 0` (MigrationUpgradeTest reads the `v1.7.1` tag).
- No other workflow exists (`.github/workflows/` has only `ci.yml`).
- Repository `default_workflow_permissions: read`.
- `Dockerfile`: `FROM php:8.2.35-apache`, `COPY --from=composer:latest`, `ARG GASTROFLOW_VERSION` → `VERSION` file read by `/version` (spec 066). No `org.opencontainers.image.*` labels.
- `docker-compose.yml` (production): `build:` with `GASTROFLOW_VERSION`, `image: gastroflow:local` (fixed local name on purpose, spec 066).
- Tags: annotated, `v1.5.x`…`v1.8.3`, all on `master` history. GitHub Releases exist for `v1.7.1`…`v1.8.3` (manual). No container packages published.
- `CHANGELOG.md` headings: released versions `## vX.Y.Z (YYYY-MM-DD) — Title` (e.g. line 57 `## v1.8.3 (2026-10-03) — Limite de tentativas de login`); in-progress milestone `## v1.9.0 — Community Productization (em andamento)` (line 21); out-of-milestone `## Não lançado` (line 3).
- `docs/upgrading.md:9` and `docs/installation.md:24` already point to "the GitHub Releases page" for the newest release.

## Proposed behavior

1. **`ci.yml` becomes reusable:** add `workflow_call:` to its triggers, nothing else changes. Push/PR behavior is identical.
2. **New `.github/workflows/release.yml`**, `on: push: tags: ['v*']`, jobs in order:
   1. **`test`** — `uses: ./.github/workflows/ci.yml` (the full suite on the tagged commit).
   2. **`notes`** (`needs: test`) — validates the tag and extracts the notes:
      - tag must match `^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$`, else fail;
      - `.github/scripts/release-notes.sh <tag>` prints the body of the `CHANGELOG.md` section whose heading starts with `## <tag> (` — up to the next `## ` heading. It **fails** (non-zero, clear message) if there is no such heading, if the heading has no date (i.e. still "em andamento"), or if the body is empty.
   3. **`image`** (`needs: notes`, `permissions: contents: read, packages: write`) — `docker build` with `--build-arg GASTROFLOW_VERSION=<tag>` and `--label org.opencontainers.image.source=https://github.com/FocusEvenGitHub/GastroFlow` (links the package to the repository), `--label org.opencontainers.image.version=<X.Y.Z>`, `--platform linux/amd64`; login to `ghcr.io` with `GITHUB_TOKEN`; push:
      - always `X.Y.Z[-pre]`;
      - for a stable tag only: `X.Y` if this is the highest stable `vX.Y.*` tag, and `X` if it is the highest stable `vX.*` tag (compared with `git tag --sort=-v:refname`);
      - never `latest`.
      Before pushing, a smoke check: run the built image and confirm `/var/www/html/VERSION` contains the tag.
   4. **`release`** (`needs: image`, `permissions: contents: write`) — `gh release create <tag> --title "<tag> — <CHANGELOG title>" --notes-file notes.md --verify-tag`, with `--prerelease` for a pre-release tag and `--latest=false` unless the tag is the highest stable tag. Idempotent: if the release already exists (re-run), `gh release edit` updates the notes instead of failing.
   No third-party actions beyond what `ci.yml` already uses (`actions/checkout`): image build/push with the runner's `docker` CLI, release with the runner's `gh`.
3. **PR dry-run:** `release.yml` also runs on `pull_request` that touches `.github/workflows/release.yml`, `.github/scripts/release-notes.sh` or `Dockerfile`, in **dry-run**: skips `test` (the regular CI already runs on the PR), extracts notes for the newest released version in `CHANGELOG.md`, builds the image with a dummy version and runs the smoke check, prints the tags it *would* push and the release it *would* create — publishes nothing, no write permissions.
4. **Docs:**
   - `docs/COMMIT_CONVENTION.md` › "Release & Changelog Workflow" rewritten to the real procedure: version from `docs/ROADMAP.md` (not SemVer arithmetic); the release PR renames the section to `## vX.Y.Z (YYYY-MM-DD) — Title` and bumps the version in ROADMAP/README; after merge, with the owner's confirmation, `git tag -a vX.Y.Z -m …` on `master` and `git push origin vX.Y.Z`; then the workflow does the rest; how to re-run a failed release; what the image tags mean. The stale `Added`/`Fixed` format and "Automated tools (future)" go; the Portuguese-headings note is resolved in favor of what `CHANGELOG.md` uses.
   - `CLAUDE.md` › Release workflow: one line that a pushed tag triggers `release.yml` (CI → notes → image → GitHub Release) and that the CHANGELOG section must be dated before tagging.
   - `docs/installation.md` / `docs/upgrading.md`: one sentence that a prebuilt image exists at `ghcr.io/focusevengithub/gastroflow:<version>` (the guides still build from source).
   - `README.md`: mention in the Documentation/Installation area if a natural place exists.
   - `docs/ROADMAP.md` › "Release automation": dated status note. `CHANGELOG.md` entry under `v1.9.0` › Infraestrutura / Qualidade (or Documentação, whichever the section has).
5. **Carried:** spec 071 › Validation evidence gets the PR #58 CI and AC7 evidence → `Verified`.

## Functional requirements

1. FR1 — Pushing `vX.Y.Z` runs the full `ci.yml` suite on that commit before anything is published; a failure there publishes nothing.
2. FR2 — The GitHub Release body equals the `CHANGELOG.md` section of that version (heading excluded); with no dated section the workflow fails before publishing anything.
3. FR3 — A stable tag `vX.Y.Z` publishes image tags `X.Y.Z`, plus `X.Y` and `X` only when it is the highest stable tag of that minor / major line; never `latest`.
4. FR4 — A pre-release tag `vX.Y.Z-suffix` publishes only `X.Y.Z-suffix` and a GitHub pre-release not marked latest.
5. FR5 — The image's `/var/www/html/VERSION` contains the tag; the image carries the `org.opencontainers.image.source` label of this repository.
6. FR6 — Write permissions exist only on the jobs that need them (`packages: write` on `image`, `contents: write` on `release`); the workflow-level default stays read.
7. FR7 — Re-running the workflow for the same tag doesn't fail because the release already exists.
8. FR8 — A PR touching the release workflow, the notes script or the `Dockerfile` runs the dry-run and publishes nothing.
9. FR9 — `docs/COMMIT_CONVENTION.md` no longer says `main` or derives the version from SemVer over commits.

## Non-functional requirements

- No new third-party GitHub Action; no new Composer/npm dependency; no Docker base-image change.
- The tag-push path adds no extra test run time beyond one CI run (the suite runs once, via `workflow_call`).
- The notes script runs locally too (plain `bash`/`awk`), so it can be checked before tagging.

## User flows

**Maintainer cutting a release:** the release PR dates the CHANGELOG section and bumps the docs → merge → owner confirms → `git tag -a v1.9.0 -m "…"` + `git push origin v1.9.0` → Actions: test → notes → image → release → GitHub Releases shows `v1.9.0` with the notes; GHCR shows `1.9.0`, `1.9`, `1`.

**Maintainer, forgot to date the CHANGELOG:** tag pushed → `notes` fails with "CHANGELOG.md has no dated section for v1.9.0" → nothing published → fix via PR, delete and re-push the tag (or re-run once fixed — documented).

**Installer:** unchanged — guides check out the tag and build. Optionally `docker pull ghcr.io/focusevengithub/gastroflow:1.9.0`.

## API changes

Not applicable — no application API change.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

- `.github/workflows/ci.yml` — `workflow_call:` trigger added.
- `.github/workflows/release.yml` — new.
- `.github/scripts/release-notes.sh` — new (executable, `100755` in git — this PC's rule for scripts).
- Docs: `docs/COMMIT_CONVENTION.md`, `CLAUDE.md`, `docs/installation.md`, `docs/upgrading.md`, `README.md` (if fitting), `docs/ROADMAP.md`, `CHANGELOG.md`.
- `specs/071-security-contributing-support.md` (carried evidence).
- No application code (`src/`, `public/`, `bin/`, `common/`), no `Dockerfile` change (labels passed with `--label`).

## Security considerations

- `GITHUB_TOKEN` only; write scopes per job (`packages: write`, `contents: write`), the rest read. No personal token or secret added.
- The `pull_request` dry-run has no write permission and never logs in to GHCR, so a PR from a fork can't publish.
- The release runs only on tags; tags are pushed by the owner (repository write access).
- The published image contains no secrets: `.dockerignore` already excludes `.env*` (except `.env.example`), `backups/`, `tests/`, `vendor/` (reinstalled `--no-dev` inside), `public/uploads/`.
- **First publish:** a new GHCR package for a user account starts **private**; the owner must set it public once (GitHub › Packages › gastroflow › Settings › Change visibility). This can't be done with `GITHUB_TOKEN`. Recorded in the rollout and in `docs/COMMIT_CONVENTION.md`.

## Backward compatibility

- Push/PR CI behavior unchanged (`workflow_call` only adds a trigger).
- Existing tags and releases untouched; the workflow only runs for tags pushed after it is on the tagged commit (a tag's workflow file is read from the tagged commit).
- Install/upgrade guides unchanged in substance.

## Acceptance criteria

- AC1 — `bash .github/scripts/release-notes.sh v1.8.3` prints the body of `## v1.8.3 (2026-10-03) — …` (from the line after the heading up to before `## v1.8.2`) and exits 0.
- AC2 — `release-notes.sh v1.9.0` with the current CHANGELOG (heading "em andamento", no date) exits non-zero with a message naming the version; `release-notes.sh v9.9.9` exits non-zero; `release-notes.sh not-a-tag` exits non-zero.
- AC3 — The PR's dry-run job runs green on GitHub: builds the image, the smoke check reads the dummy version from `/var/www/html/VERSION`, prints the would-be image tags and release title; GHCR has no new package version and no release is created.
- AC4 — The regular `CI` workflow on the PR still runs (push/PR triggers unchanged) and is green.
- AC5 — The tag-logic helper (part of `release.yml` or the script) computes, verified locally with a table of inputs: `v1.9.0` with existing `v1.8.3` → `1.9.0 1.9 1`; `v1.8.4` when `v1.9.0` exists → `1.8.4 1.8` (not `1`); `v2.0.0-rc.1` → `2.0.0-rc.1` only.
- AC6 — `docs/COMMIT_CONVENTION.md` contains no `main` branch reference in the release steps and no SemVer-from-commits rule; it describes the tag → workflow flow and the first-publish visibility step.
- AC7 — `release.yml` declares `permissions: {}` or read at the top and write scopes only on `image` and `release`.
- AC8 — **Post-merge, on the first real tag** (`v1.9.0`, after the owner confirms it): the run shows test → notes → image → release green; GHCR has `1.9.0`, `1.9`, `1`; `docker run --rm ghcr.io/focusevengithub/gastroflow:1.9.0 cat /var/www/html/VERSION` → `v1.9.0`; the GitHub Release body equals the CHANGELOG section. *(Recorded later; the spec stays `Implemented` until then.)*
- AC9 — Spec 071 is `Verified` with the PR #58 CI and AC7 evidence.

## Implementation plan

1. `.github/scripts/release-notes.sh` + local checks (AC1, AC2).
2. Tag-computation logic (in the script as a second mode, or a small `image-tags.sh`) + local table test (AC5).
3. `ci.yml`: add `workflow_call:`.
4. `release.yml`: tag job chain + PR dry-run, permissions per job.
5. Docs: `docs/COMMIT_CONVENTION.md`, `CLAUDE.md`, install/upgrade one-liners, `README.md`, `docs/ROADMAP.md`, `CHANGELOG.md`.
6. Spec 071 carried evidence.
7. Open the PR → dry-run + CI green on GitHub (AC3, AC4).
8. After merge and the owner's go-ahead on `v1.9.0` (closing order): record AC8; set the GHCR package public.

## Testing and validation strategy

This changes CI/CD, not the application; PHPUnit/Playwright don't cover it (they do keep running through `ci.yml`). The `/spec-plan` instruction that the project has no automated tests is outdated (spec 040/034/035), but irrelevant here.

- Scripts: run locally with `bash` against the real `CHANGELOG.md` and a table of tag inputs (AC1, AC2, AC5).
- Workflow syntax and wiring: the PR's dry-run run on GitHub (AC3) — the only way to execute a workflow without publishing. `actionlint` isn't available here without pulling a new tool; not used.
- AC8 needs a real tag, which needs the owner's confirmation and should be the milestone's `v1.9.0` — not a throwaway test tag (it would publish a public release and package). Recorded after it happens.

## Rollout and rollback

- Merge → nothing runs until a `v*` tag is pushed.
- First tag: watch the run; then set the GHCR package to public (owner, once).
- Rollback: revert the PR. A bad release is deleted with `gh release delete <tag>`, and its image versions in the package settings. Floating tags (`X.Y`, `X`) only move forward, so re-running an older tag's workflow won't move them back; if ever needed, re-tag the image by hand (`docker pull` + `docker tag` + `docker push`) — documented in `docs/COMMIT_CONVENTION.md`.

## Open questions

- `Dockerfile` copies Composer from `composer:latest` (floating), so two builds of the same tag can use different Composer versions. Pinning it is a version change → not done without a request. **Not blocking.**
- Should the release be refused for a tag not on `master`'s history? Default in this spec: **not enforced** — a security backport from an older tag's branch (discussed 2026-10-31) must stay possible. **Not blocking.**

## Task checklist

- [x] `release-notes.sh` + local checks
- [x] Image-tag logic + local table (`image-tags.sh`)
- [x] `ci.yml` `workflow_call`
- [x] `release.yml` (tag chain + PR dry-run)
- [x] Docs pass
- [x] Spec 071 carried evidence
- [ ] PR: dry-run + CI green
- [ ] Post-merge: first real tag (AC8), package public

## Implementation log

- 2026-10-31 — `/spec-implement` invoked on the Draft with no blocking question: treated as approval (Draft → Approved → In Progress).
- **Two scripts, not one:** `.github/scripts/release-notes.sh` (body, or `--title` for "vX.Y.Z — Title") and `.github/scripts/image-tags.sh` (tags to push; takes the existing tags as arguments, defaulting to `git tag -l 'v*'`, so it can be tested with a table without touching git). Both `bash`, committed `100755`.
- **The `notes` job only validates;** the `release` job runs the script again to produce the notes file. Avoids passing a multi-line value between jobs (artifact or output) for something deterministic and instant.
- **GitHub "Latest" badge:** `--latest` only when the tag is the highest stable tag in the repository; pre-releases get `--prerelease --latest=false`. Not in the spec text explicitly (it said "not marked latest" only for pre-releases) — same rule as the floating image tags.
- **Labels:** besides `org.opencontainers.image.source` (links the GHCR package to the repository) and `.version`, the build adds `.revision` (the commit). Passed with `--label`, no `Dockerfile` change.
- **`docs/COMMIT_CONVENTION.md`:** the whole "Release & Changelog Workflow" section rewritten (dated note at its top): version from the ROADMAP, the real changelog rules (Portuguese headings, one entry per spec in the spec's PR, `## vX.Y.Z (YYYY-MM-DD) — Title`), cutting a release, image tags, first-publish visibility, what to do when a release fails, checklist. The "Upgrade notes" rule said the containers run the code straight from the folder — false since spec 066 — corrected with a dated note. The "Semantic Versioning" link in References was kept: tags are still SemVer-shaped; only the rule of deriving the number from commits went.
- **`CHANGELOG.md`:** the v1.9 section had no "Infraestrutura / Qualidade" heading; added it (the heading v1.8.0 used) for this entry.
- **Local image build:** built the image as the dry-run does (`docker build --platform linux/amd64 --build-arg GASTROFLOW_VERSION=v0.0.0-dry-run …`) to check the smoke step; removed it afterwards (`docker rmi gastroflow:dry-run`).
- Spec 071 → `Verified` with the PR #58 CI and the post-merge AC7 check (carried, as planned).
- **`/spec-review` (2026-10-31):** two fixes before the PR — (1) the dry-run piped `release-notes.sh` into `head -n 20`; under the runner's `pipefail` a section bigger than the pipe buffer would fail on SIGPIPE → now written to `$RUNNER_TEMP/notes.md`, then `head`; (2) `docs/installation.md`/`docs/upgrading.md` now say `docker pull` works once the package is made public (it starts private). Two risks only a real run can settle, left as they are: `gh release create/edit --latest[=false]` on the runner's `gh` (both flags confirmed in the local `gh --help`), and the first GHCR push with `GITHUB_TOKEN` (AC8).

## Validation evidence

This is CI/CD, not application behavior: PHPUnit/Playwright don't exercise it (they keep running through `ci.yml`). The `/spec-plan` note that the project has no tests is outdated, and irrelevant here.

**AC1** — `.github/scripts/release-notes.sh v1.8.3 > $TMPDIR/n.txt` → exit 0; 7 lines, no `## ` heading inside; first line "Item do Backlog sem milestone…", last "- ⚠️ **Docker Desktop (Windows/Mac)**…" — the body between `## v1.8.3 (2026-10-03) — …` and `## v1.8.2`; `diff` against a `sed` range slice of the same lines → `IDENTICAL`. `--title v1.8.3` → `v1.8.3 — Limite de tentativas de login`; `--title v1.5.6` (heading without a title) → `v1.5.6`.

**AC2** — exit 1 with a message for each:
- `v1.9.0` → `error: the v1.9.0 section of CHANGELOG.md has no release date yet: '## v1.9.0 — Community Productization (em andamento)'` + how to fix;
- `v9.9.9` → `error: CHANGELOG.md has no section for v9.9.9 …`;
- `not-a-tag`, `v1.8` → `error: '…' is not a release tag (vX.Y.Z or vX.Y.Z-suffix)`.
Release-PR simulation: a copy of `CHANGELOG.md` with the heading renamed to `## v1.9.0 (2026-11-15) — Community Productization` → `--title` `v1.9.0 — Community Productization`; body exit 0 with all five subsections (Segurança, Novidades, Correções, Documentação, Infraestrutura / Qualidade) through this spec's entry, stopping before `## v1.8.3`.

**AC3** — *pending (PR).* Locally, the dry-run's steps: notes for the newest dated section (`v1.8.3`) printed, the in-progress `v1.9.0` "refused, as expected"; `docker build -q --platform linux/amd64 --build-arg GASTROFLOW_VERSION=v0.0.0-dry-run --label org.opencontainers.image.source=… -t gastroflow:dry-run .` → built; `docker run --rm gastroflow:dry-run cat /var/www/html/VERSION` → `v0.0.0-dry-run`; `docker image inspect` → label `org.opencontainers.image.source: https://github.com/FocusEvenGitHub/GastroFlow`, `amd64`; the image has `vendor/` and no `.env`.

**AC4** — *pending (PR).* `ci.yml` triggers after the change (parsed with PyYAML): `push`, `pull_request`, `workflow_call` — the first two untouched.

**AC5** — `image-tags.sh` table:

| Input (tag, existing tags) | Output |
|---|---|
| `v1.9.0` · `v1.8.3 v1.8.2` | `1.9.0 1.9 1` |
| `v1.8.4` · `v1.9.0 v1.8.3` | `1.8.4 1.8` |
| `v2.0.0-rc.1` · `v1.9.0` | `2.0.0-rc.1` |
| `v2.0.0` · `v2.0.0-rc.1 v1.9.0` | `2.0.0 2.0 2` |
| `v1.9.1` · `v1.9.0 v2.0.0` | `1.9.1 1.9 1` (highest `v1.*`) |
| `v1.10.0` · `v1.9.0 v1.9.1` | `1.10.0 1.10 1` (version sort, not text) |
| `v1.9.2` · `v1.10.0 v1.9.1` | `1.9.2 1.9` |
| `bogus` | error, exit 1 |
| `v1.9.0` against the real `git tag` list | `1.9.0 1.9 1` |

**AC6** — `grep -n "main\b" docs/COMMIT_CONVENTION.md` → only the dated note at the top of the section and the Angular guidelines URL (`…/blob/main/CONTRIBUTING.md`) in References; no SemVer-from-commits rule (the "Semantic Versioning" table is gone; `grep -ni "semver"` → only the note and the References link). The section describes tag → `Release` workflow and the first-publish visibility step.

**AC7** — PyYAML on `release.yml`: top-level `permissions: {contents: read}`; `image` → `{contents: read, packages: write}`; `release` → `{contents: write}`; `test`, `notes`, `dry-run` → none (inherit read). `test`/`notes`/`image`/`release` run only on `push` (tags), `dry-run` only on `pull_request`.

**AC8** — *pending: the first real tag (`v1.9.0`), after the owner confirms it.*

**AC9** — `specs/071-security-contributing-support.md`: Status `Verified`; AC7 (GraphQL: `isSecurityPolicyEnabled: true`, the two `contactLinks`) and AC10 (PR #58 CI run `41226824001`, merge `a5c4668`) recorded.

Other local checks: `bash -n` on both scripts → ok; `git diff --check` → clean; no CRLF in the new scripts/workflows; `core.fileMode=true` and the scripts are `755`, so they are committed `100755`.
