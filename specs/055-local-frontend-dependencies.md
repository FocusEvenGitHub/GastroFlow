# Spec 055 — Local frontend dependencies (no CDN, no build)

## Metadata

- Status: Implemented
- Created: 2026-10-03
- Updated: 2026-10-03
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Local frontend dependencies" (first item of the milestone); prerequisite for "LAN operation without internet" and the v2.0 verification matrix's "Network" block; Trello card #21
- Related branch: `055`

## Context

`v1.8.0` and its patches (`v1.8.1`–`v1.8.3`) are released; `v1.9.0` — Community Productization — is the open milestone and none of its items has started. Its first item asks to remove the runtime dependency on third-party CDNs so that, after installation, restaurant operation does not need internet access.

The roadmap text also says "Introduce an appropriate frontend build process such as Vite". **This spec deliberately drops that part** (user decision, 2026-10-03):

- `CLAUDE.md` states that `public/` has **no build step** and nothing in it depends on Node; the only Node in the repository is `tests/e2e/`, isolated on purpose (spec 040). `docs/technical-decisions.md` (spec 051 row) records the same no-build rule as a chosen trade-off.
- The goal of the item is *offline operation*, not bundling. Every library in use already ships a ready-to-serve minified build; copying those files into `public/` achieves the goal with zero new tooling, zero new runtime dependencies, and nothing extra to install on the restaurant's machine.
- A bundler would add a Node toolchain to every install/upgrade (or a committed build output, which is the same vendored files with more steps), for no functional gain at the current size of the frontend (three Alpine pages + six admin pages, no modules, no TypeScript).

If a build step ever becomes necessary (e.g. the frontend grows into modules), it gets its own spec; this one does not prepare for it.

## Problem

Every operational screen loads its core libraries from third-party CDNs at page load. Without WAN access (internet outage, restaurant LAN without uplink), the cashier, kitchen and admin pages load their HTML but **Alpine.js never initializes** and Bootstrap's CSS/JS and the icons are missing: the screens are unusable, even though the app server, MySQL and the printer are all on the local network and fine.

Additionally, `alpinejs@3.x.x` and `tom-select@2` are floating version ranges: the code that runs in production can change on any day without a commit.

## Goals

- Serve every third-party asset used by the cashier, kitchen and admin screens from `public/vendor/`, pinned to an exact version.
- After the change, those screens make **zero requests to hosts other than the GastroFlow server itself**.
- Keep `public/` free of any build step or Node dependency.
- Record, for each vendored library, its exact version, source URL and license, so it can be upgraded deliberately later.

## Non-goals

- No Vite, npm, bundler, `package.json` or build output in `public/` (see Context).
- No library upgrades beyond pinning what is used today (see FR-2 for the two floating ranges).
- No shared frontend API client / connection-status UI — those are the separate v1.9 items "Shared frontend infrastructure" and "Connection awareness".
- No full "LAN operation without internet" validation (printing, database, etc.) — that is its own v1.9 item; this spec only covers the assets.
- `public/api/docs/index.html` (Swagger UI from unpkg) is **out of scope by default**: it is developer API documentation, not a restaurant-operation screen. See Open questions (non-blocking).
- No Content-Security-Policy header (none exists today; adding one is a separate security decision).

## Current behavior

Confirmed by `grep` over `public/` (2026-10-03, `master` @ `ec4b2e4`). External asset references:

| File | Asset | Host / version |
|---|---|---|
| `public/admin/_partials/head.php:33` | Bootstrap CSS | jsdelivr `bootstrap@5.3.0` |
| `public/admin/_partials/head.php:34` | Font Awesome CSS (+ its webfonts, loaded relatively by the CSS) | cdnjs `font-awesome/6.4.0` |
| `public/admin/_partials/head.php:45` | Bootstrap bundle JS | jsdelivr `bootstrap@5.3.0` |
| `public/admin/_partials/head.php:51` | Alpine.js | unpkg `alpinejs@3.x.x` (**floating**) |
| `public/admin/index.php:5-6` | Tom Select JS + bootstrap5 CSS | jsdelivr `tom-select@2` (**floating**) |
| `public/admin/reports.php:4` | Chart.js UMD | jsdelivr `chart.js@4.4.0` |
| `public/cashier/index.php:13,15,456,458` | Bootstrap CSS/JS, Font Awesome, Alpine | same as above |
| `public/kitchen/index.php:12,13,506,507` | Bootstrap CSS/JS, Font Awesome, Alpine | same as above |
| `public/assets/css/style.css:1` | Inter font (`@import` Google Fonts, weights 400–800) | fonts.googleapis.com / fonts.gstatic.com |
| `public/api/docs/index.html:12,75` | Swagger UI CSS/JS | unpkg `swagger-ui-dist@5` (out of scope) |

Other facts relevant to the change:

- `public/.htaccess` serves any existing file directly, so files under `public/vendor/` are served by Apache with no routing change. `tests/e2e/router.php` reproduces the same rule for `php -S`.
- `public/admin/_partials/head.php:17-23` — `$asset()` appends `?v=<mtime>` to any path starting with `/`; external URLs pass through unchanged. Admin `$pageScripts` entries go through it.
- No `integrity=`/`crossorigin` attributes exist anywhere in `public/` (count: 0).
- `.gitattributes` has `* text=auto` plus explicit `eol=lf` for `*.js`, `*.css`; there is no rule for font files (`*.woff2`), and CI fails on CRLF/mixed text blobs (spec 049).
- `style.css:36` already declares a fallback stack (`'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`), so today an offline browser silently falls back to a system font.
- No documentation mentions the CDNs (`docs/`, `README.md`, `specs/000-project-baseline.md`); only the roadmap item does.

## Proposed behavior

All in-scope assets are copied, unmodified, from the exact CDN URL of the pinned version into a **versioned directory** under `public/vendor/`, and every reference is rewritten to a root-relative local path. Proposed layout (exact file set confirmed during implementation):

```text
public/vendor/
├── README.md                          # inventory: lib, version, source URL, license
├── bootstrap-5.3.0/
│   ├── css/bootstrap.min.css
│   └── js/bootstrap.bundle.min.js
├── fontawesome-free-6.4.0/
│   ├── css/all.min.css                # references ../webfonts/ relatively — layout kept
│   └── webfonts/*.woff2 (+ *.ttf the CSS references)
├── alpinejs-3.X.Y/cdn.min.js          # exact 3.x pinned at implementation time
├── tom-select-2.X.Y/
│   ├── js/tom-select.complete.min.js
│   └── css/tom-select.bootstrap5.min.css
├── chartjs-4.4.0/chart.umd.min.js
└── inter-X.Y/                         # Inter webfont + OFL license, local @font-face
```

The version is part of the directory name, so a future upgrade is a new directory and a path change — browsers can never mix an old cached file with a new page, and no cache-busting is needed for vendor files.

The Google Fonts `@import` in `style.css` is replaced by a local `@font-face` declaration (single variable-weight Inter woff2 covering 400–800, or the per-weight files — decided at implementation, recorded in the log).

## Functional requirements

1. FR-1 — Every asset listed in "Current behavior" (except Swagger UI) is served from `public/vendor/` and referenced by a root-relative path (`/vendor/...`).
2. FR-2 — Each vendored library is pinned to one exact version. Where today's reference is fixed (`bootstrap@5.3.0`, `font-awesome/6.4.0`, `chart.js@4.4.0`) that same version is used. For the floating ranges (`alpinejs@3.x.x`, `tom-select@2`) the version used is the one the CDN resolves those ranges to on the implementation date — i.e. what production is running today — recorded exactly.
3. FR-3 — Vendored files are byte-identical to the upstream distribution file at the recorded source URL (no edits, license headers preserved). The SHA-256 of each file is recorded in `public/vendor/README.md`.
4. FR-4 — `public/vendor/README.md` lists, per library: name, exact version, upstream URL of each file, license (Bootstrap MIT, Alpine.js MIT, Chart.js MIT, Tom Select Apache-2.0, Font Awesome Free — icons CC BY 4.0 / fonts SIL OFL 1.1 / code MIT, Inter SIL OFL 1.1), and the upgrade procedure in 3–4 lines.
5. FR-5 — Font Awesome icons render from local webfonts: every font file `all.min.css` references via `url(../webfonts/...)` exists at the matching relative path (no 404).
6. FR-6 — `style.css` no longer contains any `@import` or `url()` pointing at an external host; Inter is loaded via a local `@font-face`.
7. FR-7 — Loading `/cashier/`, `/kitchen/`, `/admin/index.php` and `/admin/reports.php` makes **no network request to any host other than the page's own origin**.
8. FR-8 — Font binary files are marked `binary` in `.gitattributes`, so the spec-049 CRLF check and `text=auto` never touch them; vendored `.js`/`.css` files are committed with LF (or are byte-identical to upstream and already LF — whichever is true is recorded; if an upstream file contains CRLF, that conflict with FR-3 is reported, not silently resolved).

## Non-functional requirements

- No new Composer dependency, no Node dependency in `public/`, no Docker change.
- Page behavior and appearance are unchanged (same library versions, except the floating ranges pinned to what they currently resolve to).
- Repository growth is bounded to the vendored files (expected order of magnitude: ~1–2 MB, dominated by Font Awesome webfonts); actual size recorded in the implementation log.

## User flows

- **Cashier / kitchen / admin, internet available:** no visible change.
- **Cashier / kitchen / admin, internet down, LAN up:** pages load fully styled, Alpine initializes, icons and fonts render, charts render in Relatórios, Tom Select works in Cardápio — same as with internet.
- **Maintainer upgrading a library:** follows `public/vendor/README.md` — download the new version into a new versioned directory, update the references, record version/URL/hash, delete the old directory.

## API changes

Not applicable — no endpoint or response changes; only static asset paths in view scripts.

## Data model and migrations

Not applicable — no database change.

## Architecture and affected components

Frontend/static files only:

- `public/vendor/**` (new, vendored files + `README.md`)
- `public/admin/_partials/head.php` (Bootstrap CSS/JS, Font Awesome, Alpine)
- `public/admin/index.php` (Tom Select)
- `public/admin/reports.php` (Chart.js)
- `public/cashier/index.php`, `public/kitchen/index.php` (Bootstrap, Font Awesome, Alpine)
- `public/assets/css/style.css` (Inter)
- `.gitattributes` (font files as `binary`)
- `tests/e2e/specs/` — one new Playwright test (see Testing)

No Controller/Service/Repository/Middleware is touched. `$asset()` in `head.php` is not changed: local vendor paths will get `?v=<mtime>` like other local assets, which is harmless.

## Security considerations

- Removes a supply-chain exposure: today a compromised or hijacked CDN (or the floating `3.x.x`/`@2` ranges resolving to a malicious release) would execute arbitrary JS in an authenticated admin session holding the JWT. After the change, the executed code is whatever is committed and reviewed.
- Integrity is established once, at download time, by fetching from the official package URL of the exact version and recording the SHA-256 (FR-3); no SRI attributes are needed for same-origin files.
- No secrets, auth or input-handling changes.

## Backward compatibility

- No API or data impact.
- Browsers with the CDN files cached simply start using the local ones; nothing to migrate.
- `alpinejs@3.x.x` → pinned 3.x: same major line and the same version the CDN serves today, so no behavior change at the moment of pinning; from then on Alpine stops auto-updating (intended).

## Acceptance criteria

1. AC-1 — `grep -rnE "https?://" public --include=*.php --include=*.html --include=*.css --include=*.js` (excluding `public/vendor/` and `public/api/docs/`) returns only non-asset links (e.g. the GitHub `<a href>`), no `<script src>`, `<link href>`, `@import` or `url()` to an external host.
2. AC-2 — A new Playwright test blocks every request whose host is not the app's own (`page.route('**/*', ...)` aborting non-local URLs), loads `/cashier/`, `/kitchen/`, `/admin/index.php` and `/admin/reports.php`, and asserts for each: no request was aborted, `window.Alpine` is defined, a Bootstrap rule applies (`.d-none` → `display: none`), and the Font Awesome and Inter fonts load from the server (`document.fonts.load` returns faces with status `loaded`). For the two admin pages, which redirect to the `/admin/` login screen when unauthenticated, those checks run on the page that ends up rendered, and the page's own library (Tom Select / Chart.js) is checked by its response being served with `200`. The test passes against the port-8081 instance and in CI. *(Reworded 2026-10-03 during implementation — see Implementation log; the original wording asked for the icon's `::before` content and font-family, and did not account for the admin redirect.)*
3. AC-3 — The same test, run against `master` before the change, fails (proves the test detects the CDN dependency).
4. AC-4 — Every file under `public/vendor/` (excluding `README.md`) has a matching entry in `public/vendor/README.md` with version, source URL and a SHA-256 that matches `sha256sum` of the committed file.
5. AC-5 — Every `url(../webfonts/...)` in the vendored Font Awesome CSS resolves to an existing file (checked by a one-line script, output recorded).
6. AC-6 — `git ls-files --eol public/vendor` shows no `i/crlf`/`i/mixed` entries and font files as `-text`; the CI "Line endings" step passes.
7. AC-7 — The existing E2E suite and the full CI pipeline (composer validate → audit → PHPStan → PHP-CS-Fixer → PHPUnit → E2E) pass on the spec's PR.
8. AC-8 — Manual check with the machine's internet actually disconnected (LAN/localhost only): cashier, kitchen and admin (Cardápio with Tom Select, Relatórios with charts) are fully usable; screenshot or written observation recorded in Validation evidence.

## Implementation plan

1. Create branch `055` from `master`.
2. Resolve the exact versions of the floating ranges (`alpinejs@3.x.x`, `tom-select@2`) by querying the CDN that serves them today; record them.
3. Download each in-scope file from its upstream URL for the pinned version into `public/vendor/<lib>-<version>/...` (Font Awesome: `css/all.min.css` + the `webfonts/` it references; Inter: woff2 + `OFL.txt`). Compute SHA-256 of each file.
4. Write `public/vendor/README.md` (FR-4) and add `*.woff2 binary` / `*.woff binary` / `*.ttf binary` to `.gitattributes` (FR-8).
5. Rewrite references in `head.php`, `cashier/index.php`, `kitchen/index.php`, `admin/index.php`, `admin/reports.php`.
6. Replace the Google Fonts `@import` in `style.css` with a local `@font-face`.
7. Add the Playwright offline test (AC-2); confirm it fails on `master` (AC-3) and passes on the branch.
8. Run AC-1/AC-4/AC-5/AC-6 checks; manual offline check (AC-8).
9. Docs pass in the same PR: `CHANGELOG.md` entry under the `v1.9.0` in-progress heading; `docs/ROADMAP.md` status line on this item (noting Vite was dropped and why); `docs/technical-decisions.md` row ("vendored assets, no bundler"); `docs/architecture.md` / `specs/000-project-baseline.md` / `CLAUDE.md` stack line mention `public/vendor/` where they describe the frontend.

## Testing and validation strategy

- **Automated (browser):** one new Playwright test under `tests/e2e/specs/` (spec 040 suite, port 8081 / `php -S` in CI). This is exactly the kind of defect the suite exists for — it can only be seen in a browser. AC-3 (red on `master`) proves it actually detects the regression.
- **Automated (CI):** existing pipeline unchanged; the line-endings step covers AC-6.
- **Scripted checks (one-off, output recorded):** AC-1 grep, AC-4 hash comparison (`sha256sum` vs README), AC-5 webfont reference resolution.
- **Manual:** AC-8 with real internet disconnected, since request blocking in Playwright does not cover OS-level DNS/timeouts.
- PHPUnit is not affected (no PHP logic change) but runs as part of CI anyway.

## Rollout and rollback

- Rollout: merge and deploy as usual (`git pull` + container restart is not even needed for static files). No migration, no config.
- Rollback: revert the PR; pages go back to the CDN URLs.

## Open questions

1. (non-blocking) Swagger UI at `/api/docs/` — keep it on unpkg (default: yes, developer-only page, ~1.5 MB to vendor) or vendor it too for completeness? Default if unanswered: left on CDN, documented in `public/vendor/README.md` as the one known external dependency.
2. (non-blocking) Inter as a single variable woff2 vs. five static weights — decided at implementation by whichever upstream file is the official distribution; recorded in the log.

No blocking questions.

## Task checklist

- [x] Branch `055` created
- [x] Floating versions resolved and pinned
- [x] Assets downloaded into `public/vendor/`, hashes recorded
- [x] `public/vendor/README.md` written
- [x] `.gitattributes` font rules added
- [x] References rewritten (head.php, cashier, kitchen, admin/index.php, admin/reports.php)
- [x] Inter `@font-face` replaces Google Fonts `@import`
- [x] Playwright offline test added (red on `master`, green on branch)
- [x] AC-1/4/5 checks run and recorded; AC-6 partially (attributes checked, `ls-files --eol` needs the files staged — confirmed by CI on the PR)
- [ ] Manual offline check (AC-8) — needs the user to disconnect the internet
- [x] CHANGELOG + docs pass (ROADMAP, technical-decisions, architecture, CLAUDE.md; baseline checked, nothing in it made false)
- [ ] CI green on the PR (AC-7)
- [ ] `/spec-review`

## Implementation log

- **2026-10-03 — versions.** `alpinejs@3.x.x` resolved to **3.17.4** (unpkg redirect `Location: /alpinejs@3.17.4/dist/cdn.min.js`, confirmed by `data.jsdelivr.com/.../alpinejs/resolved?specifier=3`); `tom-select@2` resolved to **2.6.2** (`.../tom-select/resolved?specifier=2`). Fixed versions (Bootstrap 5.3.0, Font Awesome 6.4.0, Chart.js 4.4.0) kept as they were.
- **Font Awesome webfonts.** `all.min.css` references 8 files (`fa-brands-400`, `fa-regular-400`, `fa-solid-900`, `fa-v4compatibility`, each `.woff2` + `.ttf`); all 8 vendored, the list derived from the CSS itself, not by hand. The `.ttf` are only a fallback for old browsers but kept so no referenced URL 404s (FR-5).
- **Inter (open question 2 resolved).** Google Fonts serves Inter as a variable font; the official npm distribution of that is `@fontsource-variable/inter` **5.3.0**, file `inter-latin-wght-normal.woff2` (one file, weights 100–900) + `LICENSE` (OFL). Only the `latin` subset is vendored — it covers U+0000-00FF, so every Portuguese character; the `unicode-range` was copied from the package's own `index.css`. The `@font-face` keeps `font-family: 'Inter'` so `--font` in `style.css` needs no change.
- **Swagger UI (open question 1).** Left on unpkg (the default the spec set); documented in `public/vendor/README.md`.
- **Line endings.** A first `grep -c $'\r'` reported "CRLF" in every vendored file — false positive of that pattern under Git Bash; counting real bytes (`tr -cd '\r' | wc -c`) gave **0** for every file, so upstream files are LF and FR-3 and FR-8 don't conflict. `LICENSE` has no extension, so it would only get `text=auto`; an explicit `public/vendor/**/LICENSE text eol=lf` rule was added, keeping spec 049's "every tracked text path has an explicit eol rule".
- **Test design deviations from AC-2 (AC-2 reworded to match; corrected by `/spec-review`).** (a) Icons: instead of inspecting an icon's `::before` content and computed font-family, the test calls `document.fonts.load('900 16px "Font Awesome 6 Free"')` and requires every returned face to be `loaded` — a stronger check, since `::before` content and font-family are set by the CSS even when the font file fails to download. (b) Admin pages: my first version of the test also checked `window.TomSelect`/`window.Chart` (not something AC-2 asked for). Unauthenticated, `public/admin/auth.js` redirects to `/admin/?next=…` right after load, so the global was read on the login page (first run: `false` on reports, while no request had been blocked). The test now checks each admin page's own vendor file was **served with 200** (`waitForResponse`); the Alpine/Bootstrap/font checks run on whatever page ends up rendered, i.e. the login screen for the admin pages. Coverage stays equivalent because all admin pages share `public/admin/_partials/head.php`, but the page itself (e.g. the Relatórios charts) is not exercised logged-in.
- **Docker.** Docker Desktop was not running at the start; it was started to bring up the port-8081 instance (`web-e2e`) that AC-2 targets. For AC-3, the five view files were temporarily reverted with `git stash push -- public/cashier public/kitchen public/admin public/assets` and restored with `git stash pop` right after (diff stat identical before/after).
- **Pre-existing flakiness found (not caused by this spec).** Running the full E2E suite, `printer-block.spec.ts` failed intermittently (run 1: 1 of 5 failed; run 2: 4 of 5). With `offline-assets` excluded (`--grep-invert "sem internet"`) it still happened (4 failed, then 0 failed), and `printer-block.spec.ts` alone passed 5/5 both with this branch's views and with `master`'s. Ruled out: the `restaurant_print_worker` container (it points at `restaurant`, not `restaurant_test`, and logged no failure in the window). Root cause not investigated — out of scope; to be filed as a follow-up.
- **CHANGELOG sections.** Adding this spec's entry under a new `v1.9.0 — Community Productization (em andamento)` heading left two unreleased sections side by side. Spec 054's entries, which sat under "Não lançado", were moved into the v1.9.0 section (spec 054's own metadata places it under `v1.9.0` › "Documentation structure"), and the now-empty "Não lançado" heading was removed. Raised by `/spec-review`, done with user approval.
- **Size.** `public/vendor/` is 1.8 MB (`du -sh`), within the expected 1–2 MB.

## Validation evidence

- **AC-1** — `grep -rnE "https?://" public --include=*.php --include=*.html --include=*.css --include=*.js | grep -vE "^public/vendor/|^public/api/docs/"` → one hit only: `public/assets/css/style.css:376`, the `xmlns='http://www.w3.org/2000/svg'` inside an inline `data:` SVG (a namespace, not a request). ✅
- **AC-2** (as reworded) — `npx playwright test specs/offline-assets.spec.ts` against port 8081 → `4 passed`; `--repeat-each=5` → `20 passed (49.8s)`. ✅ locally; not yet observed in CI, where the server is `php -S` instead of Apache.
- **AC-3** — same test with `master`'s views (stash) → `4 failed`, each `TimeoutError: page.waitForFunction` (Alpine never initialized with off-origin requests blocked). ✅
- **AC-4** — `cd public/vendor && sed -n '/^```text$/,/^```$/p' README.md | grep -v '```' | sha256sum -c` → 17 × `OK`; `find . -type f ! -name README.md | wc -l` → 17. ✅
- **AC-5** — every `url(../webfonts/…)` in `all.min.css` checked with `[ -f … ]` → 8 × `OK`, 0 missing. ✅
- **AC-6** — `git check-attr text eol`: `.woff2`/`.ttf` → `text: unset` (binary); `.js` → `eol: lf`; `LICENSE` → covered by the new rule. Real CR bytes in every vendored text file: 0. `git ls-files --eol` and CI's "Line endings" step not yet observed (files not committed). ⏳ partial
- **AC-7** — not run yet: needs the PR's CI. Locally: `php -l` on the 5 changed views inside `restaurant_web_e2e` → `No syntax errors detected` × 5; full E2E suite has the pre-existing `printer-block` flakiness described in the log. ⏳
- **AC-8** — not run: needs the internet actually disconnected on the user's machine. ⏳

Status is `Implemented`, not `Verified`: AC-6 (CI part), AC-7 and AC-8 lack evidence.
