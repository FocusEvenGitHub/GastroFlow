# Spec 036 — Kitchen shows tomorrow's date after 21:00

<!-- Reduced spec: small, obvious fix (see CLAUDE.md "Spec workflow"), same shape as spec 031. -->

## Metadata

- Status: Implemented
- Created: 2026-09-23
- Updated: 2026-09-23
- Owner: Henry
- Related issue: Not applicable (reported by the owner in session)
- Related branch: `036`

## Context

Reported by the owner: the Kitchen screen pulls **the next day's date instead of the current
one**. Reproducible at the time of the report — local clock `2026-09-23 23:28`, UTC
`2026-09-24 02:28`.

## Problem

`public/kitchen/app.js` built today's date with `new Date().toISOString().split('T')[0]` in two
places: the initial `selectedDate` (line 14) and the `_today()` helper (line 28).

`toISOString()` converts to **UTC**. In `America/Sao_Paulo` (UTC−3) every local time from 21:00
onward is already the next day in UTC, so from 21:00 to midnight the Kitchen:

- initialised its date picker to tomorrow and fetched `/api/orders?date=<tomorrow>`,
  `/api/kitchen/food-summary?date=<tomorrow>` — showing an empty screen during the evening
  service, which is exactly when the restaurant is busiest;
- compared `this.selectedDate === this._today()` in all five SSE listeners. Both sides were
  wrong in the same direction so the comparison still matched, which is why live updates kept
  working and hid the bug.

The same `toISOString()` pattern existed in `public/admin/reports.js:50` for `dateTo`, with the
same one-day-forward effect on the default report range.

**Not affected, checked rather than assumed:** `reports.js:49`'s `dateFrom` is built from
`new Date(year, month, 1)` — local midnight, which in UTC−3 is 03:00 UTC the same day, so it
never rolled over. It was changed anyway for consistency, not because it was broken. This
corrects a claim made out loud during investigation that `dateFrom` could land on the previous
month; that would be true for UTC+X, not here.

Server-side `business_date` was **not** involved: it is computed in PHP with the container's
`America/Sao_Paulo` timezone. This is purely a browser-side defect.

## Goals

- The Kitchen defaults to the operator's local date at any hour.
- The Reports default range ends on the local date.

## Non-goals

- No change to `business_date` or any server-side date handling.
- No shared frontend date utility. `docs/ROADMAP.md`'s `v1.9.0` item "Shared frontend
  infrastructure" is where a common helper belongs; introducing one here would spread this fix
  across files it does not need to touch.

## Current behavior

Confirmed by reading the code and by running the comparison below:

```text
agora (local)      : Wed Sep 23 2026 23:28:50
ANTES toISOString  : 2026-09-24      <- tomorrow
DEPOIS local       : 2026-09-23      <- correct
```

## Proposed behavior

Build the date from the browser's **local** calendar components
(`getFullYear`/`getMonth`/`getDate`), zero-padded, never through UTC.

## Functional requirements

1. FR1 — `public/kitchen/app.js` exposes `localDateString(date = new Date())` returning
   `YYYY-MM-DD` in the browser's timezone.
2. FR2 — `selectedDate` and `_today()` both use it; no `toISOString()` remains in that file.
3. FR3 — `public/admin/reports.js`'s `setCurrentMonth()` uses a local-date helper for both ends
   of the range.

## Non-functional requirements

Not applicable beyond correctness — no performance, security or compatibility dimension to a
date-formatting change.

## User flows

Kitchen operator opens the screen at 22:00 on a service day and sees that day's pending orders,
instead of an empty screen for tomorrow.

## API changes

Not applicable — no endpoint changes; only the `date` query parameter's value is now correct.

## Data model and migrations

Not applicable — no schema or data change.

## Architecture and affected components

`public/kitchen/app.js` and `public/admin/reports.js`. No backend file is touched.

## Security considerations

Not applicable — no authentication, authorization, input validation or secret is involved.

## Backward compatibility

None at risk. The `date` parameter's format is unchanged (`YYYY-MM-DD`); only its value is
corrected. Stored orders are unaffected, since `business_date` is server-side.

## Acceptance criteria

- AC1 — With the local clock at `2026-09-23 23:28` (UTC `2026-09-24`), the helper returns
  `2026-09-23`, while the previous expression returns `2026-09-24`.
- AC2 — `grep -c toISOString public/kitchen/app.js` returns `0`.
- AC3 — `node --check` passes on both changed files.
- AC4 — The existing PHPUnit suite is unaffected (no PHP changed).

## Implementation plan

1. Add `localDateString()` to `public/kitchen/app.js`; use it for `selectedDate` and `_today()`.
2. Add `_localDate()` to `public/admin/reports.js`; use it in `setCurrentMonth()`.
3. Verify with a direct before/after comparison at a rolled-over hour.

## Testing and validation strategy

There is no JavaScript test infrastructure in this project — the PHPUnit suite (Smoke, Unit,
Integration) covers PHP only, and this change touches no PHP. Validation is therefore
`node --check` for syntax plus a direct evaluation of the old and new expressions at an hour
where UTC and local differ, which is recorded below. The bug's window (21:00–00:00 local) was
open while this was implemented, so the comparison is real rather than simulated.

## Rollout and rollback

Static assets served from `public/`; a browser refresh picks them up. Rollback is reverting the
commit.

## Open questions

None.

## Task checklist

- [x] 1. `localDateString()` in `public/kitchen/app.js`, used in both places
- [x] 2. `_localDate()` in `public/admin/reports.js`
- [x] 3. Before/after comparison recorded

## Implementation log

- **2026-09-23 — Root cause is `toISOString()`, and the SSE comparison is why it went
  unnoticed.** All five SSE listeners compare `selectedDate === _today()`. Both sides used the
  same broken expression, so they matched and live refresh kept working — the screen was simply
  querying the wrong day. A bug that breaks the data while leaving the mechanism looking healthy.
- **2026-09-23 — `reports.js` had the same defect, but only on one of the two dates.**
  `dateTo` (built from `now`) rolled over; `dateFrom` (local midnight of the 1st) did not, in
  UTC−3. Both were routed through the helper for consistency, and the spec records that only one
  was actually broken rather than overstating the fix.

## Validation evidence

- **AC1** — evaluated at local `Wed Sep 23 2026 23:28:50`, UTC `2026-09-24 02:28`:
  `ANTES toISOString: 2026-09-24` / `DEPOIS local: 2026-09-23`. For `dateFrom`, both old and new
  returned `2026-09-01`, confirming it was never affected.
- **AC2** — `grep -n "toISOString" public/kitchen/app.js` returns only the explanatory comment
  on line 4, no executable use.
- **AC3** — `node --check public/kitchen/app.js` and `node --check public/admin/reports.js` →
  both clean. (`node` is not installed inside the `web` container; the checks ran on the host.)
- **AC4** — no PHP file changed, so the suite is unaffected; re-run recorded in the final report.

**Not validated:** the fix was not observed in a browser at a rolled-over hour. The expression's
behavior is proven directly, but the on-screen result was not watched.
