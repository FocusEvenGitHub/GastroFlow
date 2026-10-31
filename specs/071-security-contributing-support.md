# Spec 071 — SECURITY.md, CONTRIBUTING.md and the Community support policy

## Metadata

- Status: Implemented
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner: Henry (FocusEven)
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Closing order" item 5 ("Open-source readiness" minus the license, plus "Community support policy"). Trello cards `[v1.9] Open-source readiness` and `[v1.9] Community support policy`.
- Related branch: `071`

## Context

The v1.9 closing order (agreed 2026-10-27) has items 1–4 done (specs 065–068). Item 5 is what's left of "Open-source readiness" after the license (spec 068) plus the "Community support policy" section: `SECURITY.md`, `CONTRIBUTING.md`, vulnerability reporting, contribution and support expectations.

Owner decisions, 2026-10-31:

- **Reporting channel:** GitHub **Private vulnerability reporting** only — no e-mail address published. It is currently **disabled** on the repository (`gh api repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting` → `{"enabled":false}`), so it must be turned on.
- **Response time:** **no deadline promised** — "as soon as possible".
- **Supported versions:** security fixes go **only to the latest release** — until `v2.0.0`, the most recent tag; from then on, the latest minor of `2.x`. A fix always ships as a **new tag**, never by re-tagging or patching an old one. This matches spec 064 ("a restaurant runs a release tag, never `master`").
- Language: **English**, like the rest of `docs/` and `README.md`.

This PR also carries two pending records from earlier specs (owner request, 2026-10-31), since their PRs are already merged and the project rule is one PR per spec, with leftovers going into the next spec PR:

- spec 070: CI of PR #57 — run `41221200025`, `test` + GitGuardian green;
- spec 069: AC6 — the owner ran `bin/migrate` on the pilot and the "sem conexão" warning is gone.

## Problem

- There is no `SECURITY.md`. `docs/security.md:81-83` says "The reporting process will be defined in `SECURITY.md`. Until then, … contact the maintainers privately" — but gives no private channel. The repository is public (`visibility: public`) with issues enabled, so the only obvious channel for a vulnerability today is a public issue.
- There is no `CONTRIBUTING.md`. `README.md` › "Contributing" (lines 262-269) has four steps and nothing about tests, style, specs, changelog, or what kind of contribution is welcome.
- No document states what the maintainer commits to supporting; that text exists only as a roadmap item (`docs/ROADMAP.md` › "Community support policy").

## Goals

- `SECURITY.md` at the repository root (where GitHub shows it in the Security tab) with the private channel, what to include, what happens next, supported versions and scope.
- GitHub Private vulnerability reporting turned on, so the channel `SECURITY.md` names actually works.
- `CONTRIBUTING.md` at the root: how to set up, what the CI checks, commit/branch/spec conventions, what is welcome and what isn't.
- `SUPPORT.md` at the root (GitHub links it from the new-issue page) with the Community support policy: what gets support, which versions, where to ask, what is not promised.
- Existing docs point to them instead of saying "will be defined".

## Non-goals

- No code, configuration or schema change in the application.
- No code of conduct (not in the roadmap item; can be a later decision).
- No change to the commit convention, spec workflow, or CI — `CONTRIBUTING.md` describes them, it doesn't redefine them.
- No translation of the existing Portuguese issue/PR templates.
- Not the release automation (closing-order item 6) nor the Exit Gate (item 7).

## Current behavior

Confirmed in the repository:

- Root files: `CHANGELOG.md`, `CLAUDE.md`, `LICENSE`, `NOTICE`, `README.md` — no `SECURITY.md`, `CONTRIBUTING.md` or `SUPPORT.md` (also none under `.github/` or `docs/`).
- `.github/`: `PULL_REQUEST_TEMPLATE.md` and `ISSUE_TEMPLATE/default.md` (both in Portuguese), `workflows/ci.yml`. No `ISSUE_TEMPLATE/config.yml`, so the new-issue page offers no "report a vulnerability" link.
- `docs/security.md:81-83` — "Reporting a vulnerability" placeholder, quoted above.
- `README.md:262-269` — "Contributing": fork, branch (one per spec, e.g. `054`; outsiders `feature/…`; `COMPOSE_FILE=docker-compose.dev.yml`), commit per `docs/COMMIT_CONVENTION.md`, PR; issues for bugs/questions/ideas.
- `README.md:50-63` — the Documentation table; lists `docs/security.md`, not `SECURITY.md`.
- CI (`.github/workflows/ci.yml`): line endings → `composer validate` → `composer audit` → PHPStan → PHP-CS-Fixer → fresh install + migrations → PHPUnit unit → integration (MySQL) → Playwright.
- Repository: public, issues on, discussions off, Private vulnerability reporting off.
- `docs/ROADMAP.md` › "Open-source readiness" status (2026-10-31) says "`CONTRIBUTING.md`, `SECURITY.md` and the support expectations remain — closing order item 5"; "Community support policy" lists what 2.x may keep receiving (security fixes, critical bug fixes, dependency updates, compatibility, docs, selected contributions) and that not every future capability is promised to Community.

## Proposed behavior

1. **`SECURITY.md`** (root, English):
   - *Reporting:* use GitHub › Security › "Report a vulnerability" (direct link `https://github.com/FocusEvenGitHub/GastroFlow/security/advisories/new`); never a public issue, PR or discussion.
   - *What to include:* affected version (`/version` or tag), component/endpoint, steps to reproduce, impact, any proof of concept; no real restaurant data.
   - *What happens next:* the report is answered as soon as possible — **no deadline is promised**, it's maintained by one person; the fix is developed privately in the advisory, released as a new tag, then the advisory is published with credit unless the reporter declines.
   - *Supported versions:* table — latest release ✅, anything older ❌, `master` ❌ (development, not a release); before v2.0 "latest" = the most recent tag; from v2.0 = the latest `2.x` minor; fixes always ship as a new tag.
   - *Scope:* in scope — the GastroFlow code, its Docker image and compose files, bundled `public/vendor/` copies. Out of scope — the documented accepted limitations in `docs/security.md` › "Known limitations" (cashier/kitchen without login, no HTTPS, LAN trust model) unless the report shows impact beyond what's documented; vulnerabilities in upstream projects (report them upstream; tell us if GastroFlow needs to update).
2. **Private vulnerability reporting enabled** on `FocusEvenGitHub/GastroFlow` (`gh api -X PUT repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting`), confirmed with a `GET` returning `{"enabled":true}`. This is a repository setting change — done during implementation with the owner's go-ahead already given (decision above), and stated in the PR.
3. **`CONTRIBUTING.md`** (root, English):
   - Before starting: open an issue for anything non-trivial; security issues go through `SECURITY.md`.
   - Setup: development compose (`COMPOSE_FILE=docker-compose.dev.yml`), pointer to `docs/installation.md`/`docs/commands.md`; no PHP/Node needed on the host.
   - Checks that must pass, the same as CI, with the exact commands from `CLAUDE.md` (PHPStan, PHP-CS-Fixer, PHPUnit including the integration suite with `MYSQL_DATABASE_TEST`, Playwright on port 8081 — never 8080).
   - Conventions: PSR-12 via the fixer, `declare(strict_types=1)`, LF line endings, English docblocks / Portuguese user-facing strings, `?v=` on own JS/CSS, no CDN in operational screens, no new dependency without discussion, schema only via `common/migrations/`.
   - Workflow: commit convention; for non-trivial changes a spec in `specs/` (template + lifecycle in `specs/README.md`) — welcome but not mandatory for outside contributors; a maintainer can write it; `CHANGELOG.md` entry in the same PR.
   - What is welcome / what isn't: bug fixes, docs, tests, compatibility; large features should be discussed first and may not fit Community (support policy); the roadmap is in `docs/ROADMAP.md`.
   - License: contributions are accepted under Apache-2.0 (section 5), no CLA.
4. **`SUPPORT.md`** (root, English) — the Community support policy:
   - Where to ask: GitHub issues (bugs, questions, ideas); security via `SECURITY.md`; no private/paid support channel promised.
   - Supported versions: same rule as `SECURITY.md` (latest release only; fixes as new tags; restaurants should run a tag, not `master`).
   - What Community 2.x may keep receiving — the roadmap list (security fixes, critical bug fixes, dependency updates, compatibility, documentation, selected community contributions).
   - What is not promised: response times, every future capability reaching Community, support for modified installations or versions other than the latest.
5. **`.github/ISSUE_TEMPLATE/config.yml`** with a `contact_links` entry "Report a security vulnerability" → the advisory URL, so the new-issue page steers reporters away from public issues; blank issues stay allowed (`blank_issues_enabled: true`, today's behavior).
6. **Docs pass:** `docs/security.md` › "Reporting a vulnerability" points to `SECURITY.md`; `README.md` › Documentation table gains `SECURITY.md`, `CONTRIBUTING.md`, `SUPPORT.md`, and › Contributing points to `CONTRIBUTING.md` (keeping the short steps); `docs/ROADMAP.md` › "Open-source readiness" and "Community support policy" get a dated status note; `CHANGELOG.md` entry under `v1.9.0 — Community Productization (em andamento)` › Documentação.
7. **Carried records:** spec 070 › Validation evidence gets the PR #57 CI line; spec 069 › Validation evidence gets the owner's AC6 report.

## Functional requirements

1. FR1 — `SECURITY.md`, `CONTRIBUTING.md` and `SUPPORT.md` exist at the repository root, in English.
2. FR2 — `SECURITY.md` names exactly one reporting channel, the GitHub private advisory, with its URL, and explicitly says not to use public issues.
3. FR3 — `SECURITY.md` promises no response deadline and states that fixes go only to the latest release, as a new tag.
4. FR4 — Private vulnerability reporting is enabled on the repository.
5. FR5 — Every command quoted in `CONTRIBUTING.md` is one listed in `CLAUDE.md` › "Commands actually available" or `docs/commands.md` (no invented commands).
6. FR6 — `SUPPORT.md` contains the roadmap's support list and the supported-versions rule identical in meaning to `SECURITY.md`.
7. FR7 — The new-issue page offers a "Report a security vulnerability" link to the advisory form.
8. FR8 — No document in the repository still says the reporting process "will be defined".

## Non-functional requirements

- Documentation only; no change under `src/`, `public/`, `bin/`, `common/`, `docker*`, `tests/`.
- Text that reflects the real project (commands, CI steps, conventions verified against `CLAUDE.md`, `ci.yml` and the code), not a generic template.
- Links resolve (relative links to existing files; absolute GitHub URLs to this repository).

## User flows

- **Security researcher:** opens the repo → Security tab shows `SECURITY.md` → "Report a vulnerability" → private advisory form; or tries "New issue" → sees the "Report a security vulnerability" link.
- **Contributor:** reads `CONTRIBUTING.md` → sets up the dev compose → runs the same checks as CI → opens an issue/PR following the conventions.
- **Restaurant operator / installer:** reads `SUPPORT.md` → knows to run the latest tag, where to ask, and what isn't promised.

## API changes

Not applicable — documentation and a repository setting only.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

- New: `SECURITY.md`, `CONTRIBUTING.md`, `SUPPORT.md`, `.github/ISSUE_TEMPLATE/config.yml`.
- Edited: `docs/security.md` (reporting section), `README.md` (Documentation table, Contributing), `docs/ROADMAP.md` (status notes), `CHANGELOG.md`, `specs/069-…md` and `specs/070-…md` (carried records).
- GitHub repository setting: Private vulnerability reporting.
- No application layer touched.

## Security considerations

- The point of the spec: give vulnerability reporters a private channel before the Community release.
- No secrets, e-mail addresses or personal data are published; the channel is GitHub's own.
- `SECURITY.md` must not describe any unfixed vulnerability; the known limitations it references are already public in `docs/security.md`.

## Backward compatibility

Not applicable to code. Issues stay open to everyone (blank issues still allowed); the only visible change on GitHub is the Security policy, the advisory form and the extra link on the new-issue page.

## Acceptance criteria

- AC1 — `ls SECURITY.md CONTRIBUTING.md SUPPORT.md` at the root lists all three; each is English.
- AC2 — `gh api repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting` returns `{"enabled":true}`.
- AC3 — `SECURITY.md` contains the URL `https://github.com/FocusEvenGitHub/GastroFlow/security/advisories/new`, the instruction not to open a public issue, no promised response time (no number of days/hours), and the "latest release only, fix as a new tag" rule with a supported-versions table.
- AC4 — `grep -rn "will be defined" docs README.md` returns nothing about the reporting process; `docs/security.md` › "Reporting a vulnerability" links to `SECURITY.md`.
- AC5 — Every shell command in `CONTRIBUTING.md` appears in `CLAUDE.md` › "Commands actually available" or `docs/commands.md` (checked one by one, listed in the evidence).
- AC6 — `SUPPORT.md` lists the six items from `docs/ROADMAP.md` › "Community support policy" and states the same supported-versions rule as `SECURITY.md`.
- AC7 — After the PR merges: the repository's Security tab shows the policy, and `https://github.com/FocusEvenGitHub/GastroFlow/issues/new/choose` shows "Report a security vulnerability" (`config.yml` is read from the default branch, so this can only be checked post-merge).
- AC8 — `README.md`'s Documentation table links the three files and › Contributing links `CONTRIBUTING.md`; `docs/ROADMAP.md` has dated status notes on both items; `CHANGELOG.md` has the entry.
- AC9 — Specs 069 and 070 carry their records (owner's AC6 report; PR #57 CI run `41221200025`).
- AC10 — CI on the PR is green (docs-only change; the line-endings step covers the new files).

## Implementation plan

1. Enable Private vulnerability reporting (`gh api -X PUT …`), verify with `GET`.
2. Write `SECURITY.md`.
3. Write `SUPPORT.md`.
4. Write `CONTRIBUTING.md`, checking every command against `CLAUDE.md`/`docs/commands.md` and the CI steps against `ci.yml`.
5. Add `.github/ISSUE_TEMPLATE/config.yml`; confirm `.gitattributes` gives `.yml`/`.md` an `eol=lf` rule (CI fails otherwise).
6. Docs pass: `docs/security.md`, `README.md`, `docs/ROADMAP.md`, `CHANGELOG.md`.
7. Carried records in specs 069 and 070.
8. Review: link check (relative paths exist), `git diff --check`, no CRLF.

## Testing and validation strategy

Documentation and a repository setting — no application behavior to test. The `/spec-plan` instruction says the project has no automated tests; that is outdated (PHPUnit + Playwright exist), but they don't apply here. Validation:

- AC1, AC3, AC4, AC6, AC8, AC9: file checks with `ls`/`grep`, quoted in the evidence.
- AC2: `gh api` `GET` before/after.
- AC5: each command in `CONTRIBUTING.md` listed with where it appears in `CLAUDE.md`/`docs/commands.md`.
- AC7: post-merge check on GitHub (owner or `gh`), recorded after the merge — may stay pending in the PR.
- AC10: CI on the PR; locally `git diff --check` and the line-endings rule.

## Rollout and rollback

Merging publishes the documents. Rollback: revert the commit; Private vulnerability reporting can be turned off with `gh api -X DELETE repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting` (any open private reports stay in the advisories list).

## Open questions

- Spec 069's AC6 has two parts: "Atualização incompleta" shown on the pilot **while** a migration was pending, and "Conectado" **after** `bin/migrate`. The owner reported the second ("rodei no cliente, está corrigido"). Whether the pilot was already on the 069 code and showed "Atualização incompleta" before the migrate decides between recording AC6 as met (069 → `Verified`) or partially met (069 stays `Implemented`). **Not blocking** — ask the owner during implementation; if unanswered, record it as partially met.

## Task checklist

- [x] Private vulnerability reporting enabled
- [x] `SECURITY.md`
- [x] `SUPPORT.md`
- [x] `CONTRIBUTING.md`
- [x] `.github/ISSUE_TEMPLATE/config.yml`
- [x] Docs pass (`docs/security.md`, `README.md`, `docs/ROADMAP.md`, `CHANGELOG.md`)
- [x] Carried records (specs 069, 070)
- [x] Review (links, line endings)

## Implementation log

- 2026-10-31 — `/spec-implement` invoked on the Draft with no blocking question: treated as approval (Draft → Approved → In Progress).
- **Private vulnerability reporting** enabled with `gh api -X PUT repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting` (owner's go-ahead in the planning answers: "GitHub privado — eu ligo via gh"). It is live on GitHub now, before the PR merges — harmless (it only adds the private form), and `SECURITY.md` already points to it once merged.
- **Deviation — Node on the host:** Proposed behavior 3 said "no PHP/Node needed on the host". The browser tests' documented command (`cd tests/e2e && npm install && npx playwright …`, `docs/commands.md:97`) needs Node, so `CONTRIBUTING.md` says "you don't need PHP or MySQL on your machine. Node is only needed for the browser tests."
- **Addition — second contact link:** `config.yml` also links `SUPPORT.md` ("Support and supported versions"), next to the security link the spec asked for. Same purpose (route people before they open an issue); blank issues stay enabled.
- `CONTRIBUTING.md` branch naming: the README said outsiders use `feature/feature-name`; `CONTRIBUTING.md` says `feature/<name>` or `fix/<name>` and notes maintainers use the spec number. README's short list kept as it was, now pointing to `CONTRIBUTING.md`.
- `docs/security.md` › "Reporting a vulnerability": rewritten with a dated note. The first wording of the note quoted the old text ("will be defined"), which made AC4's own `grep` match the note; reworded.
- **Spec 069 AC6:** the owner reported the `bin/migrate` on the pilot fixed the warning, but didn't answer whether "Atualização incompleta" was seen before it (asked in the `/spec-plan` report). Recorded as **partially met** in spec 069; its Status stays `Implemented` (the open question's fallback). The pilot is not reachable from the agent's sandbox (`curl http://100.118.154.42:8080/health/ready` → timeout, exit 28), so `/health/ready` 200 could not be re-checked.
- **Spec 070:** PR #57 CI (run `41221200025`) recorded in its Validation evidence.
- **`/spec-review` (2026-10-31):** found two false statements in `CONTRIBUTING.md` — "Every PHP file starts with `declare(strict_types=1)`" (the `public/` view scripts don't; the rule and the fixer cover `src/`, `bin/`, `tests/`) and "code … in English" (many domain and frontend comments are Portuguese, e.g. `public/cashier/app.js`). Both corrected to match `CLAUDE.md` before the PR. No other finding; AC7 (post-merge) and AC10 (CI) stay pending.
- A temporary `git add -N` (to run `git diff --check` over the new files) was undone with `git reset` — nothing is staged.

## Validation evidence

No application behavior changes, so the PHPUnit/Playwright suites don't apply; checks are on the files and the GitHub setting.

- **AC1** — `ls SECURITY.md CONTRIBUTING.md SUPPORT.md` → all three at the root (`git status`: `?? CONTRIBUTING.md`, `?? SECURITY.md`, `?? SUPPORT.md`); all written in English.
- **AC2** — before: `gh api repos/FocusEvenGitHub/GastroFlow/private-vulnerability-reporting` → `{"enabled":false}`; after the `PUT` (no output) → `{"enabled":true}`.
- **AC3** — `SECURITY.md`: `grep -c "security/advisories/new"` → `1`; line 8 "Please **don't** open a public issue, pull request or discussion about a vulnerability … This is the only reporting channel."; `grep -niE "[0-9]+ ?(business )?(day|hour|week)" SECURITY.md SUPPORT.md` → no match ("no deadlines"), and the text says "no response or fix deadline is promised"; "Supported versions" table (latest ✅, older ❌, `master` ❌) and "always ship as a new tag".
- **AC4** — `grep -rn "will be defined" docs README.md SECURITY.md SUPPORT.md CONTRIBUTING.md` → no match (exit 1); `docs/security.md` › "Reporting a vulnerability" links `../SECURITY.md`.
- **AC5** — every command in `CONTRIBUTING.md` (`grep -o 'docker compose[^`]*\|cd tests/e2e[^`]*'`) and where it is documented in `docs/commands.md`:
  - `docker compose up -d` — line 14;
  - `docker compose exec web composer install` — line 92;
  - `docker compose exec web php bin/migrate` — lines 37, 93;
  - `docker compose exec web php bin/create-admin <username>` — line 45;
  - `docker compose exec web vendor/bin/phpstan analyse` — line 98 (also `CLAUDE.md`);
  - `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` — line 99 (also `CLAUDE.md`);
  - `docker compose exec web vendor/bin/phpunit --testsuite Smoke,Unit` — line 94;
  - `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` — line 95 (also `CLAUDE.md`);
  - `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e` — line 96 (also `CLAUDE.md`);
  - `cd tests/e2e && npm install && npx playwright install chromium && npx playwright test` — line 97 (also `CLAUDE.md`).
  The CI step list in `CONTRIBUTING.md` matches `.github/workflows/ci.yml` (line endings, `composer validate --strict`, `composer audit`, PHPStan, CS-Fixer, PHPUnit unit + integration, Playwright).
- **AC6** — `SUPPORT.md` lines 23-28: the six roadmap items (security fixes, critical bug fixes, dependency updates, compatibility improvements, documentation, selected community contributions); "Supported versions" states the same rule as `SECURITY.md` (latest release only, fixes as new tags, most recent tag until `v2.0.0`, then latest `2.x` minor).
- **AC7** — *pending, post-merge:* `config.yml` is only read from the default branch. Pre-merge: `python3 -c "import yaml; print(yaml.safe_load(open('.github/ISSUE_TEMPLATE/config.yml')))"` → parses, `blank_issues_enabled: True`, two `contact_links` with the advisory and `SUPPORT.md` URLs.
- **AC8** — `README.md`: Documentation table rows for `SECURITY.md`, `SUPPORT.md`, `CONTRIBUTING.md`; › Contributing opens with a link to `CONTRIBUTING.md` and `SECURITY.md`. `docs/ROADMAP.md`: dated "Status (2026-10-31): done — spec 071" under "Open-source readiness" and "Community support policy", and closing-order item 5 marked "— spec 071". `CHANGELOG.md`: entry under `v1.9.0` › Documentação.
- **AC9** — spec 069 › Validation evidence › AC6 (owner's report, partially met); spec 070 › Validation evidence (PR #57 CI run `41221200025`, `test` + GitGuardian `SUCCESS`, merge `a72e092`).
- **AC10** — *pending (CI on the PR).* Locally: relative links in `SECURITY.md`, `SUPPORT.md`, `CONTRIBUTING.md`, `README.md`, `docs/security.md` all resolve (script: every `](path)` checked with `test -e` → no "MISSING"); `git diff --check` (with the new files) → clean; `grep -lU $'\r'` on the new files → no CRLF; `.md`/`.yml` have `eol=lf` rules in `.gitattributes`.
