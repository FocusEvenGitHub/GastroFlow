# Backup & restore

Every command here runs **on the server**, in the install folder (Git Bash on Windows), not inside a container.

## What a complete backup is

| Part | What it holds | How |
|---|---|---|
| **Database** | Menu, orders, users, settings, audit history — everything typed into GastroFlow | `./bin/backup-db` |
| **Logo** | The logo uploaded in Admin › Configurações (the `uploads` volume) | `docker compose cp` (below) |
| **`.env`** | Database passwords and `JWT_SECRET` | Copy the file |

`bin/backup-db` saves **only the database**. Without the other two, a restore on a new server loses the logo, and you must create new passwords before the first start. The code itself is not part of the backup: it comes back from the release tag ([`installation.md`](installation.md)).

## Making a backup

```bash
# 1. Database → backups/gastroflow-<database>-<timestamp>.sql.gz
./bin/backup-db
# ✔ Backup criado: backups/gastroflow-restaurant-20261031-030000.sql.gz (2,1M)

# 2. Logo → backups/uploads-<date>/  (skip if you never uploaded a logo)
docker compose cp web:/var/www/html/public/uploads backups/uploads-$(date +%Y%m%d)
```

`bin/backup-db` uses the `mysqldump` that comes with the `db` container (nothing to install) and needs `db` running. It checks that the file isn't empty; on any error it deletes the partial file and says why.

**`.env`**: copy it once after installing and again whenever you change it. Keep it **apart** from the backups folder, for instance on a pen drive kept by the owner — it holds the passwords ([`security.md`](security.md#secrets)).

### Copy it off the server

`backups/` is in the install folder, on the server's own disk: a dead disk or a stolen server takes the backups with it. After each backup, copy the newest `.sql.gz` and `uploads-…` folder to **another place** — an external disk, another computer, a private cloud folder. Keep several days, not only the last one: a mistake noticed on Friday needs Wednesday's backup.

### Scheduling

GastroFlow does not schedule backups by itself. Add a scheduled task on the server.

**Linux** — `crontab -e`, one line (every day at 03:00; adjust the folder):

```cron
0 3 * * * cd /opt/gastroflow && ./bin/backup-db >> backups/backup.log 2>&1 && docker compose cp web:/var/www/html/public/uploads backups/uploads-$(date +\%Y\%m\%d) >> backups/backup.log 2>&1
```

(In a crontab, `%` must be written `\%`.) Check `backups/backup.log` the next morning the first time.

**Windows** — Task Scheduler › Create Basic Task › daily › *Start a program*: `"C:\Program Files\Git\bin\bash.exe"`, arguments `-c './bin/backup-db && docker compose cp web:/var/www/html/public/uploads backups/uploads-$(date +%Y%m%d)'`, *Start in*: the install folder. Docker Desktop must be running at that time.

The backups grow by one file per day; delete old ones by hand, or after copying them off the server.

## Restoring on the same server

> ⚠️ **Restoring replaces the database.** Every table in the backup is dropped and recreated: anything typed after that backup is lost. Make a backup of the current state first (`./bin/backup-db`), in case you need to come back.

```bash
./bin/restore-db                                                  # the newest file in backups/
./bin/restore-db gastroflow-restaurant-20261031-030000.sql.gz     # a specific one (name inside backups/, or a path)
```

It checks that the file is a valid gzip, shows the database and the file, and asks you to type `RESTAURAR`; any other answer stops without touching anything. `--yes` skips the question (for scripts); without `--yes` and without a terminal it refuses.

Then, if the logo also needs restoring:

```bash
docker compose cp backups/uploads-20261031/. web:/var/www/html/public/uploads/
docker compose exec web chown -R www-data:www-data public/uploads
```

The second line gives the files back to the web server; without it a later logo upload may fail.

Last, check:

```bash
docker compose exec web php bin/migrate --status
# ✓ Nenhuma migração pendente.
curl http://localhost:8080/health/ready
# {"success":true,"status":"ready"}
```

**Restore a backup with the release it was taken on.** A backup taken before an upgrade goes back together with the old version — that is [`upgrading.md` › Rollback](upgrading.md#rollback). If `--status` lists pending migrations, the backup is from another version than the code: stop and follow that section instead of starting the screens.

## Restoring on a new server

For a dead or replaced server. You need the `.sql.gz`, the `uploads-…` folder and the old `.env`.

1. Follow [`installation.md`](installation.md) steps 1–2 (prerequisites, clone) and check out the **same release** the old server ran — `/version` showed it; the backup's date and the `CHANGELOG.md` help if nobody wrote it down. To move to a newer release, restore first and then upgrade with [`upgrading.md`](upgrading.md).
2. Instead of steps 3–4, put the **old `.env`** in the install folder — **before** any `docker compose` command. MySQL takes its passwords from it on the first start.
3. Step 5: build and start (`docker compose up -d --build`, as written there). Skip step 7, creating the administrator: the users come back with the database.
4. Copy the backup into `backups/` in the new folder and restore it:

   ```bash
   mkdir -p backups
   cp /path/to/gastroflow-restaurant-20261031-030000.sql.gz backups/
   ./bin/restore-db gastroflow-restaurant-20261031-030000.sql.gz
   ```

5. Restore the logo (the two commands above), then check with `bin/migrate --status` and `/health/ready` as above.
6. Log in to the Admin with an old user, open Configurações and check the printer IP — if the new server is on another network, it may need changing ([`printing.md`](printing.md)).

Lost the old `.env`? Create a new one (installation steps 3–4) before step 3. Everything still works: only the Admin sessions end, since `JWT_SECRET` is new.

## Checking a backup

- `gzip -t backups/<file>.sql.gz` (no output = the file is intact). `bin/restore-db` does the same check before restoring.
- `zcat backups/<file>.sql.gz | grep -c 'INSERT INTO `orders`'` — more than 0 means it has orders in it.
- The only complete test is a real restore. The scripts always restore into the server's own database, so do it on a spare computer with a fresh install (the new-server steps above), never on the restaurant's server during opening hours.

## Commands

| Command | What it does |
|---|---|
| `./bin/backup-db` | Writes `backups/gastroflow-<database>-<timestamp>.sql.gz` |
| `./bin/restore-db [--yes] [file]` | Restores the newest backup, or `file`; asks for `RESTAURAR` unless `--yes` |
| `docker compose cp web:/var/www/html/public/uploads backups/uploads-<date>` | Copies the logo out |
| `docker compose cp backups/uploads-<date>/. web:/var/www/html/public/uploads/` | Copies it back (then `chown`, above) |

Both scripts stop with "Rode no host" if run inside a container: they need the `docker` command.
