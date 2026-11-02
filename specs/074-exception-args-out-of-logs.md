# Spec 074 — Database password out of PHP stack traces

<!-- Follow-up to spec 073. Security fix, published 2026-11-02: v1.8.5 and advisory GHSA-rp7v-47mr-x2wx. -->

## Metadata

- Status: Verified
- Created: 2026-11-01
- Updated: 2026-11-02
- Owner: Henry
- Related issue: Follow-up to `specs/073-display-errors-off-in-image.md`; Trello card "[Follow-up 073] Senha do banco nos rastros de erro do PHP"
- Related branch: `release/1.8.5` (PR #63, tag `v1.8.5`) and `ghsa-074-master` (PR #64); advisory GHSA-rp7v-47mr-x2wx

## Context

Spec 073 (PR #62, merged 2026-11-02 01:48 UTC) turned `log_errors` on and `display_errors` off in the image. Its Security considerations say the messages "now go to the logs, which were already restricted to whoever operates the server". It left one thing unconfirmed: `/api/events/stream.php` answered an empty 500 with a dummy database, "likely" because the database was unreachable (Implementation log, 2026-11-01). Following that up, while planning this spec, showed what such a failure writes.

## Problem

The image has no `php.ini`, so `zend.exception_ignore_args` keeps PHP's built-in default, `0`. PHP then records function arguments in stack traces. Illuminate's connector receives the database password as a plain argument, and PHP's `#[SensitiveParameter]` protects it only in the `PDO->__construct` frame.

Measured on the development `web` container on 2026-11-01, with a `php -r` script using made-up credentials and an unreachable port:

```
exception_ignore_args='0'
#0 …/Connector.php(65): PDO->__construct('mysql:host=127....', 'fake_user', Object(SensitiveParameterValue), Array)
#1 …/Connector.php(83): Illuminate\Database\Connectors\Connector->createPdoConnection('mysql:host=127....', 'fake_user', 'FAKEPASSWORD123...', Array)
```

So an uncaught database connection failure prints the database username and the **first 15 characters of the password** (all of it, if shorter). Where that text goes, from reading the code:

| Path | Destination | Who can see it |
|---|---|---|
| `public/api/events/stream.php` (no login, no `try/catch`, connects at `Event::max('id')`) on the current image | Container logs (spec 073) | Whoever reads `docker compose logs` |
| The same path on an image without spec 073, which includes the latest release, `v1.8.4` | **The HTTP response**, as `display_errors` is on | Any device on the restaurant network, while the database is down (*measured 2026-11-02, AC5: HTTP 200 with the prefix in the body*). `v1.8.4` also publishes port 3306 to the network (`docker-compose.yml:14`) |
| `bin/*` and the `print-worker`, uncaught | stderr, then container logs | Whoever reads the logs |
| Slim's error handler with `APP_DEBUG=true` (`src/App.php:104,135`) | `logs/app.log` (Admin › Logs) and the API response | *Corrected 2026-11-02, AC12: on a failed query the password does not reach this path. The handler traces the outer `QueryException`, which starts above the connector.* Argument values such as SQL did appear here, in debug mode only |

`/health/ready` is not affected: it catches the exception (`src/Controllers/HealthController.php:45`).

The statement in spec 073 is therefore incomplete: the logs now receive a partial secret.

## Goals

- No function argument, and so no password, appears in a stack trace produced by the image.
- A regression fails CI, instead of depending on someone rereading the `Dockerfile`.
- Measure what `v1.8.4` exposes, so the owner decides about a fix release with facts.
- Confirm the cause of the empty 500 that spec 073 left open.
- Operators know how to tell whether their logs hold a password prefix, and what to do about it.

## Non-goals

- Catching the exception in `stream.php` or answering 503 there. The screens already handle the 500 (spec 073, Implementation log), and the setting fixes every path at once.
- Redacting exception messages. `QueryException` messages include SQL and bindings; that is a different problem, and it does not carry the connection password.
- Removing the `$exception->getTraceAsString()` calls in `src/App.php`: without arguments they are harmless and useful.
- Spec 073's remaining AC9 gaps on the development stack (the `MigrationUpgradeTest` grant and `printer-block.spec.ts` in the Playwright container). They stay open, and spec 073 stays `Implemented`.
- The outdated "no automated tests" text in `.claude/skills/spec-{plan,implement,review}`.

## Current behavior

Confirmed in code:

- `Dockerfile:78-83` writes `session.upload_progress.enabled`, `display_errors`, `display_startup_errors` and `log_errors` to `zz-security.ini`; nothing sets `zend.exception_ignore_args`. A search of `Dockerfile`, `docker/`, `docs/` and `specs/` found no mention of it.
- `vendor/illuminate/database/Connectors/Connector.php:63,80` take `$password` as a plain parameter.
- `src/Database.php:19` passes `MYSQL_PASSWORD` to that connector.
- `docker/php-dev.ini` sets only `display_errors` and `display_startup_errors`, so development inherits anything else set in the image.
- `.github/workflows/release.yml:154-161`, job `dry-run`, builds the image and checks only its `VERSION`.
- No document explains how to change `MYSQL_PASSWORD` on an existing install. `docs/installation.md:47` says MySQL stores it on first start, so editing `.env` alone is not enough.

## Proposed behavior

- `Dockerfile`: `zz-security.ini` also sets `zend.exception_ignore_args=On`. Traces keep files, lines and function names, and lose argument values. Development inherits it, on purpose: a trace there reaches the same places.
- `dry-run` in `release.yml`: after the build, assert the four ini values and run a connection failure with made-up credentials, failing if the password appears in the output.
- Docs: `docs/security.md` and `docs/troubleshooting.md` explain how to check existing logs without printing them, and how to change the password if needed.
- Spec 073's Security considerations gets a dated correction pointing here.

## Functional requirements

1. FR1 — In the image, `php -i` reports `zend.exception_ignore_args => On`.
2. FR2 — An uncaught database connection failure in the image logs no credential as a trace argument: not the password, not the username, not a prefix of either. *(Made explicit 2026-11-02 after `/spec-review`: with a wrong password, MySQL's own message still names the user and the client address, never the password. That is an exception message, a non-goal.)*
3. FR3 — The trace still names the failing file, line and function, so the error stays diagnosable.
4. FR4 — The `dry-run` job fails if any of the ini values regresses or the made-up password appears in the output.
5. FR5 — The development stack reports the same `zend.exception_ignore_args` value, with `display_errors` still on.

## Non-functional requirements

No new dependency, and the PHP version stays pinned. Every credential used by a check or a test here is made up: no check reads or prints `.env` or real logs' content.

## User flows

- **Operator:** after updating, a database failure logs the cause without credentials. To check older logs, they run a command that only counts matching lines.
- **Developer:** traces show `Connector->createPdoConnection(…)` with no argument values, after the image is rebuilt. Accepted as a small debugging cost.

## API changes

No endpoint changes. With `APP_DEBUG=true`, the `trace` field of a 500 response no longer carries argument values.

## Data model and migrations

Not applicable: no data changes.

## Architecture and affected components

`Dockerfile`, `.github/workflows/release.yml`, `.github/scripts/image-php-check.sh` (new), `docs/security.md`, `docs/troubleshooting.md`, `CHANGELOG.md`, `specs/073-display-errors-off-in-image.md` (dated correction). `docs/architecture.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` and `README.md` are checked in the docs pass. No PHP code changes.

## Security considerations

- This closes a partial credential leak. On `v1.8.4` it is reachable with no login while the database is down (AC5).
- `SECURITY.md` asks that details stay unpublished until a fix is released, with the fix developed in a private GitHub security advisory, and the repository is public. This spec, the fix and its changelog entry describe the problem, so they follow Open question 1. The local branch `073` tracks the public `origin/073`, so nothing here may be pushed to it.
- The setting does not clean what is already written. Container logs last until the container is recreated, and `logs/app.log` lives in a volume. An install that had a database failure may hold a prefix there.
- Evidence recorded in this spec must never quote a real log line that could contain a real password.

## Backward compatibility

- No test or code in `src/`, `bin/` or `public/` calls `getTrace()` or reads trace arguments; `src/App.php` only uses `getTraceAsString()`, whose text simply gets shorter.
- An existing development stack gets the setting when the image is rebuilt.

## Acceptance criteria

- **AC1** — `docker run --rm <image> php -i` shows `zend.exception_ignore_args => On => On`, and `display_errors => Off => Off`, `display_startup_errors => Off => Off`, `log_errors => On => On` are unchanged.
- **AC2** — In a throwaway container of the new image, with a made-up `.env` and an unreachable database, `GET /api/events/stream.php` answers `500` with 0 bytes. This confirms or refutes the cause left open by spec 073; the result is recorded in that spec too.
- **AC3** — After AC2, `docker logs` on that container contains `createPdoConnection` and `PHP Fatal error`, and does not contain the made-up password's first 15 characters or the made-up username as a trace argument.
- **AC4** — In that container, `php bin/worker --once` or another `bin/*` command reaching the database produces the same result as AC3 on stderr, or the spec records that the command handles the failure itself.
- **AC5** — Baseline: the same request on an image built from tag `v1.8.4`, with the same made-up values, has its status and whether the body contains the password's prefix recorded, even though no change is made to that tag.
- **AC6** — The same test on the image from before this change (`master` at `c9c13c7`) has its log recorded as the baseline for AC3.
- **AC7** — Temporarily removing `zend.exception_ignore_args=On` makes the new `dry-run` step fail, seen locally by running the step's script against both images. PR CI is green with the setting in place.
- **AC8** — On the development stack, `web`, `print-worker` and `web-e2e` report `zend.exception_ignore_args => On => On` and `display_errors => STDOUT => STDOUT`.
- **AC9** — `docs/security.md` describes the log check, and the check counts lines without printing them. The password change steps were run on a throwaway stack, never on the development database.
- **AC10** — `CHANGELOG.md` has the entry under the section chosen in Open question 1, and spec 073 has its dated correction.
- **AC11** — PHPUnit, PHPStan and the Playwright suite run on the development stack, and any failure or skip is recorded with its cause.
- **AC12** — With `APP_DEBUG=true` and a wrong made-up password, on the new image and on the one before it: the `trace` of `GET /api/menu` and `logs/app.log` have no password prefix, and the new image's container logs have no password prefix. *(Added 2026-11-02 after `/spec-review`: API changes promised this with no criterion behind it.)*

## Implementation plan

1. Baselines first, on throwaway containers: AC5 and AC6.
2. `Dockerfile`: add the setting, with a comment saying why.
3. Build and run AC1 to AC4.
4. `release.yml`: extend the smoke check; prove it catches a regression (AC7).
5. Recreate the development containers; run AC8 and AC11.
6. Docs pass: `docs/security.md`, `docs/troubleshooting.md`, the correction in spec 073, `CHANGELOG.md`, and a check of the other docs listed above.
7. `/spec-review`, then publish by the route chosen in Open question 1.

## Testing and validation strategy

The project has PHPUnit, Playwright and CI. None of them runs against the image's PHP configuration, as spec 073 recorded, so AC1 to AC6 are manual, on throwaway containers with made-up credentials, never on the development or pilot stack. AC7 adds the first automated check of this configuration, in the existing `dry-run` job, with no new harness. It runs only on pull requests that touch the `Dockerfile`, the workflow or its scripts, which covers the way this setting could regress.

## Rollout and rollback

It ships with the next release, by rebuilding the image, as `docs/upgrading.md` instructs. No migration. To revert, remove the setting and the CI step, then rebuild. The pilot install follows `master`, so it gets the fix when it next updates; its logs are checked as described in `docs/security.md`, by whoever operates it. Until it took PR #62, the pilot had the same exposure in the HTTP response as `v1.8.4`, and a `0` proves little because container logs do not survive recreation, so changing its database password is worth considering either way.

## Open questions

1. **Resolved 2026-11-02 by the owner — publication route: private GitHub security advisory**, per `SECURITY.md`, with this spec published after the release. *(What happened 2026-11-02: the details reached a public branch shortly before the tag; see the Implementation log. The advisory was published at 05:19 UTC, about 20 minutes after PR #63 merged.)*
2. **Resolved 2026-11-02 by the owner — release `v1.8.5`.** `SECURITY.md` promises security fixes for the latest release, which was `v1.8.4`, and that tag carried neither this fix nor spec 073, whose Non-goals excluded the backport. AC5 confirmed the leak in the HTTP response. `v1.8.5` carries both settings, cut from `release/1.8.x` (PR #63, merged 2026-11-02 05:00 UTC by the owner), with advisory `GHSA-rp7v-47mr-x2wx` telling restaurants to check their logs.
3. Resolved 2026-11-02 — the password change steps: `ALTER USER`, the matching `.env` edit and recreating the containers work, as measured by AC9 and written in `docs/security.md`.
4. Non-blocking, still open — the `image` job of `release.yml` does not call `image-php-check.sh`, so only pull requests that touch the `Dockerfile`, the workflow or its scripts run the check. Adding the step there would also guard a tagged build.

## Task checklist

- [x] Owner decision on Open question 1
- [x] Baselines on `v1.8.4` and on the current image (AC5, AC6)
- [x] `Dockerfile` setting
- [x] AC1 to AC4 run
- [x] CI step, its regression proven on real Docker, and green on CI (AC7)
- [x] AC8 and AC11 on the development stack (with the gaps recorded in Validation evidence)
- [x] Docs pass, `CHANGELOG.md`, correction in spec 073
- [x] Owner decision on Open question 2
- [x] `/spec-review`
- [x] Published: tag `v1.8.5` and the advisory; PR #64 with CI green, its merge pending the owner

## Implementation log

- 2026-11-01: created by `/spec-plan`. The password-prefix measurement in Problem used made-up credentials on the running development `web` container, through `docker compose exec`; no container was started, and no `.env` or real log was read.
- 2026-11-02: `/spec-implement` treated as approval (`Draft` → `Approved` → `In Progress`), after the owner chose the private advisory in Open question 1. Open question 2 only blocks the release step, as written.
- 2026-11-02: baselines measured (AC5, AC6) before any change. `v1.8.4` confirms the worst row of the table in Problem: the leak reaches the HTTP response, with no login.
- 2026-11-02: the check lives in `.github/scripts/image-php-check.sh`, not inline in the workflow, so AC7 can run the same code locally against any image, and it also guards spec 073's three settings.
- 2026-11-02: the log check in `docs/security.md` searches for `createPdoConnection('`, with the quote, not for the function name as the spec's wording could suggest: the name stays in today's traces (FR3), so it would count fixed logs too.
- 2026-11-02: `print-worker` is on the check's list because `bin/worker` also dies on a connection failure (AC4), and each attempt writes the trace to the container logs, so a database outage may have left the prefix there.
- 2026-11-02: first `/spec-review` found no blocker in the code. Applied from it: the log check named `migrate`, which `v1.8.4` and the development compose lack, so the command would report `no such service` and `grep` would still print `0`, a false all-clear; "up to `v1.8.4`" in the docs claimed more than was measured; stale text in this spec; and AC12 added for a promise with no evidence. AC12 then corrected this spec's own table about `APP_DEBUG`.
- 2026-11-02: release order, and a deviation from Open question 1. `release/1.8.5` reached the public repository before its PR existed, and creating the PR was then refused twice by the session's permission check, so the details in that branch were public before the tag. The PR text was rewritten without the details, the owner reviewed and merged PR #63, and the tag was created through the GitHub API on the merge commit (`a0cfd80`), as `git fetch` has no network in the sandbox. The advisory's temporary fork carries both branches, but the public PRs did the shipping: GitHub Actions does not run on that fork.
- 2026-11-02: docs pass: `docs/architecture.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` and `README.md` assert nothing about trace contents, so they did not change.

## Validation evidence

All values below are made up. AC1 to AC6 ran on throwaway containers with no network (`gf074-*`), with the requests made from inside the container by a PHP probe. The probe reports which strings it found, not the bodies.

**AC1 — met.** New image:

```
display_errors => Off => Off
display_startup_errors => Off => Off
log_errors => On => On
zend.exception_ignore_args => On => On
```

The image from before this change reports the first three the same, and `zend.exception_ignore_args => Off => Off`.

**AC2 — met.** New image, unreachable database:

```
/api/events/stream.php     HTTP/1.0 500 Internal Server Error       bytes=0      found=[]
/health/ready              HTTP/1.1 503 Service Unavailable         bytes=81     found=[]
/health/live               HTTP/1.1 200 OK                          bytes=32     found=[]
```

The cause spec 073 left open is confirmed: the log shows `SQLSTATE[HY000] [2002] Connection refused` from `public/api/events/stream.php(49)`.

**AC3 — met.** `docker logs`: `PHP Fatal error:  Uncaught PDOException: … Connector->createPdoConnection()` with no argument values, and neither the password prefix nor `'fake_user'`.

**AC4 — met.** New image:

```
bin/worker --once          exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
bin/migrate --status       exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
bin/jobs-status            exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
```

**AC5 — measured: the leak is real on the latest release.** Image built from tag `v1.8.4` (`git archive`):

```
/api/events/stream.php     HTTP/1.1 200 OK    bytes=6680   found=[FAKEPASSWORD123, fake_user, createPdoConnection, Fatal error, /var/www/html]
bin/worker --once          exit=255  stdout=[FAKEPASSWORD123, fake_user, createPdoConnection, Fatal error, /var/www/html] stderr=[]
bin/migrate --status       exit=255  stdout=[FAKEPASSWORD123, fake_user, createPdoConnection, Fatal error, /var/www/html] stderr=[]
bin/jobs-status            exit=255  stdout=[FAKEPASSWORD123, fake_user, createPdoConnection, Fatal error, /var/www/html] stderr=[]
```

**AC6 — met.** Image from before this change (tree identical to `c9c13c7`): `/api/events/stream.php` answers 500 with 0 bytes, and `docker logs` has `createPdoConnection('mysql:host=127....', 'fake_user', 'FAKEPASSWORD123...', Array)`. The three `bin/*` commands have the prefix on stderr.

**AC7 — met.** `.github/scripts/image-php-check.sh` exits 0 on the new image and 1 on the old one, with `FAIL` on `zend.exception_ignore_args`, on the password and on the user. That run replayed real output captured from both images through a stand-in `docker` command, because the script cannot reach Docker from the sandbox, so that replay did not exercise its own `docker run` lines. On 2026-11-02 the owner ran the script on real Docker against `gf074-after`: all 7 lines `ok`, no `FAIL`, nothing on stderr (the exit code was not displayed). Against `gf074-before`: `FAIL` on `zend.exception_ignore_args`, on the password and on the user, `exit=1`. On PR #64, `dry-run` passed (run 41391851184), with the step `PHP error settings of the image: success`, and `test` passed (run 41391851144), including PHPStan, PHP-CS-Fixer, PHPUnit, the integration tests and Playwright. Only the step results were read, not the test counts.

**AC8 — met.** After `docker compose -f docker-compose.dev.yml up -d --build --no-deps web print-worker` and recreating `web-e2e` (database untouched), all three report `display_errors => STDOUT => STDOUT` and `zend.exception_ignore_args => On => On`.

**AC9 — met.** Password steps on a throwaway `mysql:8.0` and a volume of its own: `ALTER USER`, then the container recreated on the same volume with the new `MYSQL_PASSWORD`. The app then answers:

```
password=fake_old_pw  503  DB_UNAVAILABLE
password=fake_new_pw  503  MIGRATIONS_PENDING   (it connected; the throwaway database has no migrations)
```

The SQL was run non-interactively, not typed at the prompt that the doc describes, and with plain `docker`, not the Compose stack. The count's search string matches the old image's captured trace (1 line) and not the new one's (0), checked with `grep -cF` on the captures. It was not run on real logs. After `/spec-review`, the `migrate` service left the main command: `docker compose -f docker-compose.dev.yml logs --tail 0 web print-worker migrate` answered `no such service: migrate`, exit 1, confirming the false `0` it would have caused.

**AC10 — met.** The entry is under `v1.9.0` › Segurança, `v1.8.5` has its own dated section on both `release/1.8.x` and PR #64, and spec 073 has its correction.

**AC11 — run, with the known gaps.**

- PHPUnit, with `MYSQL_DATABASE_TEST=restaurant_test`: `Tests: 322, Assertions: 826, Skipped: 2`. The 2 skipped are `MigrationUpgradeTest`, as in spec 073.
- PHPStan: `[OK] No errors`. PHP-CS-Fixer was not run, as no PHP file changed.
- Playwright (`mcr.microsoft.com/playwright:v1.63.0-noble`, port 8081): `40 passed, 5 failed`. All 5 are in `printer-block.spec.ts` with `Error: spawnSync docker ENOENT`, the limitation recorded in specs 070 and 073, so those 5 did not exercise the screens here.

**AC12 — met.** Throwaway `mysql:8.0`, app with `APP_DEBUG=true` and a wrong made-up password. New image:

```
/api/events/stream.php     HTTP/1.0 500   bytes=0      no-trace-field found=[]
/api/menu                  HTTP/1.1 500   bytes=3824   trace-field    found=['fake_user', Access denied, using password: YES]
/api/settings/discount     HTTP/1.1 500   bytes=4402   trace-field    found=['fake_user', Access denied, using password: YES]
logs/app.log found=['fake_user', Access denied, using password: YES]
```

Image before this change: the same strings, with larger bodies (4739 and 5455 bytes), and no password prefix. So the password did not reach this path before either, and the argument values that do disappear are others, such as SQL. On the new image, `docker logs` shows `SQLSTATE[HY000] [1045] Access denied for user 'fake_user'@'172.21.0.3' (using password: YES)`, `createPdoConnection()` and no password. `'fake_user'` above comes from MySQL's message, not from a trace argument. The log of the old image was not read in this case.

**`v1.8.5` image, built from a clean `release/1.8.x` tree with the same settings.** Database unreachable:

```
/api/events/stream.php     HTTP/1.0 500 Internal Server Error       bytes=0      found=[]
/health/ready              HTTP/1.1 503 Service Unavailable         bytes=81     found=[]
/health/live               HTTP/1.1 200 OK                          bytes=32     found=[]
bin/worker --once          exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
bin/migrate --status       exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
bin/jobs-status            exit=255  stdout=[] stderr=[createPdoConnection, Fatal error, /var/www/html]
exception_ignore_args='1'
```

With a throwaway database loaded from that tree's `common/sql` and `bin/migrate` (exit 0), `/health/live`, `/health/ready`, `/api/menu`, `/cashier/`, `/kitchen/` and `/admin/index.php` answer 200 with no PHP error text, and `/api/events/stream.php` answers 200 with its `connected` event. The PHPUnit and Playwright suites were not run on this branch: it changes no PHP, and its `Dockerfile` carries the same settings as this spec's.

`Verified`: every criterion has its evidence above, with these limits on record. AC9's SQL ran non-interactively, outside Compose, and its count ran on captures. In AC11, the 2 upgrade tests and the 5 `printer-block` tests did not exercise the development stack; CI ran those suites and passed. `v1.8.5` has no suite run of its own, as it changes no PHP.
