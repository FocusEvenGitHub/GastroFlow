# Security

How GastroFlow is protected, what it deliberately leaves open, and what the person running the server must do. Written for the restaurant's server, a single location on its own network.

## Deployment model: a trusted local network

GastroFlow is built to run **inside the restaurant's network** and be used by the restaurant's own devices. Its protections assume that:

- **Only port 8080 is open, and only to the local network.** Never forward 8080 on the router or expose it to the internet. On Windows, open it for the *Private* profile only (README › Operating over the LAN › Firewall).
- **MySQL is not reachable from the network.** It is published on the server's `127.0.0.1:3306` only; the app reaches it over Docker's internal network. Don't change that, and don't open 3306 on the firewall.
- **There is no HTTPS.** GastroFlow serves plain HTTP. On the restaurant's own cabled or password-protected Wi-Fi this is acceptable; on a shared or open Wi-Fi (guests' network, a mall's network), anyone on it can read the traffic, including Admin passwords as they are typed. Keep the server and the screens on a network customers don't use.

## What requires a login and what doesn't

| Area | Login | Who can use it |
|---|---|---|
| Caixa (`/cashier/`), Cozinha (`/kitchen/`) and their API (`/api/orders…`, `/api/menu`, `/api/kitchen/…`, `/api/printer/…`) | **No** | Anyone who can reach the server on the network |
| Admin (`/admin/`) and its API (`/api/admin/…`) | Yes | Users created with `bin/create-admin`, according to their role |
| `/health/live`, `/health/ready`, `/version`, `/api/docs/` | No | Anyone on the network; they expose status and version only |

The cashier and kitchen are open **by design**, so a busy counter never waits on a login. The consequence: any device on the network can create, change and cancel orders. This is the main reason the network must be the restaurant's own.

## Accounts and roles

- **No default user.** A new install has no account until you run `docker compose exec web php bin/create-admin <username> [role]`. Passwords have at least 8 characters and are stored as bcrypt hashes; nobody, not even the server, can read them back.
- **Roles:**

  | Role | Admin pages |
  |---|---|
  | `admin` | Everything: Cardápio, Relatórios, Ingredientes, **Configurações, Logs, Auditoria** (including printer setup and the logo) |
  | `manager` | Cardápio, Relatórios, Ingredientes. The other pages answer "Sem permissão" — the server refuses them, hiding them in the menu is only cosmetic |
  | `cashier`, `kitchen` | Accepted by `bin/create-admin`, but they open no Admin page; the cashier and kitchen screens don't need an account |

- **Changing a password**: `PATCH /api/admin/account/password` with the current password and the new one (there is no screen for it yet). It requires the current password, so a stolen session alone can't lock the owner out.
- **Login throttling**: after **10 failed logins in 15 minutes** for the same user from the same device, or **30 from the same device** across users, the login answers "Muitas tentativas de login. Tente novamente em N minutos." until 15 minutes after the last failure. A successful login clears the count. On Docker Desktop (Windows/Mac) every device is seen with the same address, so there the limits count all devices together.
- **Changes are recorded**: Admin › **Auditoria** lists changes to the menu, the settings and orders (edits, cancellations, reopenings, discounts) with their time — and the user, when made from the Admin.

### Sessions

An Admin login lasts **8 hours** and is kept in the browser. **Sair** discards it on that device only: the server keeps no session list, so it can't end a session elsewhere, and changing a password does not end sessions already open.

If a password or an Admin device is compromised:

1. Change that user's password.
2. Generate a new `JWT_SECRET` (`openssl rand -base64 48`), put it in `.env` and run `docker compose up -d`. **Every** Admin session ends; everyone logs in again.

## Secrets

- **`.env`** holds the database passwords and `JWT_SECRET`. It stays on the server, mounted read-only into the containers, never inside the image. Don't commit it, send it by chat, or show it on screen. On Linux, `chmod 600 .env` keeps other users of the server from reading it.
- Change both `changeme` passwords **before the first start** ([`configuration.md`](configuration.md#database)).
- **Backups contain everything**: every order, every user's password hash, and — if you copy it, as [`backup-and-restore.md`](backup-and-restore.md) recommends — the `.env`. Store them like the server itself: an encrypted disk or a private cloud folder, not a shared one.

## Production settings

A restaurant runs with `.env.example`'s defaults:

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | Production behavior |
| `APP_DEBUG` | `false` | Errors don't show stack traces, file paths or SQL in API responses; they go to the log |
| `COMPOSE_FILE` | unset | The production compose: code inside the image, not editable on disk |

## Updates

- Run a **release tag**, never `master` ([`upgrading.md`](upgrading.md)). Security fixes are published as releases and listed under "Segurança" in the `CHANGELOG.md`.
- A fix to PHP or the image only applies after `docker compose up -d --build`; updating the code alone is not enough.
- Take a backup before every upgrade ([`backup-and-restore.md`](backup-and-restore.md)).

## Known limitations

Stated so nobody relies on a protection that doesn't exist. Each is tracked for a future version.

| Limitation | Effect | Mitigation today |
|---|---|---|
| Cashier and kitchen without login | Anyone on the network can operate orders | Keep the network the restaurant's own |
| No HTTPS | Traffic, including Admin passwords, is readable on the network | Same; no open or shared Wi-Fi |
| `CORS_ALLOWED_ORIGIN` defaults to `*` | Another website opened on a device of the network could call the open API from that browser | Set it to the address the screens use, e.g. `http://192.168.1.10:8080` |
| Sessions can't be revoked one by one | A leaked Admin session works until it expires (8 h) | Rotate `JWT_SECRET` (above) |
| PHP's `display_errors` is on in the image | A fatal error that happens before the app starts shows internal file paths in the page, even with `APP_DEBUG=false` | None needed in normal operation; report it if you see one |

## Reporting a vulnerability

Report it privately through GitHub (Security › Report a vulnerability), never in a public issue. The process, what to include and which versions get fixes: [`SECURITY.md`](../SECURITY.md). *(Updated 2026-10-31, spec 071 — before, this section left the process for a future `SECURITY.md` and named no private channel.)*
