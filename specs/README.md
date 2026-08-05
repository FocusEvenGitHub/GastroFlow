# specs/ — Spec-Driven Development for GastroFlow

This folder is the source of truth for *what* is being built and *why*, before any code changes happen. It exists so that non-trivial work on GastroFlow is planned in writing, reviewed like any other change (via Git), and kept in sync with the code as it evolves.

## Spec vs. regular documentation

- **A spec** describes an intended change: a problem, a goal, the proposed behavior, acceptance criteria, and — as work progresses — the actual implementation decisions and validation evidence. It is written *before* code changes for anything non-trivial, and updated *during* implementation. It has an owner and a lifecycle status.
- **Regular documentation** (README.md, CLAUDE.md, CHANGELOG.md, COMMIT_CONVENTION.md) describes the system *as it stands* or *how to work in this repo* in general. It doesn't track a single change from proposal to verification.

Rule of thumb: if you're about to change behavior, plan it in a spec first. If you're describing standing project conventions, it belongs in `CLAUDE.md` or `README.md` instead.

## Spec lifecycle

A spec moves through these states, recorded in its `Metadata` section:

1. **Draft** — written, not yet approved for implementation. May contain open questions.
2. **Approved** — the user has explicitly approved it, or has invoked `/spec-implement` on it directly (see "Approval shortcut" below).
3. **In Progress** — implementation has started.
4. **Implemented** — the code changes are complete, but full validation evidence is not yet available (e.g. a test couldn't be run, or manual verification is still pending).
5. **Verified** — the code changes are complete *and* the acceptance criteria have documented validation evidence (commands run, outputs observed, or an explicit note on why a criterion cannot be automatically verified).
6. **Cancelled** — the spec was abandoned before or during implementation. The reason should be recorded in the spec itself.

Status only ever moves forward, except back to `Cancelled` at any point, or back to `Draft`/`Approved` if requirements change materially before implementation starts.

### Approval shortcut

Explicitly invoking `/spec-implement specs/NNN-name.md` on a spec that is still in `Draft` may be treated as the user's approval to proceed, **provided the spec has no unresolved blocking questions recorded in its `Open questions` section**. If a blocking question exists, implementation must stop and ask before proceeding. This lets small, obvious changes skip a separate "please approve this spec" round-trip.

## Naming convention

```
specs/NNN-short-feature-name.md
```

- `NNN` is a zero-padded, monotonically increasing 3-digit number (`001`, `002`, ...), never reused even if a spec is cancelled.
- `short-feature-name` is lowercase, hyphen-separated, and describes the change, not the ticket or person (e.g. `specs/001-order-percentage-discount.md`).
- `specs/000-project-baseline.md` is reserved for the one-time snapshot of the system as of adopting this workflow — it is not a feature spec.
- `specs/_template.md` is the template all new specs are copied from; it is never itself a spec.

## Creating a new spec

1. Check `git status` and look at the existing files in `specs/` to find the next free `NNN`.
2. Copy `specs/_template.md` to `specs/NNN-short-feature-name.md`.
3. Fill in every section. Sections that don't apply must say `Not applicable` plus a short reason — never delete a section.
4. Write acceptance criteria that are objectively checkable (specific inputs/outputs, HTTP status codes, observable behavior) — avoid vague language like "works correctly" or "improves the system".
5. Set `Status: Draft` in the metadata.

In practice, use the `/spec-plan` skill to do steps 1–4 for you from a plain-language description of the feature, fix, or improvement.

## Implementing a spec

Use the `/spec-implement` skill, passing the spec's path (e.g. `/spec-implement specs/001-order-percentage-discount.md`). It reads the spec, checks for blocking open questions, moves the status to `In Progress`, implements one step at a time following the repository's real architecture, and keeps the spec updated as it goes.

## Updating a spec during implementation

- If reality diverges from the plan (a different approach is needed, a requirement turns out to be wrong), update the relevant sections of the spec itself — do not silently implement something different from what's written.
- Record meaningful decisions, trade-offs, and deviations in the `Implementation log` section as they happen, not retroactively from memory at the end.
- Keep the `Task checklist` in sync with what has actually been done, not what was originally planned.
- Only move `Status` to `Verified` once the `Validation evidence` section actually backs up the acceptance criteria — do not mark it `Verified` on trust.

## Keeping code and spec in sync

Whoever implements a spec is responsible for making sure the spec reflects what was actually built by the time it reaches `Implemented`/`Verified`. If the code and an approved spec disagree, that's a conflict to report and resolve explicitly (update the spec, or fix the code) — never silently pick one side.
