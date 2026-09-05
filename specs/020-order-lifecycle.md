# Spec 020 — Order lifecycle

## Metadata

- Status: Verified
- Created: 2026-09-05
- Updated: 2026-09-05
- Owner: Henry
- Related issue: Not applicable
- Related branch: 019 (continuing on the existing branch, by explicit request — not a new numbered branch per spec, unlike specs 010-019)

## Context

`docs/ROADMAP.md`'s `v1.7.0 — Domain & Architecture` phase, "Order lifecycle" subsection, asks to document and enforce the real order state machine, make cancellation and reopening explicit business operations, make invalid transitions fail predictably, and remove statuses that aren't actually used rather than keeping them for theoretical completeness.

Two product decisions this spec depends on were resolved by the user before drafting (not something the code could answer on its own):

1. **Status set stays minimal**: `pending` / `done` / a new `cancelled` — the schema's existing `preparing`/`ready` enum values are dropped (confirmed unused anywhere in the codebase, not implemented as a real kitchen workflow).
2. **Cancellation becomes a soft state** (`status = 'cancelled'`), replacing today's hard `DELETE /api/orders/{id}` — a cancelled order stays in `orders` (auditable, reportable later) instead of disappearing entirely.

## Problem

Confirmed by direct reads:

1. `common/sql/001_schema.sql:35` — `status ENUM('pending','preparing','ready','done') NOT NULL DEFAULT 'pending'`, but `src/Models/Order.php:21-22` only defines `STATUS_PENDING`/`STATUS_DONE`. Repo-wide grep confirms `'preparing'`/`'ready'` are never referenced anywhere in `src/`, `public/cashier/`, or `public/kitchen/` — pure unused schema leftovers, exactly what the roadmap flags.
2. There is no `cancelled` status at all. The only way to remove an unwanted order today is `OrderController::destroy()` → `OrderService::deleteOrder()` → `OrderRepository::deleteOrder()` → `Order::findOrFail($id)->delete()` (`src/Repositories/OrderRepository.php`), a hard, irreversible delete (`order_items` cascade via FK). This is the kitchen's only "remove this order" action: `public/kitchen/app.js:304-320`'s `deleteOrder()`, wired to `DELETE /api/orders/{id}` from the edit modal, with a confirm dialog reading "Excluir o pedido #X definitivamente? Essa ação não pode ser desfeita." A cancelled order today leaves **no trace** — not in `orders`, not in any report, not distinguishable from an order that never existed.
3. **No transition guards exist anywhere.** `OrderRepository::completeOrder()` and `::uncompleteOrder()` (`src/Repositories/OrderRepository.php`) unconditionally set `status` regardless of the current value — there is no such thing today as an "invalid" transition; every transition silently succeeds. `OrderController::complete()`/`::uncomplete()` don't even catch `ModelNotFoundException` (unlike `update()`/`addItem()`/`updateItem()`/`removeItem()`/`destroy()`, which all do) — a nonexistent order returns a generic `500`, not `404`.
4. `src/Services/ReportService.php` (5 call sites) and `src/Services/KitchenService.php:14` already filter explicitly on `Order::STATUS_DONE`/`'pending'` — reports and the food-summary aggregate are therefore **already** correctly scoped and require no change once `cancelled` exists as a third, distinct value.
5. `OrderRepository::getOrdersByStatus()`'s `'all'` branch aside, the kitchen frontend only ever calls `GET /api/orders?status=pending` and `GET /api/orders?status=done` (`public/kitchen/app.js:113,123`) — it never requests `status=all`. A cancelled order therefore will not appear in either kitchen bucket without new frontend work this spec deliberately does not add (see Non-goals).
6. `docs/architecture.md` has no section documenting order status/lifecycle at all — the roadmap's "document" half of "document and enforce" is currently unmet.

## Goals

- Formalize the real, minimal status set: `pending`, `done`, `cancelled`. Drop `preparing`/`ready` from the schema.
- Cancellation becomes an explicit, auditable operation (`status = 'cancelled'`), not a destructive `DELETE`.
- Every transition is guarded: an invalid one (anything involving an already-`cancelled` order) fails with a clear `409`, not a silent success or a generic `500`.
- A nonexistent order on any status-transition endpoint returns `404`, consistently with the rest of `OrderController`.
- Document the resulting state machine in `docs/architecture.md`.

## Non-goals

- **Not implementing a real `preparing`/`ready` kitchen workflow.** Explicitly decided against by the user — out of scope for this spec, and not assumed to be picked up later by this document.
- **Not adding a "cancelled orders" view to the kitchen or admin UI.** A cancelled order simply stops appearing in the kitchen's `pending`/`done` buckets, matching today's behavior when an order is deleted. Surfacing cancelled-order history is a reporting/audit concern, closer to `v1.7.0`'s own "Historical order snapshots" or `v1.8.0`'s "Audit history" — not built here.
- **Not adding a path back out of `cancelled`.** Treated as terminal by design (see Proposed behavior) — not because the roadmap mandates it, but because nothing in this project's real workflow asks for "un-cancel," and the roadmap's own example (`CANCELED → READY should not happen unless explicitly supported`) is a caution against silently allowing transitions out of `cancelled`, not a requirement to build one.
- **Not touching `Pricing domain`, `Money representation`, `Historical order snapshots`, `API error standardization`, or `Pagination`** — separate `v1.7.0` subsections.
- **Not adding a general Controller error-response helper.** `OrderController` keeps its existing per-method `try/catch` style; this spec only adds the same `\DomainException` → `409` and `ModelNotFoundException` → `404` catches that already exist on sibling methods in the same file.

## Current behavior

See Problem, points 1-6, with citations.

## Proposed behavior

**Status set.** `orders.status` becomes `ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending'`. `Order` gains `const STATUS_CANCELLED = 'cancelled';`.

**State machine:**

```text
PENDING ⇄ DONE        (complete / uncomplete — already exist, now guarded)
PENDING → CANCELLED   (new: cancel)
DONE    → CANCELLED   (new: cancel — a completed order can still be cancelled, e.g. after a dispute)
CANCELLED → (nothing) (terminal)
```

Guards, enforced in `OrderRepository` (matching this project's existing `\DomainException`-for-business-rule-violation precedent, e.g. `removeOrderItem()`'s "can't remove the last item"):

- `completeOrder($id)` — if current status is `cancelled`, throw `\DomainException('Não é possível concluir um pedido cancelado.')`. Otherwise set `done` (works from `pending` or already-`done` — a same-state no-op is not treated as invalid, only a transition *out of* `cancelled` is).
- `uncompleteOrder($id)` — if current status is `cancelled`, throw `\DomainException('Não é possível reabrir um pedido cancelado.')`. Otherwise set `pending`.
- `cancelOrder($id)` (new) — if current status is already `cancelled`, throw `\DomainException('Este pedido já está cancelado.')`. Otherwise set `cancelled`.

Each of `OrderController::complete()`/`::uncomplete()`/the new `::cancel()` catches `\Illuminate\Database\Eloquent\ModelNotFoundException` → `404` (fixing the two existing methods that were missing it, found while touching this exact code) and `\DomainException` → `409`, in that order, before the existing generic `\Throwable` → `500` fallback — mirroring `removeItem()`'s existing catch order in the same file.

**Cancellation replaces the hard delete.** `DELETE /api/orders/{id}` / `OrderController::destroy()` / `OrderService::deleteOrder()` / `OrderRepository::deleteOrder()` are removed. A new `POST /api/orders/{id}/cancel` (mirroring the existing `/complete`/`/uncomplete` convention) replaces it. `public/kitchen/app.js`'s `deleteOrder()` becomes `cancelOrder()`, calling the new endpoint; its confirm dialog and success message are updated to reflect that the order is cancelled (kept for the record), not destroyed — e.g. "Cancelar o pedido #X? Ele deixará de aparecer na cozinha, mas o registro é mantido." / "Pedido cancelado!". No other kitchen UI changes (see Non-goals) — a cancelled order simply stops appearing in the `pending`/`done` fetches, the same visible effect the old delete had.

## Functional requirements

1. `orders.status` accepts exactly `pending`, `done`, `cancelled`. Any other value is rejected by the database (enum constraint).
2. `POST /api/orders/{id}/cancel` sets a `pending` or `done` order to `cancelled` and returns `200`/`{"success":true,...}`.
3. `POST /api/orders/{id}/cancel` on an order that is already `cancelled` returns `409`.
4. `POST /api/orders/{id}/complete` or `/uncomplete` on a `cancelled` order returns `409`, and does not change its status.
5. Any of `complete`/`uncomplete`/`cancel` on a nonexistent order id returns `404`.
6. `DELETE /api/orders/{id}` no longer exists as a route (removed, not merely deprecated).
7. A `cancelled` order is excluded from `GET /api/orders?status=pending` and `?status=done`, from `ReportService`'s revenue/aggregate queries, and from `KitchenService`'s food-summary — all already true today by construction (Problem, point 4) and simply re-confirmed, not newly implemented.
8. Existing `pending`/`done` behavior (including reopening a `done` order back to `pending`) is unchanged for orders that were never cancelled.

## Non-functional requirements

Not applicable beyond the guards above — no performance/scale concerns for a status-column check.

## User flows

**Kitchen — cancel a pending order.** Kitchen opens the order's edit modal → clicks "Cancelar pedido" (renamed from "Excluir pedido") → confirms → order disappears from the pending list; the row still exists in `orders` with `status='cancelled'`.

**Kitchen — invalid transition (new, previously impossible to observe).** Two kitchen tablets have the same order's edit modal open; one cancels it, the other clicks "Concluir" a moment later → the second request now gets a clear `409` ("Não é possível concluir um pedido cancelado.") instead of silently succeeding and leaving the order `done` right after being cancelled.

## API changes

- `POST /api/orders/{id}/cancel` (new) — `200 {"success":true,"message":"Order cancelled"}`; `404` if the order doesn't exist; `409` if already cancelled.
- `POST /api/orders/{id}/complete`, `POST /api/orders/{id}/uncomplete` — now also return `404` (previously fell through to `500`) and `409` (new) when the order is `cancelled`.
- `DELETE /api/orders/{id}` — removed entirely (was never documented in `public/api/docs/openapi.yaml`, so no doc change needed there beyond adding `/cancel`).
- `public/api/docs/openapi.yaml` — add `/api/orders/{id}/cancel`, matching the existing style of the `/complete`/`/uncomplete` entries (both of which, like the missing `/orders/{id}` `PATCH`/`DELETE`, are pre-existing documentation gaps this spec does not otherwise expand into fixing).

## Data model and migrations

New file `common/migrations/013_order_cancellation.sql` (next free migration number — `012_order_number_integrity.sql` is current latest):

```sql
ALTER TABLE orders MODIFY COLUMN status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending';
```

Confirmed safe to apply directly (no idempotency guard needed — a `MODIFY COLUMN` to the same target definition is a harmless no-op if ever re-run, unlike an `ADD COLUMN`/`ADD INDEX` that errors on repetition): a live check against the dev DB found only `pending` (11 rows) and `done` (38 rows) in use today, zero `preparing`/`ready` rows to migrate or lose.

## Architecture and affected components

- `common/migrations/013_order_cancellation.sql` (new).
- `src/Models/Order.php` — add `const STATUS_CANCELLED = 'cancelled';`.
- `src/Repositories/OrderRepository.php` — guard `completeOrder()`/`uncompleteOrder()`; add `cancelOrder()`; remove `deleteOrder()`.
- `src/Services/OrderService.php` — pass-through `cancelOrder()`; remove `deleteOrder()` (and its `triggerKitchenEvent('order.deleted', ...)` — replaced by an `'order.cancelled'` event so the kitchen's SSE-driven refresh still fires).
- `src/Controllers/OrderController.php` — remove `destroy()`; add `cancel()` with the `ModelNotFoundException`/`DomainException` catch order described above; add the same two catches to the existing `complete()`/`uncomplete()`.
- `src/Routes.php` — remove `$group->delete('/orders/{id}', ...)`; add `$group->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);`.
- `public/kitchen/app.js` — `deleteOrder()` → `cancelOrder()`, new endpoint, updated copy.
- `public/kitchen/index.php` — the modal's delete button label/handler updated to match.
- `public/api/docs/openapi.yaml` — new `/api/orders/{id}/cancel` path.
- `docs/architecture.md` — new "Order lifecycle" section (state diagram + the guard rules above), fulfilling the roadmap's "document" requirement, not just "enforce."
- `src/Services/ReportService.php`, `src/Services/KitchenService.php` — **no changes**; already correctly scoped (Problem, point 4), re-verified by inspection, not modified speculatively.
- Tests: `tests/Unit/OrderServiceTest.php` (constructor/signature only, if `deleteOrder` removal affects it — checked, it doesn't reference `deleteOrder`); new `tests/Unit/OrderRepositoryTest.php` cases for the guard behavior (extending the file spec 019 added, same real-SQLite-DB approach).

## Security considerations

Not applicable beyond what already applies to `/api/orders*` (intentionally public, trusted-network endpoints, unchanged by this spec) — no new input surface, no secrets involved.

## Backward compatibility

**Breaking**, consistent with specs 010/019's precedent in this same area: any API consumer calling `DELETE /api/orders/{id}` must switch to `POST /api/orders/{id}/cancel`. Behavior also changes semantically (the order is preserved, not destroyed) even for a consumer that adapts the URL/method alone. No stored data is lost — the opposite: cancelled orders are now preserved where they previously vanished. This spec's own `CHANGELOG.md` entry must call out both the removed endpoint and the behavior change.

## Acceptance criteria

1. `SHOW CREATE TABLE orders` (or equivalent) shows `status` as `enum('pending','done','cancelled')`.
2. `POST /api/orders/{id}/cancel` on a real `pending` order returns `200`; the order's `status` is `cancelled` afterward (verified by re-fetching it).
3. Calling `POST /api/orders/{id}/cancel` again on that same, now-`cancelled` order returns `409`.
4. `POST /api/orders/{id}/complete` and `POST /api/orders/{id}/uncomplete` on a `cancelled` order both return `409`, and the order's `status` remains `cancelled` afterward.
5. Any of `complete`/`uncomplete`/`cancel` on a nonexistent order id (e.g. `999999`) returns `404`.
6. `DELETE /api/orders/{id}` no longer routes to any handler — confirmed by inspecting `src/Routes.php` and by a real request: since `PATCH /api/orders/{id}` still exists on that same path, Slim correctly returns `405 Method Not Allowed` (not `404`) rather than routing to a delete handler.
7. A cancelled order does not appear in `GET /api/orders?status=pending` or `?status=done` for the same date.
8. `vendor/bin/phpunit` passes, including new tests for the guard behavior.

## Implementation plan

1. Write and apply `common/migrations/013_order_cancellation.sql`; verify via `SHOW CREATE TABLE orders` against the dev DB.
2. Update `Order` model.
3. Update `OrderRepository` (guards on `completeOrder`/`uncompleteOrder`, new `cancelOrder`, remove `deleteOrder`).
4. Update `OrderService` (`cancelOrder` pass-through + `'order.cancelled'` SSE event, remove `deleteOrder`).
5. Update `OrderController` (`cancel()`, `404`/`409` catches added to `complete()`/`uncomplete()`, remove `destroy()`).
6. Update `src/Routes.php`.
7. Update `public/kitchen/app.js` and `public/kitchen/index.php`.
8. Update `public/api/docs/openapi.yaml`.
9. Add the "Order lifecycle" section to `docs/architecture.md`.
10. Add/update unit tests; run `vendor/bin/phpunit`.
11. Manually verify every acceptance criterion against the real running stack (`curl`), since transition guards are exactly the kind of behavior a mocked-repository unit test can assert but not prove end-to-end.

## Testing and validation strategy

Unit-level (PHPUnit, extending `tests/Unit/OrderRepositoryTest.php`'s real-in-memory-SQLite approach from spec 019): each guard (`cancelOrder` on an already-cancelled order, `completeOrder`/`uncompleteOrder` on a cancelled order) throwing `\DomainException`; a happy-path cancel from both `pending` and `done`. Real verification against the running dev stack via `curl` for the full HTTP-status-code matrix (Acceptance criteria 2-6), the same approach used to validate spec 019's endpoints for real rather than only through mocks.

## Rollout and rollback

No feature flag — schema + code change behind existing `/api/orders*` endpoints, applied via `bin/migrate`. Rollback: revert the code changes and run a manual down-migration reverting the enum (safe only if no row has been set to `cancelled` yet — this project's migrations have no automatic rollback, per `CLAUDE.md`).

## Open questions

None blocking — both product decisions this spec depended on were resolved by the user before this draft was written (see Context).

## Task checklist

- [x] Migration `013_order_cancellation.sql` written, applied, verified
- [x] `Order` model updated
- [x] `OrderRepository` updated (guards + `cancelOrder`, `deleteOrder` removed)
- [x] `OrderService` updated (`cancelOrder`, `deleteOrder` removed)
- [x] `OrderController` updated (`cancel()`, `404`/`409` catches, `destroy()` removed)
- [x] `src/Routes.php` updated
- [x] Kitchen frontend updated (`app.js`, `index.php`)
- [x] `openapi.yaml` updated (new `/cancel` path, plus a pre-existing `pending`/`completed` vs. real `pending`/`done` doc bug fixed in the same schema while touching it)
- [x] `docs/architecture.md` "Order lifecycle" section added (also corrected the stale `Models/` count, missing `OrderNumberCounter` since spec 019)
- [x] Unit tests updated/added, `vendor/bin/phpunit` passing
- [x] Full acceptance-criteria matrix verified against the real running stack

## Implementation log

- 2026-09-05 — Both product decisions (minimal status set, soft-cancel replacing hard delete) were resolved by the user before this spec was drafted; `Status` set directly to `Approved` then `In Progress`, no separate approval round-trip needed.
- 2026-09-05 — Live DB check before writing the migration: `SELECT status, COUNT(*) FROM orders GROUP BY status` → only `done` (38) and `pending` (11), confirming `preparing`/`ready` were safe to drop with no data loss.
- 2026-09-05 — While documenting the `Order` schema/query-param enum in `openapi.yaml` for the new `cancelled` value, found it had *always* said `completed` instead of the real `done` (a pre-existing bug, not introduced by this spec) — fixed in the same edit since it's the exact same field.
- 2026-09-05 — `docs/architecture.md`'s `Models/` table said "8 Eloquent models" but spec 019 added a 9th (`OrderNumberCounter`) without updating it — corrected while adding the new "Order lifecycle" section to the same file.
- 2026-09-05 — Acceptance criterion 6 written as "404-route-not-found" turned out to be technically imprecise: since `PATCH /api/orders/{id}` still exists on that path, Slim correctly returns `405 Method Not Allowed` for a `DELETE` there, not `404`. Corrected the criterion's wording to match the real (and more semantically correct) behavior rather than leaving a written expectation that didn't match what was actually observed.
- 2026-09-05 — Full `git diff` self-review; no unrelated changes found. One order (id 82) was created and cancelled during live validation — left in place rather than deleted, since it's now a correctly-preserved cancelled order (exactly what this spec is for), not orphaned test debris like spec 019's cleanup case.

## Validation evidence

- **Acceptance criterion 1** — `SHOW COLUMNS FROM orders LIKE 'status'` → `enum('pending','done','cancelled')`. **Confirmed.**
- **Acceptance criterion 2** — `curl -X POST /api/orders/82/cancel` → `200 {"success":true,"message":"Order cancelled"}`; re-fetching order 82 confirmed absent from both `pending` and `done` listings (criterion 7, checked together). **Confirmed.**
- **Acceptance criterion 3** — same `curl` call repeated on order 82 → `409 {"error":"Este pedido já está cancelado."}`. **Confirmed.**
- **Acceptance criterion 4** — `POST /api/orders/82/complete` → `409 {"error":"Não é possível concluir um pedido cancelado."}`; `POST /api/orders/82/uncomplete` → `409 {"error":"Não é possível reabrir um pedido cancelado."}`. **Confirmed.**
- **Acceptance criterion 5** — `POST /api/orders/999999/cancel` → `404 {"error":"Pedido não encontrado"}`. **Confirmed.**
- **Acceptance criterion 6** — `DELETE /api/orders/82` → `405 Method Not Allowed` (Slim's own routing response; wording of this criterion corrected to match — see Implementation log). **Confirmed.**
- **Acceptance criterion 7** — `GET /api/orders?status=pending&date=2026-09-04` and `?status=done&date=2026-09-04` both returned `[]`, i.e. order 82 (cancelled) is absent from both. **Confirmed.**
- **Acceptance criterion 8** — pre-existing `pending`/`done` unit tests (`OrderServiceTest`) and manual `complete`/`uncomplete` behavior for non-cancelled orders unaffected; no regressions. **Confirmed.**
- `vendor/bin/phpunit` → `OK (22 tests, 44 assertions)` (18 pre-existing + 4 new guard tests in `OrderRepositoryTest`). **Confirmed.**
- `php -l` clean on every changed PHP file. **Confirmed.**
