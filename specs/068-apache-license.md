# Spec 068 — Apache License 2.0

## Metadata

- Status: Implemented
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Open-source readiness" — the license bullet (closing order item 4). Owner decision 2026-10-31: **Apache License 2.0**. Trello card `[v1.9] Open-source readiness`.
- Related branch: `068`

## Context

The roadmap asks for "an explicit license … an intentional project decision" before the Community release. The owner compared AGPL-3.0, GPL-3.0, MIT and Apache-2.0 and chose Apache-2.0 (patent grant, contributions licensed under the same terms by default — §5, no trademark grant — §6). `CONTRIBUTING.md`, `SECURITY.md` and the support policy are item 5, not this spec.

## Problem

Measured on `master` @ `024f198` (2026-10-31):

1. **No license file.** No `LICENSE`, `NOTICE` or `COPYING` in the repository. Under copyright law that means *all rights reserved*: nobody may legally use, copy or modify GastroFlow, whatever the README says.
2. **`composer.json:5` says `"license": "proprietary"`** (`composer licenses` reports `focuseven/gastroflow … Licenses: proprietary`).
3. **README has no License section**; its "Author" section names **Henry Sampaio**.
4. **Third-party code shipped with GastroFlow** — all compatible with Apache-2.0 (permissive), but the redistribution conditions must be met:
   - Composer production dependencies (`docker compose exec web composer licenses --no-dev`, measured): 45 packages, all **MIT**, **BSD-3-Clause** (`firebase/php-jwt`, `nikic/fast-route`, `vlucas/phpdotenv`, `vlucas/valitron`) or **Apache-2.0** (`phpoption/phpoption`, no `NOTICE` file). They live in `vendor/`, not in git, and each package already carries its own `LICENSE` there — inside the built image too.
   - `public/vendor/` (in git, spec 055; `public/vendor/README.md` already lists each license): Bootstrap 5.3.0 MIT, Font Awesome Free 6.4.0 (icons CC BY 4.0, fonts SIL OFL 1.1, code MIT), Alpine.js 3.17.4 MIT, Tom Select 2.6.2 Apache-2.0, Chart.js 4.4.0 MIT, Inter 5.3.0 SIL OFL 1.1.
   - **License texts missing for most of them.** MIT/BSD require the copyright and permission notice to accompany copies. Measured on the first 300 bytes of each file: Bootstrap's CSS/JS and Font Awesome's CSS keep a header; Tom Select's JS keeps an Apache header (its CSS doesn't); **Alpine.js and Chart.js minified files have none** *(corrected 2026-10-31, Implementation log: Chart.js has its MIT header after jsDelivr's comment; Alpine.js and Tom Select's CSS are the ones without)*; only Inter ships a `LICENSE` file (`public/vendor/inter-5.3.0/LICENSE`).
   - Dev-only tooling (PHPUnit, PHPStan, PHP-CS-Fixer in `require-dev`; Playwright in `tests/e2e/`) is not shipped to restaurants and doesn't affect the license of GastroFlow itself.
5. **Authorship.** `git blame -w` over every tracked text file (excluding `public/vendor/`, images, lock files), by author e-mail: `henrykuntm@gmail.com` 40 903 lines, `noreply@anthropic.com` 2 878 (AI-assisted commits made by the owner's sessions), `user@temp` 1 516, **`edgarllider@hotmail.com` 105**. The 105 lines come from the 2025-11-30 initial commits (`960c423`, `a4aeb69`): `common/sql/001_schema.sql` 44 (the `categories`/`menu_items` DDL), `common/db.php` 21 and `common/config.php` 8 (dead code per `CLAUDE.md`), `docker-compose.yml` 20 (the `db` service skeleton), `Dockerfile` 6, `public/.htaccess` 4, `.env.example` 2. Relicensing someone else's contribution needs their permission or a prior agreement — see Open question 2. *(Resolved 2026-10-31: every one of these identities is the owner — see Implementation log; nothing needed rewriting.)*

## Goals

- GastroFlow is licensed under Apache-2.0, stated where tools and people look: `LICENSE`, `NOTICE`, `composer.json`, README.
- Every third-party file redistributed in the repository has its license text next to it.

## Non-goals

- `CONTRIBUTING.md`, `SECURITY.md`, support policy (item 5) — this spec only prepares what they will reference.
- Per-file license headers in GastroFlow's own source (Apache's appendix recommends but doesn't require them; `NOTICE` + `LICENSE` at the root suffice). Can be revisited later.
- A trademark policy for the "GastroFlow" name (§6 already withholds trademark rights; a written policy can come later).
- Removing the dead `common/db.php`/`common/config.php` (Backlog card "Triagem de código legado / dead code"), unless Open question 2 makes it the easiest path.

## Current behavior

See Problem.

## Proposed behavior

1. **`LICENSE`** at the root: the unmodified text of the Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0.txt), including the appendix as published.
2. **`NOTICE`** at the root:

   ```text
   GastroFlow
   Copyright 2025-2026 FocusEven (Henry Sampaio)

   This product includes third-party software; see public/vendor/README.md
   and the LICENSE file next to each library under public/vendor/.
   ```

3. **`composer.json`**: `"license": "Apache-2.0"` (SPDX id); `composer.lock`'s `content-hash` refreshed with `composer update --lock` (no package changes) so `composer validate --strict` stays clean.
4. **README**: a "License" section — Apache-2.0, link to `LICENSE`, one line on what it allows (use, modify, sell, closed forks) and what it requires (keep `LICENSE`/`NOTICE`, mark changed files, no use of the name); the badge row gains a license badge.
5. **`public/vendor/`**: a `LICENSE` file in each library folder that lacks one — Bootstrap, Font Awesome, Alpine.js, Tom Select, Chart.js — taken byte-for-byte from the **same pinned package version** (the npm tarball the CDN files came from), with SHA-256 added to `public/vendor/README.md` like the other files. The "Licença" column links them.
6. **Docs pass**: `docs/ROADMAP.md` (status under Open-source readiness: license done, the rest is item 5), `CHANGELOG.md`, `docs/technical-decisions.md` (the license decision and why Apache-2.0 over AGPL/MIT), Trello card.

## Functional requirements

1. FR1 — `LICENSE` is byte-identical to the Apache Software Foundation's `LICENSE-2.0.txt`.
2. FR2 — `NOTICE` exists with the project name, the copyright line and the pointer to third-party licenses.
3. FR3 — `composer licenses` reports `Licenses: Apache-2.0` for `focuseven/gastroflow`; `composer validate --strict` passes.
4. FR4 — Each of the six `public/vendor/<lib>-<version>/` folders contains a `LICENSE` (or `LICENSE.txt`/`LICENSE.md`, keeping upstream's file name — *wording widened 2026-10-31 by `/spec-review`*) from that exact version.
5. FR5 — README has a License section linking `LICENSE`; GitHub's repository sidebar detects "Apache-2.0 license" after the merge.

## Non-functional requirements

- No runtime behavior change: no PHP, JS, CSS, schema or compose change.
- No dependency added or bumped (`composer update --lock` changes only the hash).

## User flows

- **Someone evaluating GastroFlow**: GitHub page → sidebar "Apache-2.0 license" → `LICENSE`/README License section.
- **Someone redistributing it**: keeps `LICENSE`, `NOTICE` and `public/vendor/*/LICENSE`.

## API changes

Not applicable — no API surface changes.

## Data model and migrations

Not applicable — no data model changes.

## Architecture and affected components

New: `LICENSE`, `NOTICE`, `public/vendor/{bootstrap-5.3.0,fontawesome-free-6.4.0,alpinejs-3.17.4,tom-select-2.6.2,chartjs-4.4.0}/LICENSE`. Edited: `composer.json`, `composer.lock` (hash only), `README.md`, `public/vendor/README.md`, `docs/ROADMAP.md`, `docs/technical-decisions.md`, `CHANGELOG.md`. `.gitattributes`: check that `LICENSE`/`NOTICE` (no extension) get `eol=lf` — spec 049's CI rule fails on a tracked file without one.

## Security considerations

Not applicable to runtime security. Legal note: the third-party license texts must come from the pinned versions, not a newer release, so the notices match the shipped code.

## Backward compatibility

Existing installs are unaffected at runtime. Legally, code published before this change was "all rights reserved"; from the merge on, the repository's contents are offered under Apache-2.0.

## Acceptance criteria

1. AC1 — `curl -s https://www.apache.org/licenses/LICENSE-2.0.txt | cmp - LICENSE` → no output.
2. AC2 — `NOTICE` contains `GastroFlow`, `Copyright 2025-2026 FocusEven (Henry Sampaio)` and `public/vendor/README.md`.
3. AC3 — `docker compose exec web composer licenses --no-dev | head -3` shows `Licenses: Apache-2.0`; `docker compose exec web composer validate --strict` → valid; `git diff composer.lock` touches only `content-hash`.
4. AC4 — `ls public/vendor/*/LICENSE*` lists six files; each SHA-256 in `public/vendor/README.md` matches `sha256sum`; each file's copyright line names the library.
5. AC5 — README › License links `LICENSE`; after the merge, `gh api repos/FocusEvenGitHub/GastroFlow --jq .license.spdx_id` → `Apache-2.0`.
6. AC6 — CI green on the PR (including the line-ending check on the new files).

## Implementation plan

1. Resolve Open questions 1 and 2.
2. `LICENSE` from apache.org; `NOTICE`; `.gitattributes` entries if needed.
3. `composer.json` license + `composer update --lock` (development compose); `composer validate --strict`.
4. Fetch each vendored library's license from its npm tarball at the pinned version (`https://registry.npmjs.org/<pkg>/-/<pkg>-<version>.tgz` → `package/LICENSE*`), place it, record SHA-256.
5. README License section + badge; `public/vendor/README.md`.
6. Docs pass: ROADMAP, technical-decisions, CHANGELOG; Trello.

## Testing and validation strategy

No code changes, so PHPUnit/PHPStan/CS-Fixer are unaffected (CI still runs them). Validation is the commands in the Acceptance criteria: `cmp` against apache.org, `composer licenses`/`validate`, `sha256sum`, `git diff --stat`, CI, and the GitHub API after the merge (AC5's second half can only be checked post-merge).

## Rollout and rollback

Effective when merged to `master` and from the next release tag on. Reverting the PR removes the files, but anyone who obtained the code under Apache-2.0 meanwhile keeps that license (it is irrevocable, §2) — so this is effectively a one-way decision.

## Open questions

1. **Copyright holder (blocking).** Who goes in `NOTICE` — `Henry Sampaio` (README › Author), `FocusEven` (GitHub org, `composer.json` vendor name `focuseven/`), or both (`Henry Sampaio (FocusEven)`)? If FocusEven is a registered company that should own the code, that is the cleaner holder.
2. **Edgarllider's 105 lines (blocking).** The initial commits (2025-11-30) are by `edgarllider@hotmail.com`. Options: (a) the owner confirms that author agreed (or agrees now, in writing — e.g. a comment on the PR) to license their contribution under Apache-2.0, or that the work was done for the owner under an agreement that assigns it; (b) rewrite those lines before relicensing — most are trivial (compose/Dockerfile boilerplate, blank lines), `common/db.php`/`config.php` are dead code that can be deleted, and the 44 schema lines are the `categories`/`menu_items` DDL. Proposed: (a) if possible; else (b), in this spec.
3. **`user@temp` (not blocking, to confirm).** 1 516 surviving lines by `user <user@temp>` (e.g. `130450e`, 2026-09-26, "migraations Xuxu"; `d637393`, `55f3877`, 2026-08-30). Assumed to be the owner on a machine without a configured Git identity — please confirm.
4. **Font Awesome icons are CC BY 4.0 (not blocking).** Attribution is satisfied by the CSS header and its `LICENSE.txt`; no change to the screens proposed.

## Task checklist

- [x] Open questions 1–2 resolved (owner, 2026-10-31)
- [x] `LICENSE`, `NOTICE` (+ `.gitattributes`)
- [x] `composer.json` (`composer.lock` needed no change — see log)
- [x] `public/vendor/*/LICENSE*` + SHA-256
- [x] README License section
- [x] Docs pass (ROADMAP, technical-decisions, CHANGELOG) + Trello

## Implementation log

- 2026-10-31 — Open questions answered by the owner before `/spec-implement`; the invocation then counts as approval → `Approved` → `In Progress`.
  - **Q1 (holder):** FocusEven is the owner's company; he is Henry Sampaio. `NOTICE` uses `Copyright 2025-2026 FocusEven (Henry Sampaio)`. *(The owner asked, 2026-10-31, for his name to appear as "Henry Sampaio" everywhere — same as README › Author — not with his middle name.)*
  - **Q2 (`edgarllider@hotmail.com`):** not a third-party contribution — the owner made those commits on a colleague's notebook (hotfix), whose Git identity was configured. Nothing to rewrite or ask permission for.
  - **Q3 (`user@temp`):** the owner, on another notebook with a misconfigured Git identity.
  - So every surviving line is the owner's (the `noreply@anthropic.com` lines are AI-assisted commits from his own sessions); relicensing needs no one else's consent.

- **`composer.lock` unchanged.** The plan called for `composer update --lock`; not needed — `license` is not part of Composer's `content-hash`, and `composer validate --strict` passes with the current lock (below). No dependency touched.
- **The development container still reads the old `composer.json`.** `sed -i` replaced the file (new inode); the dev compose bind-mounts `composer.json` as a single file, which stays pinned to the old inode until `web` is recreated. Validation therefore ran on copies (`docker compose cp` into `/tmp/v068` in the container, `vendor/` symlinked). Harmless at runtime (nothing reads the license field); a `docker compose up -d` by the owner refreshes it. Production builds copy the file into the image, so they are unaffected.
- **Correction to Problem item 4:** Chart.js *does* carry its MIT header — after jsDelivr's own 300-byte comment, which my first check stopped at. Measured now: `chart.umd.min.js` = jsDelivr comment + npm `chart.js@4.4.0/dist/chart.umd.js` byte-for-byte. Without any notice: `alpinejs-3.17.4/cdn.min.js` and `tom-select-2.6.2/css/tom-select.bootstrap5.min.css`.
- **License sources.** Each `LICENSE*` comes from the npm tarball of the pinned version (`registry.npmjs.org/<pkg>/-/<pkg>-<ver>.tgz` › `package/LICENSE*`), kept under its original name: `bootstrap-5.3.0/LICENSE`, `fontawesome-free-6.4.0/LICENSE.txt`, `tom-select-2.6.2/LICENSE`, `chartjs-4.4.0/LICENSE.md`. The `alpinejs@3.17.4` npm package ships no license file (its `package.json` says `"license": "MIT"`), so `alpinejs-3.17.4/LICENSE.md` comes from the repository at tag `v3.17.4` (raw.githubusercontent.com). Before copying, the vendored files were compared with the tarballs: Bootstrap CSS, Alpine `cdn.min.js`, Tom Select JS and Font Awesome CSS byte-identical (`cmp`); Chart.js as above.
- `.gitattributes`: `LICENSE`/`NOTICE` (no extension) and `public/vendor/**/LICENSE.txt` get `eol=lf`; `public/vendor/**/LICENSE` was already there (spec 055), `.md` is covered by `*.md`.
- `public/vendor/README.md` "Atualizar uma biblioteca" gains "bring the `LICENSE` of the same version".

## Validation evidence

No PHP, JS or CSS changed, so PHPUnit/PHPStan/CS-Fixer were not run locally; CI runs them on the PR.

**AC1** — `curl -sSf https://www.apache.org/licenses/LICENSE-2.0.txt | cmp - LICENSE && echo identical` → `AC1: identical` (SHA-256 `cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30`, 202 lines).

**AC2** — `NOTICE`:

```
GastroFlow
Copyright 2025-2026 FocusEven (Henry Sampaio)

This product includes third-party software; see public/vendor/README.md
and the LICENSE file next to each library under public/vendor/.
```

**AC3** — on copies of the current `composer.json`/`composer.lock` inside the `web` container (see log):

```
composer validate --strict   → ./composer.json is valid
composer licenses --no-dev   → Name: focuseven/gastroflow … Licenses: Apache-2.0
git diff --stat composer.lock → (empty — unchanged; the criterion's "only content-hash" is met trivially)
```

**AC4** — `ls public/vendor/*/LICENSE*` → six files (`alpinejs-3.17.4/LICENSE.md`, `bootstrap-5.3.0/LICENSE`, `chartjs-4.4.0/LICENSE.md`, `fontawesome-free-6.4.0/LICENSE.txt`, `inter-5.3.0/LICENSE`, `tom-select-2.6.2/LICENSE`). Copyright lines: Caleb Porzio and contributors; The Bootstrap Authors; Chart.js Contributors; Fonticons, Inc.; The Inter Project Authors; Tom Select's is the bare Apache-2.0 text (no copyright line in upstream's file). `cd public/vendor && sed -n '/^```text$/,/^```$/p' README.md | grep -v '```' | sha256sum -c` → 22 `OK`, 0 failures.

**AC5** — README › License links `LICENSE` and `NOTICE`; link check over `README.md`, `docs/*.md`, `public/vendor/README.md` → `109 links checked, 0 broken`. GitHub's detection (`gh api repos/FocusEvenGitHub/GastroFlow --jq .license.spdx_id`) can only be checked **after the merge** — pending; to be recorded with the next spec's PR, as spec 064's CI evidence was.

**AC6** — CI on PR #55: `test` pass (1m44s, run `41210064493`) and GitGuardian pass — CI's line-ending check included. Locally `git check-attr eol` → `lf` for `LICENSE`, `NOTICE`, `fontawesome-free-6.4.0/LICENSE.txt`, `chartjs-4.4.0/LICENSE.md`; `file` reports no CRLF in the new files.
