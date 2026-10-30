# Spec 066 — Separate deployment: development × Community production

## Metadata

- Status: Verified (approved by the owner 2026-10-30 — recommended options for Q1/Q2; every acceptance criterion has recorded evidence, with AC2 simulated and AC10 run on a copy — see Validation evidence)
- Created: 2026-10-30
- Updated: 2026-10-30
- Owner: Henry (FocusEven)
- Related issue: Not applicable — `docs/ROADMAP.md` › v1.9.0 › "Separate deployment: development × Community production" (closing order item 2)
- Related branch: `066` (to be created by `/spec-implement`)

## Context

Item 2 of the v1.9 closing order agreed on 2026-10-27 (`docs/ROADMAP.md` › "Closing order"). Spec 063 documented the upgrade procedure and, while doing so, found that it cannot be made safe with the current compose: `docs/upgrading.md` › "Why the order matters" admits that the new code is live the moment `git checkout` finishes, before `bin/migrate` runs. The CHANGELOG's spec 058 entry carries the same correction ("'antes' não é possível — os containers rodam o código direto da pasta"). Spec 064 made restaurants run release tags; this spec makes a release tag mean a *built image*, not a working-tree folder.

## Problem

`docker-compose.yml` is a development compose used as the production one:

- `web` and `print-worker` bind-mount `public/`, `src/`, `common/`, `bin/`, `tests/`, `composer.json`, `composer.lock`, `phpunit.xml` and `vendor/` from the host (`docker-compose.yml:24-44`, `62-73`). The image's own copy of the code (`Dockerfile:40-49`) is always shadowed, so `git checkout vX.Y.Z` changes the running code immediately, before migrations — observed for real with migration 021 (spec 058/063).
- `vendor/` comes from the host, so an install needs a manual `docker compose exec web composer install --no-dev` (`docs/installation.md` step 6); skipping it gives `Fatal error … vendor/autoload.php` with HTTP 200 on every page and a restarting `print-worker`.
- The development tooling (`tests/`, `phpunit.xml`, `phpstan*.neon`, `.php-cs-fixer.dist.php`) and the read-only `.git` are mounted into the restaurant's containers.

## Goals

- A production compose file that runs the built image, with code, `vendor/` and assets **inside** the image, and mounts only data and configuration: the database volume, the restaurant logo, logs, and `.env`.
- Updating the code on disk (`git checkout`) does **not** change what the restaurant runs; only an explicit image build and container recreate does.
- Migrations are applied **before** the new code starts serving requests.
- The development compose keeps its bind mounts and its current workflow (including the e2e override, spec 040).
- `docs/installation.md`, `docs/upgrading.md` and `docs/commands.md` describe the production compose; the development workflow is documented separately.
- `/version` keeps reporting the exact release tag in production (it is a validation step of `docs/upgrading.md`).

## Non-goals

- Publishing images to a registry, versioned image tags `2.0.0`/`2.0`/`2`, GitHub Releases — that is closing-order item 6 ("Release automation"). This spec builds the production image locally from the checked-out tag; the compose file is written so item 6 can later swap `build:` for a published `image:` without changing anything else.
- Changing the PHP base image, `composer:latest` in the `Dockerfile`, or any dependency version (CLAUDE.md: no bumps without a request — see Open questions).
- A Docker `HEALTHCHECK` for `web` (still no `curl`/`wget` in the image; spec 044 follow-up stays open). The `db` healthcheck proposed below uses `mysqladmin`, which ships in `mysql:8.0`.
- Zero-downtime upgrades. Upgrading still happens with the restaurant closed.
- Rollback of migrations (forward-only stays, spec 048).

## Current behavior

Confirmed by reading the code:

- **Compose** (`docker-compose.yml`): `db` (`mysql:8.0`, volume `db_data`, `001_schema.sql` mounted as init script, `127.0.0.1:3306`), `web` (`build: .`, image `gastroflow-web`, container `restaurant_web`, port `8080:80`, bind mounts listed above plus `./.git:ro`, `./.env`, `./docker/php-timezone.ini:ro`) and `print-worker` (same image, `php bin/worker print`, same bind mounts minus `.git` and quality configs). All `restart: unless-stopped`. `depends_on: db` without a health condition.
- **Image** (`Dockerfile`): `php:8.2.35-apache` + GD etc.; `composer install --no-dev --optimize-autoloader --no-scripts` runs **before** `src/` is copied (`Dockerfile:43-47`), so the optimized classmap has no `App\` classes — the PSR-4 fallback still resolves them, so this is a missed optimization, not a bug. Copies `public/`, `src/`, `common/`, `bin/`; then `chown -R www-data` and `chmod -R 755` (marks every file executable). Timezone is already written to `conf.d/timezone.ini` (`Dockerfile:57-58`), so the mounted `docker/php-timezone.ini` (same value) is redundant in the image.
- **Build context** (`.dockerignore`): excludes `.git`, `.env*` (keeps `.env.example`), `logs/`, `*.md`, `docker-compose.yml`, `Dockerfile`, `.cache`. It does **not** exclude `vendor/`, `backups/` or `tests/`: `backups/` (database dumps with restaurant data) is sent to the Docker daemon on every build, though never copied into the image.
- **`.env`**: `public/index.php:9-10` calls `Dotenv::createImmutable(...)->load()`, which throws when `.env` is absent. Real environment variables win over the file (`createImmutable`; the e2e compose relies on it, `docker-compose.e2e.yml:8-11`). `.env` is never in the image (spec 013) — it is mounted.
- **Logo**: written to `Settings::getLogoPath()` = `public/assets/img/logo.png` (`src/Settings.php:120-128`) by `SettingsController::uploadLogo()` (`src/Controllers/SettingsController.php:124-130`) and read by `PrintService` (spec 065). It lives **inside the code tree**: with the code baked into the image, an upload would be lost on every container recreate. *Confirmed 2026-10-30 (step 1):* `public/assets/img/` holds five **tracked** files — `logo.png` (the GastroFlow logo, shown at the top of `README.md` and serving as every install's default logo) and the four README screenshots `tela_*.jpg`. The only page referencing the logo URL is `public/admin/settings.js` (lines 10, 67, 72, 84, `/assets/img/logo.png?<timestamp>`). Consequence found today, independent of this spec: an upload **overwrites a tracked file**, so every install that set its own logo has a modified working tree — exactly the "`git checkout` refuses because of local changes" case `docs/upgrading.md` step 3 attributes to someone editing files by hand, and a release that ever changes `logo.png` would block that install's upgrade. Moving uploads out of the code tree fixes this too. Design consequences: the image's `public/assets/img/logo.png` stays as the **default** (used when no upload exists), and an install switching to production must carry its uploaded logo into the uploads volume (one-time step in `docs/upgrading.md`, or a startup copy when the volume is empty and the host file differs from the tracked one — decided in step 4 and logged).
- **Logs**: `Settings::getLogFile()` = `logs/app.log` (`src/Settings.php:105-113`), written by Monolog (`src/App.php:41-48`). Today `logs/` is not mounted at all in `docker-compose.yml`, so the log already lives only in the container's writable layer and is lost on every recreate — an existing gap the production compose closes.
- **Version**: `VersionController::describe()` runs `git describe --tags --always` on `/var/www/html/.git` and returns `"dev"` when that directory is missing (`src/Controllers/VersionController.php:28-43`). An image without `.git` reports `"dev"`, which would break `docs/upgrading.md` steps 1 and 7.
- **Backup/restore**: `bin/backup-db`/`bin/restore-db` run on the host and call `docker compose exec -T db …` with no `-f`, i.e. whatever compose file Compose resolves by default in the repository folder (`bin/backup-db:29-44`). They write to `./backups/` on the host — already outside the containers, so backups are unaffected by this spec as long as `docker compose` resolves to the production file.
- **Other compose users**: `docker-compose.e2e.yml` (spec 040) is meant to be merged with `docker-compose.yml` (`-f docker-compose.yml -f docker-compose.e2e.yml`) and uses the `db` service from it. CI (`.github/workflows/ci.yml`) is not believed to use Compose (it runs a MySQL service directly) — to be confirmed during implementation.

## Proposed behavior

### Files

- `docker-compose.yml` — **production** (the default, so a restaurant that types `docker compose …` gets production — same "safe by default" choice spec 062 made for `.env.example`). Decided in Q1.
- `docker-compose.dev.yml` — the current development compose, unchanged in substance (bind mounts, `.git:ro`, quality configs). Developers select it with `COMPOSE_FILE=docker-compose.dev.yml` in their own `.env`; Compose reads `COMPOSE_FILE` from the project `.env`, so `docker compose …`, `bin/backup-db` and `bin/restore-db` keep working unchanged in both setups.
- `docker-compose.e2e.yml` — header and usage updated to `-f docker-compose.dev.yml -f docker-compose.e2e.yml`.

### Production services

- `db`: as today, plus a `healthcheck` using `mysqladmin ping` (already in `mysql:8.0`; credentials from the container's own environment, never the host `.env`).
- `migrate` (new, one-shot): same image, `command: php bin/migrate`, `restart: "no"`, `depends_on: db: condition: service_healthy`. Mounts only `.env:ro`.
- `web`: `image: gastroflow:local` (fixed tag — *corrected 2026-10-30 after `/spec-review`; was `gastroflow:${GASTROFLOW_VERSION:-local}`, see Implementation log*) built from `.`, `depends_on: migrate: condition: service_completed_successfully`. Mounts **only**: `.env:ro`, the logo volume, the logs volume. Port `8080:80`, `restart: unless-stopped`, container name `restaurant_web` kept (docs and aliases use it).
- `print-worker`: same image, `php bin/worker print`, same `depends_on` and same mounts as `web` (it reads the logo and writes the log).
- Volumes: `db_data` (same name as today, so the project's existing database volume is reused — see Backward compatibility), plus `gastroflow_logs` and `gastroflow_uploads` (names final at implementation).

Effect on upgrade: `git checkout vX.Y.Z` changes nothing that runs. `docker compose up -d --build` builds the new image while the old containers keep serving, then runs `migrate` with the **new** code and starts the new `web`/`print-worker` only if it exited 0. A refused migration (hash divergence, SQL error) leaves the new containers unstarted and `docker compose up` exits non-zero. Whether Compose stops the *old* `web` before or after `migrate` finishes, and what is left running when `migrate` fails, must be **observed** during implementation and written into `docs/upgrading.md` exactly as observed — not assumed here.

### Version

`Dockerfile` gets `ARG GASTROFLOW_VERSION` and writes it to a `VERSION` file in the image. `VersionController` reads `VERSION` first (when present and non-empty) and falls back to today's `git describe`, then `"dev"` — so the development compose behaves exactly as now. The production compose passes `args: GASTROFLOW_VERSION: ${GASTROFLOW_VERSION:-}`; the docs set it from the checked-out tag (`GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build`) or a small host helper wraps that — see Open question Q2.

### Logo

The logo moves out of the code tree into a data directory mounted as a volume. Recommended: `Settings::getLogoPath()` points to `public/uploads/logo.png`, with `public/uploads/` as the mounted volume, and every reference to the old URL updated; `PrintService` keeps reading through `Settings::getLogoPath()`, so it needs no change. A legacy `public/assets/img/logo.png` from an install that predates this spec is read as a fallback (or copied once — decided at implementation and logged), so no restaurant loses its logo on upgrade. Rejected alternative: mounting a volume over `public/assets/img/` itself — a named volume is only populated from the image when empty, so any tracked image in that folder would stay frozen at the first install's version forever.

### Image hygiene (needed for the image to *be* the release)

- `.dockerignore` adds `vendor/`, `backups/`, `tests/`, `logs/` (already), `public/uploads/`, `public/assets/img/logo.png`, `docker-compose*.yml` — a developer's local logo, dumps or vendor never reach the build context.
- `composer dump-autoload --optimize --no-dev` after the code is copied (the classmap then includes `App\`); no version change to Composer or any package.
- Permissions: `chown -R www-data:www-data` kept; `chmod -R 755` replaced by directories `755`, files `644`, `bin/*` `755` — ordinary files are no longer marked executable.
- `docker/php-timezone.ini` is not mounted in production (the image already sets the same timezone); `APP_TIMEZONE` in `.env` still overrides at the application level (`src/Settings.php:55-58`).

## Functional requirements

1. FR1 — In production, `web` and `print-worker` have no bind mount of any path under `public/`, `src/`, `common/`, `bin/`, `vendor/`, `tests/` or `.git`; their only mounts are `.env` (read-only), the uploads volume and the logs volume.
2. FR2 — After `git checkout` of a different commit in the install folder, with no `docker compose` command run, the code served by `web` is unchanged (`/version` and file contents in the container stay the same).
3. FR3 — `docker compose up -d --build` on a fresh clone with a filled `.env` brings `db`, `web` and `print-worker` to "Up" and the database to "no pending migrations", with **no** `composer install` or `bin/migrate` command typed by the operator.
4. FR4 — When a pending migration fails, the new `web`/`print-worker` containers are not started, and `docker compose up` exits non-zero with `bin/migrate`'s error visible in `docker compose logs migrate`.
5. FR5 — A logo uploaded through Admin › Configurações survives `docker compose up -d --build` and `docker compose down && docker compose up -d`, and is still printed (spec 065 behavior).
6. FR6 — `logs/app.log` survives a container recreate and is still shown in Admin's log viewer.
7. FR7 — `GET /version` in production returns the tag the image was built from (e.g. `v1.9.0`) when built at a tag, and falls back to `"dev"` — never an error — when no version was passed. In the development compose it keeps returning `git describe` as today.
8. FR8 — `bin/backup-db`, `bin/restore-db`, `bin/create-admin`, `bin/seed-demo`, `bin/seed-client-1`, `bin/jobs-status` and `bin/migrate --status` work in production with the same commands documented today (`docker compose exec web php bin/…` or `./bin/…` on the host).
9. FR9 — The development compose (`docker-compose.dev.yml`, selected by `COMPOSE_FILE`) keeps every current bind mount; editing `src/` is reflected without rebuilding; PHPUnit, PHPStan, PHP-CS-Fixer and the e2e instance on port 8081 still run with their documented commands (only the `-f` file name of the e2e command changes).
10. FR10 — An existing install (pilot) upgraded to the production compose keeps its database (same `db_data` volume) and its logo.

## Non-functional requirements

- No new dependency, no base-image/Composer/package version change (CLAUDE.md).
- The `.env` file is never copied into the image (spec 013) and is mounted read-only.
- The build context never includes `backups/` or `.env`.
- The database still publishes only on `127.0.0.1:3306` (spec 062).
- Container names `restaurant_web`/`restaurant_print_worker` unchanged.

## User flows

- **New restaurant (installer)**: clone → `git checkout vX.Y.Z` → `cp .env.example .env` → edit `.env` → `docker compose up -d --build` (migrations run automatically) → `docker compose exec web php bin/create-admin <user>` → Admin. Steps 6–7 of today's guide disappear.
- **Upgrade**: read CHANGELOG → `./bin/backup-db` → `git fetch --tags && git checkout vX.Y.Z` (nothing changes yet) → `.env` diff → `docker compose up -d --build` (build, migrate, swap) → validate.
- **Developer**: `COMPOSE_FILE=docker-compose.dev.yml` in `.env` once → same commands as today, including `composer install` and `bin/migrate` by hand.
- **Pilot install** (follows `master` as a test install, spec 064): one-time switch documented in the CHANGELOG ⚠️ note — see Rollout.

## API changes

Not applicable to the API surface: no endpoint, request or status code changes. `GET /version`'s response shape is unchanged; only its value source gains a `VERSION` file ahead of `git describe`. If the logo URL changes (`/assets/img/logo.png` → `/uploads/logo.png`), that is a static file path, not an API route — any page that references it is updated in the same change.

## Data model and migrations

Not applicable: no table or column changes, no migration file. Migrations are only *run* differently (by the `migrate` service).

## Architecture and affected components

- `docker-compose.yml` (rewritten as production), `docker-compose.dev.yml` (new — today's file), `docker-compose.e2e.yml` (usage comment), `Dockerfile`, `.dockerignore`, `.env.example` (documents `COMPOSE_FILE` and `GASTROFLOW_VERSION`; commented out by default).
- `src/Settings.php` (`getLogoPath()`/upload directory), `src/Controllers/SettingsController.php` (upload destination via `Settings`, no logic change), `src/Controllers/VersionController.php` (`VERSION` file first).
- Frontend references to the logo URL under `public/` (to be located — not confirmed in this investigation).
- No Controller/Service/Repository/Validator added.
- Docs: `docs/installation.md`, `docs/upgrading.md`, `docs/commands.md`, `README.md` (quickstart and development section), `docs/architecture.md` (deployment), `docs/technical-decisions.md` (why production = default file, why a `migrate` service), `specs/000-project-baseline.md`, `CLAUDE.md` (Commands: dev compose selection, e2e command), `CHANGELOG.md`, `docs/ROADMAP.md` (status line).

## Security considerations

- Fewer things mounted into the restaurant's containers: no `.git` (repository history), no `tests/`, no writable source tree — a compromised PHP process can no longer rewrite the code on the host.
- `.env` mounted `:ro` (today it is read-write).
- `backups/` leaves the build context, so restaurant data is no longer sent to the Docker daemon on each build.
- `VERSION` is a build argument, not a secret; nothing from `.env` is passed as a build arg.
- The uploads volume is served by Apache from `public/`; the upload path keeps re-encoding to PNG (spec 065), so only a PNG ever lands there. No PHP is executable from it in practice, since only `logo.png` is ever written — implementation must confirm the folder holds nothing else.
- The `db` healthcheck must not put the password on a command line visible in `docker inspect`/`ps` (use `MYSQL_PWD` inside the container's shell, as `bin/backup-db` does).

## Backward compatibility

- **Existing installs (the pilot)**: Compose derives the project name from the folder, so the same folder reuses the same `db_data` volume — the database is kept. Must be verified on a copy before the pilot (see Validation). The logo at `public/assets/img/logo.png` on the host is carried over by the fallback/copy described in Proposed behavior.
- **Developers**: must add `COMPOSE_FILE=docker-compose.dev.yml` to their `.env` once; without it they get the production compose (code not live-mounted) — noticed immediately, nothing destroyed. The global aliases in `~/.bashrc` (`dc`, `dweb`, …) keep working because they call `docker compose` in the project folder.
- **e2e**: the documented command changes from `-f docker-compose.yml` to `-f docker-compose.dev.yml`; `CLAUDE.md` and `tests/e2e` docs updated.
- **`docs/upgrading.md` rollback to a version older than this spec**: that version's `docker-compose.yml` is the dev compose again; the rollback section must say so (and that `composer install` is needed again there).
- **CHANGELOG ⚠️ note** required: the first upgrade to this version changes how the stack runs.

## Acceptance criteria

1. AC1 (FR1) — `docker compose config` (production) lists, for `web` and `print-worker`, exactly three mounts: `.env` read-only, the uploads volume, the logs volume. `docker inspect restaurant_web --format '{{json .Mounts}}'` shows the same.
2. AC2 (FR2) — On a production install at tag/commit A, `git checkout` B (B touching a file in `src/`), then `docker compose exec web md5sum src/<that file>` returns A's hash and `curl localhost:8080/version` still returns A's version.
3. AC3 (FR3) — On a fresh clone in a new folder (empty Docker volumes for that project), `cp .env.example .env` + filling the passwords/`JWT_SECRET` + `docker compose up -d --build` → `docker compose ps` shows `db`, `web`, `print-worker` "Up" (`migrate` exited 0), `docker compose exec web php bin/migrate --status` exits 0, `curl localhost:8080/health/ready` returns `{"success":true,"status":"ready"}` — with no `composer install` or `bin/migrate` typed.
4. AC4 (FR4) — With a deliberately broken migration added on a throwaway branch (never the development database), `docker compose up -d --build` exits non-zero, `docker compose logs migrate` shows the SQL error, and no container from the new image serves port 8080. What the old container did is recorded in the Validation evidence and in `docs/upgrading.md`.
5. AC5 (FR5) — Upload a PNG in Admin; `docker compose up -d --build --force-recreate` and `docker compose down && docker compose up -d`; `GET /api/admin/settings` still returns `logo_printable: true` and the logo is visible in Admin.
6. AC6 (FR6) — A log line written before `docker compose up -d --force-recreate` is still present in Admin's log viewer afterwards.
7. AC7 (FR7) — Built with `GASTROFLOW_VERSION=v1.9.0-test`, `curl localhost:8080/version` returns `{"success":true,"version":"v1.9.0-test"}`; built without it, `"dev"` with HTTP 200; in the dev compose, the same output as `git describe --tags --always` on the host.
8. AC8 (FR8) — In production: `./bin/backup-db` creates a non-empty `.sql.gz`; `./bin/restore-db --yes <file>` into a throwaway project restores it; `docker compose exec web php bin/create-admin` and `bin/jobs-status` run without error.
9. AC9 (FR9) — With `COMPOSE_FILE=docker-compose.dev.yml`: an edit to a file in `src/` is visible without rebuilding; `docker compose exec web vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix --dry-run --diff` give the same result as on `master`; the e2e suite passes against port 8081 with the updated `-f` command.
10. AC10 (FR10) — On a **copy** of the development stack switched to the production compose (never the development database itself): order count and the logo are the same before and after, and `/health/ready` returns ready.
11. AC11 — `docker build` context listing (or `docker build` output size) shows that `backups/`, `vendor/` and `.env` are not sent; `docker compose exec web find /var/www/html -type f -perm -u+x` lists only `bin/` scripts.
12. AC12 — `docs/installation.md` and `docs/upgrading.md` contain no `composer install` step and no bind-mount explanation for the production path; following `docs/installation.md` literally (in a new folder) reproduces AC3.

## Implementation plan

1. Confirm the unknowns before changing anything: list `public/assets/img/`, find every reference to `logo.png` under `public/` and `src/`, confirm `ci.yml` does not use Compose, confirm the project/volume name Compose uses for this folder. Record in the Implementation log.
2. `.dockerignore` + `Dockerfile` hygiene (context exclusions, `dump-autoload` after copy, file permissions, `ARG GASTROFLOW_VERSION` → `VERSION`). Build and check AC11.
3. `VersionController`: `VERSION` file first, then `git describe`, then `"dev"`; unit test for the three branches.
4. Logo out of the code tree: `Settings` upload dir/path, legacy-path fallback or one-time copy, frontend URL references; unit/integration tests for `getLogoPath()` and the upload endpoint updated.
5. Move today's `docker-compose.yml` to `docker-compose.dev.yml`; update `docker-compose.e2e.yml`'s usage comment; add `COMPOSE_FILE` (commented) to `.env.example`.
6. Write the production `docker-compose.yml` (`db` healthcheck, `migrate`, `web`, `print-worker`, volumes). Validate AC1, AC3, AC2, AC7.
7. Failure path: broken migration on a throwaway branch/project → AC4; record exact Compose behavior.
8. Data survival: AC5, AC6, then AC10 on a copy of the stack; AC8 (backup/restore) in production.
9. Development regression: AC9 (PHPUnit, PHPStan, CS-Fixer, e2e).
10. Docs pass (same PR): `installation.md`, `upgrading.md` (order rationale rewritten, rollback across this version), `commands.md`, `README.md`, `architecture.md`, `technical-decisions.md`, baseline, `CLAUDE.md`, `ROADMAP.md`, `CHANGELOG.md` with the ⚠️ switch note. AC12 by following `installation.md` literally.

## Testing and validation strategy

Note: the `/spec-plan` skill text says the project has "no automated test infrastructure today" — that is outdated (PHPUnit Unit/Integration suites since specs 033/035, CI since 034, Playwright since 040). Reported here rather than silently followed.

- Automated: unit tests for `VersionController` (VERSION file / git / `"dev"`) and `Settings::getLogoPath()`; the existing logo-upload tests updated to the new path; full PHPUnit + PHPStan + CS-Fixer in the dev compose and in CI.
- Compose behavior cannot be unit-tested: AC1–AC8 and AC10–AC12 are verified by running the commands above against a **separate Compose project** (different folder or `-p gastroflow-prod-test`, its own volumes, its own `.env` with throwaway passwords), never against the development database or its `db_data` volume. Every command and its real output goes into Validation evidence.
- AC9 by running the existing suites and the e2e suite with the dev compose.

## Rollout and rollback

- Ships in v1.9.0 with a CHANGELOG ⚠️ note. Restaurants on `v1.8.x` meet it on their first upgrade following `docs/upgrading.md`, which must describe the one-time switch (pre-switch backup, logo carried over, `vendor/` folder on the host no longer used).
- Pilot install (follows `master`): switch after merge, with a backup first; it is the real-world check of AC10.
- Developers: add `COMPOSE_FILE=docker-compose.dev.yml` to `.env`.
- Rollback: `git checkout <previous version>` restores the old single compose file; `docker compose up -d --build` + `composer install --no-dev` (needed again in the old layout) + `./bin/restore-db` only if a migration ran. The `db_data` volume is shared by both layouts, so no data moves. Documented in `docs/upgrading.md` › Rollback.

## Open questions

- **Q1 — resolved 2026-10-30 (owner):** `docker-compose.yml` = production; `docker-compose.dev.yml` selected by `COMPOSE_FILE=docker-compose.dev.yml` in the developer's `.env`. Rejected: keeping `docker-compose.yml` as dev plus a `docker-compose.prod.yml` (a restaurant whose `.env` predates it would silently stay on the dev layout).
- **Q2 — resolved 2026-10-30 (owner):** automatic migrations via the `migrate` one-shot service; `web`/`print-worker` start only after it exits 0. Accepted trade-off: an upgrade can no longer be rebuilt without migrating.
- Q3 — *Resolved 2026-10-30 at implementation: the env var on the command line.* How the version reaches the build: env var in the documented command (`GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build`) versus a small host helper (`bin/deploy`) that also runs the backup first. Recommended: the env var now, revisit in item 6 (release automation, where CI sets it). Not blocking.
- Q4 — `composer:latest` in the `Dockerfile` makes the production image's Composer floating. Pinning it is a version change, so it is out of scope unless requested; worth deciding before item 6. Not blocking.
- Q5 — *Resolved 2026-10-30 at implementation: `/uploads/logo.png`, served from the API's `logo_url`.* Logo URL: `/uploads/logo.png` (recommended) vs keeping `/assets/img/logo.png` via an Apache alias to the volume. Decided at step 1 once the references are known. Not blocking.

## Task checklist

- [x] 1. Confirm unknowns (assets folder, logo references, CI, volume name)
- [x] 2. `.dockerignore` + `Dockerfile` hygiene and `VERSION` build arg
- [x] 3. `VersionController` reads `VERSION` first (+ tests)
- [x] 4. Logo out of the code tree (+ tests, default-logo fallback)
- [x] 5. `docker-compose.dev.yml` + e2e usage + `.env.example`
- [x] 6. Production `docker-compose.yml`
- [x] 7. Failure path (AC4)
- [x] 8. Data survival + backup/restore (AC5, AC6, AC8, AC10)
- [x] 9. Development regression (AC9: PHPUnit, PHPStan, CS-Fixer, Playwright)
- [x] 10. Docs + CHANGELOG + ROADMAP pass (AC12 covered by the AC3 run, not by a literal second pass through the guide)

## Implementation log

- 2026-10-30 — Step 1. `public/assets/img/` has 5 tracked files (`logo.png` + 4 README screenshots), so a volume over that folder is ruled out for good (it would freeze the screenshots and the default logo). The uploaded logo overwrites the tracked `logo.png` (see Current behavior › Logo). Only `public/admin/settings.js` references the logo URL. CI (`.github/workflows/ci.yml`) uses a GitHub Actions `services:` MySQL and `setup-php`, not Compose, so the compose rename does not affect it. `.gitignore` covers `/vendor/`, `.env` and `/logs/*.log`; `backups/` to be checked in step 2. The Compose project/volume name could not be confirmed: the session's sandbox cannot reach the Docker socket. *(Resolved later the same day: `docker` was excluded from the sandbox; the development project is `gastroflow`, its database volume `gastroflow_db_data`.)*
- 2026-10-30 — Branch: the `/spec-implement` skill forbids changing branches, so the work sits uncommitted on `master`'s working tree; branch `066` is created when the owner asks for the commit.
- 2026-10-30 — Step 2. **Deviation from Proposed behavior:** `.dockerignore` does **not** exclude `public/assets/img/logo.png` — it is the tracked default logo (step 1), so it must be in the image. Excluded instead: `vendor/`, `backups/`, `tests/`, `public/uploads/`, and every `docker-compose*.yml`. The `Dockerfile` also creates `public/uploads/` and `logs/app.log` owned by `www-data`, so a new named volume is populated from the image already writable, and a `docker compose exec` as root can never create `app.log` owned by root (which would lock Apache out of its own log).
- 2026-10-30 — Step 3. `VersionController` now takes `Settings` (autowired) for the base path, so the unit test can point it at a temp directory.
- 2026-10-30 — Step 4. `Settings` gains `getUploadsDir()`, `getUploadedLogoPath()`, `getDefaultLogoPath()` and `getLogoUrl()`; `getLogoPath()` prefers the upload and falls back to the default, so `PrintService` is unchanged. Q5 decided: the URL is `/uploads/logo.png` (the `.gitignore` already reserved `/public/uploads/*`, never used); `GET /api/admin/settings` returns `logo_url` and `public/admin/settings.js` uses it instead of probing `/assets/img/logo.png`. For installs whose own logo sits in the tracked file, the carry-over is a documented one-time step (`docs/upgrading.md` › "First upgrade to v1.9"), not code: re-uploading through Admin keeps file ownership right, which `docker compose cp` (root-owned) would not.
- 2026-10-30 — Step 6. `migrate` and `print-worker` run as `www-data` (they share the `logs` volume with Apache). Image name `gastroflow:${GASTROFLOW_VERSION:-local}` *(replaced by a fixed `gastroflow:local` after `/spec-review` — entry below)*; all three app services carry the same `build:`, and Compose builds it once per `up` (shown three times in its output, one image). Q3 decided: `GASTROFLOW_VERSION` is passed on the command line, as documented; `docker/php-timezone.ini` is not mounted in production (the image already sets the same timezone).
- 2026-10-30 — Validation setup. The production compose was run as a separate Compose project: the working tree copied (`git ls-files -co --exclude-standard`) to the session scratchpad folder `gf-prodtest` (no `vendor/`, no `.git`, its own `.env` from `.env.example` with throwaway passwords), plus a test-only override changing container names (`prodtest_web`, `prodtest_print_worker`) and ports (8090, 3316) so it could run next to the development stack. The development database was only **read** (one `mysqldump` for AC10).
- 2026-10-30 — Finding (AC4): on `up -d --build` Compose recreates (stops) the old `web`/`print-worker` **before** running the new `migrate`; when it fails, the new containers stay `Created`. So a failed migration leaves GastroFlow down — no new code against a half-migrated database, as intended, but also no old code. Documented in `docs/upgrading.md` ("What changes when", Troubleshooting) and `docs/technical-decisions.md`; the documented exit (fix, or check out the previous version and `up` again) was exercised.
- 2026-10-30 — **`/spec-review` finding, fixed.** The image tag depended on `GASTROFLOW_VERSION` (`gastroflow:${GASTROFLOW_VERSION:-local}`), which only exists on the build command line. After a labeled build, a plain `docker compose up -d` — which `docs/commands.md` and the installation troubleshooting table tell operators to run — resolved `gastroflow:local`, a missing image, so Compose would build one from whatever was checked out and swap the running version without the upgrade step (and `/version` would read `dev`), against FR2. The validation had missed it because the test `.env` always carried the variable. Fix: fixed tag `gastroflow:local`; the version lives only in the image's `VERSION` file. Re-tested (Validation evidence › AC2). Process note from the owner: `/spec-review` runs **before** the PR is opened; here it ran after PR #52 was opened — and #52 was merged (2026-10-30 01:37, merge `b1b1a01`, head `7fb1414`) before the fix was pushed, so the fix needed a follow-up PR of its own instead of riding in #52. An exception to "one PR per spec", caused by the review running too late.
- 2026-10-30 — Environment, not this spec: `restaurant_test` did not exist on this PC (integration suite failed with `1044 Access denied`). Created with the one-time setup `CLAUDE.md` describes (`CREATE DATABASE restaurant_test` + `GRANT` for the app user, through the `db` container's root credentials); the development database `restaurant` was not touched.

## Validation evidence

Collected 2026-10-30. "Test project" = the separate Compose project `gf-prodtest` described in the Implementation log (production `docker-compose.yml` + a names/ports-only override). HTTP checks were made from inside `prodtest_web` (`php -r 'file_get_contents(...)'`), because the session's sandboxed `curl` has its own network namespace.

- **AC1 — met.** `docker compose … config`: `web` and `print-worker` mount exactly `bind .env → /var/www/html/.env (read_only)`, `volume uploads → public/uploads`, `volume logs → logs`; `migrate` the same. `docker inspect prodtest_web prodtest_print_worker` shows the same three mounts, `.env` with `RW=false`.
- **AC2 — met (simulated checkout; covers a plain `up -d` too since the fix).** The test folder is not a git clone, so "checkout B" was simulated by changing a file on disk: `src/Controllers/VersionController.php` md5 `219c4337…` → `c8499246…` in the folder; inside `prodtest_web` it stayed `219c4337…`, and `/version` still answered `v1.9.0-test`. File restored afterwards. **Re-run after the `/spec-review` fix (fixed `gastroflow:local` tag), on a fresh test project:** labeled build (`GASTROFLOW_VERSION=v1.9.0-test` in the test `.env`) → `/version` `v1.9.0-test`, `VersionController.php` md5 `219c4337…`, one image `gastroflow:local 42f655c955da`. Then the variable removed from `.env`, the file changed in the folder (md5 `2aa02146…`) and a plain `docker compose up -d` (no `--build`): `web`/`print-worker` stayed `Running` (not recreated; only the one-shot `migrate` ran again), no build, same image ID, container file still `219c4337…`, `/version` still `v1.9.0-test`. Finally `up -d --build` with no variable: image rebuilt (`fa8e52850255`), containers recreated, file `2aa02146…`, `/version` `dev` — the documented label-less case.
- **AC3 — met.** Fresh folder, empty volumes, `.env` from `.env.example` with passwords/`JWT_SECRET` set, one `docker compose up -d --build`: `db` healthy → `migrate` ran `001_ingredients.sql` … `022_clean_fresh_install.sql` and `✓ Concluído.`, `Exited (0)` → `web`, `print-worker` `Up`. `bin/migrate --status` → `✓ Nenhuma migração pendente.`; `/health/ready` → `{"success":true,"status":"ready"}` 200; `/admin/` → 200. No `composer install` or `bin/migrate` typed.
- **AC4 — met.** Added `common/migrations/999_spec066_quebrada.sql` (`ALTER TABLE tabela_que_nao_existe …`) to the test folder; `up -d --build` exited 1 with `service "migrate" didn't complete successfully: exit 255`; `docker logs gf-prodtest-migrate-1` showed the `QueryException` from `MigrationRunner.php:58`; `ps -a`: `migrate Exited (255)`, `web Created`, `print-worker Created` — nothing on 8090. The old containers had been recreated before `migrate` ran (see Implementation log). Removing the file (= checking out the previous version) and `up -d --build` again → all `Up`, `/health/ready` ready.
- **AC5 — met.** Admin API as a throwaway admin (`bin/create-admin` in the test project): before upload `logo_url` `/assets/img/logo.png` (the shipped logo, 1 438 055 bytes); after uploading a PNG, `logo_url` `/uploads/logo.png`, `logo_printable: true`, md5 `9a847c60…`. Same URL and md5 after `up -d --build --force-recreate` and after `down` + `up -d`.
- **AC6 — met.** A 404 on `/marcador-spec066-antes` wrote the marker to `app.log`; `GET /api/admin/logs` still contained it after the force-recreate and after `down` + `up -d`. `print-worker` (as `www-data`) started with no log-permission error; `logs/app.log` is `www-data`-owned in the image.
- **AC7 — met.** Built with `GASTROFLOW_VERSION=v1.9.0-test` → `/version` `{"success":true,"version":"v1.9.0-test"}` 200. The `VERSION`-file / empty-file / no-file-no-git branches: `tests/Unit/VersionControllerTest.php` (3 tests, passing). Development compose: `tests/Smoke/VersionTest.php` passes through the `git describe` path.
- **AC8 — met.** `bin/create-admin` (above) and `bin/migrate --status` ran in production. The host scripts were run by the owner from the test folder (this session's sandbox only lets commands starting with `docker` reach the socket): `./bin/backup-db` → `✔ Backup criado: backups/gastroflow-restaurant-20261030-011514.sql.gz (8,0K)`; checked: `gzip -t` OK, 16 `CREATE TABLE`, ends `-- Dump completed`. Then a marker row was inserted into the test database (`settings` key `spec066_marcador`; `orders` 65), and the owner ran `./bin/restore-db --yes gastroflow-restaurant-20261030-011514.sql.gz` → `✔ Banco 'restaurant' restaurado`. After: marker rows 0, `orders` 65, `menu_items` 17, `/health/ready` ready. The development database still had 65 orders (untouched). The scripts needed no change: in the test folder `docker compose` resolved to the production `docker-compose.yml`.
- **AC9 — met.** Development compose (`-f docker-compose.dev.yml`): `vendor/bin/phpunit` (with `MYSQL_DATABASE_TEST=restaurant_test`) → `OK, but some tests were skipped! Tests: 322, Assertions: 826, Skipped: 2` (the 2 are `MigrationUpgradeTest`, which needs `MYSQL_DATABASE_TEST_UPGRADE` — unrelated); `phpstan analyse` → `[OK] No errors`; `php-cs-fixer fix --dry-run --diff` → `Found 0 of 117 files that can be fixed`. Source edits are live without rebuilding (the new unit tests ran against host files with no rebuild). `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml config --services` → `db print-worker web web-e2e`. Playwright: no Node on the host (by design on this machine), so it ran in the official image matching the lockfile, `mcr.microsoft.com/playwright:v1.63.0-noble` (`--network host`, as uid 1000, with the host's `docker` CLI and socket mounted so `printer-block.spec.ts` can reach `web-e2e` the way it does locally), against `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e` (the development `db` stayed `Running`, not recreated): `npm ci && npx playwright test` → **`34 passed (49.7s)`**. A CI simulation with `act` was suggested by the owner; installing it (`gh extension install nektos/gh-act`) was refused by the session's permission classifier, so it was not run.
- **AC10 — met on a copy.** Development database read with `mysqldump` (read-only) and loaded into the test project's database: `orders 65, menu_items 17, migrations 23, users 1` on both sides. `up -d --force-recreate` with the production compose: `migrate` → `✔ Nenhuma migração pendente.` (the development hashes match), `/health/ready` ready, `/api/menu` OK, counts still `65 17 23`. Volume name for the real switch: the development stack uses `gastroflow_db_data`; the production `docker-compose.yml` declares `db_data` with no `name:` (`config --volumes` → `uploads logs db_data`), so in the same folder it resolves to the same `gastroflow_db_data`. The pilot install's own switch has not been done.
- **AC11 — met.** In the image: `find /var/www/html -path ./vendor -prune -o -type f -perm -u+x` lists only the 10 `bin/*` scripts; `tests`, `.git`, `.env.example`, `phpunit.xml`, `vendor/phpunit` absent; `VERSION` present; optimized classmap has 63 `App\` entries (`composer dump-autoload` output: "1227 classes"). `.dockerignore` excludes `vendor/`, `backups/`, `tests/`, `public/uploads/`, `.env`; build context was 4.99 MB.
- **CI** — PR #52 (merged, without the `/spec-review` fix): run `41003014653`, job `test` pass (2m08s); GitGuardian pass. The fix goes through a follow-up PR with its own run.
- **AC12 — met by the AC3 run** (steps 3–6 of the new `docs/installation.md`: `cp .env.example .env`, edit, `up -d --build`, `ps -a`/`logs migrate`), with two differences from a literal pass: `GASTROFLOW_VERSION` was set in the test `.env` instead of on the command line, and the override changed ports/names. Neither guide contains a `composer install` step or a bind-mount explanation for the production path any more.
