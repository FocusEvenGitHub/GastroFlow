# Spec 021 — Money representation

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Money representation" subsection: financial calculations must use exact arithmetic, avoiding binary floating-point arithmetic for money; MySQL may continue using `DECIMAL`; integer cents (or another tested exact representation) is the suggested fix; financial rules require dedicated tests.

## Problem

Confirmed by direct reads:

1. **The database layer is already correct.** `common/sql/001_schema.sql:23` — `menu_items.price DECIMAL(10,2)`; `common/migrations/008_order_items_price.sql:2-3` — `order_items.unit_price`/`packaging_cost` both `DECIMAL(10,2)`. No schema change is needed for this spec — the risk is entirely in the PHP application layer, which converts these exact decimal values to native floats and then does arithmetic on them.
2. **`MenuItem`/`OrderItem` cast money columns to PHP `float`** (`src/Models/MenuItem.php:15`, `src/Models/OrderItem.php:16-17`), and every read site re-casts with `(float)` again (`src/Repositories/OrderRepository.php:40-41,87,247,258-259`).
3. **`PrintService::printOrder()` is the one proven, real risk** (`src/Services/PrintService.php:212-227,271`): `$total = 0.0;` then, per item, `$itemTotal = ($price * $qty) + $packagingCost; $total += $itemTotal;` — repeated binary-float addition across every item on a real, customer-facing thermal receipt. `$price`/`$packagingCost` are arbitrary two-decimal reais amounts (e.g. `19.90`, `4.50`) that are not exactly representable in binary floating point; `number_format($total, 2, ...)` rounds for display, which masks small drift most of the time but not by construction — this is exactly the class of bug the roadmap names, on a document that leaves the building in the customer's hand.
4. **`OrderRepository::createOrder()`/`::addOrderItem()`'s packaging-cost calculation is not currently a proven risk in practice** — `1.0 * quantity` / `2.0 * quantity` (`src/Repositories/OrderRepository.php:88-92,236-240`) happen to use round constants that stay exact under float multiplication by a small integer. Still cast/copied to `float` with no explicit rounding before insert — correct today by coincidence of the constants chosen, not by construction, and the same code path a future non-round packaging fee would silently re-introduce the exact bug PrintService already has.
5. **`ReportService`'s revenue/aggregate math is already correct and needs no change** — every `SUM(order_items.unit_price * order_items.quantity + ...)` (`src/Services/ReportService.php:23-24,55,196-197,244-245`, 5 call sites total) runs as MySQL `DECIMAL` arithmetic *in SQL*; PHP only ever casts the single, already-correctly-computed final aggregate to `float` for JSON output — a display-only conversion, not repeated application-layer arithmetic. Confirmed by inspection, not assumed.
6. **`MenuRepository::addItem()`/`::updateItem()`** (`src/Repositories/MenuRepository.php:56,80`) pass an admin-submitted price straight through (no explicit rounding before `updateItem()`'s `(float)` cast, none at all in `addItem()`). Not a proven bug either — MySQL's `DECIMAL(10,2)` column rounds any excess precision at rest regardless of what PHP hands it — but leaves the rounding entirely implicit and DB-specific rather than an explicit, visible, testable rule.

## Goals

- Eliminate the proven float-accumulation risk in `PrintService`'s receipt total.
- Give the codebase one small, tested, reusable exact-money primitive so the same mistake isn't one careless multiplication away from reappearing (`docs/ROADMAP.md`'s own suggested fix: "another tested exact Money representation").
- Apply it consistently at every real money-arithmetic site (`OrderRepository`'s packaging-cost calculation, `PrintService`'s totals, `MenuRepository`'s price normalization on write) — not just the one already-provably-broken spot.
- No `ReportService` changes — it is already correct (see Problem, point 5) and is not touched speculatively.

## Non-goals

- **Not a `PricingService` extraction.** `docs/ROADMAP.md`'s own `v1.7.0` "Pricing domain" subsection (`OrderService → PricingService → OrderRepository`) is a separate, later item about *where* pricing rules live architecturally. This spec only fixes *how* money is calculated, in place, at its current call sites.
- **Not changing `MenuItem`/`OrderItem`'s Eloquent casts** (`'price' => 'float'`, etc.) or the DB column types. `GET /api/menu`'s JSON response (`MenuRepository::getFullMenu()`) serializes `$item->price` directly and is consumed as a JSON *number* by the cashier frontend's own arithmetic (`public/cashier/app.js`) — changing the cast to `'decimal:2'` would turn that into a JSON *string*, a real, unnecessary API-contract break for a fix that doesn't require it. The new `Money` helper accepts a float/int/numeric-string input precisely so the casts don't need to change.
- **Not touching the cashier's client-side running-total display.** It's a non-authoritative preview (the customer's real total is whatever the printed receipt says, computed server-side); JS float display-only arithmetic there is a separate, much lower-stakes concern than the server-side receipt calculation this spec fixes.
- **Not adding a currency code, multi-currency support, or a general-purpose Money library dependency.** One currency (BRL), no new Composer dependency — `CLAUDE.md`: "Don't add new dependencies... unless explicitly asked."

## Current behavior

See Problem, points 1-6, with citations.

## Proposed behavior

A new, minimal, dependency-free value object, `App\Money` (`src/Money.php`) — flat top-level namespace, matching this project's existing small-utility precedent (`App\Settings`, `App\Database`), not a new `Support/`/`ValueObjects/` layer invented for one class:

```php
final class Money
{
    public static function zero(): self;
    public static function fromCents(int $cents): self;
    public static function fromReais(float|int|string $reais): self; // rounds once, on entry
    public function plus(Money $other): self;
    public function multipliedBy(int $factor): self;
    public function getCents(): int;
    public function toReais(): float;   // for a DECIMAL(10,2) column
    public function format(): string;   // "19,90" — Brazilian style, for receipts/display
}
```

Internally, cents (`int`) are the only representation ever arithmetic'd on. `fromReais()` is the **one, single** point where a float/string reais amount is rounded into an exact integer (`(int) round($reais * 100)`) — safe because it happens exactly once per value, before any further math, not repeatedly across an accumulation (which is precisely what makes today's `PrintService` code unsafe). `plus()`/`multipliedBy()` are pure integer arithmetic — no floating point involved at any stage after entry. `toReais()`/`format()` are the only exit points, used once each for storage or display, never fed back into further arithmetic.

Applied at every real call site:

- `OrderRepository::createOrder()`/`::addOrderItem()` — `Money::fromReais($menuItem->price)` for `unit_price`; the packaging-cost `match` returns a `Money` (`Money::fromReais(1.0)->multipliedBy($quantity)`, etc.) instead of raw floats; both stored via `->toReais()`.
- `PrintService::printOrder()` — per-item `$itemTotal` and the running `$total` become `Money`, combined via `->plus()`/`->multipliedBy()`; `number_format(...)` calls replaced by `->format()`.
- `MenuRepository::addItem()`/`::updateItem()` — the submitted price is normalized through `Money::fromReais($data['price'])->toReais()` before it reaches `MenuItem::create()`/`$item->price =`, making the existing implicit "MySQL rounds it anyway" behavior explicit, visible, and testable instead of accidental.

## Functional requirements

1. `App\Money` exists, is exact for every representable two-decimal reais amount, and its arithmetic (`plus`, `multipliedBy`) never uses floating-point operators.
2. `PrintService::printOrder()`'s per-item and grand totals are computed via `Money`, not raw `float` addition.
3. `OrderRepository::createOrder()`/`::addOrderItem()`'s `unit_price`/`packaging_cost` values are computed/normalized via `Money` before being persisted.
4. `MenuRepository::addItem()`/`::updateItem()` normalize a submitted price via `Money` before persisting.
5. `ReportService` is unchanged (already correct — Problem, point 5).
6. Existing receipts/orders/reports produce numerically identical output to before this spec for every value already exactly representable in `DECIMAL(10,2)` (i.e., this is a correctness fix for an edge case, not a behavior change for typical values) — verified by the dedicated test in Functional requirement 7.
7. A dedicated unit test demonstrates the actual bug class this spec closes: summing a set of real, two-decimal reais values known to accumulate float drift (e.g. repeated `0.10`, or a set of real menu prices) produces a mismatched result under naive `float` accumulation but an exact one through `Money`.

## Non-functional requirements

Not applicable — a pure correctness fix with no performance/scale implication (money arithmetic is not a hot path).

## User flows

Not applicable — no user-visible flow changes. The printed receipt total and the admin's saved menu price are numerically identical to today for every value that was already safe; the fix only changes behavior for the edge cases that were silently wrong before.

## API changes

Not applicable — no request/response shape changes anywhere (see Non-goals on why the Eloquent casts, and therefore `GET /api/menu`'s JSON shape, are deliberately left alone).

## Data model and migrations

Not applicable — `DECIMAL(10,2)` columns are already correct (Problem, point 1); no migration needed.

## Architecture and affected components

- `src/Money.php` (new) — the value object described above.
- `src/Repositories/OrderRepository.php` — `createOrder()`, `addOrderItem()`.
- `src/Services/PrintService.php` — `printOrder()`'s item/total calculation.
- `src/Repositories/MenuRepository.php` — `addItem()`, `updateItem()`.
- `src/Services/ReportService.php` — **not modified**, re-confirmed already correct (Problem, point 5), not touched speculatively.
- `src/Models/MenuItem.php`, `src/Models/OrderItem.php` — **not modified** (casts stay `float` — see Non-goals).
- `docs/technical-decisions.md` — add one row to the existing decisions table: integer-cents `Money` helper vs. `bcmath`/a Composer money library, documenting the trade-off (matches this project's own "decisions with a real trade-off" framing already used for its other five entries).
- Tests: new `tests/Unit/MoneyTest.php` (the exactness proof — Functional requirement 7); `tests/Unit/PrintServiceTest.php` extended with a real multi-item order proving the receipt total is exact.

## Security considerations

Not applicable — no new input surface, no secrets. Slightly *reduces* risk of a class of financial-accuracy bug reaching a printed receipt.

## Backward compatibility

Fully backward compatible: no schema change, no API response shape change, no changed behavior for any value that wasn't already silently wrong. Existing `order_items`/`menu_items` rows are untouched (this spec doesn't backfill or recompute historical data — a past receipt already printed is not retroactively corrected, only future ones are computed correctly).

## Acceptance criteria

1. `Money::fromReais(19.90)->getCents() === 1990`.
2. `Money::fromReais(0.10)->plus(Money::fromReais(0.10))->plus(Money::fromReais(0.10))->getCents() === 30` (the canonical `0.1 + 0.1 + 0.1 !== 0.3` float trap, proven fixed).
3. A `PrintService::printOrder()` call against an order with several items whose prices are known to drift under naive float accumulation (constructed in the test) produces a `TOTAL` line whose cents-equivalent exactly matches the sum of the individually-printed item lines — not merely "close after rounding."
4. `MenuRepository::addItem()`/`::updateItem()` given a price with more than 2 decimal places (e.g. `19.999`) stores exactly `19.99` or `20.00` per standard rounding (round-half-up on the third decimal), not whatever MySQL's own implicit truncation/rounding would have produced un-normalized (documented and tested, not left to chance).
5. `vendor/bin/phpunit` passes, including the new `MoneyTest` and the extended `PrintServiceTest`.

## Implementation plan

1. Write `src/Money.php` and `tests/Unit/MoneyTest.php` first — the exactness proof stands on its own before touching any call site.
2. Update `OrderRepository::createOrder()`/`::addOrderItem()`.
3. Update `PrintService::printOrder()`; extend `tests/Unit/PrintServiceTest.php` with a multi-item exactness case.
4. Update `MenuRepository::addItem()`/`::updateItem()`.
5. Add the `docs/technical-decisions.md` row.
6. Run `vendor/bin/phpunit`; manually verify a real order creation + print job against the running dev stack (existing printer-connector test pattern already used in `PrintServiceTest`, plus a real `curl`-created order if the print queue is easy to inspect).

## Testing and validation strategy

Unit-level (PHPUnit): `MoneyTest` proves exactness directly (Acceptance criteria 1-2) with no DB/print dependency at all. `PrintServiceTest` (already using a `DummyPrintConnector`, per spec's existing pattern) is extended with a multi-item order built from prices known to drift under naive `float` summation, asserting the computed total's cents value exactly, not just that printing didn't throw. `MenuRepository` normalization is tested against a real in-memory SQLite DB (same approach as `OrderRepositoryTest`, specs 019/020). Manual verification: create a real order via `curl` against the running dev stack and inspect the resulting `order_items` rows' stored `unit_price`/`packaging_cost` for correctness, since PHPUnit alone doesn't touch the real MySQL `DECIMAL` storage round-trip.

## Rollout and rollback

No feature flag, no migration — pure application-code change. Rollback is a plain revert of the touched files; no schema or stored-data implications either way.

## Open questions

None blocking.

## Task checklist

- [x] `src/Money.php` written
- [x] `tests/Unit/MoneyTest.php` written, proving Acceptance criteria 1-2
- [x] `OrderRepository::createOrder()`/`::addOrderItem()` updated
- [x] `PrintService::printOrder()` updated
- [x] `tests/Unit/PrintServiceTest.php` extended with a multi-item exactness case
- [x] `MenuRepository::addItem()`/`::updateItem()` updated
- [x] `docs/technical-decisions.md` row added
- [x] `vendor/bin/phpunit` passing
- [x] Manual verification against the real running stack (order creation + stored `order_items` values, `MenuRepository` normalization)

## Implementation log

- 2026-09-05 — Investigated every real money-arithmetic site before writing code (Problem, points 1-6): confirmed `ReportService` was already exact (SQL-side `DECIMAL` math, only a final display cast to `float`) and left untouched, per Non-goals — no speculative changes there.
- 2026-09-05 — Wrote `src/Money.php` and `tests/Unit/MoneyTest.php` first, standalone, before touching any call site — the exactness proof (including the canonical `0.1+0.1+0.1 !== 0.3` trap, fixed) passed immediately.
- 2026-09-05 — Applied `Money` at `OrderRepository::createOrder()`/`::addOrderItem()`, `PrintService::printOrder()`, and `MenuRepository::addItem()`/`::updateItem()`.
- 2026-09-05 — Extended `PrintServiceTest` with a `CapturingPrintConnector` (a small in-file test double implementing `PrintConnector`) instead of relying on the library's own `DummyPrintConnector`: `Printer::close()` calls `$connector->finalize()` before `printOrder()` returns, which nulls `DummyPrintConnector`'s internal buffer and makes `getData()` throw afterward — the capturing connector ignores `finalize()`/`read()` so the printed text can still be inspected after the call. The new test builds 10 items at R$0,10 (the classic float-accumulation trap) plus a fractional-price item, and asserts the literal printed `TOTAL: R$ 60,70` line against an independently hand-computed cents value (`10*10 + 3*1990 = 6070`), not merely a value round-tripped through `Money` itself.
- 2026-09-05 — Chose **not** to add a new `MenuRepositoryTest` file for Functional requirement 4/Acceptance criterion 4 (no such test file exists yet in this project, and `MenuRepository`'s change is a one-line pass-through to the already-thoroughly-tested `Money::fromReais()`) — verified it instead with a direct, real call against the dev DB (see Validation evidence), consistent with not inventing new test scaffolding for a trivial integration point.
- 2026-09-05 — Manual validation against the real running stack: created a real order (`POST /api/orders`, item with a fractional price, `dining_option=viagem_vip`, quantity 3) and inspected the stored `order_items` row directly; called `MenuRepository::updateItem()` directly against the real DB with a 3-decimal price and confirmed correct rounding, then restored the item's original price. The test order was cancelled afterward (via spec 020's `POST /api/orders/{id}/cancel` — no hard-delete exists anymore) rather than left as pending clutter.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found.
- 2026-09-05 (post-hoc, second `/code-review` pass) — Found and fixed two real gaps: `Money::fromReais()` silently cast a non-numeric string (e.g. `"grátis"`) to `0.0` via PHP's plain `(float)` cast, creating a real R$0,00 item with no error — now throws `\InvalidArgumentException` for a non-numeric string, and `MenuController::store()`/`::updateItem()` gained an explicit `is_numeric()` pre-check for a clean `400 INVALID_PRICE` instead. Also found `DishController::update()` (unreachable — no route, spec 024) still wrote `price` straight through, bypassing `Money` entirely, unlike `MenuRepository`'s equivalent paths this spec already fixed for the identical column — brought it in line for consistency, even though it can't be reached via HTTP today.

## Validation evidence

- **Acceptance criterion 1** — `Money::fromReais(19.90)->getCents()` → `1990` (`MoneyTest::testFromReaisConvertsToExactCents`). **Confirmed.**
- **Acceptance criterion 2** — `Money::fromReais(0.10)->plus(...)->plus(...)->getCents()` → `30`, while raw `0.1 + 0.1 + 0.1 !== 0.3` is asserted true in the same test, proving the trap exists and is avoided (`MoneyTest::testRepeatedAdditionIsExactUnlikeRawFloat`). **Confirmed.**
- **Acceptance criterion 3** — `PrintServiceTest::testReceiptTotalIsExactAcrossManyItems`: 10× R$0,10 + 3× R$19,90 → printed buffer contains the literal `TOTAL: R$ 60,70`, matching the independently hand-computed cents value. **Confirmed.**
- **Acceptance criterion 4** — real call: `MenuRepository::updateItem(62, ['price' => '19.999'])` against the live dev DB → stored `price` became `20.00` (round-half-up on the third decimal); original price (`15.40`) restored afterward. **Confirmed.**
- **Acceptance criterion 5** — `vendor/bin/phpunit` → `OK (27 tests, 55 assertions)` (22 pre-existing + 4 `MoneyTest` + 1 new `PrintServiceTest` case). **Confirmed.**
- Additional real-stack check (Functional requirement 3): `POST /api/orders` with item id 62 (price `15.40`), quantity 3, `dining_option=viagem_vip` → stored `order_items` row: `unit_price=15.40`, `packaging_cost=6.00` (`2.00 × 3`, exact). **Confirmed**, order cancelled afterward.
- `php -l` clean on every changed PHP file. **Confirmed.**
