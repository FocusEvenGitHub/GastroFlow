---
name: spec-plan
description: Turn a plain-language feature, fix, or improvement request into a new spec file under specs/, ready for review and later implementation via /spec-implement. Investigates the codebase but never edits application code, the database, or makes commits.
---

# /spec-plan

Input: a plain-language description of a feature, bug fix, or improvement (e.g. `/spec-plan permitir que o caixa aplique desconto percentual em um pedido`).

Output: a new file `specs/NNN-short-feature-name.md`, filled in, left uncommitted for the user to review.

## What this skill must do, in order

1. **Check Git state.** Run `git status`. Note any pre-existing uncommitted changes so you don't confuse them with your own additions; don't touch or stage them.
2. **Find the next spec number.** List `specs/*.md`, find the highest existing `NNN` prefix (ignore `_template.md` and `specs/README.md`), and use the next integer, zero-padded to 3 digits. Derive a short, hyphenated `feature-name` from the request.
3. **Investigate the real codebase before writing anything.** Read `CLAUDE.md` and `specs/000-project-baseline.md` first for the confirmed architecture. Then find and read the actual files relevant to this request — Controllers, Services, Repositories, Models, Validators, Middleware, routes (`src/Routes.php`), and any relevant frontend files under `public/`. Do not guess at file names; search for them (Glob/Grep).
4. **Identify current behavior and affected components.** Write down, with file paths, what exists today and what would need to change. If something described in the request doesn't correspond to any real code path, say so plainly rather than assuming a component exists.
5. **Identify risks, dependencies, and compatibility concerns.** E.g.: does this touch the `orders`/`menu_items`/`jobs` tables? Does it cross the auth boundary (`JwtMiddleware`)? Does it affect the print/job queue? Does it break an existing API consumer?
6. **Create the spec file** by copying `specs/_template.md` to `specs/NNN-short-feature-name.md`.
7. **Fill in every section.** In particular:
   - `Metadata`: `Status: Draft`, today's date for `Created`/`Updated`, owner left as given or blank, related issue/branch as given or `Not applicable`.
   - `Functional requirements` and `Acceptance criteria` must be objectively checkable — concrete inputs, outputs, HTTP status codes, or observable behavior. Reject vague phrasing like "works correctly" or "improves the system" in your own draft; rewrite it before saving.
   - `Implementation plan`: small, independently reviewable steps, ordered, following the project's real layering (don't invent a Repository/Validator for a domain that doesn't have one unless the request genuinely requires it).
   - `Testing and validation strategy`: state plainly that this project has **no automated test infrastructure today** (confirmed in `specs/000-project-baseline.md`) — describe what manual/API-level verification (e.g. curl commands against real endpoints) would substitute for it, unless the request itself is to add test infrastructure.
   - `Open questions`: record anything genuinely unresolved. Mark any that would block starting implementation as **blocking** — this is what `/spec-implement` checks before treating its own invocation as approval.
   - Any section that truly doesn't apply gets `Not applicable` plus one short reason — never delete a section.
8. **Do not modify application code, configuration, or the database.** This skill only creates the spec file (and, if strictly necessary for planning clarity, no other files at all — keep it to the one spec).
9. **Do not run migrations, install dependencies, or start containers.**
10. **Do not commit.** Leave the new file unstaged for the user to review.
11. **Finish by reporting**, in the same language the user asked in:
    - the path of the spec file created;
    - a one-line summary of what it covers;
    - the next recommended command: `/spec-implement specs/NNN-short-feature-name.md`.

## Guardrails

- If the request is so vague that meaningful requirements/acceptance criteria can't be written, say so and ask a clarifying question instead of inventing scope.
- If the request describes behavior that contradicts `specs/000-project-baseline.md`, note the discrepancy in `Context`/`Current behavior` rather than silently assuming the baseline is wrong.
- Never mark anything `Verified` or `Implemented` — a freshly planned spec is always `Draft`.
