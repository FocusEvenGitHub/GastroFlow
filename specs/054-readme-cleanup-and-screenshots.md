# Spec 054 — README cleanup and new screenshots

## Metadata

- Status: Implemented
- Created: 2026-10-03
- Updated: 2026-10-03
- Owner: Henry
- Related issue: Trello card #75 "README: enxugar duplicações e novos prints" (Backlog); fits `docs/ROADMAP.md` › `v1.9.0` › "Documentation structure" and the v1.9 exit gate item "README reflects actual functionality"
- Related branch: `054`

## Context

The user asked on 2026-10-03 for the `README.md` to be improved: remove what doesn't belong there because it already lives in another `.md`, and take new screenshots of the app.

`README.md` is 409 lines. Its own "Documentation" table (`README.md:80-90`) already declares it the *entry point* and says "depth lives alongside it, by topic" — but several sections repeat, at length, content whose real home is `CHANGELOG.md`, `docs/ROADMAP.md`, `docs/architecture.md`, `docs/technical-decisions.md`, `docs/COMMIT_CONVENTION.md` or `CLAUDE.md`. Those copies are exactly the ones that went stale (see "Current behavior").

The four screenshots (`public/assets/img/tela_*.png`) were last committed on 2026-07-11, before the admin UI redesign (spec 051, `v1.8.1`), "Monte Seu Prato" (`v1.7.1`) and the kitchen side panel. They no longer show the current app.

Docs-only work, outside any code path: no PHP, JS, SQL, route or dependency changes. Per the release workflow in `CLAUDE.md`, it would ship as an out-of-milestone patch (`v1.8.4`) unless the user prefers to fold it into `v1.9.0`'s documentation work — see "Open questions".

## Problem

1. **Duplicated content that drifts.** Sections copy material from other docs, and the copies are already wrong:
   - `README.md:110` — "Kitchen live updates run over Server-Sent Events backed by a signal file". False since spec 041 (`v1.8.0`); `docs/architecture.md:88-104` and `docs/technical-decisions.md:13` already record the MySQL-backed `EventPublisher`.
   - `README.md:183` (Technical decisions) — lists "Signal-file SSE" as a current pick; `docs/technical-decisions.md:13` has it struck through.
   - `README.md:135` — says CI runs "`composer install` + `vendor/bin/phpunit`". `.github/workflows/ci.yml` now runs line-ending check → `composer validate` → `composer audit` → PHPStan → PHP-CS-Fixer → unit → integration → Playwright.
   - `README.md:130` (process diagram) and `README.md:154` — describe validation as "curl / browser / php -l", missing the test suites and `/spec-review`; `docs/ROADMAP.md:143-171` has the current flow.
   - `README.md:388` — "Releases follow Semantic Versioning", contradicting `CLAUDE.md`'s release workflow (tag number comes from the `docs/ROADMAP.md` milestone).
   - `README.md:188-235` (Roadmap) — ~50 lines re-telling every release since `v1.6.0`, one paragraph per spec. This is `CHANGELOG.md`'s job; every new release forces a second, parallel edit here. "Future ideas" (`:233-235`) lists one item out of the whole `v1.9.0` milestone.
2. **Outdated screenshots.** The images show the pre-redesign admin (no shared sidebar/toolbar), an older cashier/kitchen, and an older reports page.

## Goals

- `README.md` keeps only what a newcomer needs on the first page: what GastroFlow is, screenshots, a short architecture/stack summary, how to install and run it, and links to where each deeper topic lives.
- Every topic removed from the README is reachable through a link to the doc that owns it — nothing is deleted without a home.
- No statement left in the README is false against the code/CI as of the merge.
- New screenshots of the current UI replace the four old ones.

## Non-goals

- Rewriting `docs/architecture.md`, `docs/technical-decisions.md`, `docs/ROADMAP.md` or `CHANGELOG.md` (only the docs pass from `CLAUDE.md` — fix anything this work makes false).
- Creating the new docs listed in `v1.9.0` › "Documentation structure" (Installation, Printing, Backup & Restore, Upgrading, Troubleshooting…). This spec only trims and corrects the README.
- Translating the README (stays in English, like the rest of `docs/`).
- Any change to application code, the database, or dependencies.
- Committing the screenshot-capture script (one-off, kept in the session scratchpad).

## Current behavior

Confirmed by reading `README.md` (409 lines) section by section:

| Section (lines) | What it holds | Where that content already lives |
|---|---|---|
| Header, badges (1-17) | Logo, tagline, 4 badges | — (README's own) |
| Contents (21-39) | TOC | — |
| Screenshots (43-68) | 4 images, `public/assets/img/tela_{cashier,kitchen,admin,relatorio}.png`, ~2500×1250 PNG, dated 2026-07-11 | — |
| Overview (72-76) | 2 paragraphs | — |
| Documentation (80-90) | Table of docs | — |
| Architecture (94-113) | Mermaid + 3 bullets; bullet at `:110` is stale (signal file) | `docs/architecture.md` |
| Engineering process (117-135) | 4 bullets + mermaid + CI paragraph; `:130`, `:135` stale | `specs/README.md`, `docs/ROADMAP.md` › Development Workflow, `.github/workflows/ci.yml` |
| AI-assisted development (139-155) | ~17 lines | `CLAUDE.md`, `.claude/skills/*` |
| Tech stack (159-172) | Table | `CLAUDE.md` › Stack (shorter) |
| Technical decisions (176-184) | 5 picks; `:183` stale | `docs/technical-decisions.md` (README links in at `docs/technical-decisions.md:3` via `#technical-decisions`) |
| Roadmap (188-235) | ~50 lines of per-release, per-spec history | `CHANGELOG.md`, `docs/ROADMAP.md` |
| Learnings (239-246) | 4 bullets | — (README's own, personal) |
| Project philosophy (250-252) | 1 paragraph | overlaps "Overview" `:76` and "AI-assisted development" |
| Getting started (256-320) | Install, Composer, backup/restore, line endings (Windows) | `CLAUDE.md` › Commands; **`CLAUDE.md:44` links to README's "Line endings (Windows)" section** |
| Using the app (324-333) | 4 bullets + admin-login note (repeats Installation) | — |
| API (337-380) | Link to `/api/docs` + cURL examples | `public/api/docs` (OpenAPI) |
| Commit convention & releases (384-388) | 2 paragraphs; `:388` stale (SemVer) | `docs/COMMIT_CONVENTION.md`, `CLAUDE.md` › Release workflow |
| Contributing (392-399) | 4 steps; branch `feature/...` | `docs/COMMIT_CONVENTION.md`; the repo itself uses one branch per spec number |
| Author (403-409) | Links | — |

Inbound links that must keep working: `docs/technical-decisions.md:3` → `README.md#technical-decisions`; `CLAUDE.md:44` → README "Line endings (Windows)"; `CLAUDE.md:64` expects a version mention in `README.md` to bump on release.

Screenshot pages (plain PHP views outside Slim, per `CLAUDE.md`): `public/cashier/index.php`, `public/kitchen/index.php`, `public/admin/index.php` (menu), `public/admin/reports.php`; also present and not screenshotted today: `settings.php`, `ingredients.php`, `logs.php`, `audit-log.php`. Admin pages require a JWT login (`public/admin/auth.js`).

## Proposed behavior

### README structure after the change

1. **Header** — logo, tagline, badges (unchanged).
2. **Contents** — updated to the new section list.
3. **Screenshots** — new images (see below).
4. **Overview** — current text, with "Project philosophy" merged into it as one sentence (section removed).
5. **Documentation** — the existing table, extended so every removed topic has a row: `CHANGELOG.md` (release history), `docs/ROADMAP.md` (milestones), `docs/COMMIT_CONVENTION.md` (commits and releases), `specs/README.md` (spec lifecycle), `CLAUDE.md` (AI rules and the commands that exist).
6. **Architecture** — mermaid kept, bullets corrected (MySQL-backed `EventPublisher` for realtime), link to `docs/architecture.md`.
7. **Tech stack** — table kept (it's the quick answer a newcomer looks for).
8. **Technical decisions** — kept as a short list (anchor `#technical-decisions` must survive), "Signal-file SSE" replaced by the current choice, link to `docs/technical-decisions.md`.
9. **How this project is built** — "Engineering process" + "AI-assisted development" condensed into one short section (target ≤ 15 lines): specs before code, the lifecycle, CLAUDE.md, the CI pipeline in one line, with links. The process mermaid is either corrected to `docs/ROADMAP.md`'s current flow or removed.
10. **Roadmap** — replaced by ≤ 5 lines: current version, current milestone (`v1.9.0 — Community Productization`), links to `CHANGELOG.md` and `docs/ROADMAP.md`. The per-release history is removed (it's all in `CHANGELOG.md`).
11. **Learnings** — kept (personal, owned by the README).
12. **Getting started** — Installation, Managing dependencies, Backup & restore, Line endings (Windows) kept (the actionable part; `CLAUDE.md:44` links here). Add a one-line pointer to `CLAUDE.md` › Commands for the full command list (migrate, worker, tests, PHPStan…).
13. **Using the app** — kept, duplicated admin-login note shortened to a reference to Installation.
14. **API** — kept as is (link + cURL examples).
15. **Contributing** — commit convention link kept; "Commit convention & releases" folded in as one line pointing to `docs/COMMIT_CONVENTION.md` and the release rule; branch naming aligned with practice (see "Open questions").
16. **Author** — unchanged.

### Screenshots

- Captured from the **e2e instance on port 8081** (`docker-compose.e2e.yml`, database `restaurant_test`) populated with fictional orders, never from 8080 — so no real restaurant data appears in a public repo (see "Open questions").
- Captured with a one-off Playwright script run from `tests/e2e/` (already installed — no new dependency), saved in the session scratchpad, not committed. Viewport 1440×900 (desktop), `deviceScaleFactor: 1`, same light theme in every image.
- Same four file names overwritten (`tela_cashier.png`, `tela_kitchen.png`, `tela_admin.png`, `tela_relatorio.png`) so the README paths don't change; captions updated to what each image actually shows.
- Each PNG ≤ 500 KB (the current ones are ~2500 px wide; the new ones are smaller by design).

## Functional requirements

1. `README.md` has no section whose content is a paragraph-by-paragraph copy of `CHANGELOG.md` (no per-spec release history).
2. Every topic removed from `README.md` has a link from the README's "Documentation" table or from the section that replaced it.
3. `README.md` contains no claim contradicted by the code/CI at merge time; specifically the five stale claims listed in "Problem" item 1 are gone or corrected.
4. The anchors `#technical-decisions` and the "Line endings (Windows)" heading still exist.
5. Every relative link in `README.md` resolves to an existing file.
6. The four `public/assets/img/tela_*.png` files are replaced with captures of the current UI from the 8081 test instance.

## Non-functional requirements

- Shorter: `README.md` ends at ≤ 250 lines (409 today).
- No secrets, real customer data, real tokens or real usernames visible in any screenshot.
- Images ≤ 500 KB each.

## User flows

- **Newcomer on GitHub:** opens the repo → sees what it is, current screenshots, how to run it in < 1 screen of scrolling past the screenshots, and a table pointing to every deeper doc.
- **Maintainer cutting a release:** updates `CHANGELOG.md` and the single version line in the README — no more re-writing a release summary in the README's Roadmap.

## API changes

Not applicable — documentation and images only.

## Data model and migrations

Not applicable — no schema change. The screenshots use the existing `restaurant_test` database; populating it with fictional orders goes through the normal API (`POST /api/orders`) or the UI, never ad hoc SQL against the development database.

## Architecture and affected components

- `README.md` — rewritten per "Proposed behavior".
- `public/assets/img/tela_cashier.png`, `tela_kitchen.png`, `tela_admin.png`, `tela_relatorio.png` — replaced.
- Docs pass (`CLAUDE.md` › Release workflow): `docs/technical-decisions.md:3` (anchor still valid?), `CLAUDE.md:44` (heading still valid?), `docs/architecture.md`, `specs/000-project-baseline.md` — checked; edited only if this change makes something there false.
- `CHANGELOG.md` — one entry.
- No PHP/JS/SQL file touched.

## Security considerations

- Screenshots come from the 8081 test instance with fictional data; the admin login used for them is a throwaway test account in `restaurant_test` and must not appear in any image or in the README.
- `.env` is never read; the 8081 instance is started with the documented `docker-compose.e2e.yml` command.
- The capture script is not committed, so no test credentials end up in the repo.

## Backward compatibility

- Image paths unchanged, so anything linking to them keeps working.
- Inbound anchors preserved (FR4).
- Readers who relied on the README's Roadmap history find it in `CHANGELOG.md` (linked).

## Acceptance criteria

1. `wc -l README.md` ≤ 250.
2. `grep -n "signal file\|Signal-file" README.md` returns nothing.
3. `grep -n "Semantic Versioning" README.md` returns nothing (or appears only together with the milestone-based rule, not as the release policy).
4. The README's CI description names the same steps as `.github/workflows/ci.yml` (or links to it without listing a subset as if it were complete).
5. `grep -c "spec 0[3-5][0-9]" README.md` = 0 in the Roadmap section (no per-spec release history).
6. `grep -n "^## Technical decisions\|^### Line endings (Windows)" README.md` returns both headings.
7. A link check — every `](path)` target in `README.md` that isn't `http(s)://` or `#…` exists on disk — reports 0 missing (run via a one-line shell loop, output recorded in "Validation evidence").
8. `git log -1 --format=%cd -- public/assets/img/tela_*.png` shows the new commit; each file is ≤ 500 KB (`ls -l`); opening each image shows: cashier with items in the cart, kitchen with ≥ 2 pending orders and the side panel, admin menu with the spec-051 sidebar and toolbar, reports with populated charts.
9. Visual check of each image: no real names, tokens or usernames visible.
10. The README renders on GitHub without broken images or broken mermaid (checked on the PR's "Files changed" rich diff).

## Implementation plan

1. Create branch `054` from `master`.
2. Start the 8081 instance (`docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d web-e2e`), create a throwaway admin in `restaurant_test`, add fictional menu-backed orders through the API/UI (a few pending, a few done across different days so reports have data).
3. Write the one-off Playwright capture script in the scratchpad (login via the admin login page, navigate each page, `page.screenshot`), run it from `tests/e2e/`, review the four PNGs, overwrite the files in `public/assets/img/`.
4. Rewrite `README.md` per "Proposed behavior" — one section at a time, checking each fact against its owning doc / the code before keeping it.
5. Run the acceptance checks 1-7 and record the output.
6. Docs pass: check inbound links and `docs/architecture.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` for anything now false; add the `CHANGELOG.md` entry.
7. `/spec-review`, open the single PR, check AC 10 on the PR, update the Trello card.

## Testing and validation strategy

Docs-only change: no PHPUnit/Playwright suite exercises the README. The existing CI still runs on the PR (and its line-ending step covers the rewritten `README.md`). Validation is the shell checks in AC 1-7 (outputs pasted in "Validation evidence"), a visual review of the four images (AC 8-9), and the GitHub rendering of the PR (AC 10).

## Rollout and rollback

Merge the PR. Rollback is `git revert` of the merge commit — restores the old README and images; nothing else depends on them.

## Open questions

1. **Screenshot data source** (non-blocking, defaulted): proposed 8081 + `restaurant_test` with fictional data. If you'd rather capture from 8080 (development DB), confirm it holds no real client data.
2. **Which screens** (non-blocking, defaulted): the same four (cashier, kitchen, admin menu, reports). Add settings/ingredients/audit log? Each extra one makes the README longer.
3. **Release** (non-blocking, defaulted): out-of-milestone patch `v1.8.4`, or no tag (docs only, rides into `v1.9.0`)? Default: entry under "Não lançado", tag decided later.
4. **Contributing branch name** (non-blocking): the README says `feature/feature-name`; the maintainer uses one branch per spec number (`054`). Default: describe spec-number branches for spec work, keep `feature/…` acceptable for outside contributors.
5. **Learnings / AI-assisted development** (non-blocking): these are portfolio content, not newcomer content. Default: keep "Learnings" as is, condense AI-assisted development into "How this project is built". Say if you want either kept intact or removed.

## Task checklist

- [x] Branch `054` created
- [x] Screenshot instance up with fictional data (8082 + `restaurant_screenshots`, not 8081 — see log)
- [x] Four screenshots captured and reviewed
- [x] README rewritten
- [x] Acceptance checks 1-9 run and recorded
- [x] Docs pass + CHANGELOG entry
- [x] `bin/seed-demo` (added at the user's request) + `.gitattributes` / `.php-cs-fixer.dist.php` / `CLAUDE.md` / README
- [ ] /spec-review
- [ ] PR opened, AC 10 checked
- [x] Trello card updated (investigation/spec/implement items ticked; PR/merge items pending)

## Implementation log

- **2026-10-03 — Approval.** `/spec-implement` invoked by the user ("do it") with no blocking open questions: `Draft → Approved → In Progress`. Open questions 1–5 taken at their defaults, except as noted below.
- **2026-10-03 — Screenshot source changed from 8081 to a dedicated 8082 instance (deviation from "Proposed behavior").** `restaurant_test` turned out to be full of integration-test leftovers (thousands of duplicate menu items, e.g. dozens of "Arroz Branco" rows), which would have made every screenshot look broken. Instead: a new database `restaurant_screenshots` (created through the `db` container's own root credentials, the same mechanism `bin/backup-db` uses — host `.env` never read), loaded from `common/sql/001_schema.sql` + `bin/migrate`, served by a third container `restaurant_web_shots` (`docker compose run` of the `web-e2e` service with `MYSQL_DATABASE=restaurant_screenshots`, port 8082). So the screenshots show a fresh install's real seed menu. Throwaway admin `demo` exists only in that database.
- **2026-10-03 — Incident: the schema load first ran against the development database, then was undone.** `common/sql/001_schema.sql` starts with `CREATE DATABASE IF NOT EXISTS restaurant; USE restaurant;`, so piping it into `mysql … restaurant_screenshots` switched to `restaurant` and ran its `INSERT`s there: **6 duplicate categories (ids 7–12) and 63 duplicate menu items (ids 82–144, `created_at = 2026-10-03 00:24:03`)** were added to the dev database. The `CREATE TABLE IF NOT EXISTS` statements did nothing (tables already existed). Nothing referenced the new rows (`order_items`, `order_item_components`, `dish_components`, `item_ingredients`: 0 each). A backup was taken first (`backups/gastroflow-restaurant-20261003-002514.sql.gz`), the user approved deleting only those rows, and the counts were confirmed back to the original 73 items / 6 categories. The load was then redone with the two `restaurant` lines rewritten to `restaurant_screenshots` via `sed`. **Follow-up, out of this spec's scope:** the hardcoded `USE restaurant;` makes `001_schema.sql` unsafe to load into any other database (and likely explains the duplicate seed rows in `restaurant_test`) — recorded as a Backlog card on Trello, not fixed here.
- **2026-10-03 — Fictional data.** 54 orders created through the real API (`POST /api/orders`, `print_ticket: false`) and completed, then — in `restaurant_screenshots` only — backdated with SQL across 2026-08-20 → 2026-10-02 at lunch hours with 8–25 min prep times (the API has no way to create past orders), plus 5 pending orders for today. Seeding script kept in the session scratchpad. Planned 140 historical orders; stopped at ~54 because each request took several seconds under Docker Desktop.
- **2026-10-03 — Capture script location (deviation).** Playwright resolves `@playwright/test` from `tests/e2e/node_modules` and only discovers files under `tests/e2e/specs/`, so the one-off script lived temporarily at `tests/e2e/specs/shots.tmp.spec.ts` instead of the scratchpad; deleted after capture, never committed.
- **2026-10-03 — README length.** First rewrite came to 305 lines. To reach ≤ 250: the 2×2 screenshot HTML table became a Markdown table, "Managing dependencies" folded into one sentence under Installation, the line-endings prose shortened (heading and commands kept), the cURL examples reduced to menu / create order / login / admin call (the full list is in `/api/docs`), and the hand-written "Contents" list removed (GitHub renders an outline for every README). The create-order cURL example dropped its stale `table_number` field (not a field `OrderValidator` accepts; the server assigns the number).

- **2026-10-03 — Capture method changed from Playwright to Chrome (deviation).** The Playwright run first captured pages before their API calls returned (the 8082 API answered in 3–14 s under Docker Desktop), and the second, slower-waiting run made the user's machine freeze; the user stopped it and asked for a simpler method. The four images were then taken in the user's Chrome through the Claude-in-Chrome extension (window 1440 px wide → 1568×744 capture): admin login done by a `fetch('/api/login')` with the throwaway `demo` account from the page itself, no form typing; cashier cart filled by clicking "Adicionar" via page JS, order **not** sent; report range set to 2026-08-20 → 2026-10-03. The mouse cursor was parked in the bottom-right corner so it stays out of frame.
- **2026-10-03 — Images are JPEG, not PNG (deviation from FR6 / "Same four file names").** The Chrome tool saves JPEG. Converting would have needed another tool or dependency for no visible gain, so the files are now `tela_*.jpg` (69–90 KB each, against 500 KB allowed); the README paths and the `.gitignore` whitelist (`/public/assets/img/*` is ignored except named files) were switched from `.png` to `.jpg`, and the four old PNGs deleted. Nothing else in the repo references the PNG names except historical spec 049's file list, left as-is (it records that moment).
- **2026-10-03 — Reports caption.** Changed to "Sales summary, sales per day, main dishes sold" — what the captured viewport actually shows (peak hours / prep time are further down the page).
- **2026-10-03 — Cleanup.** Container `restaurant_web_shots` removed and the 8081 container stopped. **Left in place:** the `restaurant_screenshots` database (only fictional data and the `demo` account) — dropping it is a destructive DB operation this repo's rules reserve for the user (`DROP DATABASE restaurant_screenshots;` via root in the `db` container) — and the dev-DB backup `backups/gastroflow-restaurant-20261003-002514.sql.gz` (gitignored).

- **2026-10-03 — Scope extension requested by the user: `bin/seed-demo`.** After the first review the user asked to keep the screenshot data "como seed executável para o dia atual". Added `bin/seed-demo` (new file; listed in `.gitattributes` and `.php-cs-fixer.dist.php`, both of which enumerate `bin/*` scripts explicitly; documented in `CLAUDE.md` › Commands and `README.md` › Installation; CHANGELOG "Novidades"). Design choices: orders go through `OrderRepository::createOrder()` (real pricing, numbering and name snapshots — no Service layer, so no print jobs and no realtime events), then only dates/status are adjusted with the query builder; historical orders get a manual per-day `order_number` so today's counter isn't consumed; fixed seed (`mt_srand(54)`) so runs are reproducible for a given date; **refuses to run when the database already has any order**, so it can't pollute a restaurant in use. Not added: options for counts/date range, or creating an admin (`bin/create-admin` already does that) — add when someone needs them. This turns AC 11 below into a new criterion, added at the user's request rather than changed silently.
- **2026-10-03 — Secret exposed in tool output (incident).** The first `bin/seed-demo` test run failed with an access-denied PDO exception whose stack trace printed the `MYSQL_PASSWORD` value from the host `.env` into the session output — the run's output was not filtered. Cause of the failure: a quoting mistake in the `GRANT` for the temporary database. Reported to the user immediately; later runs filtered stack traces. The value was not written to any file.
- **2026-10-03 — `.gitignore`'s `.codegraph/` line rides along.** It was added before this spec started, by the user's global SessionStart hook (every project must ignore `.codegraph/`). The user agreed to ship it in this PR.
- **2026-10-03 — `backups/` stays gitignored (answer to the user).** A backup is a full dump of the database — users with password hashes, orders, settings — and this repository is public on GitHub; spec 045 made `backups/` ignored for exactly that reason. The dev-DB backup taken during the incident is kept on disk, not committed.
- **2026-10-03 — Leftover databases.** Besides `restaurant_screenshots`, a second throwaway database `restaurant_seedcheck` (fresh schema + migrations + one `bin/seed-demo` run) now exists, created only to prove the seed on a clean install. The user then asked to drop both; the repository's AI-safety hook blocked the `DROP DATABASE` even with that approval, and it was not routed around — the user runs it (command in the final report).
- **2026-10-03 — `bin/seed-demo` allowed on development databases (user request).** Guard is now "refuse if any order exists **and** `Settings::getAppEnv() !== 'development'`" (unset `APP_ENV` = production). On a database that already has orders, the unique `(business_date, order_number)` key (migration 012) would collide twice: with today's orders at insert time (because `createOrder()` always writes `business_date = today`) and with the target day's orders after the move. So each historical order is created with a provisional number `seed-<i>` and receives its final number — the day's `MAX(CAST(order_number AS UNSIGNED)) + 1` — in the same `UPDATE` that moves its date.
- **2026-10-03 — Version badge showed "dev" on the e2e instance (user report, fixed here).** `public/assets/js/version-badge.js` shows `GET /version`, which `VersionController::describe()` builds from `git describe --tags --always` against the repo's `.git` and falls back to `"dev"` when that directory isn't there. `docker-compose.yml` bind-mounts `.git` read-only into `web`; `docker-compose.e2e.yml`'s `web-e2e` (and anything run from it, like the 8082 screenshot instance) didn't. Added the same `:ro` mount. The badge shows the tag description, not a branch name — unchanged by design. The README screenshots were taken before this fix and carry a faint "dev" in the bottom-right corner; not retaken. Also corrected an earlier claim in this session: not every `bin/*` script is `100755` (`bin/create-admin` is `100644`); `bin/seed-demo` was added as `100755` at the user's request.

## Validation evidence

Docs-only change: no PHPUnit/Playwright test covers the README. CI's line-ending step will check the rewritten files on the PR. Checks run on 2026-10-03 against the working tree (one shell call, output verbatim):

| AC | Command | Result |
|---|---|---|
| 1 | `wc -l < README.md` | `247` (≤ 250) ✅ — `250` after the `bin/seed-demo` lines were added to Installation |
| 2 | `grep -n -i "signal file\|signal-file" README.md` | no match ✅ |
| 3 | `grep -n "Semantic Versioning" README.md` | no match ✅ |
| 4 | `grep -n "CI on every push" README.md` | line 113 lists line-ending check, `composer validate`, `composer audit`, PHPStan, PHP-CS-Fixer, unit, integration (MySQL), Playwright — the same steps as `.github/workflows/ci.yml` ✅ |
| 5 | `sed -n '/^## Roadmap/,/^## Learnings/p' README.md \| grep -c "spec 0[3-5][0-9]"` | `0` ✅ |
| 6 | `grep -n "^## Technical decisions\|^### Line endings (Windows)" README.md` | `97:## Technical decisions`, `174:### Line endings (Windows)` ✅ |
| 7 | loop over every non-http, non-`#` `](…)` target with `[ -e ]` | 16 targets, all `ok`, 0 `MISSING` ✅ |
| 8 | `ls -l public/assets/img/tela_*.jpg` + visual review | 86 620 / 90 152 / 68 673 / 77 657 bytes (≤ 500 KB). Cashier: 5 items in the cart, "Pratos Principais" tab. Kitchen: 5 pending orders with the ingredient side panel. Admin: spec-051 sidebar + search/category toolbar, "Pratos Principais" filter. Reports: 54 orders, sales-per-day chart, main dishes table ✅ — format is JPEG, not the PNG FR6 named (see log) |
| 9 | visual review of the four images | only fictional first names and the throwaway `demo` username; no tokens, emails or real data ✅ |
| 10 | GitHub rendering on the PR | **not done yet** — no PR opened |

| 11 | `bin/seed-demo` on a fresh install (added criterion — see log) | see below ✅ |

**AC 11 — `bin/seed-demo`** (2026-10-03, against `restaurant_seedcheck`: 001 schema + `bin/migrate` → 21 migrations `[OK]`):
- Run: `docker compose exec -T -e MYSQL_DATABASE=restaurant_seedcheck web php bin/seed-demo` → `✓ 60 pedidos concluídos (últimos 45 dias) e 5 pendentes de hoje criados em "restaurant_seedcheck".`
- Second run on the same DB → `Erro: o banco "restaurant_seedcheck" já tem pedidos. bin/seed-demo só roda em instalação nova.` Same refusal against `restaurant_screenshots`.
- Data: `done` 60 orders, business dates 2026-08-19 → 2026-10-02, prep 8–25 min, hours 11–14; `pending` 5 on 2026-10-03, numbers `1,2,3,4,5`; per-day numbers sequential (e.g. 2026-09-30 → `1,2,3`); 138 order items, total R$ 3 275,90, 0 without a name snapshot; `order_number_counters` only has today (`last_number` 5); `jobs` 0; `restaurant.orders` (dev) still 94 — untouched.
- `php -l bin/seed-demo` → no syntax errors; `vendor/bin/phpstan analyse bin/seed-demo` → `[OK] No errors`; `vendor/bin/php-cs-fixer fix --dry-run` → 0 of 1 fixable, and 0 of 110 with the config's full file list (now including `bin/seed-demo`); `git check-attr eol -- bin/seed-demo` → `lf`.
- Development allowance (2026-10-03, against `restaurant_screenshots`, which already had 59 orders): `-e APP_ENV=production` → `Erro: o banco "restaurant_screenshots" já tem pedidos. bin/seed-demo só roda em instalação nova ou com APP_ENV=development.`; `-e APP_ENV=development` → `✓ 60 pedidos concluídos … e 5 pendentes de hoje …`. After: 124 orders, 0 with a provisional `seed-%` number, 0 days with a repeated number (`GROUP BY business_date HAVING COUNT(*) <> COUNT(DISTINCT order_number)` → empty); a day that already had numbers continued after them (`2026-09-30` → `18,41,49,50,51,52`), today → `1…10`. `php -l`, PHPStan and PHP-CS-Fixer clean again after the change.
- `git add --chmod=+x bin/seed-demo` → `git ls-files -s` shows `100755`.
- Version badge: after adding the `.git` mount, `docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d web-e2e` → `curl localhost:8081/version` = `{"success":true,"version":"v1.8.3"}` (was `"dev"`), same as `localhost:8080/version`; `web-e2e` stopped afterwards.
- First attempt failed with `TypeError` (the random-pick closure was typed `int` but also picks the dining option string) — fixed to `mixed`, then the run above.

Dev-DB incident check (see log): after the cleanup, `SELECT COUNT(*) FROM restaurant.menu_items` → `73`, `categories` → `6`, matching the counts before the incident; `restaurant.users WHERE username='demo'` → `0`.
