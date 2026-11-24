# Configuration

GastroFlow has two places for settings:

| Where | What | Who changes it | Takes effect |
|---|---|---|---|
| `.env`, in the install folder on the server | Database credentials, secrets, environment, queue tuning | Whoever runs the server | On the next `docker compose up -d` |
| **Admin › Configurações**, stored in the database | Restaurant name, logo, printer, discount limit | An `admin` user | Immediately |

The first installation of `.env` is step 3–4 of [`installation.md`](installation.md). This page is the reference for every value.

## `.env`

Created from `.env.example` (`cp .env.example .env`), which ships the production defaults. Never commit it, never paste it into a ticket or a screenshot — it holds the database passwords and the token secret ([`security.md`](security.md#secrets)).

After editing it, apply with:

```bash
docker compose up -d
```

### Database

| Variable | Required | Default in `.env.example` | What it does |
|---|---|---|---|
| `DB_HOST` | yes | `db` | Host the app connects to. Leave `db` (the compose service). |
| `MYSQL_ROOT_PASSWORD` | yes | `changeme` | MySQL root password. **Change it before the first start.** |
| `MYSQL_DATABASE` | yes | `restaurant` | Database name. |
| `MYSQL_USER` | yes | `restuser` | The user the app connects as. |
| `MYSQL_PASSWORD` | yes | `changeme` | That user's password. **Change it before the first start.** |

> ⚠️ MySQL reads these four `MYSQL_*` values only on its **first** start, when it creates the database volume. Changing them in `.env` afterwards does not change the database — the app then can't connect. See the matching row in [`installation.md` › Troubleshooting](installation.md#troubleshooting).

### Authentication and CORS

| Variable | Required | Default | What it does |
|---|---|---|---|
| `JWT_SECRET` | **yes** | empty | Signs the Admin login tokens. Generate with `openssl rand -base64 48`. Empty → every page and API call, `/health/ready` included, fails with HTTP 500 (`JWT_SECRET environment variable is not set.` in the log); the containers still show "Up". Changing it ends every Admin session ([`security.md`](security.md#sessions)). *(Corrected 2026-11-16, spec 082 — until then only a missing line failed this way; an empty one started normally and just the Admin login failed.)* |
| `CORS_ALLOWED_ORIGIN` | no | empty | Which other web origin may call the API from a browser. The GastroFlow screens are served by GastroFlow itself and don't need it, so leave it empty: no other website can then call the API from a browser on the network. `*` allows any website — avoid it. *(Updated 2026-11-09, spec 079 — the default was `*` before.)* |

### Fiscal service (optional)

Added 2026-11-16, spec 084. Only for a restaurant that has **NF-F7**, GastroFlow's closed fiscal module; GastroFlow itself issues no fiscal document. *(Corrected 2026-11-23, spec 086: it said "FocusEven's separate fiscal service" — since ADR 0001 (spec 085) NF-F7 is no longer a product of its own.)* Leave both empty otherwise: the fiscal area is off and nothing changes.

| Variable | Default | What it does |
|---|---|---|
| `NF_F7_URL` | empty | Where NF-F7 answers. With NF-F7 in the same Docker Compose project: `http://nff7-app`. |
| `NF_F7_API_KEY` | empty | The key NF-F7 printed once when the API client was created (`nff7_…`). A secret, like `JWT_SECRET`: it lives only here, never in Admin › Configurações. |

With both set, Admin › Configurações shows a **Nota fiscal (NF-F7)** card: "Conectado" or "Sem conexão" with what to check, and a **Testar conexão** button. Today that connectivity test is all the integration does — no order, kitchen or printing step calls NF-F7, so NF-F7 being down changes nothing for the cashier. A change to either variable is picked up on the next request, no restart.

Installing NF-F7 next to GastroFlow (its image, its own `nff7/` folder and `compose.override.yml` beside GastroFlow's files), step by step: [`fiscal-nff7.md`](fiscal-nff7.md). Both paths are ignored by git and kept out of the GastroFlow image. *(Updated 2026-11-17, spec 084 — it said the steps lived only in NF-F7's package.)*

### Environment

| Variable | Default | What it does |
|---|---|---|
| `APP_ENV` | `production` | `production`, `development` or `test`. Unset → `production`. A restaurant runs `production`. |
| `APP_DEBUG` | `false` | `true` puts error details (stack trace, SQL) in API responses. Unset → `false`. **Never `true` in a restaurant.** |
| `APP_TIMEZONE` | `America/Sao_Paulo` | IANA time zone used by the application (business day, report dates). Unset → the container's zone. |

About time zones: the compose files also set `TZ: America/Sao_Paulo` on every container, including MySQL, and the PHP image has `date.timezone=America/Sao_Paulo`. `APP_TIMEZONE` changes the application's zone only; the containers' zone is fixed in `docker-compose.yml`. GastroFlow is today set up for Brazil's `America/Sao_Paulo` time.

### Job queue and retention

The print queue and the kitchen's realtime events are stored in the database and cleaned up by `print-worker` once an hour. The defaults fit a restaurant; change them only with a reason.

| Variable | Default | What it does |
|---|---|---|
| `QUEUE_RESERVATION_TIMEOUT` | `300` | Seconds a worker may hold a job before it counts as abandoned and is retried. |
| `QUEUE_RETENTION_DAYS` | `7` | Days a **completed** job is kept. Failed jobs are never removed automatically. |
| `EVENTS_RETENTION_DAYS` | `2` | Days a kitchen realtime event is kept. |

### Docker Compose

| Variable | Default | What it does |
|---|---|---|
| `COMPOSE_FILE` | unset | Unset → `docker-compose.yml`, the production setup. Developers set `docker-compose.dev.yml`. **Leave it unset in a restaurant.** Docker only loads a `compose.override.yml` by itself while this is unset; a developer who uses one (the NF-F7 include, spec 084) lists it too: `docker-compose.dev.yml:compose.override.yml`. |
| `GASTROFLOW_VERSION` | unset | The version shown at `/version`. Passed on the build command line (`GASTROFLOW_VERSION=$(git describe --tags --always) docker compose up -d --build`), not set here. Unset → `dev`. |

### Tests (developers only)

| Variable | Default | What it does |
|---|---|---|
| `MYSQL_DATABASE_TEST` | unset | Database for the integration test suite. Unset → those tests skip. Equal to `MYSQL_DATABASE` → they fail on purpose, to protect your data. Never set it in a restaurant. |

### Production vs development

| | Restaurant (production) | Developer machine |
|---|---|---|
| `APP_ENV` | `production` | `development` |
| `APP_DEBUG` | `false` | `true` |
| `COMPOSE_FILE` | unset | `docker-compose.dev.yml` |
| PHP errors | only in the container logs (`docker compose logs web`) | also on the page (`docker/php-dev.ini`) |
| Code | inside the image, from a release tag | mounted from the folder |

## Admin › Configurações

Only users with the `admin` role see this page. **Salvar Configurações** saves every field at once and records the change in **Auditoria**; the logo is uploaded as soon as a file is chosen.

### Restaurante

| Field | Default | Notes |
|---|---|---|
| Nome do Restaurante | `GastroFlow` | Up to 100 characters. Printed at the top of the ticket. |
| Logo | GastroFlow's logo | PNG, JPG or WebP, square (1:1). Always stored as PNG in the server's `uploads` volume — keep it in your backups ([`backup-and-restore.md`](backup-and-restore.md)). Printed at the top of the ticket. |
| Imprimir a logo no cupom | On | Off: the ticket and the test page print without the logo; the logo stays stored. Saved as `print_logo` (`1`/`0`); an install that never saved it counts as on (spec 075). |

### Impressão Térmica

| Field | Default | Notes |
|---|---|---|
| IP da Impressora | `192.168.0.100` | A **placeholder**: replace it with your printer's real IP before the first order, or printing gets blocked after three failures. |
| Porta | `9100` | Raw ESC/POS port. |

**Imprimir Teste** prints a test ticket. Everything about the printer: [`printing.md`](printing.md).

### Desconto no caixa

| Field | Default | Notes |
|---|---|---|
| Desconto máximo por item (%) | `100` | Highest discount the cashier and kitchen may give on one item, 0–100. **0 turns item discounts off.** |

## Users

There is no users page. Users are created on the server:

```bash
docker compose exec web php bin/create-admin <username> [role]
```

`role` is `admin` (default) or `manager`; the command also accepts `cashier` and `kitchen`, but those roles can't open any Admin page. What each role can do: [`security.md` › Accounts and roles](security.md#accounts-and-roles). There is no password screen either: a password is changed through the API (`PATCH /api/admin/account/password`, [`security.md`](security.md#accounts-and-roles)).
