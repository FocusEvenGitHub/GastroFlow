# Spec 087 — Switch "Aparece na Cozinha" por item

## Metadata

- Status: Implemented (Draft → Approved → In Progress → Implemented em 2026-11-24; falta a conferência manual do AC9 e o dono testar no Admin real — ver Validation evidence)
- Created: 2026-11-24
- Updated: 2026-11-24
- Owner: FocusEven
- Related issue: GitHub #80 — "Botão Switch de aparecimento na tela Cozinha"
- Related branch: `feat/087-show-in-kitchen-toggle` (a criar)

## Context

Issue #80 (2026-11-20): um pedido com "Cartela de Ovos" para viagem não apareceu na tela da Cozinha. O dono pede que a visibilidade na cozinha seja configurável por item no Admin, em vez de presa ao cardápio de um cliente, com os Pratos Principais ligados por padrão. É demanda de cliente fora de milestone (vai para "Não lançado" no CHANGELOG, tag patch abaixo do próximo milestone reservado).

Decisões do dono nesta conversa (2026-11-24): na migração, ligar **Pratos Principais e Adicionais** (preserva o que a cozinha mostra hoje); item novo criado no Admin vem **ligado se a categoria for Pratos Principais**, desligado nas outras.

## Problem

A cozinha filtra os itens de cada pedido por uma lista fixa de nomes de categoria (`public/kitchen/app.js:42`, `KITCHEN_CATEGORIES: ['Pratos Principais', 'Adicionais']`; usada em `kitchenItems()`, `app.js:322`). Um item de qualquer outra categoria (ex.: cartela de ovos) nunca aparece no cartão do pedido, e não há como mudar isso sem editar código.

## Goals

- Uma flag por item de cardápio (`show_in_kitchen`) decide se o item aparece no cartão do pedido na cozinha.
- O Admin liga/desliga a flag (switch no formulário + atalho no card do item).
- A mudança no Admin reflete na cozinha aberta sem recarregar a página.
- Instalações existentes não mudam de comportamento no dia da atualização.

## Non-goals

- Não muda o "Resumo de Pratos" (`dishSummary()`, continua por `category_name === 'Pratos Principais'`) nem o "Resumo de Ingredientes" (`/api/kitchen/food-summary`, por `food_category`).
- Não esconde o cartão do pedido inteiro quando nenhum item está ligado (hoje o cartão já aparece mesmo sem itens de cozinha; continua assim).
- Não mexe nos outros nomes de categoria fixos: `ADDON_CATEGORY` (caixa, `OrderRepository`) e `MAIN_DISH_CATEGORY` (`ReportService`).
- Não muda impressão (cupom) nem caixa.

## Current behavior

Confirmado no código:

- `public/kitchen/app.js:42` — `KITCHEN_CATEGORIES` fixo; `kitchenItems(order)` (`:322`) filtra `order.items` por `category_name`. Usado em `public/kitchen/index.php:226` (pendentes) e `:292` (finalizados).
- `src/Repositories/OrderRepository.php:61` (lista de pedidos) e `:565` (item adicionado pelo "Editar Pedido") montam o payload do item com `category_name` lido **ao vivo** do cardápio (`menuItem->category->name`), não do snapshot do pedido (spec 023).
- `src/Models/MenuItem.php` — `fillable` = `category_id, name, description, price, available`; casts `available`, `price`, `is_customizable`.
- `src/Validators/MenuItemValidator.php` — `available` opcional, booleano estrito (`is_bool`), em create e update.
- `src/Repositories/MenuRepository.php` — `getFullMenu()` devolve os campos do item; `addItem()`/`updateItem()` tratam `available`.
- `src/Services/MenuService.php` — não publica eventos; `updateItem()` grava auditoria só dos campos em `$recognized`.
- `src/Services/OrderService.php` publica `order.*` via `EventPublisher` (`DatabaseEventPublisher`); a cozinha (`public/kitchen/app.js:142–170`) escuta só `order.*` em `/api/events/stream.php`, que repassa qualquer `type` sem lista de permissão.
- Admin (`public/admin/app.js`): `openNewItem()`/`addItem()` (POST `/api/admin/items`), `startEdit()`/`updateItem()` (PATCH `/api/admin/items/{id}`), `toggleAvailability()` (PATCH com `{available}`); card do item em `public/admin/index.php:105–140`.
- Seeds: `common/seeds/client-1.sql` (71 `INSERT INTO menu_items`, sem a nova coluna) e `bin/seed-demo` (`DB::table('menu_items')->insert`, sem a nova coluna) rodam **depois** das migrations.

## Proposed behavior

- `menu_items.show_in_kitchen` (boolean). A cozinha mostra no cartão só os itens com `show_in_kitchen = true`.
- Admin: switch "Aparece na Cozinha" nos formulários de novo item e de edição; no card, um botão que alterna a flag (mesmo padrão de `toggleAvailability`) e um indicador quando desligado.
- Novo item: o switch vem ligado quando a categoria escolhida é "Pratos Principais", desligado nas outras (ajusta ao trocar a categoria no formulário de novo item, enquanto o usuário não mexeu no switch). Via API, `show_in_kitchen` omitido no POST segue a mesma regra no servidor.
- Toda criação/edição/exclusão de item no Admin publica `menu.updated`; a cozinha, ao receber, faz `fetchAll()` (mesmo tratamento dos `order.*`, só quando a data selecionada é hoje).

## Functional requirements

1. FR1 — Migration `common/migrations/023_menu_item_show_in_kitchen.sql` adiciona `show_in_kitchen TINYINT(1) NOT NULL DEFAULT 0` e faz `UPDATE` para `1` nos itens das categorias "Pratos Principais" e "Adicionais".
2. FR2 — `GET /api/menu` e `GET /api/admin/menu` devolvem `show_in_kitchen` (boolean) em cada item.
3. FR3 — `POST /api/admin/items` aceita `show_in_kitchen` booleano opcional; se omitido, grava `true` se `category_name === 'Pratos Principais'`, senão `false`.
4. FR4 — `PATCH /api/admin/items/{id}` aceita `show_in_kitchen` booleano opcional e grava; o campo entra no registro de auditoria `menu_item.updated`.
5. FR5 — `show_in_kitchen` não booleano (ex.: `"sim"`, `1`) → 400 `VALIDATION_FAILED` com erro no campo, igual a `available`. *(Corrigido 2026-11-24: dizia 422; o `MenuController` responde 400 a toda falha de validação.)*
6. FR6 — Cada item em `GET /api/orders` e no retorno de `POST /api/orders/{id}/items` traz `show_in_kitchen` (boolean) lido ao vivo do item do cardápio.
7. FR7 — `kitchenItems(order)` devolve só itens com `show_in_kitchen === true`; `KITCHEN_CATEGORIES` é removido.
8. FR8 — `MenuService` publica `menu.updated` (`{menu_item_id}`) após criar, editar e excluir item; a cozinha escuta e recarrega.
9. FR9 — `common/seeds/client-1.sql` e `bin/seed-demo` gravam `show_in_kitchen = 1` para itens de "Pratos Principais" e "Adicionais".

## Non-functional requirements

- Sem dependência nova. Uma coluna, sem índice (filtro é no navegador).
- Textos da tela em português simples (memória "operator text non-technical").
- Arquivos JS editados seguem com `?v=<filemtime>` (já estão).

## User flows

- **Admin/gerente**: abre o item "Cartela de Ovos" → liga "Aparece na Cozinha" → salva (ou clica no atalho do card).
- **Cozinha**: com a tela aberta, o pedido pendente que contém a cartela passa a mostrá-la em poucos segundos, sem recarregar.
- **Admin cria item** em "Pratos Principais" → switch já vem ligado; em "Bebidas" → desligado.

## API changes

- Campo novo `show_in_kitchen` (boolean) na resposta de `GET /api/menu`, `GET /api/admin/menu`, `GET /api/orders`, `POST /api/orders/{id}/items` (itens).
- Campo opcional novo `show_in_kitchen` no corpo de `POST /api/admin/items` e `PATCH /api/admin/items/{id}`. Mesma autorização de hoje (`admin`/`manager`).
- Evento SSE novo `menu.updated`. Só aditivo.

## Data model and migrations

- `common/migrations/023_menu_item_show_in_kitchen.sql`:
  ```sql
  ALTER TABLE menu_items ADD COLUMN show_in_kitchen TINYINT(1) NOT NULL DEFAULT 0;
  UPDATE menu_items m JOIN categories c ON c.id = m.category_id
     SET m.show_in_kitchen = 1
   WHERE c.name IN ('Pratos Principais', 'Adicionais');
  ```
- `MenuItem`: `show_in_kitchen` em `fillable` e `casts` (`boolean`).
- `001_schema.sql` não muda (schema só por migration; instalação nova aplica a 023 via `bin/migrate`/serviço `migrate`).

## Architecture and affected components

- `common/migrations/023_menu_item_show_in_kitchen.sql` (novo)
- `src/Models/MenuItem.php`
- `src/Validators/MenuItemValidator.php`
- `src/Repositories/MenuRepository.php` (`getFullMenu`, `addItem`, `updateItem`)
- `src/Services/MenuService.php` (injetar `EventPublisher`; `$recognized` + publicação)
- `src/Repositories/OrderRepository.php` (payloads em `:61` e `:565`)
- `public/kitchen/app.js` (`kitchenItems`, remover `KITCHEN_CATEGORIES`, listener `menu.updated`)
- `public/admin/app.js`, `public/admin/index.php` (switch nos dois formulários, atalho/indicador no card)
- `common/seeds/client-1.sql`, `bin/seed-demo`
- Nenhuma camada nova.

## Security considerations

Escrita continua atrás de `JwtMiddleware` + `RoleMiddleware(['admin','manager'])`. Entrada validada como booleano estrito. O evento `menu.updated` carrega só o id do item — nada sensível no stream público da cozinha.

## Backward compatibility

- Instalações existentes: a migração liga exatamente as categorias que a cozinha mostra hoje → mesma tela no dia da atualização. Item que estiver hoje numa categoria renomeada pelo cliente fica desligado (já não aparecia).
- Consumidores da API: só campos aditivos.
- Navegador com `app.js` antigo em cache: coberto pelo `?v=<filemtime>`.

## Acceptance criteria

1. AC1 — Após `bin/migrate` num banco com itens em "Pratos Principais", "Adicionais" e "Bebidas", `show_in_kitchen` é `1` nos dois primeiros e `0` no terceiro.
2. AC2 — `PATCH /api/admin/items/{id}` com `{"show_in_kitchen": true}` → 200; `GET /api/admin/menu` devolve o item com `show_in_kitchen: true`; o audit log `menu_item.updated` contém o campo.
3. AC3 — `PATCH` com `{"show_in_kitchen": "sim"}` → 400 com erro em `show_in_kitchen` (em `messages`). *(Corrigido 2026-11-24: dizia 422.)*
4. AC4 — `POST /api/admin/items` sem `show_in_kitchen` em "Pratos Principais" → item com `true`; em "Bebidas" → `false`.
5. AC5 — `GET /api/orders?status=pending` traz `show_in_kitchen` em cada item, refletindo o valor atual do cardápio (alterar o item depois do pedido muda a resposta).
6. AC6 — Na cozinha, um pedido com um item desligado e um ligado mostra só o ligado; ao ligar o outro no Admin, ele aparece no cartão sem recarregar a página (via `menu.updated`).
7. AC7 — Editar/criar/excluir item grava um evento `menu.updated` na tabela de eventos.
8. AC8 — No Admin, o formulário de novo item com categoria "Pratos Principais" abre com o switch ligado; com "Bebidas", desligado.
9. AC9 — `bin/seed-client-1` e `bin/seed-demo` num banco novo deixam Pratos Principais/Adicionais com `show_in_kitchen = 1`.
10. AC10 — PHPUnit (Unit + Integration), PHPStan e PHP-CS-Fixer passam.

## Implementation plan

1. Migration 023 + `MenuItem` (fillable/cast).
2. `MenuItemValidator`: regra booleana opcional em create/update + testes unitários.
3. `MenuRepository`: devolver o campo; `addItem` com o padrão por categoria; `updateItem` grava.
4. `MenuService`: `EventPublisher` no construtor, `menu.updated` em add/update/delete; `show_in_kitchen` em `$recognized`.
5. `OrderRepository`: campo nos dois payloads.
6. Cozinha: `kitchenItems()` pela flag, remover `KITCHEN_CATEGORIES`, listener `menu.updated`.
7. Admin: switch nos formulários (padrão por categoria no novo item), atalho + indicador no card.
8. Seeds: `client-1.sql` (um `UPDATE` final por categoria) e `bin/seed-demo`.
9. Testes de integração e E2E; PHPStan/CS-Fixer.
10. CHANGELOG ("Não lançado"), passe de docs (`specs/000-project-baseline.md`, `docs/architecture.md`, README se citar o filtro da cozinha), Trello.

## Testing and validation strategy

O projeto tem suíte automatizada (PHPUnit Unit/Integration, spec 035; Playwright, spec 040) — a nota do template sobre "sem infraestrutura de testes" não vale mais.

- Unit: `tests/Unit/MenuItemValidatorTest.php` — booleano aceito, não booleano rejeitado (AC3 no nível do validador).
- Integration (`restaurant_test`): migration marca as categorias certas (AC1); PATCH/POST/GET admin + audit (AC2–AC4); payload de pedidos ao vivo (AC5); evento `menu.updated` gravado (AC7), no estilo de `RealtimeEventTest`.
- E2E (porta 8081): item desligado some do cartão e aparece ao ligar sem reload (AC6); switch do novo item por categoria (AC8).
- Manual: `bin/seed-client-1`/`bin/seed-demo` em banco de teste e conferência por SQL (AC9).
- `vendor/bin/phpunit`, `phpstan analyse`, `php-cs-fixer fix --dry-run --diff` (AC10).

## Rollout and rollback

- Deploy normal: o serviço `migrate` (produção) ou `bin/migrate` (dev) aplica a 023.
- Rollback: voltar para a tag anterior. A coluna extra é ignorada pelo código antigo (que volta a filtrar por categoria); não precisa remover. Sem migration de reversão (o projeto não as tem).

## Open questions

- Nenhuma bloqueante. Os dois pontos de decisão (Adicionais ligados na migração; padrão do item novo por categoria) foram decididos pelo dono em 2026-11-24.
- Não bloqueante: o switch deve aparecer também para itens montáveis (`is_customizable`)? Proposta: sim, mesma regra para todos.

## Task checklist

- [x] Migration 023 + model
- [x] Validator + testes unitários
- [x] MenuRepository
- [x] MenuService (evento + auditoria)
- [x] OrderRepository payloads
- [x] Cozinha (filtro + listener)
- [x] Admin (switch + card)
- [x] Seeds
- [x] Testes de integração e E2E
- [x] PHPStan / CS-Fixer / PHPUnit
- [x] CHANGELOG + docs (Trello atualizado à parte)
- [ ] AC9 conferido num banco novo (manual, pendente)

## Implementation log

- 2026-11-24 — Migration 023 no padrão idempotente das anteriores (`information_schema` + `PREPARE`). O `UPDATE` que liga Pratos Principais/Adicionais só roda quando a coluna é criada nesta execução, para uma reexecução nunca religar o que o Admin desligou.
- 2026-11-24 — Padrão do item novo no servidor: `MenuRepository::addItem()` usa `show_in_kitchen` do corpo ou, se omitido, `categoria === 'Pratos Principais'` (constante local `MAIN_DISH_CATEGORY`, mesmo nome da do `ReportService`). No Admin, o switch do "Novo item" segue a categoria até o usuário mexer nele (`newItemKitchenTouched`); no formulário de edição, só reflete o valor salvo.
- 2026-11-24 — `menu.updated` publicado em `addItem`, `updateItem` e `deleteItem` do `MenuService`. Cheguei a pôr também em `updateAvailability()`, mas nenhuma rota chama esse método (o botão de disponibilidade usa o PATCH de `updateItem`) — tirei. Troca de componentes de prato (`updateDishComponents`) não publica: não muda o que o cartão mostra.
- 2026-11-24 — `stream.php` repassa qualquer `type` e só lê `order_id` do evento (nulo aqui); a cozinha só precisa do nome do evento. Nenhuma mudança no stream.
- 2026-11-24 — Desvio da spec: validação responde 400, não 422 (FR5/AC3 corrigidos em linha). É o que o `MenuController` sempre fez.
- 2026-11-24 — Seeds: `client-1.sql` ganhou um `UPDATE` final por categoria (os 71 `INSERT`s gerados ficam como estão); `bin/seed-demo` grava a flag no `insert`.
- 2026-11-24 — Fixtures E2E de pedidos da cozinha (`order-number.spec.ts`, `item-discount.spec.ts`) ganharam `show_in_kitchen: true`, espelhando o payload real; sem isso o item sumiria do cartão nesses testes.
- 2026-11-24 — O teste E2E do card do Admin falhou na primeira rodada por erro do próprio teste: `toContainText` lê texto escondido por `x-show`. Trocado por `toBeVisible`/`toBeHidden` no badge.
- 2026-11-25 — `/spec-review` sem bloqueios. Achados: AC6 parcialmente provado (o E2E dispara `menu.updated` na página; gravação do evento e transporte pelo stream provados à parte), AC9 sem evidência, e troca de componentes/reordenação não publicam `menu.updated` (não mudam o cartão). O item que motivou a issue (cartela de ovos, fora das duas categorias) precisa ser ligado à mão no Admin depois da atualização — por decisão do dono.
- 2026-11-24 — Docs: `docs/architecture.md` (seção SSE + contagem da suíte E2E, que estava parada em 34 desde a spec 060), `specs/000-project-baseline.md`, `public/api/docs/openapi.yaml` (campo no POST/PATCH e no schema do item), `CHANGELOG.md` (nova seção "Não lançado"). README não cita o filtro da cozinha — sem mudança.

## Validation evidence

Todas as rodadas em 2026-11-24, stack de desenvolvimento (`restaurant_web`, PHP 8.2.35).

- **AC1** — `docker compose exec web php bin/migrate` no banco de desenvolvimento: `▶ Executando 023_menu_item_show_in_kitchen.sql ... [OK]`. Contagem por categoria depois: `Adicionais | 1 | 5`, `Pratos Principais | 1 | 5`, `Bebidas | 0 | 3`, `Sobremesas | 0 | 2`, `Viagem | 0 | 2`. A migration também roda na cadeia completa de `MigrationUpgradeTest` (v1.7.1 → head): `MYSQL_DATABASE_TEST_UPGRADE=restaurant_test_upgrade … --filter MigrationUpgradeTest` → `OK (2 tests, 16 assertions)`.
- **AC2, AC7** — `ShowInKitchenTest::testPatchTogglesTheFlagAuditsItAndPublishesMenuUpdated` (PATCH 200, flag no `GET /api/admin/menu`, `show_in_kitchen` em `audit_log.details`, 1 evento `menu.updated`) e `testCreateAndDeletePublishMenuUpdated` (2 eventos) — ✔.
- **AC3** — `testNonBooleanIsRejected` (400, erro em `messages.show_in_kitchen`) ✔; unitários `MenuItemValidatorTest::testShowInKitchenAcceptsBooleanOnCreateAndUpdate` / `testShowInKitchenRejectsNonBoolean` ✔.
- **AC4** — `testNewItemDefaultsToVisibleOnlyForMainDishes` ✔.
- **AC5** — `testOrderPayloadFollowsTheMenuFlag` (false → PATCH → true no `GET /api/orders?status=pending`) ✔.
- **AC6, AC8** — `npx playwright test` (porta 8081): `51 passed (1.4m)`, incluindo `show-in-kitchen.spec.ts` — cartão sem o item desligado e com ele depois de `menu.updated` sem recarregar; switch do novo item seguindo a categoria; botão do card mandando `PATCH {show_in_kitchen: true}` e escondendo "Fora da cozinha". O evento é disparado no `EventSource` da página (mesmo listener), não vindo do servidor — o caminho servidor → stream já é coberto por `realtime-events.spec.ts`/`RealtimeEventTest` e o evento gravado, pelo AC7.
- **AC9** — **não executado**: `bin/seed-client-1`/`bin/seed-demo` recusam banco com cardápio e o usuário da aplicação não cria banco novo. Feito só `php -l bin/seed-demo` (`No syntax errors`) e leitura do SQL. Pendente: rodar os dois num banco limpo e conferir `SELECT … show_in_kitchen` por categoria.
- **AC10** — `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit` → `Tests: 377, Assertions: 1062, Skipped: 2` (os 2 pulados são o `MigrationUpgradeTest`, rodado à parte acima); `vendor/bin/phpstan analyse` → `[OK] No errors`; `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 125 files that can be fixed`.
