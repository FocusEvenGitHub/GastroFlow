# Spec 076 — `order_number` só numérico

<!-- Reduced in scope on purpose: one validator rule, two inputs, one kitchen payload tweak. -->

## Metadata

- Status: Verified
- Created: 2026-11-09
- Updated: 2026-11-09
- Owner: Henry
- Related issue: Trello card "Validar order_number como numérico" (https://trello.com/c/ssuprXaZ)
- Related branch: `fix/076-numeric-order-number`

## Context

`docs/ROADMAP.md` › Order validation (spec 022) records a known gap, deferred as "small, self-contained fix": the manually typed "Senha" accepts any string. This is backlog work outside the milestones, so it goes under "Não lançado".

## Problem

`OrderValidator::validateOrderData()` and `validateOrderUpdate()` only apply `optional` + `lengthMax: 50` to `order_number`. `POST /api/orders` with `"order_number": "ABC"` or `"999-TESTE"` is accepted and printed on the ticket, although the cashier, the kitchen and the auto-numbering (`OrderRepository::allocateNextNumber()`, which produces `(string) int`) all assume a plain number. Because uniqueness is per string (`uniq_order_number_per_day`), `"07"` and `"7"` can also coexist as two different senhas on the same day.

## Goals

- Reject a non-numeric `order_number` on create and update with the existing 400 validation response.
- Constrain the cashier and kitchen inputs to digits.
- Keep orders that already have a non-numeric number editable (customer name, items).

## Non-goals

- Rewriting existing non-numeric `order_number` values in the database (no migration).
- Changing the column type (`VARCHAR(50)` stays).
- Changing auto-numbering.

## Current behavior

Confirmed in code:

- `src/Validators/OrderValidator.php`: `order_number` → `optional`, `lengthMax 50` in both `validateOrderData()` and `validateOrderUpdate()`.
- `public/cashier/index.php:160`: `<input type="text" id="orderNumber">`, `placeholder="Ex: 42"`. `public/cashier/app.js:526` omits `order_number` when the cashier didn't edit the suggestion.
- `public/kitchen/index.php:425`: edit modal, `<input type="text" x-model="editingOrder.order_number">`. `public/kitchen/app.js:475` (`saveOrderChanges()`) **always** sends `order_number` in `PATCH /api/orders/{id}`, even unchanged.
- `src/Repositories/OrderRepository.php:210` casts a manual number to string; `updateOrder()` (line 496) assigns it as given.
- `tests/Integration/MigrationUpgradeTest.php:89` uses a historical `'HIST-1'` order number. That test exercises migrations, not the validator, so it is unaffected.

## Proposed behavior

`order_number`, when present, must match `^[1-9][0-9]{0,6}$`: digits only, no leading zero, 1–7 digits (up to 9 999 999, above the auto-numbering cap of 1 000 000). A JSON integer (`42`) is accepted the same as `"42"`. Absent stays valid on create (auto-assign) and on update (keep current value). Error message (pt-BR): `Senha deve ser um número inteiro positivo, sem zeros à esquerda.`

The kitchen sends `order_number` in the PATCH only when the user changed it, so editing the customer name of a legacy `HIST-1` order still works.

## Functional requirements

1. FR1: `validateOrderData()` rejects `order_number` values `"ABC"`, `"999-TESTE"`, `"07"`, `"0"`, `"-5"`, `"4.2"`, `" 42"`, `"12345678"`.
2. FR2: `validateOrderData()` accepts `"1"`, `"42"`, `42`, `"9999999"`, and an absent `order_number`.
3. FR3: `validateOrderUpdate()` applies the same rule as FR1/FR2.
4. FR4: the cashier's `#orderNumber` input uses `inputmode="numeric"` and `pattern="[1-9][0-9]{0,6}"` and keeps only digits as the user types.
5. FR5: the kitchen's Senha input gets the same attributes, and `saveOrderChanges()` omits `order_number` when it equals the value the modal opened with.

## Non-functional requirements

No new dependency. ~~Valitron's built-in `regex` rule is used.~~ *(2026-11-09, during implementation: a Valitron closure rule instead — see Implementation log.)*

## User flows

- Cashier types `42` → order created as today. Types `4a2` → the field shows `42`.
- API consumer sends `"order_number": "ABC"` → 400 with the validation error, nothing persisted.
- Kitchen opens a legacy `HIST-1` order, changes only the customer name, saves → 200; the order number stays `HIST-1`.

## API changes

`POST /api/orders` and `PATCH /api/orders/{id}`: `order_number` is now validated as numeric. Invalid input returns the existing 400 validation response (same shape as other `OrderValidator` failures). No new endpoint.

## Data model and migrations

Not applicable — no schema change; existing rows are left as they are.

## Architecture and affected components

- `src/Validators/OrderValidator.php` (one rule, shared by both methods)
- `public/cashier/index.php`, `public/cashier/app.js` (input filter, if done in JS)
- `public/kitchen/index.php`, `public/kitchen/app.js` (input + PATCH payload)
- `tests/Unit/OrderValidatorTest.php`
- `tests/e2e/specs/order-number.spec.ts` *(added 2026-11-09 during validation, see Implementation log)*

## Security considerations

Tighter input validation at a trust boundary; no auth change. The server rule is the guarantee — the HTML attributes are only UX.

## Backward compatibility

- Existing non-numeric numbers stay in the database and display as before.
- A PATCH that *sends* a legacy non-numeric value now fails. FR5 makes the kitchen stop sending it when unchanged, which was the only caller found.
- Third-party API consumers sending non-numeric senhas will get 400. The project has no known external consumers.

## Acceptance criteria

1. AC1: unit tests in `OrderValidatorTest` cover every value in FR1 (rejected) and FR2 (accepted) for `validateOrderData()`, and at least one rejected and one accepted value for `validateOrderUpdate()`; `vendor/bin/phpunit` passes.
2. AC2: `POST /api/orders` with `"order_number": "ABC"` returns 400 and creates no row (integration test or curl against the dev stack).
3. AC3: in the cashier, typing `4a2` into "Número da Senha" leaves `42` in the field.
4. AC4: in the kitchen, editing only the customer name of an order whose `order_number` is non-numeric saves with 200 and keeps the number.
5. AC5: PHPStan, PHP-CS-Fixer (dry-run) and the full PHPUnit suite pass.

## Implementation plan

1. Add a `ORDER_NUMBER_PATTERN` constant and the `regex` rule with the pt-BR message to both validator methods.
2. Unit tests for FR1–FR3.
3. Cashier input attributes + digit filter.
4. Kitchen input attributes; store the opened value and omit `order_number` from the PATCH when unchanged.
5. CHANGELOG ("Não lançado"), ROADMAP › Order validation status note, baseline/architecture check.

## Testing and validation strategy

PHPUnit unit tests for the validator (AC1); an integration test or curl for AC2; manual browser check on the dev stack for AC3/AC4 (the E2E suite could cover AC3, but a new E2E test is optional for this size). Static analysis and style checks for AC5.

## Rollout and rollback

Ships with the next patch tag. Rollback is reverting the commit; no data was changed.

## Open questions

1. (non-blocking) Is 7 digits the right upper bound, or would the owner prefer a smaller one (e.g. 4) matching real ticket rolls? Default: 7.
2. (non-blocking) Should leading zeros be normalized (`"07"` → `"7"`) instead of rejected? Default: reject, since the cashier filter makes it rare and normalizing silently hides a typo.

## Task checklist

- [x] Validator rule (create + update)
- [x] Unit tests
- [x] Integration tests (AC2 + server half of AC4)
- [x] Cashier input
- [x] Kitchen input + PATCH payload
- [x] CHANGELOG + docs pass (ROADMAP › Order validation, `openapi.yaml`)
- [x] Browser check of AC3/AC4 — new E2E test `tests/e2e/specs/order-number.spec.ts`

## Implementation log

- 2026-11-09: `/spec-implement` invoked on the Draft with no blocking open questions → Approved → In Progress. Both open questions kept their defaults (7 digits; leading zero rejected).
- The rule is a closure (`OrderValidator::orderNumberRules()`), not Valitron's `regex`: `validateRegex()` calls `preg_match()` on the raw value, so `"order_number": ["42"]` would raise a TypeError (500) instead of a 400. The closure accepts only `int|string`.
- The pattern uses the `D` modifier: plain `$` also matches before a trailing newline, so `"42\n"` would have passed. Covered by a unit test.
- Kept from before (not changed, out of scope): Valitron skips non-required rules when the value is `''`, so `"order_number": ""` still passes. On create the repository treats it as "auto-assign"; on update `updateOrder()` would store `''`. That was already possible before this spec.
- Cashier and kitchen inputs: `inputmode="numeric"`, `pattern`, `maxlength="7"` and an `@input` that strips non-digits. The kitchen remembers the value the modal opened with (`originalOrderNumber`) and sends `order_number` only when it changed.
- Docs pass: CHANGELOG "Não lançado" entry; ROADMAP › Order validation status note; `public/api/docs/openapi.yaml` `CreateOrderInput.order_number` gets the rule and `pattern`. `specs/000-project-baseline.md`, `docs/architecture.md`, `docs/technical-decisions.md` and `README.md` do not describe the `order_number` rule, so nothing in them became false.
- `/spec-review` (2026-11-09) noted: in the kitchen modal, typing any key in the Senha field of a legacy `HIST-1` order strips its non-digits at once (it becomes `1`). Intended — the user is changing the senha — and the order is only rewritten if they then save.
- `/spec-review` also noted that `PATCH /api/orders/{id}` is not in `openapi.yaml` at all (pre-existing gap, not fixed here).
- AC3/AC4 were verified with a Playwright test instead of a manual click-through, so the check stays in the suite: `tests/e2e/specs/order-number.spec.ts` (3 tests, all via `page.route`, no database writes). Running it needed `npx playwright install chromium` (browser was missing on this machine; documented setup step, no dependency change).
- Branch not created: `/spec-implement` does not change branches. `fix/076-numeric-order-number` is still to be created before the commit.

## Validation evidence

All commands run in the development `web` container (`docker compose -f docker-compose.dev.yml exec web …`), 2026-11-09.

- **AC1** — `vendor/bin/phpunit --filter OrderValidatorTest` → `OK (45 tests, 74 assertions)`. New: `testNonNumericOrderNumberIsRejectedOnCreateAndUpdate` (11 cases: every FR1 value plus `"42\n"`, an array and a float, each checked on create and update), `testNumericOrderNumberIsAcceptedOnCreateAndUpdate` (4 FR2 cases, create and update), `testUpdateWithoutOrderNumberIsAccepted`. The absent-on-create case is the existing `testMissingOrderNumberIsAccepted`.
- **AC2** — `tests/Integration/OrderWorkflowTest::testNonNumericOrderNumberIsRejectedAndPersistsNothing` (POST `"ABC"` → 400, `orders` count unchanged). Run with `-e MYSQL_DATABASE_TEST=restaurant_test … --filter 'NonNumericOrderNumber|LegacyNonNumeric'` → `OK (13 tests, 39 assertions)`, none skipped.
- **AC3** — `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e` (8081, `restaurant_test`; `/health/ready` → 200), then `cd tests/e2e && npx playwright test specs/order-number.spec.ts` → `3 passed`. Test "caixa: digitar "4a2" na senha deixa "42"" types `4a2` key by key into `#orderNumber` and asserts the value `42` (and `inputmode="numeric"`).
- **AC4** — server half: `testLegacyNonNumericOrderNumberStaysEditable` (order set to `HIST-1` in `restaurant_test` → PATCH `customer_name` only → 200; PATCH resending `HIST-1` → 400; number still `HIST-1`). Browser half, same run as AC3: "cozinha: pedido antigo com senha "HIST-1" — mudar só o cliente não manda a senha" asserts the order PATCH body is exactly `{customer_name: 'Ana'}`; "cozinha: a senha aceita só dígitos e vai no PATCH quando muda" types `1x2`, asserts `12` and the body `{customer_name: null, order_number: '12'}`.
- **The E2E test catches the regression**: with `public/kitchen/app.js`, `public/kitchen/index.php` and `public/cashier/index.php` temporarily stashed back to `master`, the same command → `3 failed` (attribute / value / body assertions); stash popped, working tree restored.
- **Full E2E suite** — `npx playwright test` → `48 passed (1.9m)`, including the existing kitchen discount test that also reads the PATCH bodies.
- **AC5** — full suite `-e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit` → `OK, but some tests were skipped! Tests: 343, Assertions: 894, Skipped: 2` (the 2 skips are not the new tests, see AC2). `vendor/bin/phpstan analyse` → `[OK] No errors`. `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 117 files that can be fixed`.
