# 📐 Convenção de Commits — GastroFlow

Este documento define o padrão de mensagens de commit para o projeto **GastroFlow**, garantindo um histórico limpo, semanticamente rico e profissional.

---

## 🧱 Estrutura da Mensagem
```
<tipo>(<escopo>): <descrição curta>

[corpo opcional com mais detalhes]

[rodapé opcional: BREAKING CHANGE, issues, etc.]
```


### Exemplo completo:
````
✨ feat(orders): add order status history tracking

Add a new table order_status_logs to record every status change.
Each log stores the old status, new status, timestamp, and user who made
the change. This allows the kitchen and admin to audit order progress.

Closes #42
````

---

## 📦 Tipos de Commit

| Tipo         | Emoji                | Uso                                                    |
|--------------|----------------------|--------------------------------------------------------|
| `feat`       | ✨ `:sparkles:`     | Nova funcionalidade (feature)                          |
| `fix`        | 🐛 `:bug:`          | Correção de bug                                        |
| `refactor`   | ♻️ `:recycle:`      | Refatoração de código (sem mudar comportamento)       |
| `docs`       | 📝 `:memo:`         | Documentação (README, comentários, guias)              |
| `style`      | 💄 `:lipstick:`     | Formatação, espaçamento, ponto e vírgula (sem lógica) |
| `test`       | ✅ `:white_check_mark:` | Adição ou correção de testes                       |
| `chore`      | 🔧 `:wrench:`       | Tarefas de build, config, dependências                 |
| `perf`       | ⚡ `:zap:`          | Melhorias de performance                               |
| `ci`         | 👷 `:construction_worker:` | CI/CD pipelines, GitHub Actions                 |
| `revert`     | ⏪ `:rewind:`       | Reversão de commit anterior                            |
| `security`   | 🔒 `:lock:`         | Correções de segurança                                 |
| `wip`        | 🚧 `:construction:` | Trabalho em progresso (work in progress)               |
| `ui`         | 🎨 `:art:`          | Alterações visuais / CSS / layout                      |
| `database`   | 🗃️ `:card_file_box:` | Migrations, seeds, schema                              |
| `api`        | 🔌 `:electric_plug:` | Endpoints, responses, contratos                        |
| `docker`     | 🐳 `:whale:`        | Dockerfile, compose, ambiente                          |

---

## 🎯 Escopos (opcional, mas recomendado)

Use escopos para indicar a área do projeto afetada:

| Escopo      | Área                                |
|-------------|-------------------------------------|
| `api`       | Backend API (Slim, controllers)     |
| `admin`     | Painel administrativo               |
| `cashier`   | Interface do caixa                  |
| `kitchen`   | Interface da cozinha                |
| `db`        | Banco de dados (schema, migrations) |
| `docker`    | Containerização                     |
| `auth`      | Autenticação/autorização            |
| `menu`      | Cardápio (itens, categorias)        |
| `orders`    | Pedidos                             |
| `validator` | Validação de dados                  |
| `config`    | Configurações gerais                |

---

## ✅ Regras de Ouro

1. **Use imperativo**: "add" e não "added" ou "adds".
2. **Máximo 72 caracteres** na linha do título.
3. **Separe título e corpo** com uma linha em branco.
4. **Use o corpo** para explicar **o quê** e **por quê**, não o "como".
5. **Referencie issues** no rodapé: `Closes #42`, `Refs #17`.
6. **Um commit = uma mudança lógica** (não misture refatoração com feature).

---

## 💡 Exemplos do mundo real

### ✅ Bons commits
````
✨ feat(orders): add endpoint to list orders by table number

🐛 fix(api): return 400 when menu item ID is invalid on order creation

♻️ refactor(menu): extract ItemRepository from MenuService for single responsibility

📝 docs(readme): add Docker setup and API endpoint documentation

🔒 security(auth): add JWT token validation on admin routes

🗃️ db: add order_status_logs table and migration
````

### ❌ Commits ruins (evite)

````
fix
ajustes
commit final
WIP (sem descrição)
arrumando umas paradas
````

---

## 🚀 Como usar emojis no terminal

No Git Bash / Linux / Mac:

```bash
git commit -m "✨ feat(orders): add status history"
```
Ou com o código do emoji:
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

### Novidades
- Descrição concisa com link para issue/PR quando aplicável

### Correções
- Descrição do bug corrigido

### Breaking Changes
- Descreva o que mudou e como migrar (raro, apenas em MAJOR)
```

4. Group changes under these headings:
   - **Novidades** — from `feat`, `ui`, `api`, `database`, `security` commits
   - **Correções** — from `fix`, `revert`, `perf` commits
   - **Breaking Changes** — from commits with `BREAKING CHANGE` footer

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
git tag -a vX.Y.Z -m "vX.Y.Z — breve descrição do release"

# 6. Push the commit and the tag
git push origin main
git push origin vX.Y.Z
```

> 💡 Use `git log --oneline v<last-tag>..HEAD` to review all commits since the last release and help draft the changelog entry.

### ✅ Checklist de Release

- [ ] All commits since last tag follow the commit convention
- [ ] `CHANGELOG.md` updated with accurate date and version
- [ ] Changelog commit pushed
- [ ] Tag created and pushed (`git push origin vX.Y.Z`)
- [ ] (Opcional) GitHub Release criado a partir da tag

### 🔗 Automated tools (future)

For larger teams, consider automating with:
- [**standard-version**](https://github.com/conventional-changelog/standard-version) — auto-bumps version and generates changelog from commits
- [**semantic-release**](https://semantic-release.gitbook.io/) — fully automated release pipeline
- **GitHub Actions** — create a workflow that runs `standard-version` on merge to main

---

## 📚 Referências

- [Conventional Commits](https://www.conventionalcommits.org/)
- [Semantic Versioning](https://semver.org/)
- [Gitmoji](https://gitmoji.dev/)
- [Angular Commit Guidelines](https://github.com/angular/angular/blob/main/CONTRIBUTING.md#commit)
- [Keep a Changelog](https://keepachangelog.com/)

---

Mantenha este documento vivo. Se surgir um novo padrão, tipo de commit, ou fluxo de release, adicione-o aqui e compartilhe com o time.