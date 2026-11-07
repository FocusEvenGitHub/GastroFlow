# Spec 075 — "Imprimir a logo no cupom" switch

<!-- Reduced in scope on purpose: one setting, read in one service, with one switch in the Admin. -->

## Metadata

- Status: Implemented
- Created: 2026-11-07
- Updated: 2026-11-07
- Owner: Henry
- Related issue: Trello card "Cupom sem logo — chave "Imprimir a logo no cupom" (spec 075)"
- Related branch: `feat/075-print-logo-toggle`

## Context

A client (a bar) asked for receipts without the logo. This is client work outside the milestones, so it ships as a patch under "Não lançado", like specs 058 and 070.

A first implementation (commit `5027c0e`, per the Trello card) was made on another machine and never pushed, and it was not found on this one or on GitHub. This spec redoes it from the card's description. The decisions below match that description: setting `print_logo`, `'0'` turns it off, a missing row means on, and both the ticket and the test page follow it.

## Problem

`PrintService::buildReceipt()` and `printTestPage()` print the logo whenever `Settings::getLogoPath()` exists. Since spec 066 that path falls back to the GastroFlow logo shipped in `public/assets/img/logo.png`, so there is always a logo and no way to print without one.

## Goals

- An admin can turn the logo off the ticket and the test page, and back on, from Admin › Configurações.
- Installs that never touch the switch keep printing the logo.

## Non-goals

- Deleting the uploaded logo. It stays stored, so turning the switch back on restores it.
- Per-printer or per-order logo choices.
- Changing how the logo is decoded, scaled or uploaded (spec 065).

## Current behavior

Confirmed in code:

- `src/Services/PrintService.php`: `printTestPage()` and `buildReceipt()` each print the logo when `file_exists($this->settings->getLogoPath())`.
- `src/Settings.php`: `getLogoPath()` returns the uploaded logo, else the default one.
- Settings are a free-form key/value table (`App\Models\Setting`). `SettingsValidator` accepts any string key and adds shape rules for known keys. `GET /api/admin/settings` returns every row.
- `public/admin/settings.php`/`settings.js`: the Restaurante card has the name and the logo; "Salvar Configurações" sends the known keys in one `PUT`.

## Proposed behavior

- New setting `print_logo`: `'1'` on, `'0'` off. No row means on, so no migration is needed.
- `PrintService::logoEnabled()` returns `Setting::getValue('print_logo') !== '0'`. Both logo blocks also check it.
- `SettingsValidator`: when `print_logo` is sent, it must be exactly `'0'` or `'1'` (strict comparison).
- Admin › Configurações › Restaurante: a "Imprimir a logo no cupom" switch under the logo preview, saved with the other fields. When it is off, a hint says the ticket prints only the restaurant name and the logo stays stored, and the "Esta logo não sai no cupom" warning is hidden, since it no longer applies.

## Functional requirements

1. FR1 — With `print_logo = '0'`, the ticket and the test page carry no raster image (`GS v 0`) and still print the rest.
2. FR2 — With no `print_logo` row, or `'1'`, the logo prints as before.
3. FR3 — `PUT /api/admin/settings` accepts `print_logo` only as `'0'` or `'1'`; any other value answers `400 VALIDATION_FAILED`.
4. FR4 — The Admin switch reflects the saved value (on when there is no row) and saves `'1'`/`'0'`.

## Non-functional requirements

No new dependency and no migration. The change is recorded in Auditoria like any other setting, through `SettingsController::updateSettings()`.

## User flows

- **Admin:** Configurações › turn off "Imprimir a logo no cupom" › Salvar Configurações. The next ticket and the test page come out without the logo.

## API changes

`PUT /api/admin/settings` validates the new `print_logo` key. `GET /api/admin/settings` returns it once saved. No new endpoint.

## Data model and migrations

Not applicable: a new row in the existing `settings` table, created on first save.

## Architecture and affected components

`src/Services/PrintService.php`, `src/Validators/SettingsValidator.php`, `public/admin/settings.php`, `public/admin/settings.js`. Docs: `docs/printing.md`, `docs/configuration.md`, `docs/architecture.md`, `CHANGELOG.md`.

## Security considerations

Admin-only, through the existing `PUT /api/admin/settings` (`adminOnly`). The value is validated against a closed list.

## Backward compatibility

Existing installs have no `print_logo` row and keep printing the logo. An older Admin page cached in a browser does not send the key, so saving from it leaves the setting unchanged.

## Acceptance criteria

- **AC1** — Unit: with `print_logo = '0'`, `printOrder()` and `printTestPage()` output no `GS v 0` and still contain the order number and the test text; with no row and with `'1'`, `printOrder()` outputs `GS v 0`.
- **AC2** — Unit: `SettingsValidator` accepts `'0'` and `'1'` and rejects `''`, `'true'`, `'yes'`, `'2'`, `' 1'` and `null`.
- **AC3** — Integration: `PUT` with `'yes'` answers 400; with `'0'` answers 200 and `GET /api/admin/settings` returns `'0'`.
- **AC4** — Browser: the switch loads off for `'0'` and on with no row; toggling it shows/hides the hint and the logo warning; saving sends `'0'`/`'1'`.
- **AC5** — PHPStan, PHP-CS-Fixer, the Unit and Integration suites, and the PR's CI pass.
- **AC6** — A test print without the logo on the client's printer.

## Implementation plan

1. `PrintService::logoEnabled()` and the two checks.
2. Validator rule.
3. Admin switch.
4. Tests (AC1 to AC3), browser check (AC4).
5. Docs pass and `CHANGELOG.md`.
6. `/spec-review`, then the PR.

## Testing and validation strategy

PHPUnit covers the service and the validator (Unit) and the HTTP round trip (Integration). No Playwright spec covers an authenticated Admin page, and adding a login fixture for one switch is out of scope, so AC4 is checked by hand in a browser against the development server with `/api/` mocked, so no request reaches the development database. AC6 needs the client's hardware.

## Rollout and rollback

Ships in the next patch release. No migration. To revert, remove the code; a saved `print_logo` row is then ignored.

## Open questions

None.

## Task checklist

- [x] `PrintService::logoEnabled()` on the ticket and the test page
- [x] `SettingsValidator` rule
- [x] Admin switch
- [x] Unit and Integration tests
- [x] Browser check
- [x] Docs pass and `CHANGELOG.md`
- [x] `/spec-review`
- [ ] PR and CI
- [ ] Test print on the client's printer

## Implementation log

- 2026-11-07: redone from the Trello card's description; the original commit `5027c0e` was not found on this machine or on GitHub.
- 2026-11-07: the validator's first version used Valitron's `in` without `strict`, and AC2 caught `' 1'` passing (`' 1' == '1'` in PHP 8). Now strict.
- 2026-11-07: the browser check caught the "off" hint staying visible with the switch on: Bootstrap's `d-block` is `!important` and beat Alpine's `x-show`. The hint is now a plain `div`.
- 2026-11-07: `/spec-review` found no blocker; its one finding, the `Smoke` suite not run, was fixed by running it (Validation evidence, AC5).

## Validation evidence

- **AC1, AC2 — met.** `docker compose -f docker-compose.dev.yml exec -T web vendor/bin/phpunit --testsuite Unit`: `OK (245 tests, 508 assertions)`.
- **AC3 — met.** `… -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration`: `Tests: 75, Assertions: 328, Skipped: 2`. The 2 skipped are `MigrationUpgradeTest`, the missing grant on this machine recorded in spec 073; CI runs them.
- **AC4 — met.** Playwright against `http://localhost:8080/admin/settings.php`, every `/api/` request mocked: `print_logo: '0'` → switch off, hint visible, logo warning hidden; switch on → hint hidden, warning visible; the two saves sent `"0"` and `"1"`; no `print_logo` key → switch on.
- **AC5 — partly met.** PHPStan `[OK] No errors`; PHP-CS-Fixer `Found 0 of 117 files that can be fixed`; Smoke `OK (5 tests, 10 assertions)`; the whole `phpunit` run (all three suites, `MYSQL_DATABASE_TEST=restaurant_test`) exited 0. CI pending until the PR.
- **AC6 — pending.** Needs the client's printer.

`Implemented`, not `Verified`: AC5's CI and AC6 are still open.
