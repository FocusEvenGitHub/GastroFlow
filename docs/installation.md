# Installation

From nothing to a working GastroFlow on the restaurant's server: administrator created, printer tested, menu in place, first backup taken. No source file is edited — only `.env`.

Every command below runs from the repository folder. **On Windows, run them in Git Bash**, not PowerShell or cmd. Command reference for day-to-day operation: [`docs/commands.md`](commands.md).

## 1. Prerequisites

- **Docker** with Compose v2 — Docker Desktop on Windows/macOS, Docker Engine on Linux. Check: `docker compose version`.
- **Git**. On Windows, Git for Windows, which also installs **Git Bash**.
- **Windows only — before cloning:**

  ```bash
  git config --global core.autocrlf false
  ```

  Git for Windows converts line endings to CRLF by default, and the scripts in `bin/` then fail inside the Linux containers. If you already cloned, see [Troubleshooting](#troubleshooting).

## 2. Clone and check out a release

```bash
git clone https://github.com/FocusEvenGitHub/GastroFlow.git
cd GastroFlow
git checkout vX.Y.Z        # the newest release on the GitHub Releases page
```

A restaurant runs a **release** (a version tag), never `master`: `master` is the next version in development and may contain unfinished work. Later updates move from tag to tag: [`docs/upgrading.md`](upgrading.md).

> **Transition (until `v1.9.0` is published):** this guide covers `v1.9.0` and later — the first release with it is cut when the v1.9 milestone closes. The current release, `v1.8.3`, **cannot be installed from scratch** (its image build fails on a fresh clone: `"/legacy": not found`) and has none of these guides. Until `v1.9.0` exists, installing means staying on `master` (skip the `git checkout`) and accepting pre-release code.

## 3. Create `.env` — before any `docker compose` command

```bash
cp .env.example .env
```

Order matters: `docker-compose.yml` mounts `.env` into the containers as a file. If `docker compose up` runs first, Docker creates an empty **folder** named `.env` in its place.

## 4. Configure `.env`

Open `.env` in a text editor and set:

| Variable | What to put |
|---|---|
| `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD` | Two different strong passwords, replacing `changeme`. Set them **now**: MySQL stores them on its first start. |
| `JWT_SECRET` | The output of `openssl rand -base64 48`. The app refuses to start without it. |
| `MYSQL_DATABASE`, `MYSQL_USER` | Any names, or keep the defaults. Choose before the first start (see Troubleshooting). |
| `APP_ENV`, `APP_DEBUG` | Keep `production` / `false`. |

Everything else is optional and documented in `.env.example` itself.

## 5. Start the containers

```bash
docker compose up -d --build
```

Builds the image and starts three services: `db` (MySQL), `web` (the app, port 8080) and `print-worker` (the print queue). The first start of MySQL takes about a minute — it creates the database and loads the initial schema. Until steps 6–7 are done, the app answers with errors; that's expected.

## 6. Install the PHP dependencies

```bash
docker compose exec web composer install --no-dev
```

The app's `vendor/` folder lives in the repository folder (it is mounted into the containers), and a fresh clone doesn't have one. Without this step every page — even `/health/ready` — shows `Fatal error: … vendor/autoload.php` (with HTTP status **200**, so check the text, not just the status) and `print-worker` keeps restarting.

## 7. Create the database tables

```bash
docker compose exec web php bin/migrate
```

If it fails with a connection error, MySQL is still starting — wait a few seconds and run it again.

## 8. Create the administrator

```bash
docker compose exec web php bin/create-admin admin
```

Replace `admin` with the username you want. It asks for a password (at least 8 characters). There are no default credentials.

## 9. Check the installation

```bash
curl http://localhost:8080/health/ready
# {"success":true,"status":"ready"}   ← exactly this; anything else (an HTML "Fatal error") means a step was missed
docker compose ps
# db, web and print-worker all "Up" — none "Restarting"
```

Then open **`http://localhost:8080/admin/`** on the server — or `http://<server-ip>:8080/admin/` from another device — and log in.

## 10. Configure the restaurant

Admin › **Configurações**:

1. **Nome do Restaurante** and **Logo** — printed on the ticket.
2. **IP da Impressora** and **Porta** (usually `9100`) of the ESC/POS network printer. Use the IP, not a hostname, and check it is on the same network as the server (e.g. both `192.168.1.x`).
3. **Salvar Configurações**, then **Imprimir Teste**. A test ticket should come out.

## 11. Create the menu

A new installation has no menu — only the categories and the packaging items ("Embalagem Simples/Especial", charged on take-away orders). Create your items in Admin.

To try GastroFlow first with sample data — a fictional menu plus 45 days of orders for the kitchen and reports:

```bash
docker compose exec web php bin/seed-demo
```

## 12. Take the first backup

```bash
./bin/backup-db
# ✔ Backup criado: backups/gastroflow-<database>-<timestamp>.sql.gz
```

Runs on the server itself (Git Bash on Windows), not inside a container. Restoring and scheduling: [`docs/commands.md`](commands.md#backup--restore).

## 13. Daily operation

- The cashier is at `/cashier/`, the kitchen at `/kitchen/`, from any device on the restaurant's network: see the README's [Operating over the LAN](../README.md#operating-over-the-lan-no-internet) (fixed IP, firewall rule for port 8080).
- After a server reboot, the three services come back on their own.
- Updating to a new version: [`docs/upgrading.md`](upgrading.md).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pages (even `/health/ready`) show `Fatal error: … vendor/autoload.php` — with HTTP status 200 | Step 6 skipped | Run step 6 |
| `print-worker` shows `Restarting` in `docker compose ps` | Same: no `vendor/` | Run step 6, then `docker compose restart print-worker` |
| `cp: cannot create regular file '.env'` / `.env` is a folder | `docker compose up` ran before step 3 | `docker compose down`, delete the `.env` folder, redo steps 3–5 |
| `Table '…' doesn't exist` in the log, or login fails right after install | Step 7 skipped | Run step 7 |
| `bin/migrate` fails on `001_ingredients.sql` with `1824 Failed to open the referenced table 'menu_items'` | Installed with a GastroFlow older than v1.9 and `MYSQL_DATABASE` other than `restaurant`: the tables went to a separate `restaurant` database | On an install with no data yet: `docker compose down -v`, update, start again from step 5 |
| `JWT_SECRET` error in the log | Empty `JWT_SECRET` | Step 4, then `docker compose up -d` |
| `bin/migrate` or the app can't connect after changing `MYSQL_DATABASE`/`MYSQL_USER`/passwords in `.env` | MySQL only reads them on its **first** start; the database volume keeps the old ones | On an install with no data yet: `docker compose down -v` (**deletes the database**) and start again from step 5 |
| `bin/…: /usr/bin/env: 'php\r': No such file or directory` or `bash\r` | Cloned on Windows with `core.autocrlf=true` | Step 1's setting, then rebuild the files: `git rm --cached -r .` and `git reset --hard` (with no local changes) |
| Another device can't open `http://<server-ip>:8080` | Server firewall | README › Operating over the LAN › Firewall |
| "Imprimir Teste" fails | Printer IP/port, or a different network | Check the IP from the printer's own config page; ping it from the server |
