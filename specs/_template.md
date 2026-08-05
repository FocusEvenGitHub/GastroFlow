# Spec NNN — Feature name

<!--
Copy this file to specs/NNN-short-feature-name.md and fill in every section.
Sections that do not apply must say "Not applicable" plus a short reason — do not delete them.
Acceptance criteria must be objectively checkable. Avoid vague language such as
"works correctly", "improves the system", or "is user-friendly".
-->

## Metadata

- Status: Draft
- Created: YYYY-MM-DD
- Updated: YYYY-MM-DD
- Owner:
- Related issue:
- Related branch:

## Context

Why this change is being proposed now; what prompted it.

## Problem

The concrete problem or gap being addressed, described in terms of current, observable behavior.

## Goals

What this change must achieve, as a short bullet list.

## Non-goals

What this change explicitly will not do, to keep scope bounded.

## Current behavior

What the system does today, confirmed by reading the relevant code (cite file paths). Distinguish confirmed-in-code from assumed.

## Proposed behavior

What the system should do after this change, described precisely enough to implement from.

## Functional requirements

Numbered, testable statements of required behavior.

## Non-functional requirements

Performance, security, compatibility, observability, or other quality requirements, if any.

## User flows

Step-by-step flows for the affected user roles (cashier, kitchen, admin, API consumer, etc.).

## API changes

New/changed endpoints, request/response shapes, status codes. Not applicable if no API surface changes.

## Data model and migrations

New/changed tables, columns, or Eloquent models, and the migration file(s) that will implement them (see `common/migrations/`). Not applicable if no data model changes.

## Architecture and affected components

Which Controllers, Services, Repositories, Models, Validators, Middleware, or frontend files are affected, following the project's real layering (not an idealized one).

## Security considerations

Authentication, authorization, input validation, secrets handling. Not applicable only with a stated reason.

## Backward compatibility

Impact on existing API consumers, stored data, or workflows, and how it's handled.

## Acceptance criteria

Objectively verifiable statements (specific input → specific output/status/behavior). This is what `/spec-implement` will use to decide between `Implemented` and `Verified`.

## Implementation plan

Small, ordered steps. Each step should be independently reviewable.

## Testing and validation strategy

How each acceptance criterion will be checked. State plainly if no automated test infrastructure exists for the affected area and what manual/alternative verification will be used instead.

## Rollout and rollback

How the change is deployed/enabled, and how to revert it if something goes wrong. Not applicable only with a stated reason.

## Open questions

Unresolved questions. Mark any that are **blocking** — a blocking open question here prevents `/spec-implement` from treating invocation as approval while the spec is in `Draft`.

## Task checklist

- [ ] Step 1
- [ ] Step 2

Kept in sync with actual implementation progress, not the original plan.

## Implementation log

Chronological notes on real decisions, trade-offs, and deviations made during implementation. Filled in during `/spec-implement`, not before.

## Validation evidence

Commands run, their actual output, and how each maps to an acceptance criterion. Filled in during `/spec-implement`. A spec cannot be marked `Verified` without this section backing up every acceptance criterion.
