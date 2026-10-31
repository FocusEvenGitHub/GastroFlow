# 📐 Commit Convention — GastroFlow

This document defines the commit message standard for the **GastroFlow** project, ensuring a clean, semantically rich, and professional history.

---

## 🧱 Message Structure
```
<type>(<scope>): <short description>

[optional body with more details]

[optional footer: BREAKING CHANGE, issues, etc.]
```


### Full example:
````
✨ feat(orders): add order status history tracking

Add a new table order_status_logs to record every status change.
Each log stores the old status, new status, timestamp, and user who made
the change. This allows the kitchen and admin to audit order progress.

Closes #42
````

---

## 📦 Commit Types

| Type         | Emoji                | Usage                                                  |
|--------------|----------------------|--------------------------------------------------------|
| `feat`       | ✨ `:sparkles:`     | New feature                                             |
| `fix`        | 🐛 `:bug:`          | Bug fix                                                 |
| `refactor`   | ♻️ `:recycle:`      | Code refactor (no behavior change)                     |
| `docs`       | 📝 `:memo:`         | Documentation (README, comments, guides)                |
| `style`      | 💄 `:lipstick:`     | Formatting, spacing, semicolons (no logic change)      |
| `test`       | ✅ `:white_check_mark:` | Adding or fixing tests                              |
| `chore`      | 🔧 `:wrench:`       | Build, config, dependency tasks                         |
| `perf`       | ⚡ `:zap:`          | Performance improvements                                |
| `ci`         | 👷 `:construction_worker:` | CI/CD pipelines, GitHub Actions                  |
| `revert`     | ⏪ `:rewind:`       | Revert a previous commit                                |
| `security`   | 🔒 `:lock:`         | Security fixes                                          |
| `wip`        | 🚧 `:construction:` | Work in progress                                        |
| `ui`         | 🎨 `:art:`          | Visual changes / CSS / layout                           |
| `database`   | 🗃️ `:card_file_box:` | Migrations, seeds, schema                              |
| `api`        | 🔌 `:electric_plug:` | Endpoints, responses, contracts                        |
| `docker`     | 🐳 `:whale:`        | Dockerfile, compose, environment                        |

---

## 🎯 Scopes (optional, but recommended)

Use scopes to indicate the affected area of the project:

| Scope       | Area                                |
|-------------|--------------------------------------|
| `api`       | Backend API (Slim, controllers)     |
| `admin`     | Admin panel                         |
| `cashier`   | Cashier interface                   |
| `kitchen`   | Kitchen interface                   |
| `db`        | Database (schema, migrations)       |
| `docker`    | Containerization                    |
| `auth`      | Authentication/authorization        |
| `menu`      | Menu (items, categories)            |
| `orders`    | Orders                              |
| `validator` | Data validation                     |
| `config`    | General settings                    |

---

## ✅ Golden Rules

1. **Use the imperative mood**: "add", not "added" or "adds".
2. **Maximum 72 characters** on the title line.
3. **Separate title and body** with a blank line.
4. **Use the body** to explain **what** and **why**, not "how".
5. **Reference issues** in the footer: `Closes #42`, `Refs #17`.
6. **One commit = one logical change** (don't mix a refactor with a feature).

---

## 💡 Real-world examples

### ✅ Good commits
````
✨ feat(orders): add endpoint to list orders by table number

🐛 fix(api): return 400 when menu item ID is invalid on order creation

♻️ refactor(menu): extract ItemRepository from MenuService for single responsibility

📝 docs(readme): add Docker setup and API endpoint documentation

🔒 security(auth): add JWT token validation on admin routes

🗃️ db: add order_status_logs table and migration
````

### ❌ Bad commits (avoid)

````
fix
tweaks
final commit
WIP (no description)
fixing some stuff
````

---

## 🚀 How to use emojis in the terminal

In Git Bash / Linux / Mac:

```bash
git commit -m "✨ feat(orders): add status history"
```
Or with the emoji shortcode:
````bash
git commit -m ":sparkles: feat(orders): add status history"
````

---

## 🏷️ Release & Changelog Workflow

*Rewritten 2026-10-31 (spec 072). The previous version described a `main` branch, a version derived from commits by SemVer arithmetic and an `Added`/`Fixed` changelog format — none of which this project uses.*

### 🔢 Which version

The version comes from [`docs/ROADMAP.md`](ROADMAP.md), not from the commits:

- a **milestone** (`v1.9.0`, `v2.0.0`, …) is tagged only when **every** item of that milestone is done;
- work outside the milestones (client requests) gets a **patch** tag kept below the next reserved milestone number (as `v1.7.1` and `v1.8.1`–`v1.8.3` did);
- release candidates use a suffix: `v2.0.0-rc.1`.

A tag is always created by the owner, after explicit confirmation — never by automation.

### 📝 Changelog rules

1. `CHANGELOG.md` (root) is curated by hand, **one entry per spec, inside that spec's pull request**, under the in-progress heading: the milestone's (`## v1.9.0 — Community Productization (em andamento)`) or `## Não lançado` for out-of-milestone work.
2. Headings inside a version are in Portuguese: `### Segurança`, `### Novidades`, `### Correções`, `### Documentação`, `### Infraestrutura / Qualidade`.
3. A released version's heading is **`## vX.Y.Z (YYYY-MM-DD) — Title`**. The release workflow copies the body of exactly that section into the GitHub Release; a section without the date is refused (below).

### ⚠️ Upgrade notes in `CHANGELOG.md`

When a change needs something beyond the normal update ([`docs/upgrading.md`](upgrading.md)) — a new `.env` variable, a changed port, a manual step — say so in a `⚠️` note under the entry and link `docs/upgrading.md`. Migrations no longer need a note of their own: since v1.9 the production compose runs them in the `migrate` service before the new code starts (spec 066). *(Corrected 2026-10-31, spec 072: this rule used to say the containers run the code straight from the repository folder — true until spec 066 put the code inside the image.)*

### 🚀 Cutting a release

1. **Release PR** — in the last spec PR of the release (or a release PR if none is open):
   - rename the in-progress heading to `## vX.Y.Z (YYYY-MM-DD) — Title` (for client work: move the `Não lançado` entries under the new heading);
   - bump the version where the docs state it (`docs/ROADMAP.md`, `README.md`).

   Check the notes locally before merging:

   ```bash
   .github/scripts/release-notes.sh --title vX.Y.Z   # "vX.Y.Z — Title"
   .github/scripts/release-notes.sh vX.Y.Z           # the GitHub Release body
   ```

2. **Merge** the PR into `master`.
3. **Tag** — only with the owner's confirmation, on the merged `master`:

   ```bash
   git checkout master && git pull --ff-only
   git tag -a vX.Y.Z -m "vX.Y.Z — Title"
   git push origin vX.Y.Z
   ```

4. **The `Release` workflow** (`.github/workflows/release.yml`) does the rest, each step only if the previous one passed:

   | Job | What it does |
   |---|---|
   | `test` | the full CI suite (`ci.yml`) on the tagged commit |
   | `notes` | refuses the release if `CHANGELOG.md` has no dated `## vX.Y.Z (…)` section |
   | `image` | builds the Docker image (amd64, `/version` answers the tag) and pushes it to `ghcr.io/focusevengithub/gastroflow` |
   | `release` | creates the GitHub Release with the CHANGELOG section as notes |

   Nothing is published if `test` or `notes` fails.

### 🐳 Image tags

| Tag pushed | Image tags |
|---|---|
| `v1.9.0` (highest of its line) | `1.9.0`, `1.9`, `1` |
| `v1.8.4` after `v1.9.0` exists | `1.8.4`, `1.8` — `1` stays on `1.9.0` |
| `v2.0.0-rc.1` | `2.0.0-rc.1` only |

There is no `latest`. `.github/scripts/image-tags.sh vX.Y.Z` prints what a tag would publish. A pre-release becomes a GitHub *pre-release*; the GitHub *Latest* badge goes to the highest stable tag.

**First publish only:** a new GHCR package starts **private**. Make it public once: GitHub › your profile › Packages › `gastroflow` › Package settings › Change visibility › Public. The workflow can't do it.

### 🔁 When a release fails

- `test` failed: fix through a PR, then move the tag to the fixed commit — `git tag -d vX.Y.Z`, `git push origin :refs/tags/vX.Y.Z`, tag again and push. Nothing had been published.
- `notes` failed: the CHANGELOG section is missing or undated — same: fix through a PR, re-create the tag on the fixed commit.
- `image` or `release` failed on something transient (registry, network): **Re-run failed jobs** in the Actions tab. Re-running is safe — the image tags are overwritten with the same image, and an existing release gets its notes updated instead of a second release.
- Removing a published release: `gh release delete vX.Y.Z` and delete the image versions in the package settings. Floating image tags (`X.Y`, `X`) only move forward; to point them back, re-tag by hand (`docker pull`, `docker tag`, `docker push`).

### ✅ Release checklist

- [ ] Every item of the milestone is done (`docs/ROADMAP.md`), or it is an out-of-milestone patch
- [ ] Release PR merged: dated `CHANGELOG.md` heading, version bumped in the docs, `release-notes.sh` checked
- [ ] Owner confirmed the tag
- [ ] Tag pushed; the `Release` workflow is green
- [ ] GitHub Release and image tags look right (`docker run --rm ghcr.io/focusevengithub/gastroflow:X.Y.Z cat /var/www/html/VERSION`)
- [ ] First publish only: package set to public

---

## 📚 References

- [Conventional Commits](https://www.conventionalcommits.org/)
- [Semantic Versioning](https://semver.org/)
- [Gitmoji](https://gitmoji.dev/)
- [Angular Commit Guidelines](https://github.com/angular/angular/blob/main/CONTRIBUTING.md#commit)
- [Keep a Changelog](https://keepachangelog.com/)

---

Keep this document alive. If a new pattern, commit type, or release flow comes up, add it here and share it with the team.
