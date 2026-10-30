# Commands

Every command GastroFlow has, by task. First installation, step by step: [`docs/installation.md`](installation.md); updating: [`docs/upgrading.md`](upgrading.md).

Run them from the repository folder — **on Windows, in Git Bash**. Most run *inside* the `web` container (`docker compose exec web …`); the backup scripts run on the server itself and are marked **host**.

`docker compose` uses `docker-compose.yml`, the **production** setup: the code runs from the built image, and only the database, the uploaded logo and the log live outside it, in Docker volumes (spec 066). Development uses `docker-compose.dev.yml` — see [Development](#development).

## Services

| Command | What it does |
|---|---|
| `GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build` | Builds the image from the checked-out version and starts `db` (MySQL 8.0) → `migrate` (applies pending migrations, then exits) → `web` (the app, port 8080) and `print-worker` (print queue). `web`/`print-worker` only start if `migrate` succeeds. The `GASTROFLOW_VERSION=…` prefix only labels `/version`. |
| `docker compose up -d` | Starts the services from the image already built (e.g. after `docker compose stop`). Runs `migrate` again — a no-op when nothing is pending. |
| `docker compose ps` | Status of the three services — all should be `Up`, none `Restarting`. `ps -a` also lists `migrate`, which should be `Exited (0)`. |
| `docker compose logs migrate` | What the last migration run did — the first place to look when `up` reports that `migrate` failed. |
| `docker compose stop` / `docker compose start` | Stops / starts the services. Data stays in the database volume. |
| `docker compose restart print-worker` | Restarts one service. |
| `docker compose logs -f web` | Follows a service's output (`web`, `print-worker`, `db`). |
| `docker compose down` | Removes the containers. Data stays in the volumes. |
| `docker compose down -v` | Removes the containers **and every volume — every order, menu item and user, the uploaded logo and the log are lost.** Only for an install with no data, or after a backup. |

The application log is also readable in Admin › Logs.

## Health

| Command | What it does |
|---|---|
| `curl http://localhost:8080/health/ready` | `{"success":true,"status":"ready"}` when the app **and** the database answer **and** no migration is pending (otherwise 503: `DB_UNAVAILABLE` / `MIGRATIONS_PENDING`). |
| `curl http://localhost:8080/health/live` | Answers as long as the app process is up, even with the database down. |
| `curl http://localhost:8080/version` | The version/branch the server is running. |

## Database

| Command | What it does |
|---|---|
| `docker compose exec web php bin/migrate` | Applies pending migrations. In production the `migrate` service already runs it on every `up`; by hand it is for development. Refuses to run if an already-applied migration file changed. |
| `docker compose exec web php bin/migrate --status` | Read-only: lists pending migrations; exit code 1 if there is any, 0 if none. While one is pending, `/health/ready` answers 503 `MIGRATIONS_PENDING`. |
| `docker compose exec web php bin/migrate --trust-current-hashes` | Accepts the current content of already-applied migration files (e.g. after a line-ending normalization). Explicit, never automatic. |

## Users

| Command | What it does |
|---|---|
| `docker compose exec web php bin/create-admin <username>` | Creates an administrator; asks for the password (min. 8 characters). There are no default credentials. |

## Backup & restore

| Command | What it does |
|---|---|
| `./bin/backup-db` | **Host.** Writes `backups/gastroflow-<database>-<timestamp>.sql.gz`, using the `db` container's own `mysqldump`. |
| `./bin/restore-db` | **Host.** Restores the most recent file in `backups/`. **Replaces every table** — asks you to type `RESTAURAR`. |
| `./bin/restore-db <file>` | **Host.** Restores a specific file from `backups/`. |
| `./bin/restore-db --yes <file>` | **Host.** Same, without the confirmation prompt (for scripts). |

Run inside the `web` container, these two scripts stop with "Rode no host": they need the `docker` command, which only exists on the server. `backups/` is not versioned — copy it somewhere else (another disk, the cloud) as well.

## Printing and the job queue

Printing goes through a queue processed by `print-worker`. After 3 consecutive failures printing is **blocked**; reactivate it with **Reativar impressão** in the cashier or the kitchen.

| Command | What it does |
|---|---|
| `docker compose exec web php bin/jobs-status [print]` | Read-only: jobs that failed for good, and jobs stuck with a worker that died. |
| `docker compose exec web php bin/jobs-prune [--days=N]` | Deletes completed jobs older than N days (default `QUEUE_RETENTION_DAYS`, 7). Failed jobs are kept. |
| `docker compose exec web php bin/events-prune [--days=N]` | Deletes kitchen realtime events older than N days (default `EVENTS_RETENTION_DAYS`, 2). |
| `docker compose exec web php bin/worker print --once` | Processes one print job and exits — for diagnosis. The `print-worker` service runs `bin/worker print` continuously. |

Pruning also runs automatically inside `print-worker`; the commands are for doing it by hand. Printer setup and troubleshooting: [`docs/printing.md`](printing.md).

## Sample data

| Command | What it does |
|---|---|
| `docker compose exec web php bin/seed-demo` | On a database with no menu, creates a fictional one; then adds 60 completed orders over the last 45 days and 5 pending today. Refuses on a database that has orders, unless `APP_ENV=development`. |
| `docker compose exec web php bin/seed-client-1` | Loads the full menu of the pilot client (items, prices, ingredients, dish components). Only on a database with no menu. |

Both accept another database: `docker compose exec -e MYSQL_DATABASE=<database> web php bin/seed-demo`.

## Development

Development uses **`docker-compose.dev.yml`**: it mounts the repository folder into the containers, so an edit in `src/` or `public/` is live without rebuilding, and `composer install` / `bin/migrate` are run by hand. Select it once by adding to your `.env`:

```bash
COMPOSE_FILE=docker-compose.dev.yml
```

Docker Compose reads `COMPOSE_FILE` from `.env`, so every `docker compose …` command below (and `bin/backup-db`/`bin/restore-db`) then uses the development setup — the database volume is the same one either way. Without that line you get the production setup, where your edits don't show up until a rebuild.

| Command | What it does |
|---|---|
| `docker compose exec web composer install` | PHP dependencies **including** the test/analysis tools, into the repository's `vendor/`. Also `composer update/require/remove`. |
| `docker compose exec web php bin/migrate` | Applies pending migrations — by hand in development. |
| `docker compose exec web vendor/bin/phpunit --testsuite Smoke,Unit` | Unit tests. |
| `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` | Integration tests against MySQL. Skip themselves without `MYSQL_DATABASE_TEST`, and fail on purpose if it equals `MYSQL_DATABASE`. One-time setup: `CREATE DATABASE restaurant_test` plus a `GRANT` for `MYSQL_USER`. `MigrationUpgradeTest` also needs `MYSQL_DATABASE_TEST_UPGRADE` (its own database, which it drops and rebuilds). |
| `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e` | Second app instance on port **8081** pointed at `restaurant_test`, for the browser tests. |
| `cd tests/e2e && npm install && npx playwright install chromium && npx playwright test` | Browser tests (Playwright) against 8081 — never against 8080. |
| `docker compose exec web vendor/bin/phpstan analyse` | Static analysis (level 5). |
| `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` | Code-style check (PSR-12); without `--dry-run --diff` it applies the fixes. |
| `composer start` | `php -S 0.0.0.0:80 -t public` — built-in server, without Docker. |

CI (`.github/workflows/ci.yml`) runs `composer validate --strict` → `composer audit` → PHPStan → PHP-CS-Fixer → PHPUnit (Unit, then Integration on a database built from scratch) → Playwright, on every push and pull request to `master`.
