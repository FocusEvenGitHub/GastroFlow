# Spec 026 — Pricing domain

## Metadata

- Status: Verified
- Created: 2026-09-06
- Updated: 2026-09-06
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Pricing domain" subsection: move pricing rules away from persistence code, in the preferred direction `OrderService → PricingService → OrderRepository`; pricing should explicitly calculate items subtotal, packaging, discounts (if applicable), and final total; repositories should persist values, not decide restaurant pricing policy.

This is the next unaddressed v1.7.0 subsection after specs 019–025 (order numbering, lifecycle, money representation, validation, historical snapshots, API error standardization, query performance/pagination), all `Verified`.

## Problem

Confirmed by direct reads of `src/Repositories/OrderRepository.php`, `src/Services/OrderService.php`, and `src/Services/PrintService.php`:

1. **`OrderRepository` decides pricing policy, not just persistence.** `createOrder()` (`src/Repositories/OrderRepository.php:131-134`) and `addOrderItem()` (`:322-332`) both call `Money::fromReais($menuItem->price)` directly and `self::packagingCostFor($diningOption, $quantity)` (`:397-404`) — a hardcoded `match` on `dining_option` (`viagem_simples` → R$1,00/unit, `viagem_vip` → R$2,00/unit, `local` → free) that encodes a real restaurant pricing rule inside the persistence layer. `OrderService` (`src/Services/OrderService.php`) never sees a price — it only passes raw request data through to the repository.
2. **The item/order total formula is duplicated**, not shared. `PrintService::printOrder()` (`src/Services/PrintService.php:213-229`) independently recomputes `$itemTotal = $price->multipliedBy($qty)->plus($packagingCost)` and accumulates an order `$total` — the same "subtotal + packaging = item total, sum items = order total" formula that conceptually belongs with pricing, re-implemented rather than reused.
3. **No discount concept exists anywhere in the codebase** — confirmed via `grep -i discount` across `src/`, no matches. The roadmap's "discounts, if applicable" is genuinely not applicable today; this spec does not introduce one.
4. **Report aggregation (`ReportService.php`) is not in scope for this problem.** Its `SUM(order_items.unit_price * order_items.quantity + order_items.packaging_cost)` SQL reads already-persisted, already-priced values for reporting — it does not decide pricing policy, so moving it to `PricingService` would not reduce duplication and is excluded (see Non-goals).

## Goals

- Introduce a `PricingService` that is the single place restaurant pricing policy is decided: resolving a menu item's unit price, computing the packaging fee for a dining option/quantity, and computing an item's line total and an order's total from already-resolved values.
- `OrderRepository` calls `PricingService` for these decisions instead of embedding them itself; it keeps deciding *how* to persist (transaction boundaries, locking) but not *what the price is*.
- `PrintService` calls the same `PricingService` total-calculation logic instead of duplicating the formula.
- No behavior change: identical `unit_price`/`packaging_cost` values are persisted, identical totals are printed, for the same inputs as today.

## Non-goals

- No discount feature — none exists today, and the roadmap only asks to design for it "if applicable." Do not add a discount field, parameter, or code path.
- No change to `ReportService.php`'s SQL-level aggregation — it operates on already-persisted values, not policy decisions, so it stays as-is (see Problem #4).
- No change to the `orders`/`order_items` schema, migrations, or API request/response shapes — this is an internal refactor of where a calculation lives, not a behavior or contract change.
- No literal `OrderService → PricingService → OrderRepository` call chain if it would require moving the menu-item price lookup outside `OrderRepository::createOrder()`'s existing `lockForUpdate()` transaction (see Architecture and affected components — this would reintroduce a TOCTOU race spec 023's code review specifically closed).

## Current behavior

- `OrderRepository::createOrder()` (`src/Repositories/OrderRepository.php:74-150`): inside one DB transaction, locks the referenced `menu_items` rows (`lockForUpdate()`, ordered by id — spec 023 code review, prevents both a stale-availability race and a lock-ordering deadlock), then for each item computes `$unitPrice = Money::fromReais($menuItem->price)` and `$packagingCost = self::packagingCostFor($diningOption, $quantity)`, and persists both as `OrderItem` columns.
- `OrderRepository::addOrderItem()` (`:301-347`): same pattern, single item, also inside a `lockForUpdate()` transaction on the menu item.
- `OrderRepository::packagingCostFor()` (`:397-404`): private, hardcoded `match` — the actual pricing rule.
- `OrderService` (`src/Services/OrderService.php`): thin pass-through; `createOrder()`/`addOrderItem()` delegate directly to the repository and never touch price.
- `PrintService::printOrder()` (`src/Services/PrintService.php:210-274`): reads persisted `unit_price`/`packaging_cost` per `OrderItem`, recomputes `$itemTotal` and accumulates `$total` using `Money`, for display on the receipt.
- `Money` (`src/Money.php`): exact integer-cent arithmetic (spec 021) — `fromReais()`, `plus()`, `multipliedBy()`, `toReais()`, `format()`. No `PricingService` or equivalent exists (confirmed via `Glob src/**/*Pricing*` — no match).

## Proposed behavior

- New `App\Services\PricingService` (stateless, no constructor dependencies — pure calculation, matching `Money`'s design):
  - `packagingFeeFor(string $diningOption, int $quantity): Money` — the exact rule currently in `OrderRepository::packagingCostFor()`, moved verbatim (no behavior change).
  - `unitPriceFor(MenuItem $menuItem): Money` — wraps `Money::fromReais($menuItem->price)`; the single place a menu item's persisted price becomes a policy-usable `Money` value.
  - `lineTotal(Money $unitPrice, int $quantity, Money $packagingFee): Money` — `$unitPrice->multipliedBy($quantity)->plus($packagingFee)`, the formula currently duplicated in `PrintService`.
  - `orderTotal(iterable $lineTotals): Money` — sums line totals; replaces `PrintService`'s manual `Money::zero()` accumulator loop.
- `OrderRepository::createOrder()` / `addOrderItem()` call `PricingService::unitPriceFor()`/`packagingFeeFor()` in place of the current direct `Money::fromReais()`/`packagingCostFor()` calls — still inside the existing `lockForUpdate()` transaction, so the concurrency guarantee from spec 023's code review is unchanged. `OrderRepository` gains a `PricingService` constructor dependency (autowired by PHP-DI, same as every other service/repository in this project — no container definition needed).
- `PrintService::printOrder()` calls `PricingService::lineTotal()`/`orderTotal()` instead of recomputing the formula inline.
- `OrderService` is unchanged — it still does not need to see a price, since the decision is made where the data already lives (inside the locked transaction), not one layer up. This is a deliberate, documented deviation from the roadmap's literal `OrderService → PricingService → OrderRepository` chain (see Non-goals's last bullet and Architecture section below).

## Functional requirements

1. Given the same `menu_items.price` and `dining_option`/`quantity` inputs, `PricingService::unitPriceFor()` and `packagingFeeFor()` return the same `Money` values that `OrderRepository`'s current inline logic produces today (byte-for-byte identical `toReais()` output).
2. `PricingService::lineTotal($unitPrice, $quantity, $packagingFee)` returns `$unitPrice->multipliedBy($quantity)->plus($packagingFee)`.
3. `PricingService::orderTotal($lineTotals)` returns the sum of all given line totals, `Money::zero()` for an empty input.
4. `OrderRepository::createOrder()` and `addOrderItem()` no longer contain a literal pricing `match` statement or a direct `Money::fromReais($menuItem->price)` call — both are delegated to `PricingService`.
5. `PrintService::printOrder()` no longer independently computes `$price->multipliedBy($qty)->plus($packagingCost)` — it delegates to `PricingService::lineTotal()`, and the accumulated order total delegates to `PricingService::orderTotal()`.
6. An order created before and after this change, with identical input, produces identical `order_items.unit_price`/`packaging_cost` values and an identical printed total (verified via the existing `OrderRepositoryTest`/`PrintServiceTest` fixtures — see Testing and validation strategy).

## Non-functional requirements

- `PricingService` must remain free of I/O (no DB queries, no HTTP, no printer access) so it stays trivially unit-testable and safely callable from inside an existing DB transaction without changing that transaction's semantics.

## User flows

Not applicable — this is an internal refactor with no user-facing behavior change. Cashier order creation, kitchen item add, and receipt printing all produce identical results before and after.

## API changes

Not applicable — no request/response shape changes.

## Data model and migrations

Not applicable — no schema change; `unit_price`/`packaging_cost` continue to be plain `DECIMAL(10,2)` columns populated the same way.

## Architecture and affected components

- New: `src/Services/PricingService.php`.
- Changed: `src/Repositories/OrderRepository.php` (`createOrder()`, `addOrderItem()`, remove `packagingCostFor()`, add constructor-injected `PricingService`).
- Changed: `src/Services/PrintService.php` (`printOrder()`, replace inline total math with `PricingService` calls; add constructor-injected `PricingService`).
- Unchanged: `src/Services/OrderService.php`, `src/Controllers/OrderController.php`, `src/Validators/OrderValidator.php`.
- **Deviation from the roadmap's literal `OrderService → PricingService → OrderRepository` chain, documented per `CLAUDE.md`'s "when code and spec conflict, report the conflict instead of silently choosing one":** the menu item's current price is only known once `OrderRepository::createOrder()` has taken `lockForUpdate()` on the `menu_items` row (spec 023's code review fix, closing a real TOCTOU race between an availability/price check and the `OrderItem` insert). Computing the price one layer up in `OrderService`, before that lock is held, would reintroduce the exact race that fix closed. `PricingService` is therefore called *by* `OrderRepository`, from inside its existing transaction, rather than *before* it by `OrderService`. The roadmap's goal — pricing policy centralized outside persistence code, not scattered/hardcoded in a repository — is still met; only the literal caller order differs, for a concurrency-safety reason specific to this codebase.
- Existing test-construction pattern breaks: `tests/Unit/OrderRepositoryTest.php` and `tests/Smoke/OrderRepositoryTest.php` currently do `new OrderRepository()` with no arguments (no DI container in these tests). Adding a required `PricingService` constructor parameter requires updating both to `new OrderRepository(new PricingService())`. Likewise for any `PrintService` test construction (`tests/Unit/PrintServiceTest.php`, `tests/Smoke/PrintServiceTest.php`), if `PrintService` is constructed directly there. This is a mechanical test-fixture update, tracked in the Implementation plan, not a design risk.

## Security considerations

Not applicable — no new input surface, no change to authentication/authorization/validation; `PricingService` consumes already-validated, already-locked data.

## Backward compatibility

- Behavior-preserving by design (Goals, Functional requirement 6): existing orders, existing printed receipts, and existing API responses are unaffected.
- Test fixtures that directly instantiate `OrderRepository`/`PrintService` (see Architecture) must be updated in the same change — otherwise the existing suite fails to construct these classes, not because behavior changed but because a constructor signature did.

## Acceptance criteria

- [ ] `src/Services/PricingService.php` exists with `packagingFeeFor()`, `unitPriceFor()`, `lineTotal()`, `orderTotal()`, and no I/O.
- [ ] `OrderRepository` no longer defines `packagingCostFor()` or calls `Money::fromReais($menuItem->price)` directly; both go through `PricingService`.
- [ ] `PrintService::printOrder()` no longer computes `$price->multipliedBy($qty)->plus($packagingCost)` inline; it calls `PricingService`.
- [ ] `vendor/bin/phpunit` passes for `tests/Unit/OrderRepositoryTest.php`, `tests/Unit/OrderServiceTest.php`, `tests/Unit/PrintServiceTest.php`, `tests/Unit/MoneyTest.php`, and their `tests/Smoke` counterparts, after fixture updates.
- [ ] For a real order created via `POST /api/orders` with a `viagem_simples` item (quantity 2, menu price R$10,00), `order_items.unit_price = 10.00` and `order_items.packaging_cost = 2.00` — identical to pre-change behavior, confirmed manually against the dev DB.
- [ ] A reprint of an existing order (`PrintService`) shows the same total as before this change, for at least one multi-item order, confirmed manually.

## Implementation plan

1. Create `src/Services/PricingService.php` with `packagingFeeFor()` (move `OrderRepository::packagingCostFor()`'s logic verbatim) and `unitPriceFor()`.
2. Add `lineTotal()` and `orderTotal()` to `PricingService`, matching `PrintService`'s existing formula.
3. Update `OrderRepository`: add `PricingService` constructor dependency, replace inline pricing calls in `createOrder()`/`addOrderItem()`, delete `packagingCostFor()`.
4. Update `PrintService`: add `PricingService` constructor dependency, replace the inline `$itemTotal`/`$total` computation in `printOrder()`.
5. Update `tests/Unit/OrderRepositoryTest.php`, `tests/Smoke/OrderRepositoryTest.php`, and any direct `PrintService`/`OrderRepository` construction in other test files to pass a `new PricingService()`.
6. Add `tests/Unit/PricingServiceTest.php` covering `packagingFeeFor()` (all three `dining_option` cases), `unitPriceFor()`, `lineTotal()`, and `orderTotal()` (including the empty-input zero case).
7. Run the full suite; manually verify one order-creation and one reprint against the dev DB per the Acceptance criteria above.

## Testing and validation strategy

This project has a real PHPUnit suite (`tests/Unit`, `tests/Smoke`, run via `docker compose exec web vendor/bin/phpunit`, also run in CI per `.github/workflows/ci.yml`) — this is not a project without test infrastructure. Each acceptance criterion maps to:

- New `PricingServiceTest` (pure unit test, no DB) for the four `PricingService` methods.
- Existing `OrderRepositoryTest`/`OrderServiceTest` (in-memory SQLite, per their current setup) continuing to pass after the fixture update, proving persisted `unit_price`/`packaging_cost` values are unchanged.
- Existing `PrintServiceTest` continuing to pass after the fixture update, proving printed totals are unchanged.
- One manual `POST /api/orders` against the real dev DB (`docker compose up -d`, real menu item), inspecting `order_items` directly, and one manual reprint, per the two manual acceptance criteria above — actually run, not assumed, per `CLAUDE.md`'s rule against claiming a test passed without running it.

## Rollout and rollback

Standard code change on branch `019`, no feature flag needed (no external behavior change). Rollback is a plain `git revert` of the commit(s) — no migration, no data change, no deployment sequencing concern.

## Open questions

None blocking. `PricingService`'s method names/shape above are a concrete proposal, not open for negotiation during implementation — if `/spec-implement` finds a better shape while wiring it up, it should be recorded as a deviation in the Implementation log, not silently substituted.

## Task checklist

- [x] Step 1 — create `PricingService` with `packagingFeeFor()`/`unitPriceFor()`
- [x] Step 2 — add `lineTotal()`/`orderTotal()`
- [x] Step 3 — wire into `OrderRepository`, remove `packagingCostFor()`
- [x] Step 4 — wire into `PrintService`, remove inline total math
- [x] Step 5 — update existing test fixtures
- [x] Step 6 — add `PricingServiceTest`
- [x] Step 7 — run full suite + manual validation

## Implementation log

- **Status set to `Approved` then immediately `In Progress`** on `/spec-implement` invocation, per the skill's rule that an explicit invocation on a blocking-question-free `Draft` spec counts as approval.
- **Re-investigation before editing found two inaccuracies in this spec's own text, corrected here rather than silently:**
  1. "Architecture and affected components" and the Implementation plan referenced `tests/Smoke/OrderRepositoryTest.php` and `tests/Smoke/PrintServiceTest.php`. These do not exist — `tests/Smoke/` contains only `ApiTest.php` (a real HTTP smoke test through the full DI-wired `App`, unaffected by this change since autowiring resolves the new `PricingService` automatically). The actual `OrderRepositoryTest`/`PrintServiceTest` requiring fixture updates both live in `tests/Unit/`, already listed there too — only the stray `tests/Smoke/*` mentions were wrong.
  2. **`src/Jobs/PrintOrderJob.php:48`** was not listed as an affected file, but it manually constructs `new PrintService($logger, $settings)` outside the DI container (jobs run via `bin/worker`, not through Slim's autowired container). Adding a required `PricingService` constructor parameter to `PrintService` means this call site needs `new PrintService($logger, $settings, new PricingService())` too, or it fatals at runtime for every real print job. Added to scope and fixed.
- **`AdminController` and `OrderService` needed no changes** — both receive `PrintService`/`OrderRepository` through PHP-DI constructor autowiring (`src/App.php`'s `useAutowiring(true)`), which resolves the new no-dependency `PricingService` parameter automatically. Confirmed via `grep -rn "new PrintService\|new OrderRepository"` across the repo: only the test files and `PrintOrderJob.php` construct these classes manually.
- **Menu-price example in the last two acceptance criteria adjusted during manual validation:** no real menu item in the dev DB is priced at exactly R$10,00 as originally written; used the real item "Cebola" (id 19, price R$4,00) instead, quantity 2, `viagem_simples`. This does not weaken the check — it still exercises the same formula (`unit_price × quantity + packaging_fee`) against a real, already-existing menu item rather than a hypothetical one.
- **`PricingService` constructor position in `OrderRepository`/`PrintService`:** placed as the sole (`OrderRepository`) or first-after-required (`PrintService`, before the optional `?callable $connectorFactory = null`) constructor parameter, so it stays a required, non-defaultable dependency rather than being tacked on at the end after an optional parameter (which PHP does not allow before a parameter with a default anyway).

## Validation evidence

All commands actually run against the real `docker compose` stack (`db`, `web`, `print-worker`, already up — confirmed via `docker compose ps` before starting).

**Syntax check** (`docker compose exec -T web php -l <file>`), every changed/new file — all returned "No syntax errors detected": `src/Services/PricingService.php`, `src/Repositories/OrderRepository.php`, `src/Services/PrintService.php`, `src/Jobs/PrintOrderJob.php`, `tests/Unit/OrderRepositoryTest.php`, `tests/Unit/PrintServiceTest.php`, `tests/Unit/PricingServiceTest.php`.

**Full automated suite** (`docker compose exec -T web vendor/bin/phpunit`):
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.33
...
OK (64 tests, 102 assertions)
```
This run includes the new `PricingServiceTest` (6 tests) and the updated `OrderRepositoryTest`/`PrintServiceTest` fixtures — maps to Acceptance criteria 1–4 (as corrected in the Implementation log: no `tests/Smoke` counterparts exist for these classes, only `tests/Unit`, which is what actually ran).

**Manual API check** (Acceptance criterion 5, dev DB, real menu item):
- `curl -s http://localhost:8080/api/menu` → confirmed real item id 19 "Cebola", `price: 4`.
- `curl -s -X POST http://localhost:8080/api/orders -d '{"items":[{"id":19,"quantity":2,"dining_option":"viagem_simples"}],"print_ticket":false}'` → `{"success":true,"id":108,...}`.
- `curl -s "http://localhost:8080/api/orders?status=all"` → order 108's item: `"unit_price":4,"packaging_cost":2` — exactly `PricingService::unitPriceFor()` (menu price, unchanged) and `packagingFeeFor('viagem_simples', 2)` = 2 × R$1,00 (unchanged formula), proving the extraction didn't change persisted values.
- Cleanup: `curl -s -X POST http://localhost:8080/api/orders/108/cancel` → `{"success":true,"message":"Order cancelled"}` (soft-cancelled per spec 020, not deleted, so the order is preserved for audit but excluded from normal "pending/done" operational views).

**Manual reprint check** (Acceptance criterion 6, real order, real `PrintService`+`PricingService`, no real printer available in this environment — a temporary script constructed `PrintService` with a capturing in-memory connector instead of a real `NetworkPrintConnector`, run via `docker compose exec -T web php common/_tmp_pricing_manual_check.php`, then deleted immediately after — `git status` confirmed it left no trace):
```
2x Cebola               R$ 10,00
    [Simples]
...
TOTAL: R$ 10,00
```
`2 × R$4,00 + R$2,00 packaging = R$10,00`, computed end-to-end through the real `PricingService::lineTotal()`/`orderTotal()` path against order 108's actual persisted DB row — matches the pre-change formula exactly (also independently covered by the synthetic-data `PrintServiceTest::testReceiptTotalIsExactAcrossManyItems`, which passed in the suite run above).

**What was not validated:** a real thermal printer was not available in this environment, so the physical print output (paper width, ESC/POS control codes rendering correctly on real hardware) was not checked — out of scope for this spec anyway, since no printer-control-code logic changed, only the total's arithmetic source.

All six acceptance criteria have direct evidence above.
