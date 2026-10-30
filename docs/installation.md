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

## 5. Build and start

```bash
GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build
```

Builds the GastroFlow image — the code and its PHP dependencies go **inside** it — and starts everything in order: `db` (MySQL), then `migrate`, which creates the database tables and exits, then `web` (the app, port 8080) and `print-worker` (the print queue). The first start takes a couple of minutes: downloading the base images, building, and MySQL's first initialization. The command returns when everything is up.

`GASTROFLOW_VERSION=…` only labels the build with the release you checked out, which `/version` shows; without it, `/version` says `dev`.

## 6. Check that the database is ready

```bash
docker compose ps -a
# db, web, print-worker: "Up"   migrate: "Exited (0)" — it runs once per start, that is expected
docker compose logs migrate
# … ends with "✓ Concluído."
```

If `migrate` exited with any other code, `web` and `print-worker` were not started — see [Troubleshooting](#troubleshooting).

## 7. Create the administrator

```bash
docker compose exec web php bin/create-admin admin
```

Replace `admin` with the username you want. It asks for a password (at least 8 characters). There are no default credentials.

## 8. Check the installation

```bash
curl http://localhost:8080/health/ready
# {"success":true,"status":"ready"}
curl http://localhost:8080/version
# {"success":true,"version":"vX.Y.Z"}   ← the release from step 2
docker compose ps
# db, web and print-worker all "Up" — none "Restarting"
```

Then open **`http://localhost:8080/admin/`** on the server — or `http://<server-ip>:8080/admin/` from another device — and log in.

## 9. Configure the restaurant

Admin › **Configurações**:

1. **Nome do Restaurante** and **Logo** — printed on the ticket.
2. **IP da Impressora** and **Porta** (usually `9100`) of the ESC/POS network printer. Use the IP, not a hostname, and check it is on the same network as the server (e.g. both `192.168.1.x`).
3. **Salvar Configurações**, then **Imprimir Teste**. A test ticket should come out.

Supported printers, finding the printer's IP, what happens when printing fails, and printer troubleshooting: [`docs/printing.md`](printing.md).

## 10. Create the menu

A new installation has no menu — only the categories and the packaging items ("Embalagem Simples/Especial", charged on take-away orders). Create your items in Admin.

To try GastroFlow first with sample data — a fictional menu plus 45 days of orders for the kitchen and reports:

```bash
docker compose exec web php bin/seed-demo
```

## 11. Take the first backup

```bash
./bin/backup-db
# ✔ Backup criado: backups/gastroflow-<database>-<timestamp>.sql.gz
```

Runs on the server itself (Git Bash on Windows), not inside a container. Restoring and scheduling: [`docs/commands.md`](commands.md#backup--restore).

## 12. Daily operation

- The cashier is at `/cashier/`, the kitchen at `/kitchen/`, from any device on the restaurant's network: see the README's [Operating over the LAN](../README.md#operating-over-the-lan-no-internet) (fixed IP, firewall rule for port 8080).
- After a server reboot, the three services come back on their own.
- The running version is the image built in step 5, not the folder: editing or checking out files in the folder changes nothing until the next `docker compose up -d --build`. Data lives in Docker volumes — the database, the uploaded logo and the application log — and survives rebuilding the image.
- Updating to a new version: [`docs/upgrading.md`](upgrading.md).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `docker compose up` ends with `service "migrate" didn't complete successfully` | Creating or updating the tables failed; `web` and `print-worker` were not started | `docker compose logs migrate` shows the error. Fix its cause (rows below), then `docker compose up -d` again |
| `dependency failed to start: container …-db-1 is unhealthy` | MySQL didn't come up within about 3 minutes (slow first start, or wrong credentials) | `docker compose logs db`; then `docker compose up -d` again |
| `cp: cannot create regular file '.env'` / `.env` is a folder | `docker compose up` ran before step 3 | `docker compose down`, delete the `.env` folder, redo steps 3–5 |
| `migrate` fails on `001_ingredients.sql` with `1824 Failed to open the referenced table 'menu_items'` | Installed with a GastroFlow older than v1.9 and `MYSQL_DATABASE` other than `restaurant`: the tables went to a separate `restaurant` database | On an install with no data yet: `docker compose down -v`, update, start again from step 5 |
| `JWT_SECRET` error in the log | Empty `JWT_SECRET` | Step 4, then `docker compose up -d` |
| `migrate` or the app can't connect after changing `MYSQL_DATABASE`/`MYSQL_USER`/passwords in `.env` | MySQL only reads them on its **first** start; the database volume keeps the old ones | On an install with no data yet: `docker compose down -v` (**deletes the database**) and start again from step 5 |
| `/version` says `dev` | Built without `GASTROFLOW_VERSION` | Cosmetic. Rebuild as in step 5 to label it |
| `bin/…: /usr/bin/env: 'php\r': No such file or directory` or `bash\r` | Cloned on Windows with `core.autocrlf=true` | Step 1's setting, then rebuild the files: `git rm --cached -r .` and `git reset --hard` (with no local changes), then step 5 again — the image is built from these files |
| Another device can't open `http://<server-ip>:8080` | Server firewall | README › Operating over the LAN › Firewall |
| "Imprimir Teste" fails | Printer IP/port, or a different network | Check the IP from the printer's own config page; ping it from the server — more in [`docs/printing.md`](printing.md#troubleshooting) |
