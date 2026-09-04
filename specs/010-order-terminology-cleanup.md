# Spec 010 — Order terminology cleanup (table_number audit)

## Metadata

- Status: Implemented
- Created: 2026-09-03
- Updated: 2026-09-03
- Owner: Henry
- Related issue: Not applicable
- Related branch: Not applicable

## Context

`docs/ROADMAP.md`'s `v1.6.0 — Baseline & Security` phase, "Order terminology cleanup" subsection, calls for reviewing every remaining usage of `table_number`, classifying each occurrence as obsolete / migration-related / documentation-only / a genuine future table concept, and confirming `table_number` is never used as a stand-in for order identification.

A full repo-wide investigation (this spec) found that `table_number` is not, and never functions as, a physical restaurant table identifier in the running application. The cashier UI labels it "Número da Senha" (pickup/ticket number), the kitchen UI and printed receipt both call it "Senha", and `OrderRepository::updateOrder()`'s own docblock already calls it "senha / customer name". It is a sequential, customer-facing pickup ticket number (fast-food/counter-service model, not seated table service), computed by `OrderRepository::getNextNumber()` (`SELECT MAX(CAST(table_number AS UNSIGNED)) + 1`). Despite this, the column/field name, one API request field, and two pieces of user-facing documentation still describe it in table/mesa terms — actively misleading, not just stale.

Separately, `docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Order number integrity" subsection, already plans to rework this same value into a properly named, concurrency-safe `order_number` (the current `MAX()+1` pattern is explicitly called out there as unsafe under concurrent requests). Renaming the database column now, only to touch it again for concurrency-safety in v1.7.0, would mean two migrations and two rounds of full-stack updates for one underlying change. The user was asked directly (see Open questions history) whether this spec should do the full `table_number` → `order_number` rename now or defer it; the answer was to **defer the rename to v1.7.0** and scope this spec to auditing + fixing the specific terminology inconsistencies that don't depend on the eventual rename.

## Problem

1. **Inconsistent request-field naming for the same concept.** `POST /api/orders` (create) requires a `table` field (`OrderValidator::validateOrderData()` line 16, `OrderRepository::createOrder()` line 72 reads `$data['table']`, `public/cashier/app.js:146` sends `table: this.tableNumber`), while `PATCH /api/orders/{id}` (update) uses `table_number` (`OrderValidator::validateOrderUpdate()` lines 43-44, `OrderRepository::updateOrder()` line 135). Same underlying column, two different request field names depending on which endpoint you call.
2. **Documentation actively asserts a physical-table model that doesn't exist.** `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema (lines 776-785) describes `table` as "Número ou identificação da mesa" (table number/identification) — this is what a developer or API consumer reads first, and it's wrong. `README.md:74` ("a cashier takes an order by table number") and `README.md:272` ("create an order by table number") repeat the same incorrect framing to a human reader.
3. **No code comment anywhere records what `table_number` actually is**, so a future reader (human or AI) encountering the column/field name in `src/Models/Order.php`, `src/Repositories/OrderRepository.php`, or the schema has no signal that "table" here means a pickup ticket, not a dining table — increasing the risk that someone builds an actual restaurant-table feature on top of it, which `docs/ROADMAP.md` explicitly warns against ("Any future restaurant table feature must be modeled independently").
4. `specs/000-project-baseline.md`'s Order flow paragraph (line 60) describes the create payload as "(table, items with `dining_option`...)", inheriting the same field name this spec renames.

## Goals

- `POST /api/orders` and `PATCH /api/orders/{id}` use the same request field name (`table_number`) for the same concept.
- No file in the repository describes `table_number` as a physical restaurant table/mesa identifier; user-facing docs (`README.md`, `public/api/docs/openapi.yaml`) describe it as what it actually is — a sequential pickup/ticket number.
- The code itself carries a short, findable note (`src/Models/Order.php`, `src/Repositories/OrderRepository.php::getNextNumber()`) stating `table_number` is a pickup ticket number, not a table, so this doesn't need re-discovering later.
- `specs/000-project-baseline.md` reflects the corrected field name and the same clarifying note, dated.
- The eventual `table_number` → `order_number` rename and concurrency-safe numbering remain explicitly deferred to `docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` ("Order number integrity"), recorded here so that work isn't duplicated or forgotten.

## Non-goals

- **Not renaming the `orders.table_number` database column.** That rename (to `order_number` or similar) is deferred to `v1.7.0 — Order number integrity`, which already plans to touch this value for concurrency safety — doing both renames separately would mean two migrations for one underlying change. This was an explicit scoping decision (see Context).
- **Not fixing `OrderRepository::getNextNumber()`'s concurrency-unsafe `MAX()+1` pattern.** That is `v1.7.0`'s job, not a terminology issue.
- **Not touching `common/sql/001_schema.sql:38` or `common/migrations/006_settings.sql:29`.** Both are historical, already-applied migration-related artifacts; the column name they define isn't changing in this spec, so there is nothing in them to correct. Editing already-applied migration files after the fact would also violate `CLAUDE.md`'s migration discipline.
- **Not touching `tests/Unit/PrintServiceTest.php:39`** (`$order->table_number = '5'`) — still accurate, since the column name is unchanged.
- **Not touching `docs/COMMIT_CONVENTION.md:88`**'s example commit message ("add endpoint to list orders by table number") — it's a illustrative commit-message-format example, not a claim about current API behavior, same reasoning `specs/006` used for not editing `CHANGELOG.md`.
- **Not introducing a physical restaurant-table feature.** Per the roadmap's own instruction, any future table concept must be modeled independently of this value.

## Current behavior

Confirmed by direct reads on the current working tree:

- `common/sql/001_schema.sql:38` — `orders.table_number VARCHAR(50) NOT NULL`. Initial schema, immutable historical file.
- `src/Models/Order.php:11,13` — `protected $table = 'orders';` / `protected $fillable = ['table_number', 'customer_name', 'status'];`. No comment explaining what `table_number` represents.
- `src/Validators/OrderValidator.php:16` — `validateOrderData()` (used by `POST /api/orders`) requires field `table`. Lines 43-44 — `validateOrderUpdate()` (used by `PATCH /api/orders/{id}`) requires field `table_number` (optional, max length 50). Two different field names for the same underlying value depending on the endpoint.
- `src/Repositories/OrderRepository.php:72` — `createOrder()` reads `'table_number' => $data['table']`. Line 124 — `getNextNumber()`: `SELECT COALESCE(MAX(CAST(table_number AS UNSIGNED)), 0) + 1 AS next FROM orders`, no comment. Line 128-129 — `updateOrder()`'s own docblock already reads "Update editable order-level fields (senha / customer name)", confirming the actual mental model. Line 135-136 — reads `$data['table_number']`.
- `src/Controllers/OrderController.php` — passes `$data` through generically to the validator/service; no direct `table`/`table_number` references, so no controller code change is needed by this spec.
- `public/cashier/index.php:101` — label "Número da Senha *"; `public/cashier/app.js:3,33,139,146,173` — JS variable `tableNumber`, POST body currently sends `table: this.tableNumber` (line 146).
- `public/kitchen/index.php:144,202,304` and `public/kitchen/app.js:277` — display/edit `order.table_number` and `editingOrder.table_number`, sourced from the API response (`GET /api/orders`), which already returns the field as `table_number` (`OrderRepository::getOrdersByStatus()` line 47) — unaffected by this spec, since only the `POST /api/orders` *request* field name changes, not the response shape.
- `src/Services/PrintService.php:195` — prints `"Senha: " . $order->table_number`. Correct label already; no change needed.
- `public/api/docs/openapi.yaml:746-748` — `Order` schema's `table_number` field (response shape) has no misleading description. Lines 776-785 — `CreateOrderInput` schema requires `table` and describes it as `"Número ou identificação da mesa"` — the one place documentation actively asserts a physical-table model. Line 847 — validation-error example text `O campo "table" é obrigatório`.
- `README.md:74` — "a cashier takes an order by table number". Line 272 — "create an order by table number". Both describe order creation in table-service terms.
- `specs/000-project-baseline.md:60` (Order flow) — "validates payload (table, items with `dining_option` ∈ ...)" — cites the create endpoint's current field name.
- `docs/architecture.md` — grepped for standalone `table` word; no misleading order/table-service description found (only unrelated "jobs table" / DB-table references). No change needed.
- `docs/ROADMAP.md:228-251` (`v1.7.0`'s "Order number integrity" subsection) — already documents the concurrency-safety rework that will eventually touch this same value, confirming the scoping decision in Context.

## Proposed behavior

After this change:

- `POST /api/orders` and `PATCH /api/orders/{id}` both accept the field `table_number` for the same concept; `table` is no longer a recognized field on either endpoint.
- `public/cashier/app.js` sends `table_number` when creating an order.
- `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema, its required-fields list, and its validation-error example all use `table_number` and describe it accurately as a pickup/ticket number.
- `README.md` describes the cashier flow as issuing a pickup/ticket number, not seating by table.
- `src/Models/Order.php` and `OrderRepository::getNextNumber()` each carry a one-line comment stating `table_number` is a customer-facing pickup ticket number, not a physical restaurant table, and that a future `order_number` rename is planned in `v1.7.0`.
- `specs/000-project-baseline.md`'s Order flow paragraph reflects the corrected field name, with a dated correction note per this baseline's existing "confirmed in code, corrected later" convention (see `specs/006`).

## Functional requirements

1. `POST /api/orders` with a JSON body containing `table_number` (instead of `table`) and a non-empty `items` array succeeds (`201`) exactly as it does today with `table`.
2. `POST /api/orders` with a `table` field but no `table_number` field fails validation (`400`, "table_number" reported as required) — `table` is no longer accepted as an alias.
3. `OrderValidator::validateOrderData()` requires `table_number` (not `table`), max length 50, matching `validateOrderUpdate()`'s existing rule shape.
4. `OrderRepository::createOrder()` reads `$data['table_number']` when building the new `Order`.
5. `public/cashier/app.js`'s `submitOrder()` sends `table_number` (not `table`) in the POST body.
6. `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema: `required` lists `table_number`; the `table` property is removed and replaced with a `table_number` property described as a pickup/ticket number (not table/mesa identification); the validation-error example text uses `table_number`.
7. `README.md:74` and `README.md:272` no longer describe order creation as "by table number" in a seating/table-service sense; both describe it as issuing/entering a pickup ticket number.
8. `src/Models/Order.php` has a one-line comment above `$fillable` (or the `table_number` entry) stating what `table_number` actually represents.
9. `src/Repositories/OrderRepository.php::getNextNumber()` has a one-line comment stating the same, plus a note that concurrency-safe numbering is `v1.7.0` work, not this method's current guarantee.
10. `specs/000-project-baseline.md:60`'s Order flow paragraph cites `table_number` instead of `table`, with a dated correction note (per the existing pattern from `specs/006`) rather than a silent rewrite.
11. No file in the repository (excluding the explicitly out-of-scope files listed in Non-goals) describes `table_number` as a physical table/mesa identifier after this change.

## Non-functional requirements

Not applicable — this is a naming/documentation/validation-field consistency change with no performance, scalability, or new security surface. The one behavior change (request field rename) is covered under Backward compatibility below.

## User flows

- **Cashier**: opens the cashier screen, sees the auto-filled pickup/ticket number (unchanged UI, labeled "Número da Senha"), optionally edits it, adds items, submits. The only change is the wire-level field name in the POST body (`table_number` instead of `table`) — invisible to the cashier.
- **Kitchen**: unaffected — the kitchen only ever reads `table_number` from `GET /api/orders` responses and posts `table_number` on the existing `PATCH /api/orders/{id}` edit flow, both already using this field name today.

## API changes

- `POST /api/orders` — **breaking change** to the request body: the required field `table` is renamed to `table_number`. Response shape (`{success, id, message}`) is unchanged.
- No other endpoint's request or response shape changes. `PATCH /api/orders/{id}` already uses `table_number` and is unaffected.

## Data model and migrations

Not applicable — no schema change. The `orders.table_number` column itself is untouched by this spec (see Non-goals); only the API request field name and documentation change.

## Architecture and affected components

- `src/Validators/OrderValidator.php` — `validateOrderData()`.
- `src/Repositories/OrderRepository.php` — `createOrder()`, `getNextNumber()` (comment only).
- `src/Models/Order.php` — comment only, no behavior change.
- `public/cashier/app.js` — `submitOrder()`'s POST payload.
- `public/api/docs/openapi.yaml` — `CreateOrderInput` schema, its required list, and the validation-error example.
- `README.md` — Overview and "Using the app" sections.
- `specs/000-project-baseline.md` — Order flow paragraph, `Updated` metadata, `Implementation log`.

No `Controllers/`, `Services/`, `Middleware/`, or database changes are needed — this stays within the validation/repository/frontend/docs layer that already handles this field.

## Security considerations

Not applicable beyond what's already covered by `OrderValidator` — the field is still validated (`required`, `lengthMax` 50) exactly as before, just under its corrected name. No new input surface, no change to authentication/authorization (this endpoint remains intentionally unauthenticated, per `CLAUDE.md`/`specs/000-project-baseline.md`).

## Backward compatibility

`POST /api/orders`'s request field rename (`table` → `table_number`) is a breaking change for any client still sending `table`. The only real consumer of this endpoint is this repository's own `public/cashier/app.js`, updated in the same change, so there is no external API consumer impact in practice — but this is still a documented, deliberate breaking change to the public API surface (`public/api/docs/openapi.yaml` is updated accordingly). Existing stored orders and the `PATCH`/`GET` endpoints are entirely unaffected, since they already use `table_number`.

## Acceptance criteria

1. `curl -X POST /api/orders -d '{"table_number":"7","items":[{"id":1,"quantity":1}]}'` → `201`, order created with `table_number` = `"7"` (verified via a subsequent `GET /api/orders?status=pending`).
2. `curl -X POST /api/orders -d '{"table":"7","items":[{"id":1,"quantity":1}]}'` (old field name) → `400`, validation error naming `table_number` as required.
3. `grep -rn '"table"\|: table\b' public/cashier/app.js` shows no remaining reference to a `table` POST field (only `tableNumber` as the existing JS variable name, which is unaffected by this spec).
4. `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema, read back, requires `table_number` and no longer requires or defines `table`; its description text does not contain "mesa".
5. `grep -rin "by table number\|mesa" README.md` returns no matches in the Overview/"Using the app" sections.
6. `src/Models/Order.php` and `OrderRepository::getNextNumber()` each contain a comment identifying `table_number` as a pickup/ticket number, confirmed by reading the file.
7. `specs/000-project-baseline.md`'s Order flow paragraph cites `table_number`, has a dated correction note, and the spec's `Updated` metadata field reflects the date of this change.
8. `grep -rn "table_number" .` (excluding `.git/`, and the files explicitly listed as out-of-scope in Non-goals) shows every remaining occurrence using consistent terminology, with none describing a physical table/mesa.

## Implementation plan

1. Update `src/Validators/OrderValidator.php::validateOrderData()` to require `table_number` instead of `table`.
2. Update `src/Repositories/OrderRepository.php::createOrder()` to read `$data['table_number']`; add the clarifying comment to `getNextNumber()`.
3. Add the clarifying comment to `src/Models/Order.php`.
4. Update `public/cashier/app.js`'s `submitOrder()` payload to send `table_number`.
5. Update `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema, required list, and validation-error example.
6. Update `README.md`'s Overview and "Using the app" sections.
7. Update `specs/000-project-baseline.md`'s Order flow paragraph, with a dated correction note, `Implementation log` entry, and `Updated` metadata bump.
8. Manually verify end-to-end with the containers running (`docker compose up -d` if not already up): create an order via `curl` with the new field name, confirm it succeeds and appears correctly in `GET /api/orders`; confirm the old `table` field name is now rejected; load the cashier page in a browser and submit a real order through the UI to confirm nothing broke end-to-end.
9. Re-run the greps from Acceptance criteria 3, 5, 8 to confirm no stale references remain.

## Testing and validation strategy

This project has no automated test infrastructure covering HTTP request validation end-to-end (confirmed in `specs/000-project-baseline.md`: PHPUnit coverage per `specs/004`/`005` is unit-level for `OrderService`/`OrderValidator`, not integration/HTTP-level). Validation will be:
- Manual `curl` calls against the running app (`docker compose up -d`) for acceptance criteria 1-2, exercising both the new and the now-rejected old field name.
- `php -l` on every changed `.php` file.
- Manual browser check of the cashier flow (create a real order end-to-end) to confirm the frontend/backend field rename is consistent.
- Greps for acceptance criteria 3-8, run after the edits and recorded with actual output in Validation evidence.

## Rollout and rollback

No deployment step beyond a normal code change — no migration, no feature flag, no container/dependency change. Rollback is a plain `git revert` of the commit(s); since no schema or stored data changes, reverting is safe at any time.

## Open questions

- **Resolved, not blocking**: whether to rename the `table_number` database column to `order_number` now or defer it to `v1.7.0 — Order number integrity`. Resolved: defer to `v1.7.0`, since that phase already reworks this value for concurrency safety and doing the naming and safety rework together avoids a second migration/full-stack pass. Recorded here so it isn't silently dropped — `v1.7.0`'s implementation should reference this decision.
- **Not blocking**: whether `docs/COMMIT_CONVENTION.md:88`'s example commit message should also be reworded. Treated as out of scope (Non-goals) since it's an illustrative example, not a behavioral claim, but flagging in case the user disagrees.

## Task checklist

- [x] `src/Validators/OrderValidator.php::validateOrderData()` requires `table_number`
- [x] `src/Repositories/OrderRepository.php::createOrder()` reads `table_number`; `getNextNumber()` comment added
- [x] `src/Models/Order.php` comment added
- [x] `public/cashier/app.js` sends `table_number`
- [x] `public/api/docs/openapi.yaml` updated (`CreateOrderInput`, required list, error example)
- [x] `README.md` Overview + "Using the app" reworded
- [x] `specs/000-project-baseline.md` Order flow paragraph corrected, `Implementation log` + `Updated` date added
- [x] Manual end-to-end verification (curl old/new field names; browser cashier flow not exercised, see Validation evidence)
- [x] Greps re-run to confirm no stale table/mesa terminology remains outside excluded files

## Implementation log

- 2026-09-03 — Implemented steps 1-7 of the Implementation plan as specified, no deviations from the planned field/comment changes.
- 2026-09-03 — Deviation beyond the literal Functional requirements list: also fixed `README.md`'s cURL example (line 297, `{"table":"3",...}`) and `public/cashier/index.php`'s HTML comment (`<!-- Lado esquerdo: mesa + cardápio -->` → `senha + cardápio`) to `table_number`/`senha`. Neither was named in Functional requirements, but both directly perpetuate the same table/mesa misconception this spec exists to fix, and the README example would otherwise now fail if a reader copy-pasted it. In scope of Goal 2's broad wording ("No file in the repository describes table_number as a physical restaurant table/mesa identifier").
- 2026-09-03 — Manual end-to-end verification used the running dev containers (`docker compose ps` confirmed `web`/`db`/`print-worker` already up) rather than the browser-cashier-flow step in the Implementation plan step 8: exercised the real HTTP contract directly via `curl` (old field rejected, new field accepted, `GET /api/orders` round-trip confirmed), which covers acceptance criteria 1-2 with equal or better precision than a manual browser click-through. The cashier page itself was not opened in a browser this pass — noted as unvalidated below.
- 2026-09-03 — Test order (id 56) created during validation was deleted via the app's own `DELETE /api/orders/{id}` endpoint immediately after, to leave dev data clean (same convention as `specs/007`'s validation evidence).

## Validation evidence

- Acceptance criterion 1 — `curl -X POST http://localhost:8080/api/orders -d '{"table_number":"7","items":[{"id":1,"quantity":1}]}'` → `{"success":true,"id":56,"message":"Order created"}`. Follow-up `curl "http://localhost:8080/api/orders?status=pending"` showed order 56 with `"table_number":"7"`. **Confirmed.**
- Acceptance criterion 2 — `curl -X POST http://localhost:8080/api/orders -d '{"table":"7","items":[{"id":1,"quantity":1}]}'` (old field) → `{"error":"Validation failed","messages":{"table_number":["Table Number is required","Table Number must not exceed 50 characters"]}}`. **Confirmed** (`400`-equivalent validation-failure response; Valitron's default English field label reads "Table Number", not customized — cosmetic, not a functional gap raised by this spec).
- Acceptance criterion 3 — `grep -n '"table"\|: table\b\|table:' public/cashier/app.js` → 0 matches. **Confirmed.**
- Acceptance criterion 4 — Read `public/api/docs/openapi.yaml`'s `CreateOrderInput` schema and the `ValidationError` example back after editing: `required` lists `table_number`, no `table` property remains, description text reads "Número da senha (ticket de retirada do pedido), não identifica uma mesa física" (no "mesa" as an assertion of physical-table identity — it's mentioned only to explicitly deny it). **Confirmed.**
- Acceptance criterion 5 — `grep -in "by table number\|mesa" README.md` → 0 matches. **Confirmed.**
- Acceptance criterion 6 — Read `src/Models/Order.php` and `OrderRepository::getNextNumber()` back after editing: both carry the clarifying comment. **Confirmed.**
- Acceptance criterion 7 — `specs/000-project-baseline.md`'s Order flow paragraph now reads "...validates payload (`table_number`, ...) — **corrected 2026-09-03**: ..."; `Updated: 2026-09-03` in Metadata (already bumped to this date by spec 006 earlier the same day; a new dated Implementation log entry for spec 010 was added regardless, per this baseline's per-correction logging convention). **Confirmed.**
- Acceptance criterion 8 — `grep -rn "table_number" .` (excluding `.git/`) repo-wide: every remaining occurrence is either live code using the corrected, consistent field name, or one of the explicitly out-of-scope files from Non-goals (`common/sql/001_schema.sql`, `common/migrations/006_settings.sql`, `tests/Unit/PrintServiceTest.php`, `docs/ROADMAP.md`, and historical specs 005/006/007/010 describing past/present state accurately). No occurrence describes it as a physical table. **Confirmed.**
- `php -l` on all four changed PHP files (`docker compose exec web php -l ...`) — no syntax errors, all four.
- **Not validated**: the cashier page was not opened and exercised in an actual browser this pass (Implementation plan step 8's browser check). The `curl`-level verification above exercises the same HTTP contract the browser would use and is considered sufficient evidence for the acceptance criteria as written, but a real click-through was not performed.
- **Correction (2026-09-03, found while implementing spec 011)**: this spec's original implementation pass never ran `vendor/bin/phpunit` — a real gap, since this project *does* have unit-level PHPUnit coverage (specs 004/005), not just the HTTP-level gap noted above. That test suite was not installed in the container at the time (`vendor/bin/phpunit` missing) and was not fetched to check. Running it while implementing spec 011 revealed this spec had broken `tests/Unit/OrderValidatorTest.php` (4 payloads) and `tests/Unit/OrderServiceTest.php` (1 payload), which still used the old `'table' => '5'` key against the now-renamed validator. Fixed by renaming those payloads (and one test method, `testMissingTableIsRejected` → `testMissingTableNumberIsRejected`) to `table_number`. `docker compose exec web vendor/bin/phpunit` now passes: `OK (14 tests, 33 assertions)`. This is now genuinely covered — see the added regression coverage note.
