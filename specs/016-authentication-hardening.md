# Spec 016 — Authentication hardening (password change + documented strategy)

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Authentication hardening" subsection, asks to review the authentication lifecycle and define/test: token expiration, invalid tokens, expired tokens, logout behavior, password changes, password hashing, login throttling — plus documenting the authentication strategy clearly.

Investigation (this `/spec-plan` pass) found the lifecycle is a mix of already-solid and genuinely missing pieces. Per an explicit scoping decision with the user, **this spec covers the password-change endpoint and documenting the auth strategy** (including the already-solid pieces, so the documentation is complete); **login throttling is deferred to its own follow-up spec**, since it needs its own design decisions (per-IP vs per-username, storage mechanism — this project has no Redis/cache, so it likely needs a small schema addition — and lockout duration) that deserve a dedicated decision point rather than being bundled in.

## Problem

Confirmed by direct reads:

1. **Token expiration, invalid tokens, expired tokens — already correctly handled**, not a gap: `src/Controllers/AuthController.php:39` sets `'exp' => time() + 3600 * 8` (8-hour expiry) on every issued JWT. `src/Middleware/JwtMiddleware.php:32-40` distinguishes and correctly responds to all three cases already: missing token → `401` `"Token não fornecido."`; expired (`Firebase\JWT\ExpiredException`) → `401` `"Token expirado."`; any other decode failure (malformed, wrong signature, etc.) → `401` `"Token inválido."`. This was simply never written down anywhere as the intended strategy.
2. **Password hashing — already correct**, not a gap: `AuthController::login()` uses `password_verify()`; `bin/create-admin` (spec 015) uses `password_hash($password, PASSWORD_BCRYPT)`. Also never documented as the deliberate approach.
3. **Logout behavior — no server-side mechanism exists, and none is needed for the current architecture**, but this was never stated explicitly: JWTs are stateless and self-contained (no session store, no `Middleware/` component queries a database or cache to validate a token beyond signature + expiry). "Logging out" today can only mean the client discarding its own token — there is no `POST /api/logout` route, no token blocklist, no revocation mechanism of any kind. Left undocumented, this reads as an oversight rather than a deliberate, reasoned choice matching the roadmap's own "Keep the stack proportional" principle (no Redis/broker for a single-location deployment).
4. **Password changes — a genuine, missing feature**: no route, controller method, or any code path anywhere lets an authenticated user change their own password. The only way to change a password today is directly in the database (as `bin/create-admin`'s existence for account *creation* implies — there's no equivalent for *changing* an existing one).
5. **Login throttling — a genuine, missing feature**, explicitly deferred (see Context) — `POST /api/login` has no rate limiting, failed-attempt counting, or lockout of any kind today.
6. No file documents the authentication strategy as a whole. `docs/architecture.md` has sections for the request lifecycle, layers, SSE, jobs/printing, and persistence, but nothing specifically about authentication — the closest existing material is scattered: `specs/000-project-baseline.md`'s Authentication/Security considerations sections (a point-in-time baseline snapshot, already several corrections deep from specs 006/010/015) and `README.md`'s brief mentions.
7. No UI anywhere (`public/admin/*`) has a password-change form — `public/admin/settings.php`'s only password-related field is the admin panel's own *login* form (confirmed by reading it), not an account/profile screen.

## Goals

- An authenticated admin can change their own password via the API, with the current password verified first, the new password meeting a minimum strength bar, and the new password stored using the same hashing the rest of the app already uses.
- `docs/architecture.md` gains a dedicated "Authentication" section documenting, as one coherent strategy: token issuance/expiry, how invalid/expired/missing tokens are handled, why there is no server-side logout mechanism, how passwords are hashed, and that login throttling is intentionally out of scope for this spec (tracked separately).

## Non-goals

- **Not implementing login throttling.** Deferred to a dedicated follow-up spec per the explicit scoping decision in Context — this spec documents that it's intentionally not yet done, rather than silently ignoring it.
- **Not adding a server-side logout/token-revocation mechanism** (blocklist, session store, etc.). The stateless-JWT, client-discards-the-token approach is documented as the deliberate strategy for this single-location deployment, matching the roadmap's own "Keep the stack proportional" principle — not a gap to close.
- **Not building a password-change UI** in `public/admin/*`. This spec adds the backend endpoint only; no existing frontend consumes it (confirmed above), and adding one is a separate, larger frontend-scoped effort.
- **Not requiring the new password differ from the current one**, and **not requiring a `new_password_confirmation` field** (the client-side form, if one is ever built, is the natural place for double-entry confirmation — this is a JSON API, not a CLI prompt like `bin/create-admin`, where blind terminal typing justified double entry). Kept minimal, matching the roadmap's actual requirement list ("password changes" as a lifecycle event to support, not a specific UX flow).
- **Not adding password complexity rules beyond a minimum length.** Matches the same bar `bin/create-admin` already established (spec 015): non-empty, at least 8 characters.
- **Not touching `docs/ROADMAP.md`'s own text**, `CHANGELOG.md`, or any already-`Implemented`/`Verified` spec's own Context section.

## Current behavior

Confirmed by direct reads on the current working tree:

- `src/Controllers/AuthController.php` — `login()` only; no other method exists. Constructor takes `string $secret` directly (not resolved via the DI container — instantiated manually in `src/Routes.php:46-49`'s closure for `/api/login`).
- `src/Middleware/JwtMiddleware.php` — as described in Problem point 1; on success, attaches the decoded JWT payload (`sub`, `username`, `role`, `iat`, `exp`) to the request as the `user` attribute (`$request->withAttribute('user', $decoded)`).
- `src/Routes.php` — `/api/login` is a standalone route outside the `/api` and `/api/admin` groups, registered via a closure that manually constructs `new AuthController($secret)` (the same `$secret` variable computed at the top of `register()` and reused for `JwtMiddleware`). The `/api/admin` group (line 51+) already has `->add($jwt)` applied at the group level.
- `src/Models/User.php` — `$fillable = ['username', 'password', 'role']`; no methods beyond the inherited Eloquent ones.
- `public/admin/settings.php` — its only `type="password"` field is for the admin panel's own login form (`loginForm.password`), not an account/profile password-change form.
- `docs/architecture.md` — no "Authentication" section exists; the topic is only touched on in the Request lifecycle section's callout ("Only `/api/admin/*` requires a JWT...").

## Proposed behavior

After this change:

- `PATCH /api/admin/account/password` (JWT-protected, using the existing `$jwt` middleware instance): accepts `{"current_password": "...", "new_password": "..."}`. Identifies the user from the JWT's `sub` claim (already attached to the request by `JwtMiddleware`). Returns `400` if either field is missing or `new_password` is under 8 characters; `401` if `current_password` doesn't verify against the stored hash; `200` with `{"success": true, "message": "..."}` on success, having updated the user's stored password hash via `password_hash($new_password, PASSWORD_BCRYPT)`.
- `docs/architecture.md` gains an "Authentication" section (placed near the existing Request lifecycle section) documenting: JWT issuance and 8-hour expiry; the three `JwtMiddleware` response cases; bcrypt password hashing (`password_hash`/`password_verify`); the deliberate absence of server-side logout/revocation and why; the new password-change endpoint; and an explicit note that login throttling is tracked as separate, not-yet-done follow-up work (avoiding the impression that omitting it here was an oversight).

## Functional requirements

1. `PATCH /api/admin/account/password` without a valid JWT returns `401` (existing `JwtMiddleware` behavior, unchanged).
2. `PATCH /api/admin/account/password` with a valid JWT but missing `current_password` or `new_password` returns `400` with a clear error message.
3. `PATCH /api/admin/account/password` with a valid JWT, correct `current_password`, but `new_password` under 8 characters returns `400`.
4. `PATCH /api/admin/account/password` with a valid JWT and an incorrect `current_password` returns `401` with a clear error message, and does not modify the stored password.
5. `PATCH /api/admin/account/password` with a valid JWT, correct `current_password`, and a valid (≥8 character) `new_password` returns `200`, and the user's stored password hash changes such that `password_verify($new_password, $updatedHash)` is `true` and `password_verify($oldPassword, $updatedHash)` is `false`.
6. After a successful password change, a subsequent `POST /api/login` with the new password succeeds, and with the old password fails (`401`).
7. `docs/architecture.md` contains a section documenting token expiry, the three `JwtMiddleware` outcomes, password hashing, the absence of server-side logout (with rationale), the new password-change endpoint, and an explicit note that login throttling is separate follow-up work.

## Non-functional requirements

Not applicable beyond the security goal already stated — `password_hash()`'s default cost factor is used, matching existing conventions (`bin/create-admin`).

## User flows

- **Admin changing their own password**: already logged in (has a valid JWT from a prior `POST /api/login`), sends `PATCH /api/admin/account/password` with their current and new password, gets confirmation, and must use the new password on their next login (the current session's JWT remains valid until its existing 8-hour expiry — no immediate re-authentication is forced, consistent with there being no server-side session/token-revocation mechanism, documented explicitly in Non-goals/Proposed behavior).

## API changes

New endpoint: `PATCH /api/admin/account/password` (JWT-protected).
- Request body: `{"current_password": string, "new_password": string}`.
- `200`: `{"success": true, "message": "Senha alterada com sucesso."}`.
- `400`: `{"error": "..."}` — missing field(s) or `new_password` too short.
- `401`: `{"error": "..."}` — missing/invalid/expired JWT (existing `JwtMiddleware` behavior), or incorrect `current_password`.

## Data model and migrations

Not applicable — no schema change. The existing `users.password` column is updated in place; no new column or table.

## Architecture and affected components

- `src/Controllers/AuthController.php` — new `changePassword()` method.
- `src/Routes.php` — new route registration for `PATCH /api/admin/account/password`, reusing the existing `$jwt` middleware instance (per-route `->add($jwt)`, matching how `/api/login` is already registered as a standalone closure route rather than going through the `/api/admin` group's container-resolved controllers — `AuthController`'s constructor takes a plain `string $secret` that PHP-DI's autowiring can't resolve without new container configuration, which this spec avoids introducing).
- `docs/architecture.md` — new "Authentication" section.
- No `Services/`, `Repositories/`, `Validators/`, `Models/` changes — `User` model is used as-is.

## Security considerations

This spec's core purpose is closing the "password changes" gap in the roadmap's authentication-lifecycle checklist, using the same hashing (`password_hash`/`PASSWORD_BCRYPT`) already established and verified in specs across this milestone. `current_password` verification before allowing a change prevents a stolen/leaked JWT alone (without also knowing the current password) from letting an attacker lock out the legitimate user by changing their password — a meaningful defense given there's no server-side session invalidation. The new endpoint does not invalidate the JWT used to make the request (documented explicitly, not left ambiguous) — this is consistent with the project's stateless-JWT design, not a new gap introduced by this spec.

## Backward compatibility

No impact — this is a purely additive endpoint. No existing route, response shape, or stored data changes.

## Acceptance criteria

1. `PATCH /api/admin/account/password` with no `Authorization` header → `401`.
2. `PATCH /api/admin/account/password` with a valid JWT and body `{"current_password": "x"}` (missing `new_password`) → `400`.
3. `PATCH /api/admin/account/password` with a valid JWT, correct current password, and `new_password` of 4 characters → `400`.
4. `PATCH /api/admin/account/password` with a valid JWT and an incorrect `current_password` → `401`; the account's password is unchanged (verified by a subsequent successful login with the *original* password).
5. `PATCH /api/admin/account/password` with a valid JWT, correct current password, and a valid new password (≥8 chars) → `200`; a subsequent `POST /api/login` with the new password → `200` with a JWT; the same call with the old password → `401`.
6. `docs/architecture.md`'s new "Authentication" section, read back, covers all six items listed in Functional requirement 7.
7. `docker compose exec web vendor/bin/phpunit` passes with the same count as before this spec.
8. `php -l` passes on all changed PHP files.

## Implementation plan

1. Add `AuthController::changePassword(Request $request, Response $response): Response`.
2. Register `PATCH /api/admin/account/password` in `src/Routes.php`, protected by the existing `$jwt` middleware instance.
3. Write `docs/architecture.md`'s new "Authentication" section.
4. Validate end-to-end against the real running app: create a throwaway test admin via `bin/create-admin` (reusing spec 015's tool, avoiding touching the real admin account), log in, exercise all Acceptance criteria 1-5 with real `curl` calls, confirm via a follow-up login that the password genuinely changed.
5. Run `vendor/bin/phpunit` and `php -l`; read the diff to confirm scope.

## Testing and validation strategy

No automated test infrastructure covers HTTP-level authentication flows (confirmed: no existing test exercises `POST /api/login` or any `/api/admin/*` route). Validation is real `curl` calls against the running app, using a throwaway test admin account (created via `bin/create-admin`, matching the pattern already established in spec 015's own validation) rather than this project's real admin account, plus the existing PHPUnit suite for regression coverage.

## Rollout and rollback

No migration, no dependency, no container change. Rollback is a plain `git revert`. Purely additive — no existing behavior changes.

## Open questions

- **Not blocking**: should changing a password also invalidate the JWT that was used to make the change (forcing immediate re-login)? Deferred — doing so would require a server-side revocation mechanism, which is explicitly out of scope here (see Non-goals) and would be its own, larger design decision. Documented as a known, deliberate limitation in `docs/architecture.md`'s new section rather than silently left unstated.
- **Not blocking**: login throttling itself (per-IP vs per-username, storage mechanism, lockout duration) — explicitly deferred to its own follow-up spec, per the scoping decision already made with the user in Context.

## Task checklist

- [x] `AuthController::changePassword()` added
- [x] `PATCH /api/admin/account/password` route registered, JWT-protected
- [x] `docs/architecture.md` "Authentication" section added
- [x] Acceptance criteria 1-5 verified via real `curl` against a throwaway test admin
- [x] `vendor/bin/phpunit` + `php -l` run, zero regressions

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Re-verified `src/Controllers/AuthController.php` and `src/Routes.php` immediately before editing — unchanged since `/spec-plan`, no conflict to report.
- 2026-09-03 — Implemented `AuthController::changePassword()` and the `PATCH /api/admin/account/password` route exactly per plan: registered as a standalone closure route (matching `/api/login`'s existing pattern, manually instantiating `AuthController` with `$secret`) with `->add($jwt)` applied per-route, avoiding any new PHP-DI container configuration for `AuthController`'s scalar `$secret` constructor parameter — as anticipated in the spec's Architecture section.
- 2026-09-03 — Wrote `docs/architecture.md`'s new "Authentication" section, placed immediately after the Request lifecycle section's two callout bullets, covering all six items from Functional requirement 7 (issuance/expiry, the three `JwtMiddleware` outcomes, password hashing, the new password-change endpoint and its explicit non-invalidation-of-existing-JWTs behavior, the deliberate absence of logout/revocation with rationale, and login throttling as explicitly deferred).
- 2026-09-03 — Validated end-to-end using a throwaway test admin (`spec016test`, created via `bin/create-admin`) rather than this project's real admin account, per the spec's own Testing and validation strategy. Did not delete the test user afterward (no delete-user endpoint exists anywhere in the app) — same precedent as spec 015's own validation, harmless leftover test data.

## Validation evidence

- Acceptance criterion 1 — `curl -X PATCH /api/admin/account/password` with no `Authorization` header → `HTTP 401` (existing `JwtMiddleware` behavior, unchanged). **Confirmed.**
- Acceptance criterion 2 — Valid JWT, body `{"current_password":"spec016_orig_pw"}` (missing `new_password`) → `HTTP 400`, `{"error":"Senha atual e nova senha são obrigatórias."}`. **Confirmed.**
- Acceptance criterion 3 — Valid JWT, correct current password, `new_password` = `"abc123"` (6 chars) → `HTTP 400`, `{"error":"A nova senha deve ter ao menos 8 caracteres."}`. **Confirmed.**
- Acceptance criterion 4 — Valid JWT, `current_password` = `"totally_wrong"` → `HTTP 401`, `{"error":"Senha atual incorreta."}`. Follow-up `POST /api/login` with the *original* password → `HTTP 200` — confirms the failed attempt did not modify the stored password. **Confirmed.**
- Acceptance criterion 5 — Valid JWT, correct current password, `new_password` = `"spec016_NEW_pw"` (14 chars) → `HTTP 200`, `{"success":true,"message":"Senha alterada com sucesso."}`. Subsequent `POST /api/login` with the new password → `HTTP 200` with a valid JWT (`"role":"admin"`); the same call with the old password → `HTTP 401`, `{"error":"Credenciais inválidas."}`. **Confirmed, both directions.**
- Acceptance criterion 6 — `docs/architecture.md`'s new "Authentication" section read back after writing: confirmed it covers token issuance/expiry, all three `JwtMiddleware` outcomes, password hashing, the new endpoint, the deliberate absence of logout, and login throttling as explicitly deferred. **Confirmed.**
- Acceptance criterion 7 — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)` — same count as before this spec. **Confirmed.**
- Acceptance criterion 8 — `docker compose exec web php -l src/Controllers/AuthController.php` and `php -l src/Routes.php` → `No syntax errors detected`, both files. **Confirmed.**
- `git diff -- src/Controllers/AuthController.php src/Routes.php` reviewed — scope confirmed minimal and exact: one new controller method, one new route registration.
