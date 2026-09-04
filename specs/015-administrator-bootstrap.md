# Spec 015 — Administrator bootstrap

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Administrator bootstrap" subsection, requires removing permanent default credentials (`admin`/`admin123`) and providing an explicit administrator creation process (suggested: `php bin/create-admin`), with secure password hashing, duplicate-user validation, empty/invalid password rejection, and no predictable production credentials.

This follows the same pattern already applied twice this milestone: spec 002 removed the hardcoded `JWT_SECRET` fallback, spec 014 removed the hardcoded `restuser`/`restpass` database credentials — both replaced with "fail loudly / require explicit configuration" instead of a silent, predictable default. This spec applies the same principle to the one remaining hardcoded credential: the seeded `admin`/`admin123` application user.

## Problem

Confirmed by direct reads:

1. `common/sql/001_schema.sql` (current, post-spec-014 line numbers) ends with:
   ```sql
   -- Inserir um usuário admin padrão (senha: admin123, hash bcrypt)
   INSERT INTO users (username, password, role) VALUES
       ('admin', '$2y$10$kAdCtbkdV7SCeV8aL3gJput/GXQsvgpjxTSI/lVfMhgaEPuXiMRry', 'admin');
   ```
   Every fresh installation gets this exact, publicly-documented (in this very repo's `README.md` and `public/api/docs/openapi.yaml`) admin account, with no forced change on first use.
2. No script or process exists to create an administrator any other way — `src/Controllers/AuthController.php` only verifies existing credentials (`password_verify()`), it never creates a `User`. `bin/` currently has only `migrate` and `worker` (confirmed via directory listing) — no `create-admin`.
3. Documentation actively advertises the default credential as the way to log in, which would need to change in lockstep with removing the seed, or the docs become both wrong and a security liability (telling readers a credential that used to work): `README.md:277` ("Default login" callout), `README.md:308` (cURL login example), `public/api/docs/openapi.yaml:232,251` (Swagger UI's own login description and example value), `specs/000-project-baseline.md:76` (Authentication paragraph, "confirmed in code" snapshot), `CLAUDE.md:15` ("no formal seeding beyond the one admin row").
4. No automated test depends on the seeded `admin`/`admin123` user — confirmed by reading `tests/Smoke/ApiTest.php` (only exercises `GET /api/menu`) and `.github/workflows/ci.yml` (runs migrations + PHPUnit, no login step). Removing the seed breaks no test.
5. `src/Models/User.php` — a plain Eloquent model (`$fillable = ['username', 'password', 'role']`), no existing factory/creation helper anywhere to build on.

## Goals

- No fresh GastroFlow installation has a working `admin`/`admin123` (or any other predictable) login.
- An explicit, documented process (`php bin/create-admin <username>`) creates the first (or an additional) administrator, with a securely hashed password, rejecting a duplicate username or an empty/too-short password.
- `README.md`, `public/api/docs/openapi.yaml`, `CLAUDE.md`, and `specs/000-project-baseline.md` all describe the new process accurately — none still advertise the old default credential.

## Non-goals

- **Not building a web UI for administrator creation.** A CLI script (matching `bin/migrate`/`bin/worker`'s existing convention) is sufficient and matches the roadmap's own suggested approach; a UI-based user-management screen is out of scope here.
- **Not adding password complexity rules beyond a minimum length.** The roadmap requires "empty/invalid password rejection," not a full password-policy engine — this spec rejects empty and under-8-character passwords (a concrete, simple, testable bar) and leaves anything more elaborate to a future spec if ever needed.
- **Not building general user management** (listing, editing, deleting, or promoting/demoting users) — only creating a new administrator. `docs/ROADMAP.md`'s "Authorization / RBAC" subsection (roles beyond `admin`/`staff`) is separate, later work.
- **Not touching `common/migrations/*.sql`** — this spec edits `common/sql/001_schema.sql` directly, the same file (and same "only runs on a genuinely empty volume" reasoning) spec 014 already edited, not a tracked migration.
- **Not adding hidden/masked password input** to `bin/create-admin` (e.g. disabling terminal echo). This is a solo-developer/small-team local CLI tool, not a shared multi-user console — the password is read via plain `fgets(STDIN)`, matching the project's general pragmatism (no complexity added without a concrete need). Documented explicitly so it isn't mistaken for an oversight.
- **Not fixing the separate, pre-existing gap that `README.md`'s "Installation" section never mentions running `php bin/migrate`** — out of scope for this spec (unrelated to administrator bootstrap); only the new `bin/create-admin` step is added to that flow.
- **Not requiring email or any field beyond username/password/role** — matches the existing `users` table schema exactly (`username`, `password`, `role`), no new column.

## Current behavior

Confirmed by direct reads on the current working tree:

- `common/sql/001_schema.sql` — `users` table (`id`, `username` unique, `password`, `role` enum `admin`/`staff` default `staff`, `created_at`), followed by the one seeded `admin`/`admin123` row.
- `src/Controllers/AuthController.php::login()` — looks up `User::where('username', ...)->first()`, verifies via `password_verify()`, issues an HS256 JWT (8h expiry) via `firebase/php-jwt`. No user-creation code path exists anywhere in `src/`.
- `src/Models/User.php` — `$fillable = ['username', 'password', 'role']`, no custom methods.
- `bin/migrate`, `bin/worker` — both follow the same bootstrap pattern: `require vendor/autoload.php`, `Dotenv::createImmutable(__DIR__ . '/..')->load()`, `new Settings()`, `Database::boot($settings)`, then their specific logic, with Portuguese CLI output (`echo`, `fwrite(STDERR, ...)`).
- `README.md:277` — "Default login" callout naming `admin`/`admin123` explicitly. `README.md:308` — cURL login example using the same credentials.
- `public/api/docs/openapi.yaml:232` — "Credenciais padrão: `admin` / `admin123`" in the login endpoint's description; line 251 — `admin123` as the `password` field's example value.
- `specs/000-project-baseline.md:76` — Authentication paragraph states "A default `admin`/`admin123` user (bcrypt hash) is seeded by `common/sql/001_schema.sql` (lines ~148–150), matching the README's stated default credentials," as a "confirmed in code" snapshot claim.
- `CLAUDE.md:15` — "There is no ORM migration framework and no formal seeding beyond the one admin row in `001_schema.sql`."
- No test file references `admin`/`admin123` or exercises `POST /api/login` (confirmed: `tests/Smoke/ApiTest.php` only tests `GET /api/menu`).

## Proposed behavior

After this change:

- `common/sql/001_schema.sql` still creates the `users` table, but seeds no rows — a fresh installation has zero users until `bin/create-admin` is run.
- `php bin/create-admin <username>` (run via `docker compose exec web php bin/create-admin <username>`, matching `bin/migrate`'s existing invocation convention): prompts for a password (and confirmation) via STDIN, validates it's non-empty and at least 8 characters and that the two entries match, checks `username` isn't already taken, and on success creates a `User` with `role = 'admin'` and a `password_hash()`-generated bcrypt hash — printing a success message and exiting `0`; on any validation failure, prints a clear error to STDERR and exits non-zero without creating anything.
- `README.md`'s "Default login" callout is replaced with instructions to run `bin/create-admin`; its Installation flow gains a step for it; its cURL login example uses a placeholder credential with a note to run `bin/create-admin` first.
- `public/api/docs/openapi.yaml`'s login endpoint description and example no longer reference `admin123`.
- `specs/000-project-baseline.md` and `CLAUDE.md` are corrected (dated, in place) to no longer claim a seeded admin row exists.

## Functional requirements

1. `common/sql/001_schema.sql` contains a `users` table definition but no `INSERT INTO users` statement.
2. `php bin/create-admin newadmin` (no existing user named `newadmin`), given a valid password entered twice matching, creates exactly one row in `users` with `username = 'newadmin'`, `role = 'admin'`, and a `password` value that `password_verify($enteredPassword, $storedHash)` confirms as correct.
3. `php bin/create-admin newadmin` run a second time (username now taken) exits non-zero, prints an error mentioning the username is already in use, and creates no additional row.
4. `php bin/create-admin someuser` given an empty password (just pressing enter) exits non-zero, prints an error, and creates no row.
5. `php bin/create-admin someuser` given a password under 8 characters exits non-zero, prints an error, and creates no row.
6. `php bin/create-admin someuser` given two non-matching password entries (password vs. confirmation) exits non-zero, prints an error, and creates no row.
7. `php bin/create-admin` with no username argument exits non-zero with a usage message, and creates no row.
8. After this change, `POST /api/login` with `{"username":"admin","password":"admin123"}` against a freshly initialized database returns `401` (`"Credenciais inválidas."`) — the account no longer exists.
9. `README.md` no longer states `admin`/`admin123` as a working default login.
10. `public/api/docs/openapi.yaml`'s login endpoint no longer references `admin123`.

## Non-functional requirements

Not applicable beyond the security goal already stated — `password_hash()`'s default cost factor is used (matching the existing seeded hash's bcrypt format, `$2y$...`), no new dependency.

## User flows

- **Operator installing GastroFlow for the first time**: clones the repo, configures `.env`, runs `docker compose up -d`, runs `bin/migrate` (existing step), then runs `docker compose exec web php bin/create-admin <chosen-username>`, enters and confirms a password, and can now log into the admin panel with that account.
- **Existing admin creating a second admin account**: runs the same command with a different username while already having valid credentials — no login/JWT is required to run this CLI tool (it operates directly against the database, like `bin/migrate`), consistent with `bin/migrate`/`bin/worker` also being unauthenticated CLI tools.

## API changes

Not applicable — no HTTP endpoint changes. `POST /api/login`'s behavior is unchanged; only the data available to authenticate against changes (no pre-seeded row).

## Data model and migrations

No schema change — the `users` table structure is untouched. `common/sql/001_schema.sql` loses its seed `INSERT`, following the exact precedent set by spec 014 (editing the one-time initial-schema file directly, not a tracked migration, since it only runs on a genuinely empty database volume).

## Architecture and affected components

- `common/sql/001_schema.sql` — remove the seed `INSERT INTO users`.
- `bin/create-admin` (new file) — CLI script, executable, following `bin/migrate`/`bin/worker`'s existing structure.
- `README.md` — "Default login" callout, Installation flow, cURL login example.
- `public/api/docs/openapi.yaml` — login endpoint description and example.
- `specs/000-project-baseline.md` — Authentication paragraph, dated correction.
- `CLAUDE.md` — the "no formal seeding" claim, and "Commands actually available" gains `bin/create-admin`.
- No `Controllers/`, `Services/`, `Repositories/`, `Middleware/` changes — `src/Models/User.php` is used as-is (already has the right `$fillable` fields).

## Security considerations

This spec closes the last of the three hardcoded-default-credential gaps named across `v1.6.0` (JWT secret, DB credentials, now the application admin account). `password_hash()` (PHP's built-in, currently defaulting to bcrypt) is used rather than reimplementing hashing — matching `AuthController::login()`'s existing `password_verify()` expectations exactly, so no change is needed on the verification side. The script never logs or persists the plaintext password anywhere (not even transiently to a file); it exists only in memory for the duration of the process.

## Backward compatibility

- **Existing, already-initialized databases** (including this project's own dev database) are unaffected — `common/sql/001_schema.sql` only runs via `docker-entrypoint-initdb.d` on a genuinely empty volume (same reasoning as spec 014). This project's existing seeded `admin`/`admin123` row, if not already changed, is not touched by this spec.
- **Fresh installations** now require an explicit `bin/create-admin` run before any admin-panel login is possible — this is the intended behavior change (an installation is not fully functional until an administrator is created), matching `docs/ROADMAP.md`'s `v1.9.0` "Installation experience" sequence, which already lists "Create administrator" as its own step, separate from "Run migrations."

## Acceptance criteria

1. `grep -n "INSERT INTO users" common/sql/001_schema.sql` returns no matches.
2. Running `bin/create-admin` against a real (test) database: creating `testadmin` with a valid 8+ character password (entered twice, matching) succeeds, and a subsequent `POST /api/login` with those exact credentials returns `200` with a JWT.
3. Re-running `bin/create-admin testadmin` (same username) fails with a clear "already exists" message and exit code `1`; the `users` table still has exactly one `testadmin` row afterward.
4. Running `bin/create-admin emptytest` and entering an empty password fails with exit code `1` and no row is created for `emptytest`.
5. Running `bin/create-admin shorttest` and entering a 4-character password fails with exit code `1` and no row is created for `shorttest`.
6. Running `bin/create-admin mismatchtest` and entering two different passwords for the password/confirmation prompts fails with exit code `1` and no row is created for `mismatchtest`.
7. `README.md` contains no remaining instance of the literal string `admin123`.
8. `public/api/docs/openapi.yaml` contains no remaining instance of the literal string `admin123`.
9. `php -l` passes on `bin/create-admin`.

## Implementation plan

1. Write `bin/create-admin`, mirroring `bin/migrate`'s bootstrap boilerplate (dotenv load + `getenv()`→`$_ENV` bridge, `Database::boot()`), with the validation/creation logic described in Proposed behavior.
2. Remove the seed `INSERT INTO users` from `common/sql/001_schema.sql`.
3. Update `README.md`: replace the "Default login" callout, add a `bin/create-admin` step to the Installation flow, update the cURL login example.
4. Update `public/api/docs/openapi.yaml`: remove `admin123` from the login description and example.
5. Update `specs/000-project-baseline.md`'s Authentication paragraph with a dated correction note (per the established pattern from specs 006/010), plus an `Implementation log` entry and `Updated` date bump.
6. Update `CLAUDE.md`: correct the "no formal seeding" line, add `bin/create-admin` to "Commands actually available."
7. Test `bin/create-admin` against the real running database using a throwaway test username (not `admin`, to avoid colliding with or confusing this project's own real dev admin account) — exercise all six scenarios in Acceptance criteria 2-6, then manually verify no leftover test rows remain problematic (a test user existing afterward is harmless and doesn't need cleanup, but this is noted for the user's awareness).
8. `php -l` on the new script; read the diff to confirm scope.

## Testing and validation strategy

No automated test infrastructure covers CLI scripts in this project today (`bin/migrate`/`bin/worker` have no test coverage either). Validation is real execution of `bin/create-admin` against the actual running database (via `docker compose exec web php bin/create-admin ...`), using throwaway test usernames, covering every scenario in Acceptance criteria 2-6, plus real `curl` calls to confirm the created credentials actually authenticate. `vendor/bin/phpunit` is re-run to confirm no regression (nothing in this spec should affect it, since no test references the seeded admin user).

## Rollout and rollback

No migration, no dependency, no container change. Rollback is a plain `git revert`. Since `common/sql/001_schema.sql` only affects freshly-initialized databases, reverting has no effect on any already-running installation either way. This project's own dev database keeps whatever admin account(s) already exist in it, regardless of this spec.

## Open questions

- **Not blocking**: should `bin/create-admin` support a `--role=staff` flag to create non-admin users too, now that the script exists? Deferred — the roadmap's own wording ("Administrator bootstrap") and the current lack of any staff-facing login-gated feature suggest this isn't needed yet; `role` is hardcoded to `'admin'` for this spec. Easy to extend later without breaking this script's existing usage.

## Task checklist

- [x] `bin/create-admin` created (username arg, password/confirm prompts, duplicate check, min-length check, bcrypt hash, `role = 'admin'`)
- [x] `common/sql/001_schema.sql` seed `INSERT INTO users` removed
- [x] `README.md` updated (Default login callout, Installation flow, cURL example)
- [x] `public/api/docs/openapi.yaml` updated (description + example)
- [x] `specs/000-project-baseline.md` Authentication paragraph corrected, `Implementation log` + `Updated` date added
- [x] `CLAUDE.md` updated ("no formal seeding" line, "Commands actually available")
- [x] Acceptance criteria 2-6 verified via real execution against the running database
- [x] `php -l` + diff review

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Re-verified `common/sql/001_schema.sql`'s seed block and `bin/`'s directory listing immediately before editing — unchanged since `/spec-plan`, no conflict to report.
- 2026-09-03 — Implemented `bin/create-admin` exactly per plan, mirroring `bin/migrate`'s bootstrap boilerplate. Made executable (`chmod +x`), matching `bin/migrate`/`bin/worker`'s existing permissions.
- 2026-09-03 — Removed the seed `INSERT INTO users` from `common/sql/001_schema.sql`; updated `README.md` (Default login callout reworded, Installation flow gained a `bin/create-admin` step, cURL login example uses a placeholder password), `public/api/docs/openapi.yaml` (description + example), `specs/000-project-baseline.md` (dated correction + Implementation log entry), and `CLAUDE.md` (seeding claim + new command listed) — no deviations from the plan.
- 2026-09-03 — Validated all six CLI scenarios (Acceptance criteria 2-6, plus the missing-argument case) against the real running database using throwaway usernames (`testadmin`, `emptytest`, `shorttest`, `mismatchtest`) chosen specifically to avoid colliding with this project's own real `admin` account. Confirmed afterward via a direct `User::all()` query that only `admin` (pre-existing, untouched) and `testadmin` (the one valid creation) exist — none of the three failed attempts left a row behind.
- 2026-09-03 — Did not attempt to validate the schema-file change (`common/sql/001_schema.sql`'s removed seed) by initializing a fresh database volume, for the same reason as spec 014: this project's own `db_data` volume is populated real dev data, and tearing it down to test a fresh-install path would be a destructive, unnecessary action. Validated instead by direct inspection (Acceptance criterion 1) and by confirming `bin/create-admin` — the file's designed replacement — works correctly against the real database.

## Validation evidence

- Acceptance criterion 1 — `grep -n "INSERT INTO users" common/sql/001_schema.sql` → no matches. **Confirmed.**
- Acceptance criterion 2 — `printf "testadmin_pw_123\ntestadmin_pw_123\n" | docker compose exec -T web php bin/create-admin testadmin` → `✓ Administrador "testadmin" criado com sucesso.` (exit 0). Follow-up `curl -X POST /api/login -d '{"username":"testadmin","password":"testadmin_pw_123"}'` → `HTTP 200`, valid JWT, `"role":"admin"`. **Confirmed.**
- Acceptance criterion 3 — Re-running the same command for `testadmin` → `Erro: já existe um usuário com o username "testadmin".` (exit 1). `User::all()` afterward shows exactly one `testadmin` row. **Confirmed.**
- Acceptance criterion 4 — `printf "\n" | ... bin/create-admin emptytest` → `Erro: a senha não pode ser vazia.` (exit 1); no `emptytest` row created. **Confirmed.**
- Acceptance criterion 5 — `printf "abc1\nabc1\n" | ... bin/create-admin shorttest` → `Erro: a senha deve ter ao menos 8 caracteres.` (exit 1); no `shorttest` row created. **Confirmed.**
- Acceptance criterion 6 — `printf "password_one\npassword_two\n" | ... bin/create-admin mismatchtest` → `Erro: as senhas não coincidem.` (exit 1); no `mismatchtest` row created. **Confirmed.**
- Additional evidence beyond the stated criteria — `docker compose exec -T web php bin/create-admin` (no username argument) → `Uso: php bin/create-admin <username>` (exit 1), matching Functional requirement 7.
- Acceptance criterion 7 — `grep -n "admin123" README.md` → no matches. **Confirmed.**
- Acceptance criterion 8 — `grep -n "admin123" public/api/docs/openapi.yaml` → no matches. **Confirmed.**
- Acceptance criterion 9 — `docker compose exec web php -l bin/create-admin` → `No syntax errors detected in bin/create-admin`. **Confirmed.**
- Regression check — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)`, same as before this spec.
- `git diff --stat` reviewed across all six changed files — scope confirmed to match the plan exactly.
