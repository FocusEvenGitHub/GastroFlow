# Spec 013 — Docker secret handling

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Docker secret handling" subsection, requires that runtime secrets are not embedded into Docker images, naming `Dockerfile`, `.dockerignore`, Compose configuration, and environment loading as the things to review, and stating production images must not contain `.env`, database passwords, JWT secrets, development-only files, or local logs.

This is the concrete issue `specs/012-production-error-handling.md`'s Non-goals already flagged and deliberately left unfixed: `Dockerfile:41`'s `COPY .env ./` bakes the real `.env` file — whatever secrets it holds at build time — into a layer of the built image, permanently and irrevocably (extractable later via `docker history`/`docker save`/layer inspection, regardless of what happens to the container afterward).

## Problem

Direct reads of `Dockerfile`, `.dockerignore`, and `docker-compose.yml` confirm the exact gap and its actual severity:

1. `Dockerfile:41` — `COPY .env ./` copies the real `.env` file (present in the build context) into the image at build time.
2. `.dockerignore` (5 lines total) excludes `.git`, `.env.example`, `README.md`, `docker-compose.yml`, `Dockerfile`, `*.md`, `.cache` from the build context — it does **not** exclude `.env` (the real secrets file) or `logs/`. It's backwards from what actually matters here: it blocks the harmless `.env.example` from ever reaching the build context, while leaving the real `.env` free to be sent and then explicitly `COPY`'d in.
3. This is **currently redundant, not load-bearing**: `docker-compose.yml` already bind-mounts the real `.env` into both `web` and `print-worker` at runtime (`./.env:/var/www/html/.env`), which shadows whatever the image itself contains at that path. Under the only documented way to run this app (`docker compose up -d`, per `CLAUDE.md`'s "Commands actually available"), the baked-in copy from `Dockerfile:41` is never actually read — the mounted file always wins. The risk is not "the running app reads the wrong secrets," it's that **the built image itself is a leak vector**: anyone who obtains the image (e.g., if it were ever pushed to a registry, shared, or archived) can extract whatever `JWT_SECRET`/`MYSQL_*` values happened to be in `.env` on the machine that built it, with no dependency on the container ever running.
4. `Dockerfile` uses selective, explicit `COPY` lines (`public/`, `src/`, `common/`, `legacy/`, `bin/`, `.env`) rather than a blanket `COPY . .` — so "development-only files" (`tests/`, `.git`, `docs/`, `specs/`, `.claude/`, `phpunit.xml`, etc.) are **already** not baked into the image. `.env` is the one actual violation of the roadmap's "must not contain" list; local logs (`logs/`) are never `COPY`'d either, so they're already not in the image — `.dockerignore` not excluding them is a latent risk only if the `COPY` lines ever change, not a current leak.
5. No database password or JWT secret is ever passed as a Docker build `ARG`/`--build-arg` (confirmed: no `ARG` directive exists in `Dockerfile`, and `docker-compose.yml`'s `web`/`print-worker` services have no `build.args`) — the only embedding mechanism found is the `.env` file copy itself.

## Goals

- The built Docker image (`gastroflow-web`) contains no copy of the real `.env` file, under any build.
- `.dockerignore` explicitly excludes `.env` (and any `.env.*` variant except `.env.example`) and `logs/` from the build context, as defense-in-depth against a future `COPY` change reintroducing this — not just relying on today's selective `COPY` list.
- The app continues to receive its real configuration exactly as it does today (`docker-compose.yml`'s existing bind mount) — no behavior change for the one documented way this app is run.

## Non-goals

- **Not changing how `docker-compose.yml` supplies `.env`/DB credentials to the running containers.** The existing bind mount (`./.env:/var/www/html/.env`) and the `environment:` block's `${VAR}` substitutions are the correct, already-working runtime mechanism — this spec only stops the *build-time* copy, which is redundant with it.
- **Not addressing the separate, unrelated `vendor/` build/mount mismatch** (the image builds its own `vendor/` via `composer install` during `docker build`, which is then shadowed at runtime by `docker-compose.yml`'s own `./vendor:/var/www/html/vendor` host bind mount). This is an existing architectural quirk, not a secret-handling issue, and touching it isn't required to close this roadmap item.
- **Not adding Docker BuildKit secret mounts (`--mount=type=secret`) or any new build-time secret-injection mechanism.** This app has no build-time need for secrets at all (no private Composer registry, no build-time DB connection) — the fix here is subtraction (stop copying `.env` in), not adding new machinery.
- **Not touching `common/config.php`/`common/db.php`** (confirmed dead code, per `specs/000-project-baseline.md`).
- **Not changing `Settings::get()`/environment-loading code in `public/index.php`, `bin/migrate`, `bin/worker`, `public/api/events/stream.php`** — their `Dotenv::createImmutable(...)->load()` calls read the *runtime, mounted* `.env`, which is unaffected by this spec.
- **Not rebuilding/republishing any previously-built image that may already exist locally or in a registry.** This spec fixes the `Dockerfile`/`.dockerignore` going forward; it does not attempt to audit or purge any image built before this change (out of scope — no registry is used by this project per `CLAUDE.md`/`docker-compose.yml`, so the exposure is local-build-only today).

## Current behavior

Confirmed by direct reads on the current working tree:

- `Dockerfile:1-52` — builds from `php:8.2-apache`; installs system packages and PHP extensions; adjusts the Apache `DocumentRoot`; installs Composer deps (`--no-dev --no-interaction --optimize-autoloader --no-scripts`); then `COPY public/ ./public/`, `COPY src/ ./src/`, `COPY common/ ./common/`, `COPY legacy/ ./legacy/`, `COPY bin/ ./bin/`, `COPY .env ./` (line 41); sets ownership/permissions; sets the container timezone.
- `.dockerignore` (full contents): `.git`, `.env.example`, `README.md`, `docker-compose.yml`, `Dockerfile`, `*.md`, `.cache`.
- `docker-compose.yml` — `web` and `print-worker` services both mount `./.env:/var/www/html/.env` (read-write bind mount, host file wins at container start) and separately set `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `TZ` directly as container `environment:` entries (interpolated from the host's own `.env` via Compose's own, separate `.env`-reading convention — this is orchestration-time substitution, not baked into any image layer).
- No `ARG`/`--build-arg`/BuildKit secret mount exists anywhere in `Dockerfile` or `docker-compose.yml`.
- `CLAUDE.md`'s "Commands actually available" lists `docker compose up -d` as the only documented way to run this app — no bare `docker run gastroflow-web` usage is documented or implied anywhere in the repo.

## Proposed behavior

After this change:

- `Dockerfile` no longer has a `COPY .env ./` line. The image builds successfully without it (no build step depends on `.env` being present during `docker build`).
- `.dockerignore` adds `.env` and `.env.*` (with `.env.example` explicitly re-allowed via a `!.env.example` negation, mirroring `.gitignore`'s existing pattern) and `logs/`.
- `docker compose up -d --build` produces a working app identical to today's behavior — the app still reads its real configuration via the existing bind mount, unaffected by the Dockerfile change.
- Inspecting the newly built image (`docker run --rm gastroflow-web cat /var/www/html/.env` or equivalent) shows no such file exists in the image, or shows only whatever the base image already had (none) — confirming the real `.env` is no longer baked in.

## Functional requirements

1. `Dockerfile` does not contain a `COPY .env` instruction (or any instruction that copies the real `.env` file into the image).
2. `.dockerignore` contains `.env` and `.env.*` entries (excluding `.env.example` via negation), and a `logs/` (or equivalent) entry.
3. `docker compose build web` (or `docker compose up -d --build`) completes successfully with no error caused by the removed `COPY` line.
4. After a fresh build, no file at `/var/www/html/.env` exists inside the `gastroflow-web` image itself (verified by running a throwaway container from the image with the compose volume mount *not* attached).
5. After `docker compose up -d` (with the normal compose-provided bind mount), the app behaves identically to before this change: the cashier page loads, `GET /api/menu` returns data, and `POST /api/login` succeeds with the existing seeded/admin credentials — confirming the real `.env` still reaches the running container exactly as before, via the bind mount, unaffected by the `Dockerfile` change.

## Non-functional requirements

Not applicable beyond the security goal already stated — no performance impact; if anything, the image is marginally smaller/has one fewer layer.

## User flows

Not applicable — no user-facing behavior change of any kind.

## API changes

Not applicable.

## Data model and migrations

Not applicable — no database changes.

## Architecture and affected components

- `Dockerfile` — remove the `COPY .env ./` line.
- `.dockerignore` — add `.env`, `.env.*`, `!.env.example`, `logs/`.
- No application code (`src/`, `public/`, `common/`) or `docker-compose.yml` changes.

## Security considerations

This spec's entire purpose is closing a real secret-embedding vector in the built Docker image. The fix is a pure subtraction (stop copying a file in) plus a defense-in-depth `.dockerignore` addition — it does not introduce any new secret-handling mechanism, reducing risk of introducing a new bug in the process. Verification must include actually inspecting a freshly built image's filesystem for the absence of `.env` (Acceptance criterion / Functional requirement 4), not just reading the `Dockerfile` diff — a `COPY` removal is simple enough that reading the diff is strong evidence, but a real rebuild-and-inspect closes the loop per `CLAUDE.md`'s "never claim a test passed without running it."

## Backward compatibility

None expected: the only documented way to run this app (`docker compose up -d`) already supplies `.env` via bind mount, which takes precedence over anything baked into the image. Removing the redundant `COPY` does not change what configuration the running container actually sees. If any undocumented workflow exists that runs the image standalone (`docker run gastroflow-web` without the compose bind mount) and relied on the baked-in `.env`, it would break — no such workflow is referenced anywhere in `CLAUDE.md`, `README.md`, or `docker-compose.yml`, so this is treated as out of scope/nonexistent rather than a compatibility risk to mitigate.

## Acceptance criteria

1. `grep -n "COPY .env" Dockerfile` returns no matches.
2. `.dockerignore` contains `.env`, `.env.*`, `!.env.example`, and a `logs` entry (verified by reading the file).
3. `docker compose build web` completes with exit code `0`.
4. `docker run --rm gastroflow-web ls /var/www/html/.env` (no volume mount attached) reports the file does not exist (non-zero exit / "No such file or directory").
5. After `docker compose up -d` (normal compose run, bind mount active): `curl http://localhost:8080/api/menu` returns `200` with menu data; `curl -X POST http://localhost:8080/api/login` with the existing valid credentials returns a token — confirming runtime configuration is unaffected.

## Implementation plan

1. Remove `Dockerfile:41`'s `COPY .env ./` line.
2. Update `.dockerignore`: add `.env`, `.env.*`, `!.env.example` (mirroring `.gitignore`'s existing negation pattern), and `logs/`.
3. Rebuild the `web` image (`docker compose build web`) and confirm it succeeds.
4. Run a throwaway container from the freshly built image without the compose volume mount and confirm `.env` is absent (Acceptance criterion 4).
5. Bring the real stack back up (`docker compose up -d`) and confirm the app still works end-to-end (Acceptance criterion 5) — this also rebuilds `print-worker` from the same image, so confirm it starts cleanly too (`docker compose ps`).
6. Read the diff to confirm only `Dockerfile`/`.dockerignore` changed.

## Testing and validation strategy

No automated test infrastructure covers Docker image contents (this project's PHPUnit suite runs application code only, not image builds). Validation is: a real `docker compose build`, a real throwaway `docker run` inspection of the built image's filesystem, and real `curl` calls against the running stack after `docker compose up -d` — all executed, not assumed.

## Rollout and rollback

This changes the built image, not any running container's live configuration (until the next `docker compose up -d --build`/`docker compose up -d` after a build). Rollback is a plain `git revert` followed by rebuilding. Since the fix removes a redundant, already-shadowed `COPY`, there is no data or state to migrate either direction.

## Open questions

None — the fix is narrow and the current behavior is fully confirmed by direct reads; no ambiguous decision remains.

## Task checklist

- [x] `Dockerfile`'s `COPY .env ./` removed
- [x] `.dockerignore` updated (`.env`, `.env.*`, `!.env.example`, `logs/`)
- [x] `docker compose build web` succeeds
- [x] Freshly built image confirmed to not contain `.env` (throwaway container check)
- [x] `docker compose up -d` confirmed working end-to-end (menu + login)
- [x] Diff reviewed to confirm scope stayed within `Dockerfile` + `.dockerignore`

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Removed `Dockerfile:41`'s `COPY .env ./`; updated `.dockerignore` per plan. `docker compose build web` succeeded cleanly.
- 2026-09-03 — Verified the freshly built image has no `.env` at `/var/www/html/.env` via a throwaway `docker run --rm gastroflow-web` with no volume mount attached (had to work around Git Bash's automatic POSIX-path-to-Windows-path conversion mangling `/var/www/html/.env` — used `MSYS_NO_PATHCONV=1` to pass the path through unmodified).
- 2026-09-03 — **Unrelated incident surfaced during `docker compose up -d` (Acceptance criterion 5), not caused by this spec's changes**: bringing the stack back up recreated the `db` container (Compose detected the `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` values in the user's real `.env` had changed since `db` was last created — unrelated to this spec, a side effect of the user having copied spec 011's `.env.example` placeholder value `changeme` over their real database password). Because MySQL's official image only applies those env vars to a genuinely empty data volume, the already-populated `db_data` volume still expected the original password, so every DB-backed endpoint returned `500` (`Access denied for user 'restuser'`). This was **not** a data-loss event (the volume was never removed or reset), but a real, active incident discovered mid-validation. Stopped implementation, reported it to the user immediately and explicitly rather than attempting a fix myself (per `CLAUDE.md`: never touch `.env`, never run destructive DB operations without explicit direction). The user confirmed the original values were `MYSQL_ROOT_PASSWORD=rootpass`/`MYSQL_PASSWORD=restpass` (matching the pre-existing `.env.example` content recorded earlier in this session, before spec 011 overwrote it) and restored them in their own real `.env`. Re-ran `docker compose up -d` to recreate `web`/`print-worker` with the corrected `MYSQL_PASSWORD` (baked into the container environment at creation time via Compose's `${VAR}` substitution — editing `.env` alone does not update an already-running container's environment). Connectivity restored; verified no data loss (`55 orders, 76 menu items`, matching pre-incident state as far as could be observed).
- 2026-09-03 — This incident is logged here (rather than in a new spec) because it was discovered and resolved entirely within this spec's own validation step (Acceptance criterion 5) and is now fully resolved; it does not represent unfinished work carried forward.

## Validation evidence

- Acceptance criterion 1 — `git diff -- Dockerfile` shows the `COPY .env ./` line removed; `grep -n "COPY .env" Dockerfile` → no matches (checked via the diff itself, equivalent to the grep).
- Acceptance criterion 2 — `.dockerignore` read back after editing: contains `.env`, `.env.*`, `!.env.example`, `logs/`. **Confirmed.**
- Acceptance criterion 3 — `docker compose build web` → completed successfully, image `gastroflow-web` built and tagged (`Image gastroflow-web Built`). **Confirmed.**
- Acceptance criterion 4 — `MSYS_NO_PATHCONV=1 docker run --rm gastroflow-web ls -la /var/www/html/.env` → `ls: cannot access '/var/www/html/.env': No such file or directory` (exit code 2). A directory listing of `/var/www/html/` in the same throwaway container confirmed `bin/`, `common/`, `composer.json`, `composer.lock`, `legacy/`, `public/`, `src/`, `vendor/` are all present and correctly owned — only `.env` is absent, confirming the fix is precise (nothing else was accidentally excluded). **Confirmed.**
- Acceptance criterion 5 — After `docker compose up -d` (and after resolving the unrelated DB-password incident above): `curl http://localhost:8080/api/menu` → `HTTP 200`, `15742` bytes of real menu data. `curl -X POST http://localhost:8080/api/login` with `admin`/`admin123` → `HTTP 200`, valid JWT returned. `docker compose ps` shows all three services (`db`, `web`, `print-worker`) `Up`. **Confirmed** — runtime behavior is unaffected by the `Dockerfile`/`.dockerignore` change itself (the incident above was a separate, pre-existing condition, now resolved).
- Additional evidence: `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)`, same as before this spec. `docker compose exec web php -r "...Order::count()..."` → `55 orders, 76 menu items` — confirms no data was lost during the DB-password incident/recovery.
- `git diff --stat -- Dockerfile .dockerignore` → 2 files changed, minimal diff (one line removed, four lines added), confirming scope stayed within the two intended files.
Not yet available — filled in during `/spec-implement`.
