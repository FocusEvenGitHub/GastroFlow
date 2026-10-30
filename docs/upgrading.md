# Upgrading

How to update an installed GastroFlow to a newer version without losing the restaurant's data — and how to go back if something goes wrong. First installation: [`docs/installation.md`](installation.md). Every command: [`docs/commands.md`](commands.md).

Run the commands from the repository folder on the server — **on Windows, in Git Bash**.

## Releases, not `master`

A restaurant runs a **release**: a version tag such as `v1.9.0`, listed on the [GitHub Releases page](https://github.com/FocusEvenGitHub/GastroFlow/releases). `master` is the next version **in development** — it may contain unfinished work, and is only for development and for test installs that accept that.

> **Transition (until `v1.9.0` is published):** this guide was written for `v1.9.0`, the first release it covers — it is cut when the v1.9 milestone closes. The current release, `v1.8.3`, predates it. Installs that follow `master` today (e.g. the pilot install) upgrade with `git pull` instead of step 3's `git checkout`, knowing they receive unreleased code. Coming from a version before v1.9 also needs the one-time steps in [First upgrade to v1.9](#first-upgrade-to-v19).

## What changes when

The containers run a **built image** with the code inside it, not the files in this folder (since v1.9, spec 066). So:

- step 3's `git checkout` changes **nothing** that is running — the restaurant keeps working on the old version;
- step 5's `docker compose up -d --build` builds the new image while the old one is still serving, then stops the old containers, applies the new version's database changes (the `migrate` service) and only then starts the new `web` and `print-worker`.

Between the old containers stopping and the new ones starting — usually seconds, longer when there are migrations — nothing answers: the cashier and kitchen show **"Reconectando…"**. That is why you still upgrade **with the restaurant closed**.

If a database change fails, the new `web` and `print-worker` are **not** started — the new code never runs against a half-updated database — but the old ones are already stopped, so GastroFlow stays down until you fix the cause or [roll back](#rollback).

## 1. Before

1. Read [`CHANGELOG.md`](../CHANGELOG.md) from your current version up to the newest one — especially the `⚠️` notes: they say when something beyond these steps is needed.
2. Check which version is running:

   ```bash
   curl http://localhost:8080/version
   # {"success":true,"version":"v1.9.0"}               ← a release: exactly a tag
   # {"success":true,"version":"v1.8.3-47-ga8b28d6"}   ← NOT a release: 47 commits after v1.8.3 (unreleased code)
   ```

   Write the version down: it is what you go back to in [Rollback](#rollback).
3. Choose a moment with the restaurant closed.

## 2. Backup

```bash
./bin/backup-db
# ✔ Backup criado: backups/gastroflow-<database>-<timestamp>.sql.gz
```

Copy that file somewhere off the server (another disk, a pen drive, the cloud). It is the **only** way back: database changes cannot be undone.

## 3. Update the code

```bash
git fetch --tags
git checkout vX.Y.Z        # the newest release on the GitHub Releases page
```

Only move **forward** — never check out a version older than the one running (database changes cannot be undone; going back is the [Rollback](#rollback)).

If `git checkout` refuses because of local changes, someone edited a tracked file on the server — that is not part of a normal install. Show `git status` to whoever maintains the install before going on. (Before v1.9, uploading a logo in Admin modified `public/assets/img/logo.png` — see [First upgrade to v1.9](#first-upgrade-to-v19).)

See what changed outside the code:

```bash
git diff <old-version> vX.Y.Z --stat -- Dockerfile docker-compose.yml .env.example composer.lock common/migrations
```

## 4. `.env`

Updating the code never touches `.env`. If `.env.example` changed:

```bash
git diff <old-version> vX.Y.Z -- .env.example
```

and add or adjust the new variables in `.env` by hand. Never copy `.env.example` over `.env` — it would wipe your passwords and `JWT_SECRET`.

## 5. Build and switch

```bash
GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build
```

Builds the new image, then: `db` → `migrate` (applies only what is new; the database is never recreated) → `web` and `print-worker`. It returns when everything is up — or with `service "migrate" didn't complete successfully` if a database change failed: see [Troubleshooting](#troubleshooting).

If `migrate` refuses with a message about a changed migration (hash) — `docker compose logs migrate` — **stop**: an already-applied migration file differs from what ran. The only legitimate case is a line-ending conversion — see `bin/migrate --trust-current-hashes` in [`docs/commands.md`](commands.md#database) and ask whoever maintains the install.

## 6. Validate

```bash
docker compose ps -a                    # db, web, print-worker: "Up"; migrate: "Exited (0)"
curl http://localhost:8080/health/ready
# {"success":true,"status":"ready"}
curl http://localhost:8080/version      # exactly the new tag, e.g. "v1.9.0"
docker compose exec web php bin/migrate --status
# ✓ Nenhuma migração pendente.          (exit code 0; 1 when something is pending)
```

Then, on a cashier screen: the pill says **"Conectado"**, the menu loads, and a test order goes through (cancel it in the kitchen afterwards). Reopen the restaurant.

## First upgrade to v1.9

Only once, when coming from `v1.8.x` or from a `master` older than spec 066 — that layout ran the code straight from this folder. Steps 1–6 above apply as written; in addition:

1. **Your own logo, if you uploaded one.** Old versions saved it over the GastroFlow logo that ships with the code, so it shows up as a change:

   ```bash
   git status --short public/assets/img/logo.png
   #  M public/assets/img/logo.png   ← your logo is there
   ```

   If it shows ` M`, before step 3 keep a copy and put the shipped file back:

   ```bash
   cp public/assets/img/logo.png backups/logo-restaurante.png
   git checkout -- public/assets/img/logo.png
   ```

   After step 6, upload `backups/logo-restaurante.png` again in Admin › Configurações › **Logo**. From v1.9 on, uploads are stored apart from the code (a Docker volume) and survive every upgrade.
2. **Nothing else to migrate**: the database volume is the same one — the folder name decides it — so orders, menu and users stay. The `vendor/` folder in the repository is no longer used by the running app (it is inside the image); you may leave it.
3. **Commands you may know from before** — `docker compose exec web composer install` and `docker compose exec web php bin/migrate` after every update — are no longer needed: step 5 does both.

## Rollback

If something is wrong after the upgrade and can't be fixed on the spot, go back to the version and the data you had at step 2:

```bash
./bin/restore-db --yes backups/<the file from step 2>
git checkout <old-version>
GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build
curl http://localhost:8080/version      # the old version again
```

Orders taken **after** the backup are lost by the restore — another reason to upgrade with the restaurant closed. When the problem is fixed — usually by a newer release — upgrade again from step 1.

**Rolling back to a version before v1.9** (the old layout, code run from the folder): after `docker compose up -d --build`, also run `docker compose exec web composer install --no-dev`, as that version's guide says. A logo uploaded after the upgrade lives in the v1.9 volume, which the old version doesn't read — it shows the logo saved in the folder.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `docker compose up` ends with `service "migrate" didn't complete successfully`; cashier/kitchen show "Conexão perdida" | A database change failed; the new `web`/`print-worker` were not started and the old ones are stopped | `docker compose logs migrate` shows why. Fix the cause and run step 5 again, or [roll back](#rollback) |
| `migrate` refuses: migration content changed (hash) | An applied migration file differs from what ran | Stop; see step 5 |
| `/version` still shows the old version | Step 5 didn't run, or failed — `git checkout` alone changes nothing that runs | Step 5 |
| `/version` shows `dev` | Step 5 ran without `GASTROFLOW_VERSION=…` | Cosmetic; repeat step 5 as written |
| `/version` shows `vX.Y.Z-N-g…` instead of a bare tag | The folder is on unreleased code (`master` or a commit), not on a release | Step 3 with a release tag — unless this is a deliberate test install |
| A PHP or image update listed in the CHANGELOG didn't take effect (`docker compose exec web php -v`) | `up -d` ran without `--build` | Step 5 |
| The logo went back to the GastroFlow one after upgrading to v1.9 | The restaurant's logo was in `public/assets/img/logo.png` and was not carried over | [First upgrade to v1.9](#first-upgrade-to-v19), item 1 |
| A database tool on another computer can't connect to MySQL any more | Since v1.9 MySQL listens only on the server itself (`127.0.0.1:3306`) | Run the tool on the server, or through an SSH tunnel |
| `git checkout`: "Your local changes would be overwritten" | A tracked file was edited on the server (before v1.9: often the logo) | Step 3 |
| Rolling back to a version older than v1.9: `docker compose up -d --build` fails with `"/legacy": not found` | Those versions' `Dockerfile` copies a `legacy/` folder that is no longer in the repository (old installs had it, empty) | `mkdir legacy`, then repeat `docker compose up -d --build` |
