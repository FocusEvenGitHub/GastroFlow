---
name: spec-review
description: Read-only consistency review of a spec against the real diff, the actual architecture, its own acceptance criteria, and its validation evidence. Never edits the spec, code, or the database — reports mismatches only.
---

# /spec-review

Input: the path to a spec file, e.g. `/spec-review specs/001-order-percentage-discount.md`, optionally followed by a diff target (a branch name, commit range, or PR number) to compare against.

- If a diff target is given, use it (`git diff <target>` / `git diff master...<target>` as appropriate).
- If none is given, default to the spec's `Related branch` field if it's set and exists locally (`git diff master...<branch>`); otherwise fall back to the current working tree (`git diff HEAD`, staged + unstaged combined).

Output: a report printed directly in the conversation, in the same language the user used. **This skill writes nothing to disk, ever** — not the spec, not a new file, not the database, not application code.

## The comparison chain

The review walks this chain, checking each adjacent link:

```
Spec  ↕  diff  ↕  arquitetura  ↕  acceptance criteria  ↕  validation evidence
```

## What this skill must do, in order

1. **Check Git state.** Run `git status`. Note the current branch and any unrelated pre-existing changes. Don't touch, stage, or discard anything.
2. **Read the spec file in full** — every section, not just metadata.
3. **Resolve the diff target** per the rules above and pull it (`git diff`, `git log`, `git show` as needed — read-only).
4. **Re-read the real, current architecture.** Don't trust `specs/000-project-baseline.md` blindly — it's a snapshot from 2026-08-05 and code may have moved since. Read `CLAUDE.md` for the confirmed layering rules, then read the actual files the spec's `Architecture and affected components` section names, plus whatever the diff actually touches.
5. **Link 1 — Spec ↔ diff.** For each item in `Implementation plan` and `Task checklist`, check whether the diff shows it done. Flag: items marked done with no corresponding diff hunk; diff hunks that touch files/behavior the spec never mentions (scope creep); anything in `Proposed behavior` that the diff contradicts.
6. **Link 2 — diff ↔ arquitetura.** Check the diff against GastroFlow's real layering and conventions from `CLAUDE.md`: does it introduce a Controller/Service/Repository/Validator/Middleware only where the pattern already exists for that domain (or where the spec explicitly justified a new one)? Does it follow the promoted-properties/`declare(strict_types=1)` conventions used in newer files? Does it change the DB schema anywhere outside a file in `common/migrations/`? Does it add a dependency or bump a Composer/Docker version without the spec calling for it?
7. **Link 3 — arquitetura ↔ acceptance criteria.** Check that `Acceptance criteria` are phrased against real, existing components/endpoints/behavior (not invented ones), and that they're objectively checkable (concrete input → concrete output/status/behavior), not vague language.
8. **Link 4 — acceptance criteria ↔ validation evidence.** For every acceptance criterion, check `Validation evidence` line by line:
   - Is there evidence for it at all?
   - Does the evidence's command/output actually demonstrate that specific criterion, or is it generic/unrelated?
   - Is the evidence plausible given this project has **no automated test infrastructure** (confirmed in `specs/000-project-baseline.md`)? Evidence describing a test suite run is a red flag to call out explicitly, not accept at face value.
   - **Independent re-verification, when safe:** if a piece of evidence is a non-destructive, idempotent check (e.g. `php -l` on a changed file, `composer validate`, a `GET` curl call, reading a file), you may re-run it and compare. Never re-run anything stateful or destructive (POST/PATCH/DELETE calls that mutate data, `bin/migrate`, `docker compose` commands, anything in this project's `ask`/`deny` permission lists) — for those, just check the evidence as recorded. Clearly label which results are "independently re-verified now" vs. "evidence as recorded, not re-run."
9. **Cross-check `Status` against reality.** If the spec claims `Verified` but a link above is broken (missing evidence, evidence that doesn't hold up, unmet criteria), say so plainly — this skill does not change `Status` itself, only reports the discrepancy.
10. **Never modify anything.** No edits to the spec file, no code changes, no commits, no migrations, no destructive or mutating commands.

## Guardrails

- If the diff target can't be resolved (branch doesn't exist, no changes found), report that plainly instead of fabricating a diff.
- If a section of the spec is missing entirely (not even "Not applicable"), flag it as a template-compliance problem, separate from the four comparison links.
- Cite real file paths (and line numbers where meaningful) for every claim in the report — don't assert something is missing/present without having actually read it.
- If code and spec disagree on a factual matter (e.g., spec says a component exists that doesn't), report the conflict — don't silently resolve it in either direction.

## Final report

Present, in the same language the user used:

- Spec reviewed, and the diff scope actually used.
- **Link 1 (Spec ↔ diff):** findings.
- **Link 2 (diff ↔ arquitetura):** findings.
- **Link 3 (arquitetura ↔ acceptance criteria):** findings.
- **Link 4 (acceptance criteria ↔ validation evidence):** a per-criterion table — Met / Not met / Partially met / No evidence / Evidence doesn't hold up — with citations.
- Whether the spec's current `Status` matches what the review found, and why if not.
- Nothing was written to disk (restate this explicitly, since it's a hard constraint of this skill).
