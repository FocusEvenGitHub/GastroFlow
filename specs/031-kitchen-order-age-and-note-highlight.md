# Spec 031 — Kitchen order age (timezone) and note highlight

<!-- Reduced spec: small, obvious fix (see CLAUDE.md "Spec workflow"). -->

## Metadata

- Status: Implemented
- Created: 2026-09-13
- Updated: 2026-09-13
- Owner: Henry
- Related issue: Not applicable (client feedback after spec 030)
- Related branch: master (working tree, not committed)

## Context

Client feedback: a just-created order shows "3h" on the Kitchen card; and item notes should stand out (red) in both light and dark themes.

## Problem

- `public/kitchen/app.js` `timeAgo()` parsed `created_at` as `new Date(str.replace(' ', 'T') + 'Z')`. `created_at` (`OrderRepository::getOrdersByStatus()`, `toDateTimeString()`) is server local time (`America/Sao_Paulo`, UTC−3, per `docker-compose.yml` `TZ` and PHP timezone ini) with no offset, so appending `Z` made every order look 3 hours older. Pre-existing bug, not introduced by spec 030.
- `.item-note` in `public/kitchen/index.php` used muted grey italic text — easy to miss.

## Goals

- Kitchen card age reflects the real elapsed time regardless of the browser's timezone.
- Item notes render in a prominent red that works in light and dark themes.

## Non-goals

- No change to the existing `created_at`/`updated_at` string format (kept for backward compatibility).
- No change to notes styling outside the Kitchen screen.

## Current behavior

See Problem (confirmed in code).

## Proposed behavior

- `GET /api/orders` orders gain `created_at_iso` (ISO 8601 with offset, e.g. `2026-09-13T00:56:50-03:00`, via Carbon `toIso8601String()`).
- `timeAgo(order)` uses `new Date(order.created_at_iso)`; both kitchen templates call `timeAgo(order)`.
- `.item-note`: bold, `color: var(--danger)` (`#d63031` light / `#f85149` dark, already defined in `public/assets/css/style.css`), red left border and a translucent red background (separate tint for `[data-theme="dark"]`), indented under the item.

## Functional requirements

1. `GET /api/orders` includes `created_at_iso` with a UTC offset for every order.
2. Kitchen card age for an order created N minutes ago shows ≈ N minutes.
3. Kitchen item notes are red in light and dark themes.

## Non-functional requirements

Not applicable — display-only change plus one additive response field.

## User flows

Kitchen: new order arrives → card shows "agora"/"Xmin" → notes appear as a red highlighted line under the item.

## API changes

`GET /api/orders`: additive `created_at_iso` (documented in `public/api/docs/openapi.yaml`).

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

`src/Repositories/OrderRepository.php`, `public/kitchen/app.js`, `public/kitchen/index.php`, `public/api/docs/openapi.yaml`, `tests/Unit/OrderRepositoryTest.php`.

## Security considerations

Not applicable — no new input; timestamps were already exposed.

## Backward compatibility

Additive field only; `created_at` unchanged. No other frontend parsed `created_at` (`grep` in `public/`: only the kitchen used it).

## Acceptance criteria

1. AC1 — `created_at_iso` present with offset and represents the same instant as `created_at` (unit test + live API).
2. AC2 — For an order created 3 minutes before the check, the computed age from `created_at_iso` is 3 minutes (live).
3. AC3 — Notes render red in light and dark themes (manual).
4. AC4 — Full PHPUnit suite passes.

## Implementation plan

1. Add `created_at_iso` to the repository listing + OpenAPI.
2. Switch `timeAgo()` to it.
3. Restyle `.item-note`.
4. Unit test; run suite; live check.

## Testing and validation strategy

Unit test for the field shape/instant; live API check against the container clock; manual visual check of note colors (no UI test infrastructure).

## Rollout and rollback

Deploy code; no migration. Revert the commit to roll back.

## Open questions

None.

## Task checklist

- [x] `created_at_iso` in `getOrdersByStatus()` + OpenAPI
- [x] `timeAgo(order)` using `created_at_iso`
- [x] `.item-note` red highlight (light/dark)
- [x] Unit test + suite + live check
- [ ] Manual visual check (AC3)

## Implementation log

- 2026-09-13 — Added a new field instead of changing `created_at`'s format, to avoid breaking any consumer of the existing string.
- 2026-09-13 — Reused `--danger`, which the theme already redefines for dark mode, so only the background tint needed a dark-specific rule.

## Validation evidence

- AC1/AC2 — live, inside `restaurant_web`: container clock `2026-09-13T00:59:34-03:00`; `GET /api/orders?status=all` → `#115 created_at=2026-09-13 00:56:50 created_at_iso=2026-09-13T00:56:50-03:00 age_min=3`.
- Root cause confirmed: `node` parsing `"2026-09-13 00:52:00"` with the old `+ 'Z'` vs `"2026-09-13T00:52:00-03:00"` → differ by exactly `3` hours.
- `node --check public/kitchen/app.js` → OK.
- AC1 — `OrderRepositoryTest::testListedOrdersExposeCreatedAtWithTimezoneOffset` passes (`--filter OrderRepositoryTest` → `OK (27 tests, 53 assertions)`).
- AC4 — `docker compose exec web vendor/bin/phpunit` → `OK (124 tests, 185 assertions)`.
- AC3 — not yet verified visually.
