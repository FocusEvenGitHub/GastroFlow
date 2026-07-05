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

## 📚 Referências

- Conventional Commits
- Gitmoji
- Angular Commit Guidelines

Mantenha este documento vivo. Se surgir um novo padrão ou tipo de commit, adicione-o aqui e compartilhe com o time.