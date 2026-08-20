# Spec 003 — Centralize filesystem paths on Settings, injected via DI

## Metadata

- Status: Verified
- Created: 2026-08-19
- Updated: 2026-08-19
- Owner: Henry (via Claude Code)
- Related issue: #10
- Related branch: 013

## Context

`docs/ROADMAP.md` v2.0 — Foundation item #10. Several files compute filesystem paths (logs, uploaded assets) via ad hoc `__DIR__ . '/../../'`-style expressions relative to their own location. This is fragile (each file recomputes the same logical path differently) and hard to override. The user explicitly requested implementation of roadmap items #8, #9, #10 in this conversation.

## Problem

The following files hardcode `__DIR__`-relative paths for the same two logical resources (the app log file, and the public logo image):

| File:Line | Current expression | Resource |
|---|---|---|
| `src/App.php:37` | `__DIR__ . '/../logs/app.log'` | log file (Monolog `StreamHandler` factory) |
| `src/Controllers/AdminController.php:90` | `__DIR__ . '/../../logs/app.log'` | log file (`getLogs()`) |
| `src/Controllers/AdminController.php:151` | `__DIR__ . '/../../public/assets/img'` | logo upload dir (`uploadLogo()`) |
| `src/Services/PrintService.php:83` | `__DIR__ . '/../../public/assets/img/logo.png'` | logo path (`printTestPage()`) |
| `src/Services/PrintService.php:137` | `__DIR__ . '/../../public/assets/img/logo.png'` | logo path (`buildReceipt()`, duplicate) |

The log-file path alone is computed two different ways relative to two different file locations — a sign this should be centralized rather than a one-off fix.

`src/Services/PrintService.php` is included even though the roadmap's "Files involved" list only names `Settings.php`, `App.php`, and `AdminController.php` — the roadmap's acceptance criteria say "every occurrence of `__DIR__ . '/../..'` in the code is replaced," which `PrintService.php` clearly falls under.

## Goals

- Centralize these paths as methods on the existing `Settings` object.
- Inject `Settings` via the project's existing DI container wiring wherever a class is normally resolved through it.
- Preserve exact current runtime behavior (same real filesystem locations) — this is a refactor, not a relocation.

## Non-goals

- `src/Database/MigrationRunner.php:15`'s `__DIR__ . '/../../common/migrations'` is explicitly excluded — it's a standalone CLI/bootstrap concern (`bin/migrate`) outside the DI-wired app, already supports a constructor override, and isn't named or implied by the roadmap text.
- No change to what paths resolve to — only how they're computed/obtained.

## Current behavior

- `src/Settings.php` is a minimal wrapper with a single `get(string $key, $default = null)` method reading `$_ENV`; no properties, no constructor, no path helpers.
- `Settings` is instantiated with no arguments at three call sites: `public/index.php:12`, `public/api/events/stream.php`, `bin/worker:30`.
- `Settings::class` is registered as a fixed singleton value in the DI container at `src/App.php:34` (`Settings::class => $this->settings`), with autowiring enabled (`src/App.php:30`), so any class resolved through the container can receive `Settings` via constructor injection for free.
- `AdminController` (constructor: `PrintService $printService` only) and `PrintService` (constructor: `LoggerInterface $logger` only) are both resolved through the container in the normal web request path — `AdminController` via Slim's `[AdminController::class, 'method']` route callables in `src/Routes.php`, `PrintService` via `OrderService`'s constructor (`src/Services/OrderService.php:16`).
- One exception: `src/Jobs/PrintOrderJob.php:32` builds `PrintService` manually (`new PrintService($logger)`), completely outside the DI container — this job class is instantiated by `JobService::processNext()` via bare `new $handler()` (`src/Services/JobService.php:74`), invoked from the queue worker (`bin/worker`), which has no container at all.

## Proposed behavior

`Settings` gains a `$basePath` (defaulting to the project root via `dirname(__DIR__)`, computed from `src/Settings.php`'s own location — same result as what `public/index.php` and `bin/worker` already compute independently) and path-getter methods. All five call sites above use these getters instead of local `__DIR__` arithmetic.

## Functional requirements

1. `Settings::getLogFile()` returns the same absolute path as today's `__DIR__ . '/../logs/app.log'` (from `App.php`) / `__DIR__ . '/../../logs/app.log'` (from `AdminController.php`) — i.e. `<project-root>/logs/app.log`.
2. `Settings::getPublicAssetsImgDir()` returns `<project-root>/public/assets/img`.
3. `Settings::getLogoPath()` returns `<project-root>/public/assets/img/logo.png`.
4. Every existing `new Settings()` call site continues to work unchanged (no required constructor arguments).
5. `AdminController::getLogs()` and `AdminController::uploadLogo()` use the new `Settings` getters instead of `__DIR__`.
6. `PrintService::printTestPage()` and `PrintService::buildReceipt()` use `Settings::getLogoPath()` instead of `__DIR__`.
7. `PrintOrderJob::handle()` still works when run via `bin/worker` (manually constructs `Settings` alongside `PrintService`, mirroring how `bin/worker` already manually constructs `Settings` for `Database::boot()`).

## Non-functional requirements

Behavior-preserving refactor — no functional change to what gets logged where or where the logo is read from/written to.

## User flows

Not applicable — internal refactor, no user-facing behavior change.

## API changes

Not applicable — no request/response shape changes.

## Data model and migrations

Not applicable.

## Architecture and affected components

- `src/Settings.php` — add `$basePath` property/constructor param and path-getter methods.
- `src/App.php` — use `$this->settings->getLogFile()` in the `LoggerInterface` factory closure.
- `src/Controllers/AdminController.php` — add `Settings` as a second constructor dependency (promoted properties); use getters in `getLogs()`/`uploadLogo()`.
- `src/Services/PrintService.php` — add `Settings` as a second constructor dependency (promoted properties); use `getLogoPath()` in both places.
- `src/Jobs/PrintOrderJob.php` — manually construct `Settings` (outside DI) and pass it to `PrintService`.

## Security considerations

Not applicable — no change to what's exposed or how; paths are still bounded to the same project-relative locations, no user input involved in path construction.

## Backward compatibility

Fully backward compatible: `getLogFile()`, `getPublicAssetsImgDir()`, `getLogoPath()` resolve to byte-identical absolute paths as the code they replace, and `Settings`'s constructor remains callable with zero arguments.

## Acceptance criteria

1. `Settings` exposes `getLogDir(): string`, `getLogFile(): string`, `getPublicDir(): string`, `getPublicAssetsImgDir(): string`, `getLogoPath(): string`, `getBasePath(): string`.
2. `AdminController` receives `Settings` via constructor injection and uses it in `getLogs()` and `uploadLogo()` instead of `__DIR__`.
3. Every occurrence of the hardcoded `__DIR__`-relative paths listed in "Problem" is replaced with the corresponding `Settings` getter call, including `PrintService.php` (both occurrences) and `PrintOrderJob.php`'s manual construction path.
4. All pre-existing `new Settings()` call sites (`public/index.php`, `public/api/events/stream.php`, `bin/worker`) continue to work with no argument changes required.
5. Runtime-resolved paths are identical to before the change (verified manually, see Testing and validation strategy).

## Implementation plan

1. `src/Settings.php`: add `private string $basePath;`, constructor `__construct(?string $basePath = null)` defaulting via `$basePath ?? dirname(__DIR__)`, and getters `getBasePath()`, `getLogDir()`, `getLogFile()`, `getPublicDir()`, `getPublicAssetsImgDir()`, `getLogoPath()`.
2. `src/App.php`: replace the hardcoded log path in the `LoggerInterface` factory closure with `$this->settings->getLogFile()`.
3. `src/Controllers/AdminController.php`: add `Settings $settings` as a second promoted constructor property; replace both `__DIR__`-based paths in `getLogs()`/`uploadLogo()`.
4. `src/Services/PrintService.php`: add `Settings $settings` as a second promoted constructor property; replace both `$logoPath` assignments.
5. `src/Jobs/PrintOrderJob.php`: construct `Settings` manually and pass to `PrintService`, mirroring `bin/worker`'s existing manual `Settings` construction.

## Testing and validation strategy

No automated test suite exists in this project. Validation is manual, against the running app (`docker compose up -d`):
- `GET /api/admin/logs` returns log content from the same real log file as before (or an empty result if the log doesn't exist yet — same as current behavior).
- `POST /api/admin/settings/logo` (multipart upload) writes to `public/assets/img/logo.png`, same as before.
- `POST /api/admin/settings/test-print` (or a real print job through the queue) picks up the logo from the same location if present, and logs the same way to `logs/app.log`.
- `docker compose exec web php -l` on every edited file to confirm no syntax errors.

## Rollout and rollback

Standard code change on branch `013`. Rollback is a plain revert; no data/schema impact, no path relocation (so no filesystem cleanup needed either way).

## Open questions

None blocking.

## Task checklist

- [x] `Settings.php` — add `$basePath` + getters
- [x] `App.php` — use `getLogFile()`
- [x] `AdminController.php` — inject `Settings`, use getters
- [x] `PrintService.php` — inject `Settings`, use `getLogoPath()`
- [x] `PrintOrderJob.php` — manually construct `Settings`, pass to `PrintService`

## Implementation log

- 2026-08-19: Implemented as planned. `MigrationRunner.php` deliberately left untouched (see Non-goals). `PrintService.php`/`PrintOrderJob.php` included beyond the roadmap's literal file list because the acceptance criteria's "every occurrence" wording covers them and they have the identical hardcoded-path problem.

## Validation evidence

All against the running container (`docker compose exec web ...`):
- `php -l` on all 5 edited files (`App.php`, `AdminController.php`, `PrintService.php`, `PrintOrderJob.php`, `Settings.php`) → "No syntax errors detected" for each.
- Direct instantiation: `new Settings()->getBasePath()` → `/var/www/html`; `getLogFile()` → `/var/www/html/logs/app.log`; `getPublicAssetsImgDir()` → `/var/www/html/public/assets/img`; `getLogoPath()` → `/var/www/html/public/assets/img/logo.png`. Confirmed via `ls` that these paths match the real, pre-existing `logs/app.log` and `public/assets/img/logo.png` files on disk — same locations as before the refactor (AC 5).
- `AdminController::getLogs()` exercised end-to-end (manual construction mirroring DI autowiring: `new AdminController(new PrintService(new NullLogger(), $settings), $settings)`, called with a real Slim PSR-7 request/response) → returned `success: true`, `file: app.log`, 10 real log lines read from the actual log file (AC 2, AC 3 partially — `uploadLogo()`'s `getPublicAssetsImgDir()` path was verified by direct call, not a full multipart upload, to avoid overwriting the real logo file).
- `PrintService`'s `getLogoPath()` resolution confirmed to match the real, existing logo file (`exists: yes`).
- `PrintOrderJob`'s manual (non-DI) construction path replicated directly (`new Settings(); new PrintService($logger, $settings);`) → constructs successfully, confirming the queue-worker path (outside the DI container) still works (AC 3, the `PrintOrderJob.php` occurrence).
- Live end-to-end: `curl http://localhost:8080/api/menu` → `200 OK` through the full `App::get()` bootstrap, confirming `App.php`'s `LoggerInterface` factory change (using `getLogFile()`) doesn't break app startup.
- All pre-existing `new Settings()` call sites (`public/index.php`, `public/api/events/stream.php`, `bin/worker`) were not modified and take no required arguments in the new constructor — confirmed by reading `src/Settings.php`'s new signature (`?string $basePath = null`) (AC 4).
