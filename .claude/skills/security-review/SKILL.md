---
name: security-review
description: Read-only, GastroFlow-specific security audit targeting JwtMiddleware, Routes, SQL/migrations, input validation, authentication, authorization, secrets, file access, ESC/POS printing, the job queue, API endpoints, CORS, and dependencies. Reports findings via ReportFindings; never edits code, secrets, or the database.
---

# /security-review

Input: optional — a diff target (branch name, commit range, or PR number) to scope the review to pending changes, or `--full` to audit the whole relevant surface regardless of pending changes.

- No argument: if there are pending changes (`git diff HEAD` is non-empty), review those; otherwise fall back to a full audit of the 13 areas below.
- `--full`: always audit the whole surface, ignoring any pending diff.

This skill is narrower and more targeted than the generic bundled security-review: it always inspects these specific areas, using the real locations confirmed in `specs/000-project-baseline.md` (re-verify against current code — that doc is a snapshot, not live truth):

| Area | Real location(s) in this repo |
|---|---|
| JwtMiddleware | `src/Middleware/JwtMiddleware.php` |
| Routes | `src/Routes.php` |
| SQL / migrations | `common/sql/001_schema.sql`, `common/migrations/*.sql`, `src/Database/MigrationRunner.php`; any raw/string-built SQL or `DB::raw` calls in Controllers/Services/Repositories |
| Input validation | `src/Validators/OrderValidator.php` (Valitron), and any controller handling user input **without** one (`MenuController`, `IngredientController`, `DishController`, `AdminController`, ...) |
| Authentication | `src/Controllers/AuthController.php`, `POST /api/login`, JWT issuance via `firebase/php-jwt`, `password_verify()` |
| Authorization | the `role` claim in the JWT, and whether anything downstream of `JwtMiddleware` actually checks it — `JwtMiddleware` validates the *token*, not necessarily the *role*; confirm whether role-based access control exists at all |
| Secrets | `.env` / `.env.*` (never read their contents), the known hardcoded JWT fallback at `src/Routes.php:19`, and any other hardcoded credentials/keys/tokens anywhere in `src/`, `public/`, `common/` |
| File access | file uploads (`POST /api/admin/settings/logo`), log reads (`GET /api/admin/logs`), any `file_get_contents`/`fopen`/`readfile`/`move_uploaded_file` touching a user-influenced path |
| ESC/POS | `src/Services/PrintService.php`, `mike42/escpos-php`, `NetworkPrintConnector` configuration (printer host/IP source, injection into the raw print buffer) |
| Job queue | `src/Jobs/PrintOrderJob.php`, `src/Services/JobService.php`, `src/Models/Job.php`, `bin/worker` |
| API endpoints | every route registered in `src/Routes.php`, cross-checked against `specs/000-project-baseline.md`'s "Main endpoints" for which ones sit inside vs. outside the `/api/admin` (JwtMiddleware) group |
| CORS | `src/Middleware/CorsMiddleware.php` |
| Dependencies | `composer.json` / `composer.lock` — note `composer.lock` is gitignored per the baseline's own open question, which limits reproducible vulnerability checking; say so if it materially limits this review |

## What this skill must do, in order

1. **Check Git state.** Run `git status` (read-only). Determine scope per the Input rules above.
2. **Read `CLAUDE.md`'s "Security rules" section and `specs/000-project-baseline.md`'s "Security considerations" section first.** Treat already-documented issues (e.g. the hardcoded JWT fallback) as known baseline — confirm whether they're still present, worsened, fixed, or moved, rather than re-announcing them as fresh discoveries.
3. **Read the real, current files for each of the 13 areas** — don't rely on the baseline doc beyond orientation; code may have moved since 2026-08-05.
4. **SQL/migrations:** search for string-concatenated SQL, unescaped `DB::raw()`, or any query building user input directly into SQL text rather than through Eloquent bindings/parameterization.
5. **Dependencies:** non-destructive inspection only — `composer show`, `composer outdated`, reading `composer.json`, are fine. Never run `composer update`/`require`/`remove` (already in this project's `ask` permission list) — if a version bump is genuinely needed to fix something, report it as a recommendation, don't execute it.
6. **API endpoints:** for every route in `src/Routes.php`, determine whether it's inside the `/api/admin` group or not, and flag any endpoint that mutates data, reads sensitive data, or exposes internal state while sitting outside that group without a documented reason (the baseline notes this is "by design" for orders/menu/kitchen — treat that as the accepted trust model unless the diff/scope changes it).
7. **Never modify anything**: no code edits, no `.env` reads or writes, no migrations, no `bin/migrate`, no `docker compose down -v`, no destructive or mutating command, no commits. This is a report-only pass — do not use `--fix`-style remediation unless the user explicitly asks in a separate follow-up.
8. **Report via `ReportFindings`**, most severe first (empty array if nothing survives verification for the current scope). Use `category` values matching the 13 areas (kebab-case: `jwt-middleware`, `routes`, `sql-migrations`, `input-validation`, `authentication`, `authorization`, `secrets`, `file-access`, `escpos`, `job-queue`, `api-endpoints`, `cors`, `dependencies`).
9. **For each finding, state the concrete exploit scenario** in `failure_scenario` (specific input/state → specific consequence) — never a generic "this could be a problem."

## Guardrails

- Never print or exfiltrate secret *values* — if a hardcoded secret is found in source, quote only the surrounding code (e.g. the fallback string literal already known and documented at `src/Routes.php:19`), never dump `.env` contents.
- If an area has no relevant code to review in the current scope (e.g. the diff never touches ESC/POS), say so explicitly rather than silently omitting it from the final summary.
- Every finding must cite a real file and, where meaningful, a line number.
- If an issue already appears in `specs/000-project-baseline.md`'s Security considerations and is still present, include it once, labeled as a known/pre-existing issue rather than a new finding — but don't drop it just because it's already documented.
- If remediation belongs in a spec (non-trivial fix), say so and point at `/spec-plan` — this skill does not write specs or code itself.

## Final report

After calling `ReportFindings`, add, in the same language the user used:

- The scope actually reviewed (pending diff vs. full audit, and against what target).
- Any of the 13 areas that had no relevant files/changes to review in this scope.
- Whether any previously-documented baseline security issue (per `specs/000-project-baseline.md`) was found to be fixed, unchanged, or worse.
