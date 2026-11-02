# Spec 073 — `display_errors` off in the production image

<!-- Reduced in scope on purpose: a PHP configuration change, with no application code. -->

## Metadata

- Status: Implemented
- Created: 2026-11-01
- Updated: 2026-11-01
- Owner: Henry
- Related issue: Trello card "display_errors ligado na imagem: erros fatais aparecem na página (e /health/ready responde 200)"
- Related branch: `073`

## Context

Found during spec 062's clean-room install (2026-10-26, `specs/062-installation-experience.md` › Implementation log) and recorded as a known limitation by spec 067 (`docs/security.md`). It was pulled from the Backlog on 2026-11-01 so it lands before the release candidate.

## Problem

The official PHP image ships no `php.ini`, so `display_errors` keeps PHP's built-in default, which is on. Measured by spec 062, without `vendor/`:

- every page that loads `vendor/autoload.php`, `GET /health/ready` included, answers **HTTP 200** with an HTML `Fatal error: … /var/www/html/public/../vendor/autoload.php` page, so a monitor that checks only the status sees a healthy app;
- the page exposes internal paths even with `APP_DEBUG=false`, because `APP_DEBUG` governs only the errors Slim handles (`src/App.php`), not the ones raised before it boots or in views outside Slim.

## Goals

- In the production image, a PHP error never reaches the response body.
- A fatal error answers HTTP 500, so `/health/ready` stops reporting a broken app as healthy.
- The error stays diagnosable: it reaches `docker compose logs`.
- Development keeps seeing errors on the page.

## Non-goals

- Changing `HealthController` or adding a bootstrap `try/catch` in `public/index.php`: PHP is expected to answer 500 by itself (see Proposed behavior, confirmed by AC2).
- A friendly error page for a fatal error.
- Backporting to `release/1.8.x`.
- `logs/app.log` rotation (its own Backlog card).

## Current behavior

Confirmed in code:

- `Dockerfile` writes `conf.d/timezone.ini` and `conf.d/zz-security.ini` (only `session.upload_progress.enabled=0`); nothing sets `display_errors` or `log_errors`.
- No `ini_set`/`error_reporting` call exists in `src/`, `public/` or `bin/`.
- `public/index.php:4` requires `vendor/autoload.php` before anything else, and `Dotenv::load()` follows; a failure in either happens before Slim's error middleware exists.
- `public/cashier/`, `public/kitchen/`, `public/admin/*.php` and `public/api/events/stream.php` run outside Slim (`public/.htaccess`).
- `docker-compose.dev.yml` (`web`, `print-worker`) and `docker-compose.e2e.yml` (`web-e2e`) already mount an ini file into `conf.d`: `docker/php-timezone.ini` as `90-timezone.ini`.
- `docs/security.md:79` lists this as a known limitation; `docs/troubleshooting.md:48` tells operators what to do when the page shows `Fatal error: …`.
- No test under `tests/` asserts on `display_errors` or on `Fatal error` text.

Measured by AC2: with `display_errors=Off`, PHP answers 500 on a fatal error when no output has been sent.

## Proposed behavior

- `Dockerfile`: `zz-security.ini` also sets `display_errors=Off`, `display_startup_errors=Off` and `log_errors=On`. `error_log` stays unset: under Apache the message goes to Apache's error log, which the official image sends to the container's stderr; under the CLI it goes to stderr.
- New `docker/php-dev.ini` with `display_errors=On` and `display_startup_errors=On`, mounted as `conf.d/zzz-dev.ini` in `web`, `print-worker` and `web-e2e` of the development and e2e compose files. PHP reads `conf.d` alphabetically, so the name must sort after `zz-security.ini`.

## Functional requirements

1. FR1 — In the image, `php -i` reports `display_errors => Off` and `log_errors => On`.
2. FR2 — With `vendor/` missing, `GET /health/ready` on the image answers 500 and the body has no `Fatal error`, no `/var/www/html` and no `vendor/autoload.php`.
3. FR3 — That same failure appears in the container's logs.
4. FR4 — A `bin/*` command that fails fatally still shows the error on its stderr.
5. FR5 — On the development compose, `display_errors` stays on.

## Non-functional requirements

No new dependency, and the PHP version stays pinned. The first line of FR1 runs on the image without starting Apache.

## User flows

- **Operator:** a broken install shows an empty or browser-default error page instead of file paths, and `docker compose logs web` has the cause, as `docs/troubleshooting.md` says.
- **Monitor:** `GET /health/ready` answers 500.
- **Developer:** after recreating the containers, errors still appear on the page. `log_errors=On` also applies in development, so they reach the container logs too, and a command-line error prints twice (`Warning:` and `PHP Warning:`). Accepted: the log is useful in development as well.

## API changes

No endpoint changes. A path where PHP itself fails fatally, before any output, answers 500 with no PHP error text instead of 200 with it. With `vendor/` missing, that covers the paths that load it; `/cashier/` and `/kitchen/` do not, and keep answering 200. Errors Slim handles keep their JSON format.

## Data model and migrations

Not applicable: no data changes.

## Architecture and affected components

`Dockerfile`, new `docker/php-dev.ini`, `docker-compose.dev.yml`, `docker-compose.e2e.yml`. `docker-compose.yml` needs no change, because production runs the image's own settings. No PHP code changes.

## Security considerations

This closes an information leak: internal paths and any value PHP prints in a warning or fatal error no longer reach the response. A warning in a view or API response also stops being written into the page or the JSON. The same messages now go to the logs, which were already restricted to whoever operates the server.

## Backward compatibility

- A warning that used to appear on a production page now appears only in the logs.
- A fatal error raised after output started still answers 200 with a truncated page, because the headers have already been sent. This is PHP's behavior and is not changed here; only the error text disappears.
- An existing development stack needs its containers recreated to pick up the new mount. Without that it would take the image's `Off`, once the image is rebuilt.

## Acceptance criteria

- **AC1** — `docker run --rm <image> php -i` shows `display_errors => Off => Off` and `log_errors => On => On`.
- **AC2** — In a throwaway container of the image with `vendor/` moved away, `/health/ready` and `/api/events/stream.php` answer `500`, and neither body contains `Fatal error`, `/var/www/html` or `autoload`. *(Corrected 2026-11-01 during implementation: this named `/cashier/`, which does not load `vendor/` and answers 200 with its normal page, before and after the change.)*
- **AC3** — After AC2, `docker logs` on that container contains `PHP Fatal error` and `vendor/autoload.php`.
- **AC4** — In the same state, `docker exec … php bin/migrate --status` exits non-zero and shows the error on stderr.
- **AC5** — With `vendor/` restored, `GET /health/live` answers 200.
- **AC6** — On the development stack, `docker compose exec web php -i` shows `display_errors => STDOUT` and `docker compose exec print-worker php -i` shows the same. *(Corrected 2026-11-01 by `/spec-review`: this said `On`, but the command line reports the enabled setting as `STDOUT`.)*
- **AC7** — `docs/security.md` no longer lists the limitation and `docs/troubleshooting.md:48` describes the new symptom.
- **AC8** — The PR's CI is green, including the `Dockerfile` dry-run from spec 072.
- **AC9** — PHPUnit and the Playwright suite pass on the development stack after the change.

## Implementation plan

1. Measure the current behavior on the image: AC2 and AC3 before the change, recorded as the baseline.
2. `Dockerfile`: add the three settings to `zz-security.ini`.
3. Add `docker/php-dev.ini` and its mount to the three services.
4. Build and run AC1 to AC6.
5. Docs pass: `docs/security.md`, `docs/troubleshooting.md`, `docs/configuration.md` (development notes), `docs/architecture.md`, `specs/000-project-baseline.md` if either asserts the old behavior, and the `CHANGELOG.md` entry under `v1.9.0`.
6. `/spec-review`, then the PR.

## Testing and validation strategy

The project has PHPUnit, Playwright and CI, but none of them covers the image's PHP configuration: the suites run on the development stack or on `setup-php`. AC1 to AC5 are therefore manual, on a throwaway container of the built image, never on the development or pilot stack. AC6 and AC9 run on the development stack. No new automated test is planned: guarding one ini line would need a container-based test harness the project does not have.

## Rollout and rollback

It ships with the next release: restaurants get it by rebuilding the image, as `docs/upgrading.md` already instructs. No migration. To revert, remove the three settings and the mount; rollback needs only the rebuild.

## Open questions

None blocking.

- If AC2 shows PHP does not answer 500 on this image, the "no code change" decision falls and the spec must return to the owner before anything is added to `public/index.php`.

## Task checklist

- [x] Baseline measured on the current image
- [x] `Dockerfile` settings
- [x] `docker/php-dev.ini` and mounts
- [x] AC1 to AC6 run
- [x] Docs pass and `CHANGELOG.md`
- [x] PHPUnit and Playwright (with the gaps recorded in Validation evidence)
- [x] `/spec-review`
- [x] PR and CI (PR #62)

## Implementation log

- 2026-11-01: `/spec-implement` treated as approval (`Draft` → `Approved` → `In Progress`; no blocking question).
- 2026-11-01: the baseline showed `/cashier/` and `/kitchen/` never load `vendor/`, so the Problem section and AC2 were wrong about them. Both corrected in place; `/api/events/stream.php` replaces `/cashier/` in AC2, being the path outside Slim that did leak.
- 2026-11-01: the assumption in Current behavior held: PHP answers 500 by itself, so no change to `public/index.php` or `HealthController`.
- 2026-11-01: at AC5, with `vendor/` restored and a dummy database, `/api/events/stream.php` answered an empty 500. The likely cause is the unreachable database, but it was not confirmed, and that case was not measured on the old image or in the kitchen screen.
- 2026-11-01: implemented on the local `072` tree, because `/spec-implement` never changes branches. Branch `073` is created from `origin/master` when the PR is opened.
- 2026-11-01: `/spec-review` found no blocker and three things to fix, all applied: `docs/troubleshooting.md` had no row for the empty 500, which the documented `curl` shows as no output at all; the spec said nothing changes in development, while `log_errors=On` also applies there (accepted, and now stated in User flows); and the wording of API changes, AC6 and Current behavior was out of date.
- 2026-11-01: reading the code during the review, the screens already handle the empty 500: `/health/ready` counts as a lost connection (`public/assets/js/gf.js`), an API call shows "Erro do servidor (HTTP 500)", and the kitchen reopens a closed stream on each heartbeat (`public/kitchen/app.js`). This comes from reading the code; it was not run in a browser.

## Validation evidence

`/spec-plan` and `/spec-implement` still say the project has no automated tests; that is out of date (PHPUnit, Playwright, CI). What does not exist is coverage of the image's PHP configuration, so AC1 to AC5 were run by hand on throwaway containers (`gf073-before`, `gf073-after`, since removed), with the requests made from inside the container (the image has no `curl`, so a small PHP probe replaced it).

**Baseline, image before the change, `vendor/` moved away:**

```
display_errors => STDOUT => STDOUT
log_errors => Off => Off
/health/ready              HTTP/1.1 200 OK    bytes=475     leak=[Fatal error, /var/www/html, autoload]
/health/live               HTTP/1.1 200 OK    bytes=475     leak=[Fatal error, /var/www/html, autoload]
/cashier/                  HTTP/1.1 200 OK    bytes=44163   leak=[]
/kitchen/                  HTTP/1.1 200 OK    bytes=42616   leak=[]
/api/events/stream.php     HTTP/1.1 200 OK    bytes=548     leak=[Fatal error, /var/www/html, autoload]
```

`docker logs` had only the access lines: the error was not logged.

**AC1 — met.** `display_errors => Off => Off`, `display_startup_errors => Off => Off`, `log_errors => On => On`, `session.upload_progress.enabled => Off => Off`.

**AC2 — met.** New image, `vendor/` moved away:

```
/health/ready              HTTP/1.0 500 Internal Server Error   bytes=0       leak=[]
/health/live               HTTP/1.0 500 Internal Server Error   bytes=0       leak=[]
/cashier/                  HTTP/1.1 200 OK                      bytes=44163   leak=[]
/kitchen/                  HTTP/1.1 200 OK                      bytes=42616   leak=[]
/api/events/stream.php     HTTP/1.0 500 Internal Server Error   bytes=0       leak=[]
```

**AC3 — met.** `docker logs`: `[php:error] … PHP Fatal error:  Uncaught Error: Failed opening required '/var/www/html/public/../vendor/autoload.php' … in /var/www/html/public/index.php:4`.

**AC4 — met.** `php bin/migrate --status` → `exit=255`, stdout empty, stderr with `PHP Fatal error:  Uncaught Error: Failed opening required '/var/www/html/bin/../vendor/autoload.php'`.

**AC5 — met.** `vendor/` restored, with an empty `.env` and a dummy database: `/health/live` → `200` (32 bytes); `/health/ready` → `503` (expected, no database).

**AC6 — met.** After `docker compose -f docker-compose.dev.yml up -d --build --no-deps web print-worker` (database untouched): `display_errors => STDOUT => STDOUT` and `log_errors => On => On` in `web` and in `print-worker`; `web-e2e` also reports `display_errors => STDOUT => STDOUT`.

**AC7 — met.** The row is gone from `docs/security.md`, and `docs/troubleshooting.md` describes the new symptom.

**AC8 — met.** PR #62: `test` passed (run 41371991200, every step `success`, including PHPStan, PHP-CS-Fixer, PHPUnit and Playwright) and `dry-run` passed (run 41371991293, which builds the `Dockerfile`).

**AC9 — partly met.**

- PHPUnit, with `MYSQL_DATABASE_TEST=restaurant_test`: `Tests: 322, Assertions: 826, Skipped: 2`. The 2 skipped are `MigrationUpgradeTest`: with `MYSQL_DATABASE_TEST_UPGRADE` set they error with `Access denied for user 'restuser'@'%' to database 'restaurant_test_upgrade'` on this machine (a missing grant, unrelated to this spec). CI runs them.
- PHPStan: `[OK] No errors`.
- Playwright (`mcr.microsoft.com/playwright:v1.63.0-noble`, port 8081): `40 passed, 5 failed`. All 5 are in `printer-block.spec.ts` with `Error: spawnSync docker ENOENT`: the test calls `docker` from inside the Playwright container, where it does not exist, as recorded in spec 070. So those 5 did not exercise the screens here.

- In the CI of PR #62, the integration and Playwright steps passed, with `MYSQL_DATABASE_TEST_UPGRADE` set by the workflow. That run uses `setup-php`, without the image or `docker/php-dev.ini`, so it covers those tests but not on the development stack that AC9 names. Only the step results were read: the job log answered HTTP 403, so the test counts were not seen.

`Implemented`, not `Verified`: AC9 still has those two gaps on the development stack.
