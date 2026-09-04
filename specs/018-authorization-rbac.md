# Spec 018 — Authorization / RBAC (scoped to /api/admin/*)

## Metadata

- Status: Verified
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Authorization / RBAC" subsection, asks to move beyond "authenticated vs unauthenticated" toward simple role-based authorization, with suggested roles `ADMIN`, `MANAGER`, `CASHIER`, `KITCHEN`, examples ("administrative configuration requires admin-level permission," "cashier mutations require appropriate permission," "kitchen mutations require appropriate permission," "reports require authorized access"), and an explicit instruction to avoid over-engineering the permission system.

Investigation confirmed the roadmap's own diagnosis is exactly right: `role` already exists on `User` and is already embedded in every issued JWT (`AuthController::login()`), but **nothing anywhere checks it** — `JwtMiddleware` only validates the token's signature/expiry, never its `role` claim. Today, any authenticated user (whatever their `role`) can reach every route in the `/api/admin` group equally.

**Explicit scoping decision made with the user before writing this spec**: the roadmap's literal examples ("cashier mutations," "kitchen mutations") would, taken at face value, mean putting `/api/orders*` and `/api/kitchen/*` behind login — but those are currently **deliberate, unauthenticated public endpoints** (reaffirmed repeatedly this milestone as "a deliberate choice for a single-location, trusted-network deployment, not an oversight," `docs/architecture.md`), and neither the cashier nor kitchen screen has any login UI. Reversing that is a much larger, riskier change than "add role checks," and was not requested. **This spec scopes RBAC enforcement to the already-authenticated `/api/admin/*` surface only** — `CASHIER`/`KITCHEN` roles are added to the schema (so the roadmap's full suggested role set exists and future work extending auth to those areas doesn't need a second migration), but they don't yet gate any endpoint, since their real operational domain (orders, kitchen food-summary) remains outside `/api/admin/*` by design.

## Problem

Confirmed by direct reads:

1. `src/Middleware/JwtMiddleware.php` — validates the JWT's signature and expiry only; never inspects the decoded `role` claim for any authorization decision.
2. `common/sql/001_schema.sql` — `users.role ENUM('admin','staff') NOT NULL DEFAULT 'staff'`. Only two roles exist today, neither matching the roadmap's suggested set, and `'staff'` is never actually distinguished from `'admin'` by any code path.
3. `src/Routes.php`'s `/api/admin` group (17 routes: menu management, settings, logo upload, logs, test-print, 7 report endpoints, plus the new `PATCH /api/admin/account/password` from spec 016) has exactly one guard — `JwtMiddleware`, group-level — applied uniformly. A `cashier`/`kitchen`-role account (if one existed) could today read the application log or change the printer settings, identically to an `admin` account.
4. `bin/create-admin` (spec 015) hardcodes `role = 'admin'` for every account it creates — the only user-creation path in the entire application. There is currently no way to create a `manager`/`cashier`/`kitchen` account at all without direct database manipulation.
5. This project's own dev database (confirmed via a real query) currently has exactly 3 users, all `role = 'admin'` (`admin`, `testadmin` from spec 015's validation, `spec016test` from spec 016's validation) — no `'staff'`-role rows exist to worry about when narrowing the `role` enum.

## Goals

- `users.role` supports exactly the roadmap's suggested set: `admin`, `manager`, `cashier`, `kitchen`.
- Within `/api/admin/*`: routes the roadmap calls "administrative configuration" (settings, logo upload, test-print, log viewing) require `admin` specifically. Menu management and reports require `admin` or `manager`. Changing one's own password (spec 016) remains open to any authenticated role — it's self-service, not an administrative privilege.
- `bin/create-admin` can create an account with any of the four roles, not just `admin` (otherwise the new roles are unreachable without raw SQL, which the project has been moving away from since spec 015).
- A request with a valid JWT but an insufficient role gets a clear `403`, distinct from `JwtMiddleware`'s existing `401` for missing/invalid/expired tokens.

## Non-goals

- **Not moving `/api/orders*`, `/api/menu`, `/api/kitchen/*`, or `/api/menu/reorder` behind authentication.** Explicit scoping decision (see Context) — these stay exactly as they are today: public, unauthenticated, by deliberate design. `cashier`/`kitchen` roles exist in the schema but don't yet gate anything, since their operational domain is these public endpoints, not `/api/admin/*`.
- **Not building any cashier/kitchen login UI.** Follows directly from the above.
- **Not adding a generic, configurable permissions/policy engine.** Per the roadmap's own "avoid over-engineering" instruction: a small `RoleMiddleware` taking a fixed array of allowed roles per route, matching the existing `JwtMiddleware`'s style exactly, is sufficient — no ACL table, no permission strings, no dynamic role hierarchy.
- **Not changing what `password_verify`/`password_hash` do, or anything from spec 016.** `PATCH /api/admin/account/password` remains open to any authenticated role.
- **Not retroactively deciding what any hypothetical existing `'staff'`-role row "should" become with certainty** — this project's own dev database has none (confirmed), but the migration must still handle any that might exist elsewhere; `'staff'` rows are mapped to `'cashier'` (documented, reasonable default — "staff" is closest in spirit to a general operational role), not silently dropped or left to error.
- **Not adding role checks inside controller methods themselves.** Enforcement lives entirely in `RoleMiddleware`, attached per-route in `src/Routes.php` — controllers are unaware of authorization, matching the existing separation where `JwtMiddleware` (not `AdminController` et al.) owns authentication.

## Current behavior

Confirmed by direct reads on the current working tree:

- `src/Middleware/JwtMiddleware.php` — as described in Problem point 1; on success, attaches the decoded payload (including `role`) to the request as the `user` attribute.
- `src/Routes.php:56-75` — the full `/api/admin` route list (menu: index/store/updateItem/getComponents/updateComponents/delete; settings: getSettings/updateSettings/uploadLogo/testPrint; logs: getLogs; reports: 7 endpoints), all under one `->add($jwt)` at the group level, no per-route distinction. Plus the standalone `PATCH /api/admin/account/password` (spec 016), also `->add($jwt)` but outside the group.
- `common/sql/001_schema.sql` — `users` table as described in Problem point 2.
- `bin/create-admin` — hardcodes `'role' => 'admin'` in its `User::create()` call (spec 015).
- `src/Middleware/` currently has exactly 3 PSR-15 middlewares: `CorsMiddleware`, `JsonBodyParserMiddleware`, `JwtMiddleware` — no precedent for a role-based one yet, but the pattern (constructor-configured, PSR-15 `process()`, builds its own `Slim\Psr7\Response` on rejection) is well-established and directly reusable.

## Proposed behavior

After this change:

- `common/migrations/011_user_roles.sql` maps any existing `'staff'` rows to `'cashier'`, then narrows `users.role` to `ENUM('admin','manager','cashier','kitchen') NOT NULL DEFAULT 'cashier'`.
- `src/Middleware/RoleMiddleware.php` (new): constructed with an array of allowed role strings; on `process()`, checks `$request->getAttribute('user')->role` (already set by `JwtMiddleware`, which must run first — guaranteed by Slim's group-then-route middleware ordering, since `RoleMiddleware` is attached per-route inside a group that already has `JwtMiddleware` at the group level) against the allowed list; `403` with a clear message if not allowed, otherwise passes through.
- `src/Routes.php`: settings/logo/test-print/logs routes get `->add(new RoleMiddleware(['admin']))`; menu-management and report routes get `->add(new RoleMiddleware(['admin', 'manager']))`; the password-change route is untouched (no role restriction).
- `bin/create-admin` accepts an optional second argument or `--role=` flag (exact form decided during implementation to keep the existing `bin/create-admin <username>` invocation — defaulting to `admin` — working unchanged); validates it's one of the four allowed values.

## Functional requirements

1. `users.role` accepts exactly `admin`, `manager`, `cashier`, `kitchen` (verified by successfully creating one user of each role and confirming the column accepts all four).
2. Any pre-existing `role = 'staff'` row (none exist in this project's dev database, but the migration must handle the general case) becomes `role = 'cashier'` after the migration runs, not left dangling or erroring.
3. `GET /api/admin/settings` with a valid JWT for a `manager`-role user returns `403`.
4. `GET /api/admin/settings` with a valid JWT for an `admin`-role user returns `200` (unchanged from today).
5. `POST /api/admin/items` (menu management) with a valid JWT for a `manager`-role user returns the same success response as an `admin`-role user would (both allowed).
6. `POST /api/admin/items` with a valid JWT for a `cashier`-role user returns `403`.
7. `GET /api/admin/reports/sales` with a valid JWT for a `manager`-role user returns `200`; with a `cashier`-role user returns `403`.
8. `PATCH /api/admin/account/password` succeeds identically regardless of the authenticated user's role (`admin`, `manager`, `cashier`, or `kitchen` — self-service, not gated).
9. Any `/api/admin/*` request with a missing/invalid/expired JWT still returns `401` (from `JwtMiddleware`, unchanged) — `RoleMiddleware` is never reached in that case, confirming ordering is correct.
10. `bin/create-admin <username>` with no role specified still creates an `admin`-role user (unchanged default behavior from spec 015).
11. `bin/create-admin` given an explicit, valid non-`admin` role creates a user with that role.
12. `bin/create-admin` given an invalid role value (e.g. `"superuser"`) exits non-zero with a clear error and creates no row.

## Non-functional requirements

Not applicable beyond the security goal already stated — one additional in-memory array-membership check per protected request, negligible cost.

## User flows

- **Admin managing settings**: logs in, gets a JWT with `role: admin`, can reach every `/api/admin/*` route as before.
- **Manager managing the menu**: logs in with a `manager`-role account (created via `bin/create-admin <username> --role=manager` or equivalent), can manage menu items and view reports, but a request to `/api/admin/settings` or `/api/admin/logs` returns `403`.
- **Cashier/kitchen accounts**: can be created and can log in and change their own password, but every other `/api/admin/*` route returns `403` for them — their actual operational work continues to happen through the public `/api/orders*`/`/api/kitchen/*` endpoints, unaffected by this spec.

## API changes

- Every route in the `/api/admin` group (except `PATCH /api/admin/account/password`) can now additionally return `403` (`{"error": "..."}`) for an authenticated user whose role isn't permitted, on top of the existing `401` for missing/invalid/expired tokens.
- No request/response shape changes for any currently-`200`/`201` outcome.

## Data model and migrations

- New migration `common/migrations/011_user_roles.sql`: `UPDATE users SET role = 'cashier' WHERE role = 'staff';` then `ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','cashier','kitchen') NOT NULL DEFAULT 'cashier';`. Applied via the existing `bin/migrate`/`MigrationRunner` mechanism — no ad hoc `ALTER TABLE`, per `CLAUDE.md`.

## Architecture and affected components

- `common/migrations/011_user_roles.sql` (new).
- `src/Middleware/RoleMiddleware.php` (new), matching `JwtMiddleware`'s existing structure/style.
- `src/Routes.php` — per-route `->add(new RoleMiddleware([...]))` on the relevant `/api/admin/*` routes.
- `bin/create-admin` — accepts and validates an optional role argument.
- No `Controllers/`, `Services/`, `Repositories/`, `Models/` changes.

## Security considerations

This spec's purpose is closing the exact gap the roadmap names: "authenticated vs unauthenticated" is not real authorization. `RoleMiddleware` fails closed (any role not explicitly in the allowed list is rejected, including a missing/malformed `user` attribute) rather than fail-open, matching `JwtMiddleware`'s own fail-closed behavior. Enforcement lives in one small, auditable class rather than scattered per-controller checks, reducing the chance a future new admin route is added without a role check by accident (though that risk isn't eliminated — a new route still needs its `RoleMiddleware` added deliberately, same as it needs `JwtMiddleware` today).

## Backward compatibility

- **This project's own existing `admin`-role users** (`admin`, `testadmin`, `spec016test`) are entirely unaffected — `admin` remains a valid role and retains access to everything, identical to today.
- **Any hypothetical `'staff'`-role row** on another installation becomes `'cashier'` after the migration (a behavior change for such a row: it would newly lose access to settings/logs, which it never should have had unrestricted access to in the first place, and gain no new access, since `'staff'` was never checked for anything before this spec).
- **No existing `/api/admin/*` route becomes newly `404`** — this is purely an additional `403` outcome layered on top of existing routes.

## Acceptance criteria

1. `common/migrations/011_user_roles.sql` applied successfully via `bin/migrate` against the real dev database.
2. Creating one user of each of the four roles via `bin/create-admin` succeeds; `SELECT role FROM users` confirms all four values stored correctly.
3. `curl -H "Authorization: Bearer <manager-jwt>" GET /api/admin/settings` → `403`.
4. `curl -H "Authorization: Bearer <admin-jwt>" GET /api/admin/settings` → `200` (unchanged).
5. `curl -H "Authorization: Bearer <manager-jwt>" POST /api/admin/items` (valid payload) → same success response an admin would get.
6. `curl -H "Authorization: Bearer <cashier-jwt>" POST /api/admin/items` → `403`.
7. `curl -H "Authorization: Bearer <manager-jwt>" GET /api/admin/reports/sales` → `200`; same call with a `cashier-jwt` → `403`.
8. `curl -H "Authorization: Bearer <cashier-jwt>" PATCH /api/admin/account/password` (valid body) → `200` (role-independent, unchanged from spec 016).
9. `curl` (no `Authorization` header) to any `/api/admin/*` route → `401` (unchanged `JwtMiddleware` behavior — confirms `RoleMiddleware` doesn't run before/instead of it).
10. `bin/create-admin sometest` (no role arg) → creates an `admin`-role user (unchanged default).
11. `bin/create-admin sometest2 <invalid-role-flag>` → exits non-zero, no row created.
12. `docker compose exec web vendor/bin/phpunit` passes with the same count as before this spec.
13. `php -l` passes on all changed PHP files.

## Implementation plan

1. Write `common/migrations/011_user_roles.sql`.
2. Write `src/Middleware/RoleMiddleware.php`.
3. Update `src/Routes.php`: attach `RoleMiddleware` to the appropriate `/api/admin/*` routes per Proposed behavior's mapping.
4. Extend `bin/create-admin` to accept and validate an optional role.
5. Apply the migration to the real dev database via `bin/migrate`; verify it applied cleanly (Acceptance criterion 1).
6. Create one throwaway test user per role via `bin/create-admin`, log in as each, and exercise Acceptance criteria 3-9 with real `curl` calls.
7. Verify `bin/create-admin`'s default-role and invalid-role behavior (Acceptance criteria 10-11).
8. Run `vendor/bin/phpunit` and `php -l`; read the diff to confirm scope.

## Testing and validation strategy

No automated test infrastructure covers HTTP-level authorization (confirmed: no existing test exercises any `/api/admin/*` route or role logic). Validation is real `bin/migrate` execution against the live dev database, real `bin/create-admin` invocations creating one throwaway test account per role, and real `curl` calls exercising every acceptance criterion against the running app — plus the existing PHPUnit suite for basic regression coverage.

## Rollout and rollback

The migration is forward-only (per this project's established `MigrationRunner` convention — no `down()` semantics exist anywhere). Rollback of the code changes is a plain `git revert`; rolling back the already-applied migration would require a manual, explicit follow-up migration to widen the enum back and restore any `'cashier'`-mapped-from-`'staff'` rows — not something this spec's rollback needs to plan for automatically, since no such rows exist in this project's own database today.

## Open questions

- **Not blocking**: the exact CLI syntax for `bin/create-admin`'s new role argument (positional `bin/create-admin <username> <role>` vs a `--role=` flag) is left to implementation-time judgment, provided the existing no-role-argument invocation keeps defaulting to `admin` unchanged (Functional requirement 10) — either form satisfies the spec's actual requirements equally.
- **Not blocking**: whether `manager` should also be allowed on settings/logs (making the admin-only set even smaller) — this spec follows the roadmap's own literal wording ("administrative configuration requires admin-level permission") for that specific boundary; adjusting it later is a small, isolated change to the `RoleMiddleware` arguments in `src/Routes.php`, not a redesign.

## Task checklist

- [x] `common/migrations/011_user_roles.sql` written
- [x] `src/Middleware/RoleMiddleware.php` written
- [x] `src/Routes.php` updated with per-route `RoleMiddleware`
- [x] `bin/create-admin` accepts/validates an optional role
- [x] Migration applied to the real dev database
- [x] Acceptance criteria 2-11 verified via real `bin/create-admin`/`curl` calls
- [x] `vendor/bin/phpunit` + `php -l` run, zero regressions

## Implementation log

- 2026-09-03 — Set Status Draft → In Progress (explicit `/spec-implement` invocation, no blocking open questions).
- 2026-09-03 — Re-verified `bin/create-admin`'s current content immediately before editing — unchanged since `/spec-plan`, no conflict to report.
- 2026-09-03 — Implemented the migration, `RoleMiddleware`, `Routes.php` wiring, and `bin/create-admin`'s role argument exactly per plan.
- 2026-09-03 — Resolved Open question 1 (CLI syntax for the role argument): used a positional second argument (`bin/create-admin <username> [role]`, defaulting to `admin`) rather than a `--role=` flag — simpler to parse with the script's existing `$argv`-based approach, and the spec left either form acceptable.
- 2026-09-03 — In `src/Routes.php`, used two small factory closures (`$adminOrManager`, `$adminOnly`) that each construct a fresh `RoleMiddleware` instance, rather than sharing single instances across multiple `->add()` calls — avoids any ambiguity about middleware-instance reuse across routes, at negligible cost (17 small object constructions at bootstrap time).
- 2026-09-03 — Applied `011_user_roles.sql` to the real dev database via `bin/migrate` — applied cleanly (`[OK]`). Confirmed via `SHOW COLUMNS FROM users` that the enum is now exactly `('admin','manager','cashier','kitchen')` with `DEFAULT 'cashier'`.
- 2026-09-03 — Validated using one throwaway test user per role (`rbac_admin`, `rbac_manager`, `rbac_cashier`, `rbac_kitchen`, plus `rbac_defaulttest` and a rejected `rbac_invalidroletest`) rather than this project's real admin account. A test menu item created during Acceptance criterion 5's validation (id 78, "RBAC Test Item") was deleted afterward via the app's own `DELETE /api/admin/items/{id}` endpoint, per the cleanup convention established in spec 007's validation evidence. The six throwaway test *users* were left in place (harmless leftover test data, same precedent as specs 015/016 — no user-deletion endpoint exists to clean them up with anyway).

## Validation evidence

- Acceptance criterion 1 — `docker compose exec web php bin/migrate` → `▶ Executando 011_user_roles.sql ... [OK]`. **Confirmed.**
- Acceptance criterion 2 — Created one user per role (`admin`, `manager`, `cashier`, `kitchen`) via `bin/create-admin`; all four succeeded. Confirmed via `SHOW COLUMNS FROM users` that the `role` column's enum and default are exactly as specified. **Confirmed.**
- Acceptance criterion 3 — `GET /api/admin/settings` with a `manager` JWT → `HTTP 403`. **Confirmed.**
- Acceptance criterion 4 — Same request with an `admin` JWT → `HTTP 200`. **Confirmed.**
- Acceptance criterion 5 — `POST /api/admin/items` with a `manager` JWT (real payload) → `HTTP 201`, `{"success":true,"id":78,"message":"Item adicionado"}` — same success shape an admin would get. **Confirmed.**
- Acceptance criterion 6 — Same request with a `cashier` JWT → `HTTP 403`. **Confirmed.**
- Acceptance criterion 7 — `GET /api/admin/reports/sales` with a `manager` JWT → `HTTP 200`; same request with a `cashier` JWT → `HTTP 403`. **Confirmed.**
- Acceptance criterion 8 — `PATCH /api/admin/account/password` with a `cashier` JWT (valid current/new password) → `HTTP 200`, `{"success":true,"message":"Senha alterada com sucesso."}` — role-independent, as required. **Confirmed.**
- Acceptance criterion 9 — `GET /api/admin/settings` with no `Authorization` header → `HTTP 401` (unchanged `JwtMiddleware` behavior, confirming it still runs before `RoleMiddleware` and that a missing token never reaches the role check). **Confirmed.**
- Acceptance criterion 10 — `bin/create-admin rbac_defaulttest` (no role argument) → created with `role = 'admin'` (confirmed via a direct query). **Confirmed.**
- Acceptance criterion 11 — `bin/create-admin rbac_invalidroletest superuser` → `Erro: role inválida "superuser". Use uma de: admin, manager, cashier, kitchen.` (exit `1`); no row created for that username. **Confirmed.**
- Acceptance criterion 12 — `docker compose exec web vendor/bin/phpunit` → `OK (14 tests, 33 assertions)` — same count as before this spec. **Confirmed.**
- Acceptance criterion 13 — `php -l` on `src/Routes.php`, `src/Middleware/RoleMiddleware.php`, and `bin/create-admin` — no syntax errors, all three. **Confirmed.**
- `git diff -- src/Routes.php bin/create-admin` reviewed — scope confirmed to match the plan exactly (route wiring + role argument only, no unrelated changes).
