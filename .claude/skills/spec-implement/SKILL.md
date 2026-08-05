---
name: spec-implement
description: Implement a spec from specs/ step by step, following GastroFlow's real architecture, keeping the spec's checklist/log/validation sections in sync with what was actually done. Never commits, never hides failures or unmet requirements.
---

# /spec-implement

Input: the path to a spec file, e.g. `/spec-implement specs/001-order-percentage-discount.md`.

## What this skill must do, in order

1. **Check Git state.** Run `git status`. If there are unrelated pre-existing uncommitted changes, note them and don't fold them into this work; don't discard anything.
2. **Read the spec file in full.** Not just the metadata — every section.
3. **Validate it against `specs/_template.md`.** All template sections must be present (filled in, or `Not applicable` + reason). If sections are missing outright, stop and report which ones before proceeding — don't silently fill them in with invented content.
4. **Check `Open questions` for blocking items.**
   - If any open question is explicitly marked blocking (or is clearly a decision only the user can make — e.g. ambiguous business rule, missing credential, conflicting requirement), **stop and ask** before writing any code.
   - If the spec's `Status` is `Draft` and there are no blocking open questions, treat this explicit `/spec-implement` invocation as the user's approval to proceed — set `Status: Approved` then immediately `Status: In Progress` in the same pass, and say so in your summary.
   - If `Status` is already `Approved` or `In Progress`, just move it to `In Progress` if it isn't already.
5. **Re-investigate the affected files before editing.** Don't rely solely on what the spec says — read the current, real state of every file the spec's `Architecture and affected components` section names, plus `CLAUDE.md` and `specs/000-project-baseline.md`. Code may have moved on since the spec was written; if it conflicts with the spec, stop and report the conflict rather than silently picking spec-over-code or code-over-spec.
6. **Implement one step at a time**, in the order given by `Implementation plan`, following GastroFlow's real layering (only touch/create a Repository, Validator, Service, etc. where the pattern already exists for that domain, or where the spec explicitly calls for introducing one).
7. **Stay in scope.** Don't refactor, rename, or "improve" adjacent code the spec didn't ask about. Don't add dependencies, bump Composer/Docker versions, or change the DB schema outside a migration file in `common/migrations/`.
8. **Update the spec as you go, not just at the end:**
   - Check off `Task checklist` items as they're actually completed (not preemptively).
   - Add entries to `Implementation log` for real decisions and deviations, when they happen.
9. **Tests**: this project has **no existing automated test infrastructure** (confirmed in `specs/000-project-baseline.md` — no `phpunit.xml`, no `tests/`, no `phpunit/phpunit` dependency). If the spec's scope is specifically to add test infrastructure, do that per its plan. Otherwise, do not fabricate a test suite — say explicitly in `Validation evidence` that no test infrastructure exists and describe what manual verification (e.g. real `curl` calls against the running app, if it's already up) was used instead, or that verification could not be executed if the app isn't running.
10. **Run whatever validation is genuinely available** — e.g. `php -l` on changed files for a syntax check, `composer validate`, reading the diff, manually tracing the new code path. Do not run destructive DB operations, do not start/stop containers or install packages unless the spec explicitly required it and the user is aware.
11. **Review your own diff** (`git diff`) before finishing. Fix anything wrong you find; note anything you deliberately left as-is and why.
12. **Fill in `Validation evidence`** with the actual commands run and their actual output, mapped to each acceptance criterion. Never write evidence for something you didn't run.
13. **Set final `Status`:**
    - `Implemented` — code changes are complete but full validation evidence for all acceptance criteria isn't available (e.g. couldn't run the app, a criterion needs manual QA not yet done).
    - `Verified` — every acceptance criterion has real, recorded validation evidence.
    - Never mark `Verified` on trust alone.
14. **Never commit, push, or change branches.** Leave everything in the working tree.
15. **Never hide**: a failed check, a command that couldn't be run, an unmet requirement, a difference between spec and actual implementation, the absence of test infrastructure, or any manual step still required from the user. Surface all of these explicitly in the final summary even if it makes the result look incomplete.

## Final report

End by presenting, in the same language the user used:

- files changed (and why, in one line each);
- what was actually validated, and how (commands + real results);
- what could not be validated, and why;
- remaining risks or pending items;
- the spec's final `Status` and whether it matches reality.
