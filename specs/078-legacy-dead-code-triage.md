# Spec 078 — Triagem de código legado / dead code

## Metadata

- Status: Implemented
- Created: 2026-11-09
- Updated: 2026-11-09
- Owner: Henry
- Related issue: Trello #65 "Triagem de código legado / dead code" (https://trello.com/c/3ijGRZjN)
- Related branch: `chore/078-legacy-dead-code-triage` (proposta)

## Context

Três itens aparecem há meses nos docs como "dead code, não removido, questão em aberto": `common/config.php` + `common/db.php`, `src/Controllers/DishController.php` e o diretório `legacy/`. `specs/000-project-baseline.md` › Open questions perguntava se algo externo dependia dos helpers PDO; `docs/technical-decisions.md` › Open, not yet resolved repete a pergunta. O card pede uma decisão com evidência para cada um, não uma remoção às cegas.

**Resposta do dono (2026-11-09):** nada fora do repositório usa `common/config.php` nem `common/db.php` (nenhum script manual, cron do piloto ou outro projeto). Isso fecha a questão em aberto do baseline.

## Problem

Investigação feita para esta spec (2026-11-09), item por item:

1. **`common/config.php` / `common/db.php`** — helpers de PDO puro (`getPDO()`), com credenciais padrão fixas (`restuser`/`restpass`) e um `die()` que imprime a mensagem de erro de conexão. Sem chamadores: `git grep` não acha `require`/`include` de nenhum dos dois fora de `db.php` → `config.php`. Os últimos chamadores eram `legacy/api/index.php`, `legacy/api/menu.php` e `legacy/api/orders.php`, apagados no commit `b4c9573` (2026-07-11, "deleting legacy architecture") — confirmado com `git grep getPDO` no commit anterior. Os dois arquivos ainda **entram na imagem de produção** (`Dockerfile:48`, `COPY common/ ./common/`, necessário pelas migrations), fora do DocumentRoot (`public/`), então não são servidos pela web.
2. **`src/Controllers/DishController.php`** — nenhuma rota em `src/Routes.php` (confirmado por grep; também `docs/architecture.md`, spec 027). Além de inalcançável, **está quebrado**: `show()` chama `MenuItem::with('ingredients')` e `update()` chama `$dish->ingredients()->sync()`, mas `src/Models/MenuItem.php` não tem método `ingredients()` (só `category`, `components`, `usedInDishes`) e o schema não tem tabela ligando prato a ingrediente. Se fosse roteado, daria `RelationNotFoundException`/erro de método. O que ele pretendia fazer já existe nas rotas vivas: `PATCH /api/admin/items/{id}` (dados do prato) e `GET`/`PUT /api/admin/items/{id}/components` (composição), em `MenuController`. Nenhum arquivo de `public/` chama `/api/admin/dishes`. Tem uma entrada própria em `phpstan-baseline.neon` (linhas 3–7).
3. **`legacy/`** — já não existe: os arquivos saíram em `b4c9573`, o diretório não está no Git (`git ls-files legacy` vazio) nem nesta árvore, e o `COPY legacy/` do `Dockerfile` foi removido pelas specs 059/062. Sobra só documentação dizendo o contrário: `docs/architecture.md:228` ("Empty, tracked — kept as a marker") e `specs/000-project-baseline.md:56` ("exists as an empty tracked directory").

## Goals

- Remover `common/config.php` e `common/db.php`.
- Remover `src/Controllers/DishController.php` e a entrada correspondente de `phpstan-baseline.neon`.
- Registrar que `legacy/` já foi removido, corrigindo os docs que dizem que ele existe.
- Atualizar com nota datada todo doc que chama esses itens de "dead code ainda não removido" ou de questão em aberto.

## Non-goals

- Reintroduzir edição de prato com ingredientes (o que o `DishController` tentava): é escopo novo, e a composição de pratos já é coberta por `MenuController`.
- Mexer em `docs/upgrading.md` › linha "`/legacy`: not found" (`:149`): ela trata de **voltar para uma tag antiga**, cujo `Dockerfile` ainda copia `legacy/` — continua verdadeira.
- Reescrever registros históricos: CHANGELOG de versões passadas, specs antigas (011, 029, 059, 062–064) e o status da spec 029 em `docs/ROADMAP.md:695` ficam como estão.
- Outras limpezas de código que pareçam mortas e não estejam no card.

## Current behavior

Ver *Problem*. Resumo confirmado no código: os três itens não têm efeito em nenhum caminho de execução (HTTP, `bin/`, worker, testes). As referências que restam são documentação, `CLAUDE.md`, `phpstan-baseline.neon` e `.claude/skills/security-review/SKILL.md:20` (lista o `DishController` entre controllers a auditar).

## Proposed behavior

Nenhuma mudança de comportamento observável: os arquivos removidos não são carregados por nada. A API continua respondendo 404 para `/api/admin/dishes/{id}`, como hoje (a rota nunca existiu).

## Functional requirements

1. FR1 — `common/config.php`, `common/db.php` e `src/Controllers/DishController.php` não existem mais no repositório.
2. FR2 — `phpstan-baseline.neon` não tem mais a entrada com `path: src/Controllers/DishController.php`, e o PHPStan continua sem erros (sem "ignored error pattern … was not matched").
3. FR3 — `git grep -n "common/config.php\|common/db.php\|getPDO\|DishController"` só encontra ocorrências em registros históricos (CHANGELOG de versões já lançadas, specs anteriores à 078, a própria spec 078) e em notas datadas que registram a remoção.
4. FR4 — `docs/architecture.md`, `specs/000-project-baseline.md`, `docs/technical-decisions.md` e `CLAUDE.md` não afirmam mais que os itens existem; cada correção nos docs com histórico (architecture, baseline, technical-decisions) é uma nota datada, não reescrita silenciosa.

## Non-functional requirements

- Nenhuma dependência nova, nenhuma mudança de schema, de rota ou de `Dockerfile`.
- A imagem continua construindo (`COPY common/` segue válido: `common/` ainda tem `migrations/`, `seeds/`, `sql/`).

## User flows

Not applicable — nenhuma tela ou papel de usuário muda.

## API changes

Not applicable — o `DishController` nunca teve rota; `/api/admin/dishes/*` responde 404 antes e depois.

## Data model and migrations

Not applicable — nenhuma tabela muda.

## Architecture and affected components

- Removidos: `common/config.php`, `common/db.php`, `src/Controllers/DishController.php`.
- `phpstan-baseline.neon` — remover a entrada do `DishController`.
- `CLAUDE.md` — linhas 13–14 (menção ao `Dish` controller e aos helpers PDO).
- `docs/architecture.md` — linhas 81, 83, 88 (tabela de camadas), 201 (Persistence), 224 e 228 (árvore de diretórios).
- `specs/000-project-baseline.md` — linhas 48/50 (contagem de controllers, já desatualizada por outras specs), 55, 56 e a Open question da linha 192.
- `docs/technical-decisions.md:33` — sai de "Open, not yet resolved".
- `.claude/skills/security-review/SKILL.md:20` — tirar `DishController` da lista (diretório protegido no sandbox; a edição pode precisar de permissão).
- `CHANGELOG.md` — entrada em "Não lançado".

## Security considerations

Remove código com credenciais padrão fixas (`restuser`/`restpass` em `common/config.php`) e um `die()` que expõe a mensagem do driver (`common/db.php`), ambos presentes na imagem de produção. Nenhuma superfície nova.

## Backward compatibility

Nenhum consumidor interno (confirmado por grep) e nenhum externo (confirmado pelo dono). Quem fizer rollback para uma tag antiga recebe os arquivos de volta junto com ela, sem efeito.

## Acceptance criteria

- AC1 — `ls common/config.php common/db.php src/Controllers/DishController.php` → os três "No such file or directory".
- AC2 — `docker compose exec web vendor/bin/phpstan analyse` → `[OK] No errors`, e `grep -c DishController phpstan-baseline.neon` → `0`.
- AC3 — `docker compose exec web vendor/bin/phpunit` (Unit + Smoke) e `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` passam como antes.
- AC4 — `docker compose exec web php -r 'require "vendor/autoload.php"; var_dump(class_exists("App\\Controllers\\DishController"));'` → `bool(false)` (o autoload não acha mais a classe; nenhum cache de classmap a mantém viva).
- AC5 — `git grep` do FR3 só devolve ocorrências históricas ou notas datadas (lista registrada na Validation evidence).
- AC6 — `curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/api/admin/dishes/1` → `401` ou `404` antes e depois (mesmo código nos dois — a rota não existe, e o grupo admin exige JWT). *(Nota de implementação: rodado de dentro do container `web` em `http://localhost/…`, porque o sandbox não alcança a porta 8080 do host; deu `404` nos dois.)*
- AC7 — CI do PR verde (inclui a análise estática, a suíte de integração e os testes de navegador).
- AC8 — Docs da seção *Architecture and affected components* atualizados com notas datadas; `CHANGELOG.md` com entrada em "Não lançado".

## Implementation plan

1. Registrar o AC6 "antes" (curl).
2. Apagar `common/config.php` e `common/db.php`.
3. Apagar `src/Controllers/DishController.php` e a entrada do baseline; rodar PHPStan.
4. Rodar AC1–AC6.
5. Docs pass: `CLAUDE.md`, `docs/architecture.md`, `specs/000-project-baseline.md`, `docs/technical-decisions.md`, `.claude/skills/security-review/SKILL.md`, `CHANGELOG.md`.
6. No mesmo PR: aplicar o stash pendente "spec 077 Verified (CI evidence)" (status `Verified` da spec 077 com a evidência do CI do PR #71, que ficou fora daquele merge).

## Testing and validation strategy

O projeto tem suítes automatizadas (Unit, Smoke, Integration, E2E, PHPStan — specs 004, 034, 035, 040); o template ainda diz o contrário, o que está desatualizado. Como esta spec só remove código sem chamador, a validação é: grep sem referências vivas (AC5), autoload sem a classe (AC4), análise estática e suítes iguais a antes (AC2, AC3), mesma resposta HTTP (AC6) e CI (AC7). Nenhum teste novo: não há comportamento novo a testar.

## Rollout and rollback

Entra na próxima tag, sem passo de operação. Rollback: reverter o commit.

## Open questions

1. ~~(era a questão do baseline) Algo externo depende de `common/config.php`/`common/db.php`?~~ **Resolvida 2026-11-09 pelo dono: não.**
2. (não bloqueante) A contagem de controllers em `docs/architecture.md:81` ("10: Auth, Dish, …") já estava errada antes desta spec (faltam `AuditLog`, `Health`, `Version`). Proposta: corrigir a lista inteira na mesma nota datada, já que a linha precisa mudar de qualquer jeito.

## Task checklist

- [x] AC6 "antes" registrado
- [x] `common/config.php` e `common/db.php` removidos
- [x] `DishController` e entrada do baseline removidos
- [x] AC1–AC6 rodados
- [x] Docs pass + CHANGELOG
- [x] Stash da spec 077 aplicado
- [ ] AC7 — CI do PR

## Implementation log

- 2026-11-09 — `/spec-implement` rodado com a spec em Draft e sem pergunta bloqueante: tratado como aprovação (Draft → Approved → In Progress).
- 2026-11-09 — Arquivos removidos com `git rm`. A entrada do `DishController` em `phpstan-baseline.neon` foi apagada junto; o PHPStan continuou `[OK]` (sem aviso de padrão ignorado sem uso).
- 2026-11-09 — AC6 rodado de dentro do container (`docker compose exec web curl http://localhost/...`): o sandbox recusa a porta 8080 do host. Resposta `404` antes e depois — o Slim não acha a rota antes de qualquer middleware de JWT, por isso não é `401`.
- 2026-11-09 — Open question 2: a lista de controllers em `docs/architecture.md:81` foi corrigida na mesma nota datada (12, incluindo `AuditLog`, `Health`, `Version`).
- 2026-11-09 — `CLAUDE.md` editado sem nota datada (é instrução viva, não registro histórico): saíram a menção ao `Dish` controller e a linha dos helpers PDO.
- 2026-11-09 — A árvore de `docs/architecture.md` perdeu a linha `legacy/` e ganhou um comentário datado no lugar; a linha `config.php, db.php` virou `seeds/client-1.sql`, que existe e faltava na árvore.
- 2026-11-09 — Visto e deixado fora de escopo: `.claude/skills/security-review/SKILL.md:20` também cita `AdminController`, que não existe desde a spec 028. Só o `DishController` saiu, como a spec pede.
- 2026-11-09 — `/spec-review`: as linhas 48 e 50 de `specs/000-project-baseline.md`, listadas em *Architecture and affected components*, tinham ficado sem nota (o grep do AC5 exclui `specs/0[0-6]*`). Ganharam nota datada; a da linha 50 registra também que o `IngredientRepository` existe desde a spec 029.
- 2026-11-09 — Stash "spec 077 Verified (CI evidence)" aplicado (`git stash pop`): `specs/077-multi-worker-queue-load-test.md` passa a `Verified` com a evidência do CI do PR #71.

## Validation evidence

Todos os comandos rodados em 2026-11-09 contra o stack de desenvolvimento.

| AC | Comando | Resultado |
|---|---|---|
| AC1 | `ls common/config.php common/db.php src/Controllers/DishController.php` | os três `Arquivo ou diretório inexistente` |
| AC2 | `docker compose exec web vendor/bin/phpstan analyse --no-progress`; `grep -c DishController phpstan-baseline.neon` | `[OK] No errors`; `0` |
| AC3 | `docker compose exec web vendor/bin/phpunit` | `OK, but some tests were skipped! Tests: 344, Assertions: 560, Skipped: 78.` (os 78 pulados são a suíte Integration sem `MYSQL_DATABASE_TEST`) |
| AC3 | `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration` | `OK, but some tests were skipped! Tests: 78, Assertions: 348, Skipped: 2.` — igual ao run da spec 077, antes desta mudança |
| AC4 | `docker compose exec web php -r 'require "vendor/autoload.php"; var_dump(class_exists("App\\Controllers\\DishController"));'` | `bool(false)` |
| AC5 | `git grep -n "common/config.php\|common/db.php\|getPDO\|DishController" -- . ':!specs/0[0-6]*' ':!specs/07[0-6]*'` | só `CHANGELOG.md:12` (entrada nova), `docs/architecture.md:81,83,201` e `docs/technical-decisions.md:33` (as notas datadas desta spec), além da própria spec 078 |
| AC6 | `docker compose exec web curl -s -o /dev/null -w "%{http_code}" http://localhost/api/admin/dishes/1`, antes e depois | `404` e `404` |
| AC7 | CI do PR | **ainda não rodou** — por isso `Implemented` |
| AC8 | revisão do diff | `CLAUDE.md`, `docs/architecture.md`, `docs/technical-decisions.md`, `specs/000-project-baseline.md` (linhas 48, 50, 55, 56, 192), `.claude/skills/security-review/SKILL.md`, `CHANGELOG.md` |
| AC5 (complemento) | `git grep -n "DishController" -- specs/000-project-baseline.md` | as 3 ocorrências (linhas 48, 50, 55) estão em linhas com nota datada da spec 078 |

O projeto tem suítes automatizadas (Unit, Smoke, Integration); o texto da skill que diz o contrário está desatualizado.
