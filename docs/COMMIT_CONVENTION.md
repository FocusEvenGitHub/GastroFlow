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

This section describes the process of cutting a new release, updating the changelog, and publishing a tag.

### 📐 Semantic Versioning

This project follows [Semantic Versioning](https://semver.org/):

| Version bump | When                                                                 |
|--------------|----------------------------------------------------------------------|
| **MAJOR**    | Breaking changes (`BREAKING CHANGE` in commit footer)               |
| **MINOR**    | New features (`feat` commits)                                        |
| **PATCH**    | Bug fixes, refactors, docs, chores (`fix`, `refactor`, `docs`, etc.) |

### 📝 Changelog Rules

1. **`CHANGELOG.md`** lives at the project root and is manually curated.
2. Every release adds a new entry at the **top** of the file (newest first).
3. Each entry follows this format:

```markdown
## vX.Y.Z (YYYY-MM-DD)

### Added
- Concise description with a link to the issue/PR when applicable

### Fixed
- Description of the fixed bug

### Breaking Changes
- Describe what changed and how to migrate (rare, MAJOR only)
```

4. Group changes under these headings:
   - **Added** — from `feat`, `ui`, `api`, `database`, `security` commits
   - **Fixed** — from `fix`, `revert`, `perf` commits
   - **Breaking Changes** — from commits with a `BREAKING CHANGE` footer

> ⚠️ **Note:** the project's actual `CHANGELOG.md` currently uses the Portuguese headings `Novidades`/`Correções` instead of `Added`/`Fixed`. This convention document is now in English; `CHANGELOG.md` itself was not changed as part of this translation. Align one with the other in a future pass — don't silently pick a side.

### 🚀 Release Steps

```bash
# 1. Ensure you are on main and up to date
git checkout main
git pull origin main

# 2. Decide the next version based on commits since the last tag
#    (see SemVer table above)

# 3. Update CHANGELOG.md — add the new version entry at the top
#    (manual edit)

# 4. Commit the changelog update
git add CHANGELOG.md
git commit -m "📝 docs: add changelog for vX.Y.Z"

# 5. Create an annotated tag
git tag -a vX.Y.Z -m "vX.Y.Z — brief release description"

# 6. Push the commit and the tag
git push origin main
git push origin vX.Y.Z
```

> 💡 Use `git log --oneline v<last-tag>..HEAD` to review all commits since the last release and help draft the changelog entry.

### ✅ Release Checklist

- [ ] All commits since last tag follow the commit convention
- [ ] `CHANGELOG.md` updated with accurate date and version
- [ ] Changelog commit pushed
- [ ] Tag created and pushed (`git push origin vX.Y.Z`)
- [ ] (Optional) GitHub Release created from the tag

### 🔗 Automated tools (future)

For larger teams, consider automating with:
- [**standard-version**](https://github.com/conventional-changelog/standard-version) — auto-bumps version and generates changelog from commits
- [**semantic-release**](https://semantic-release.gitbook.io/) — fully automated release pipeline
- **GitHub Actions** — create a workflow that runs `standard-version` on merge to main

---

## 📚 References

- [Conventional Commits](https://www.conventionalcommits.org/)
- [Semantic Versioning](https://semver.org/)
- [Gitmoji](https://gitmoji.dev/)
- [Angular Commit Guidelines](https://github.com/angular/angular/blob/main/CONTRIBUTING.md#commit)
- [Keep a Changelog](https://keepachangelog.com/)

---

Keep this document alive. If a new pattern, commit type, or release flow comes up, add it here and share it with the team.
