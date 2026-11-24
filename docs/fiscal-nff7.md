# Fiscal service (NF-F7) — installation

GastroFlow issues no fiscal document. A restaurant that needs one runs **NF-F7**, GastroFlow's closed, private fiscal module, inside GastroFlow's own Docker Compose project. GastroFlow talks to it over HTTP only (`http://nff7-app` on the internal Docker network), with an API key. Nothing else is shared: NF-F7 has its own database, volumes and secrets. *(Corrected 2026-11-23, spec 086: it said "FocusEven's separate and private fiscal service" — see ADR 0001.)*

**What it does today (spec 084):** a connectivity test. Admin › Configurações shows a **Nota fiscal (NF-F7)** card with "Conectado" or "Sem conexão". No order, kitchen or printing step calls NF-F7, so NF-F7 being down changes nothing for the cashier. Issuing NFC-e comes later, once NF-F7 offers it.

A restaurant without NF-F7 skips this page entirely: GastroFlow's public `docker-compose.yml` doesn't mention NF-F7, and with the two `.env` variables empty the fiscal area is off.

Every command runs from the GastroFlow folder, unless it says otherwise. Written 2026-11-17 from the first real installation, with NF-F7 `0.1.0-gastroflow.1`.

## 1. Before you start

- GastroFlow installed and working ([`installation.md`](installation.md)), on a release tag.
- A GitHub token that can **read** the private package `ghcr.io/focusevengithub/nf-f7`: one read-only token per restaurant, from a machine account. Write down which host has which token outside the repository, so you can revoke it when the restaurant leaves.
- The NF-F7 package files for the version you install (`deploy/gastroflow/` from that NF-F7 tag). They're in the NF-F7 repository, which is private.
- The NF-F7 version, for example `0.1.0-gastroflow.1`.

## 2. Log in to the image registry

```bash
echo '<token>' | docker login ghcr.io -u '<github-user>' --password-stdin
```

On a machine where the `gh` CLI is logged in with your own account, this works too:

```bash
gh auth refresh -s read:packages
gh auth token | docker login ghcr.io -u "$(gh api user --jq .login)" --password-stdin
```

The login is saved in the host's `~/.docker/config.json`, never in any `.env`.

## 3. Copy the NF-F7 files

```bash
mkdir -p nff7
git -C <path-to-NF-F7-clone> archive v0.1.0-gastroflow.1 deploy/gastroflow | tar -x --strip-components=2 -C nff7
cp nff7/nff7.env.example nff7/nff7.env
```

`nff7/` and `compose.override.yml` (next step) are ignored by GastroFlow's git and kept out of its Docker image (spec 084), so a `git pull` or an image rebuild never touches them.

## 4. Fill in `nff7/nff7.env`

This generates the passwords and the certificate key without showing them:

```bash
sed -i \
  -e "s|^NFF7_IMAGE_TAG=.*|NFF7_IMAGE_TAG=0.1.0-gastroflow.1|" \
  -e "s|^NFF7_MYSQL_ROOT_PASSWORD=.*|NFF7_MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)|" \
  -e "s|^NFF7_MYSQL_PASSWORD=.*|NFF7_MYSQL_PASSWORD=$(openssl rand -hex 24)|" \
  -e "s|^NFF7_CERT_ENCRYPTION_KEY=.*|NFF7_CERT_ENCRYPTION_KEY=$(openssl rand -base64 32)|" \
  nff7/nff7.env
```

Check it, still without printing the secrets:

```bash
grep -E '^NFF7_' nff7/nff7.env | sed -E 's/(PASSWORD|KEY)=.+/\1=<filled>/'
```

Expected: the version tag, both passwords and the key as `<filled>`, `NFF7_MYSQL_DATABASE=nff7`, `NFF7_MYSQL_USER=nff7`, `NFF7_UI_PORT=8090`.

> ⚠️ **Keep a copy of `NFF7_CERT_ENCRYPTION_KEY` off this server.** It encrypts the uploaded fiscal certificates: lose it and the certificates have to be uploaded again.

If port 8090 is already taken on the host (another NF-F7 running there, for example), change `NFF7_UI_PORT` — the first installation used `8091` for that reason.

## 5. Create `compose.override.yml`

```bash
printf 'include:\n  - path: ./nff7/compose.nff7.yml\n    env_file: ./nff7/nff7.env\n' > compose.override.yml
```

Docker Compose loads `compose.override.yml` by itself, next to `docker-compose.yml` — **but only while `COMPOSE_FILE` is unset**:

- **Restaurant (production):** `COMPOSE_FILE` is unset (as [`configuration.md`](configuration.md) asks). Nothing else to do.
- **Development:** `.env` has `COMPOSE_FILE=docker-compose.dev.yml`; change that line to `COMPOSE_FILE=docker-compose.dev.yml:compose.override.yml`.

## 6. Check and start

```bash
docker compose config --services
```

It must list `nff7-db`, `nff7-migrate` and `nff7-app` next to GastroFlow's own services. Then the usual command:

```bash
docker compose up -d
```

The first run downloads the NF-F7 image; `nff7-migrate` prepares NF-F7's database before `nff7-app` starts.

## 7. NF-F7 administrator

```bash
docker compose exec nff7-app php bin/user create admin --role=admin
```

It asks for the password. This is NF-F7's own user, unrelated to GastroFlow's Admin users.

## 8. Register the issuer

NF-F7's admin screen is published on the server itself only: http://127.0.0.1:8090/emitentes (or the `NFF7_UI_PORT` you chose). From another computer, through an SSH tunnel: `ssh -L 8090:127.0.0.1:8090 <server>`.

Log in with the user from step 7 and register the restaurant's issuer (CNPJ, legal name, municipality). The fiscal certificate can wait: the connectivity test doesn't need it.

Then find the issuer's id (read-only query; the database password is read inside the container, never shown):

```bash
docker compose exec nff7-db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT id, cnpj, legal_name FROM issuers"'
```

## 9. GastroFlow's API key

```bash
docker compose exec nff7-app php bin/api-client create gastroflow --issuer=<id>
```

Use the `id` from step 8. The key (`nff7_…`) is printed **once**; copy it straight into the next step. An `id` that doesn't exist creates nothing and exits with an error.

## 10. Point GastroFlow at NF-F7

Add two lines to GastroFlow's `.env` (no spaces around `=`):

```
NF_F7_URL=http://nff7-app
NF_F7_API_KEY=<the key from step 9>
```

Nothing to restart: GastroFlow reads `.env` on every request.

## 11. Check

Open Admin › Configurações (`/admin/settings.php`). Below "Desconto no caixa" there is now the **Nota fiscal (NF-F7)** card, which must say **Conectado como gastroflow (NF-F7 <version>)**. **Testar conexão** runs the test again.

## Troubleshooting

| What you see | Cause | Fix |
|---|---|---|
| `compose file "compose.override.yml" set by COMPOSE_FILE environment variable is invalid: … no such file or directory` | `COMPOSE_FILE` lists the override, but the file doesn't exist | Step 5 |
| `config --services` doesn't list `nff7-*` | Override not loaded: `COMPOSE_FILE` is set and doesn't include it | Step 5 |
| `required variable NFF7_… is missing a value` | `nff7/nff7.env` incomplete | Step 4 |
| `denied` / `unauthorized` pulling the image | No registry login, or the token can't read the package | Step 2 |
| `port is already allocated` on 8090 | Something else uses the UI port | Change `NFF7_UI_PORT` (step 4) |
| No fiscal card in Admin › Configurações | GastroFlow doesn't see `NF_F7_URL` and `NF_F7_API_KEY` | `grep -n 'NF_F7' .env \| cut -d= -f1` must show both names exactly; not in `nff7/nff7.env`, not commented out, no spaces around `=` |
| Card says "O serviço fiscal recusou a chave" | Wrong, incomplete or revoked key | Create a new key (step 9) and replace it in `.env` |
| Card says "Não foi possível falar com o serviço fiscal" | `nff7-app` down, or wrong `NF_F7_URL` | `docker compose ps nff7-app`; the URL is `http://nff7-app` |
| Card says "Configuração incompleta" | Only one of the two variables is filled | Step 10 |

## Day to day

- **Replace the key:** create a new one (step 9), put it in `.env`, then revoke the old one: `docker compose exec nff7-app php bin/api-client list` (shows prefix and last use, never the key) and `… bin/api-client revoke <id>`.
- **Update NF-F7:** change `NFF7_IMAGE_TAG` in `nff7/nff7.env`, then `docker compose up -d`; `nff7-migrate` runs before the new `nff7-app`. Update GastroFlow as usual ([`upgrading.md`](upgrading.md)); the two are independent.
- **Backup:** GastroFlow's `bin/backup-db` covers GastroFlow's database only. NF-F7's data lives in the `nff7_db_data`, `nff7_certificates` and `nff7_fiscal_documents` volumes, plus the certificate key kept apart (step 4) — follow NF-F7's own backup instructions.
- **Turn the fiscal area off:** empty `NF_F7_URL` and `NF_F7_API_KEY` in `.env` — the card disappears, nothing else changes. To stop NF-F7 too: delete `compose.override.yml` (development: take `:compose.override.yml` out of `COMPOSE_FILE`) and `docker compose up -d --remove-orphans`. Its volumes stay until you delete them.
- **The restaurant leaves:** revoke its registry token.
