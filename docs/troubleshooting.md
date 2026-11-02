# Troubleshooting

Start with the first checks, then go to the table for the area that's failing. Commands run on the server, in the install folder.

## First checks

```bash
docker compose ps                          # db, web, print-worker "Up"; migrate "Exited (0)"; none "Restarting"
curl http://localhost:8080/health/ready    # {"success":true,"status":"ready"}
curl http://localhost:8080/version         # the release you installed
docker compose logs --tail=50 web          # or db, migrate, print-worker
```

| `/health/ready` answers | Meaning |
|---|---|
| `{"success":true,"status":"ready"}` | App and database fine — the problem is elsewhere (network, printer, browser) |
| 503 `DB_UNAVAILABLE` | The app can't reach MySQL: `docker compose logs db` |
| 503 `MIGRATIONS_PENDING` | An upgrade is half done: [`upgrading.md` › Troubleshooting](upgrading.md#troubleshooting) |
| Nothing printed, no error from `curl` (HTTP 500 with an empty body) | PHP failed before GastroFlow started: `docker compose logs web` has the `PHP Fatal error` line |
| No answer (`curl` reports it can't connect) | `web` is down: `docker compose ps`, `docker compose logs web` |

The application log — errors, failed prints, logins — is in **Admin › Logs** (admin users), or on the server:

```bash
docker compose exec web tail -n 100 logs/app.log
```

Every API response carries an `X-Request-Id` header (browser developer tools › Network); the same id is on that request's lines in the log.

## By area

| Area | Where |
|---|---|
| Installing, first start, `.env`, `migrate` failing on a new install | [`installation.md` › Troubleshooting](installation.md#troubleshooting) |
| After an upgrade, wrong version, rolling back | [`upgrading.md` › Troubleshooting](upgrading.md#troubleshooting) |
| Printer, printing blocked, logo on the ticket | [`printing.md` › Troubleshooting](printing.md#troubleshooting) |
| Backups and restoring | [`backup-and-restore.md`](backup-and-restore.md) |
| Everything else | below |

## Operation

| Symptom | Cause | Fix |
|---|---|---|
| Cashier or kitchen shows **"Reconectando…"** or **"Conexão perdida"** | The device can't reach the GastroFlow server, or the database is down. It is about the server, not the internet — with the internet down and the network up it stays "Conectado" | First checks above. If they pass on the server, it's the network between the device and the server: same Wi-Fi? Server IP changed (give it a fixed IP — README › Operating over the LAN)? Firewall (port 8080)? The screen recovers by itself when the connection comes back |
| Cashier or kitchen shows **"Atualização incompleta"** (yellow) and "Atualização do sistema incompleta. … Avise o responsável pelo sistema." | The server is up, but a database update (migration) was not applied — usually code updated without the update step. `/health/ready` answers 503 `MIGRATIONS_PENDING` | `./bin/backup-db`, then `docker compose exec web php bin/migrate --status` (lists what is missing) and `docker compose up -d` (production runs `migrate` on start; on an install from before v1.9, `docker compose exec web php bin/migrate`). The screens turn "Conectado" within 5 s. More in [`upgrading.md`](upgrading.md#troubleshooting) |
| Kitchen stops showing new orders but says "Conectado" | The live update stream stalled while the server stayed up (known issue) | Reload the kitchen page (F5) |
| Another device can't open `http://<server-ip>:8080` at all | Firewall, or wrong IP | README › Operating over the LAN › Firewall |
| An error toast you don't understand, or "Erro do servidor (HTTP 500)." | An error in the server | Admin › Logs, or `logs/app.log`, at that time; with `APP_DEBUG=false` the details are only there |
| A blank or browser error page, or `/health/ready` answering 500 | PHP failed before GastroFlow started (e.g. a broken image). The cause is never shown on the page | `docker compose logs web` has the `PHP Fatal error` line; rebuild with `docker compose up -d --build` |

## Admin

| Symptom | Cause | Fix |
|---|---|---|
| Admin goes back to the login screen by itself | The session expired (8 hours) or `JWT_SECRET` changed | Log in again — you return to the page you were on |
| "Sem permissão para esta área." | The user is a `manager` and the page is admin-only (Configurações, Logs, Auditoria) | Use an `admin` user ([`security.md` › Accounts and roles](security.md#accounts-and-roles)) |
| Login: "Muitas tentativas de login. Tente novamente em N minutos." | 10 wrong passwords for that user from that device in 15 minutes (or 30 from the device) | Wait the minutes shown. Forgot the password: create another admin with `bin/create-admin` and change it from there |
| Login: "Credenciais inválidas." with what you believe is the right password | Wrong user, or Caps Lock | `docker compose exec web php bin/create-admin <new-user>` creates a working admin |

## Server

| Symptom | Cause | Fix |
|---|---|---|
| Server disk filling up | `logs/app.log` grows without limit (it is not rotated); `backups/` gains a file per backup | Size: `docker compose exec web du -h logs/app.log` and `du -sh backups/`. Empty the log (copy it first if you need it): `docker compose exec web sh -c ': > logs/app.log'`. Move old backups off the server ([`backup-and-restore.md`](backup-and-restore.md#copy-it-off-the-server)) |
| After a power cut or reboot, screens don't open | Docker didn't start yet, or Docker Desktop (Windows) isn't set to start with the computer | Wait a minute; `docker compose ps`. `db`, `web` and `print-worker` restart by themselves once Docker is up |
| Many old print jobs or kitchen events in the database | Normal: `print-worker` removes completed jobs after 7 days and events after 2, once an hour | Nothing; failed jobs are kept on purpose (`bin/jobs-status`) |
