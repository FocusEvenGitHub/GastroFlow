# Upgrading

How to update an installed GastroFlow to a newer version without losing the restaurant's data — and how to go back if something goes wrong. First installation: [`docs/installation.md`](installation.md). Every command: [`docs/commands.md`](commands.md).

Run the commands from the repository folder on the server — **on Windows, in Git Bash**.

## Why the order matters

The containers run the code **straight from this folder** (it is mounted into them). So the moment `git pull` finishes, the new code is already serving the cashier and the kitchen — while its database changes (migrations) are not applied yet. Until step 6 runs, any screen that touches a new column fails. That is why:

- upgrade **with the restaurant closed**, and
- run the steps in sequence, without stopping in between.

While a migration is pending, `GET /health/ready` answers `503` with `MIGRATIONS_PENDING`, and the cashier and kitchen show **"Reconectando…" / "Conexão perdida"** — on purpose: the server is not ready yet.

## 1. Before

1. Read [`CHANGELOG.md`](../CHANGELOG.md) from your current version up to the newest one — especially the `⚠️` notes: they say when something beyond these steps is needed.
2. Check which version is running:

   ```bash
   curl http://localhost:8080/version
   # {"success":true,"version":"v1.8.3-47-ga8b28d6"}   ← last tag, commits after it, "g" + commit
   git rev-parse --short HEAD                          # the same commit, from the folder
   ```

   Write the commit down: it is what you go back to in [Rollback](#rollback).
3. Choose a moment with the restaurant closed.

## 2. Backup

```bash
./bin/backup-db
# ✔ Backup criado: backups/gastroflow-<database>-<timestamp>.sql.gz
```

Copy that file somewhere off the server (another disk, a pen drive, the cloud). It is the **only** way back: database changes cannot be undone.

## 3. Update the code

```bash
git pull
```

`master` is the released version of GastroFlow — restaurants follow it. To go to one specific version instead, `git fetch --tags && git checkout vX.Y.Z`.

If `git pull` refuses because of local changes, someone edited a tracked file on the server — that is not part of a normal install. Show `git status` to whoever maintains the install before going on.

See what changed outside the code — it tells you which of the next steps actually do something:

```bash
git diff <old-commit> HEAD --stat -- Dockerfile docker-compose.yml .env.example composer.lock common/migrations
```

## 4. `.env`

`git pull` never touches `.env`. If `.env.example` changed:

```bash
git diff <old-commit> HEAD -- .env.example
```

and add or adjust the new variables in `.env` by hand. Never copy `.env.example` over `.env` — it would wipe your passwords and `JWT_SECRET`.

## 5. Rebuild and restart the containers

```bash
docker compose up -d --build
```

Always run it, even when nothing seems to have changed: if the `Dockerfile` changed (e.g. a PHP security update) it rebuilds the image, and if `docker-compose.yml` changed (e.g. a port) it recreates the containers. When nothing changed, it does nothing.

## 6. Dependencies and migrations — right away

```bash
docker compose exec web composer install --no-dev
docker compose exec web php bin/migrate
```

`composer install` is a no-op when `composer.lock` did not change. `bin/migrate` applies only what is new; the database is never recreated.

If `bin/migrate` refuses with a message about a changed migration (hash), **stop**: an already-applied migration file differs from what ran. The only legitimate case is a line-ending conversion — see `bin/migrate --trust-current-hashes` in [`docs/commands.md`](commands.md#database) and ask whoever maintains the install.

## 7. Validate

```bash
docker compose exec web php bin/migrate --status
# ✓ Nenhuma migração pendente.          (exit code 0; 1 when something is pending)
curl http://localhost:8080/health/ready
# {"success":true,"status":"ready"}
curl http://localhost:8080/version      # the new version
docker compose ps                       # db, web, print-worker: all "Up"
```

Then, on a cashier screen: the pill says **"Conectado"**, the menu loads, and a test order goes through (cancel it in the kitchen afterwards). Reopen the restaurant.

## Rollback

If something is wrong after the upgrade and can't be fixed on the spot, go back to the version and the data you had at step 2:

```bash
./bin/restore-db --yes backups/<the file from step 2>
git checkout <old-commit>
docker compose up -d --build
docker compose exec web composer install --no-dev
curl http://localhost:8080/version      # the old version again
```

Orders taken **after** the backup are lost by the restore — another reason to upgrade with the restaurant closed. When the problem is fixed, return to `master` with `git checkout master` and upgrade again from step 1.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Cashier/kitchen show "Conexão perdida"; `/health/ready` → `MIGRATIONS_PENDING` | Step 6 not run (or failed) | Run `bin/migrate`; check with `bin/migrate --status` |
| An order fails with HTTP 500; the log says `Unknown column '…'` | Same: code updated, migration not applied | Same |
| `/version` still shows the old version | `git pull` did not run, or failed | Step 3; check `git status` |
| A PHP or image update listed in the CHANGELOG didn't take effect (`docker compose exec web php -v`) | `up -d` ran without `--build` | `docker compose up -d --build` |
| Pages show `Fatal error … vendor/autoload.php` | `vendor/` missing or broken | `docker compose exec web composer install --no-dev` |
| `bin/migrate` refuses: migration content changed (hash) | An applied migration file differs from what ran | Stop; see step 6 |
| A database tool on another computer can't connect to MySQL any more | Since v1.9 MySQL listens only on the server itself (`127.0.0.1:3306`) | Run the tool on the server, or through an SSH tunnel |
| `git pull`: "Your local changes would be overwritten" | A tracked file was edited on the server | Step 3 |
| Rolling back to a version older than v1.9: `docker compose up -d --build` fails with `"/legacy": not found` | Those versions' `Dockerfile` copies a `legacy/` folder that is no longer in the repository (old installs had it, empty) | `mkdir legacy`, then repeat `docker compose up -d --build` |
