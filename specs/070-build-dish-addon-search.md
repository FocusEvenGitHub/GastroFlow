# Spec 070 — Monte Seu Prato: busca de adicionais com Tom Select

## Metadata

- Status: Verified
- Created: 2026-10-31
- Updated: 2026-10-31
- Owner: Henry (FocusEven)
- Related issue: Not applicable (pedido direto do dono, 2026-10-31)
- Related branch: `070`

## Context

O dono pediu "um select2 no modal do Monte Seu Prato". Com cardápio grande de adicionais, o caixa rola a grade do modal procurando o item pelo olho.

Decisões tomadas com o dono antes desta spec (2026-10-31):

- **Tom Select, não Select2.** O Select2 depende de jQuery, que o GastroFlow não tem (o front é Alpine + Bootstrap, sem build — `CLAUDE.md`). O Tom Select 2.6.2 já está vendorizado em `public/vendor/tom-select-2.6.2/` (spec 055) e é usado no Admin (`public/admin/app.js`, `initTomSelect()`): mesma experiência de busca com digitação, sem dependência nova.
- **Busca acima da grade.** O select é um atalho a mais; a grade com −/+ continua igual.

## Problem

No modal `#buildDishModal` (`public/cashier/index.php`), a única forma de escolher um adicional é achar o cartão dele na grade, agrupada por `food_category`, e tocar no nome ou no `+`. Não há como digitar parte do nome para chegar nele.

## Goals

- Um campo de busca no topo do modal: digitar parte do nome filtra os adicionais; escolher um soma +1 na quantidade dele.
- Usar a biblioteca já vendorizada, sem tocar em `public/vendor/`.

## Non-goals

- Não trocar nem remover a grade de cartões, os botões −/+ ou o rodapé (chips, total, confirmar).
- Não mudar preço, API, banco, impressão ou o comportamento da tela da cozinha. *(2026-10-31: a cozinha só ganhou `?v=` no `style.css`/`version-badge.js`, a pedido do dono — ver log.)*
- Não usar o Tom Select em outras telas do caixa (busca do cardápio principal etc.).
- Não desabilitar no dropdown o adicional que já está no máximo (`MAX_ADDON_QTY`); escolher de novo apenas não muda nada, como o `+` desabilitado da grade já indica.
- Não atualizar as opções em tempo real se o cardápio mudar com o modal aberto; elas são montadas a cada abertura.

## Current behavior

Confirmado no código:

- `public/cashier/index.php:385-445` — modal `#buildDishModal`, aberto/fechado por `x-effect` em cima de `builder.open`. O `modal-body` itera `addonGroups` → `group.items`, cada cartão com `stepAddon(addon.id, ±1)`.
- `public/cashier/app.js:160-173` — `addonGroups`: itens da categoria `ADDON_CATEGORY` (`'Adicionais'`), sem `available === false` nem `is_customizable`, agrupados por `food_category` na ordem de `FOOD_CATEGORY_LABELS`.
- `public/cashier/app.js:201-204` — `stepAddon(id, delta)` limita a quantidade a `[0, MAX_ADDON_QTY]` (10).
- `public/cashier/app.js:206-225` — `openBuilder()` / `closeBuilder()`.
- `public/cashier/index.php:12-16, 589-596` — o caixa **não** carrega o Tom Select hoje (só Bootstrap, Font Awesome, Alpine, `gf.js`, `app.js`).
- `public/admin/index.php:5-6` — o Admin carrega `tom-select.complete.min.js` e `tom-select.bootstrap5.min.css`.
- `public/assets/css/style.css:446-456` — estilos de tema escuro para `.ts-wrapper`/`.ts-dropdown` já existem, globais.

## Proposed behavior

1. O caixa passa a carregar `/vendor/tom-select-2.6.2/css/tom-select.bootstrap5.min.css` e `/vendor/tom-select-2.6.2/js/tom-select.complete.min.js` (antes do `app.js`).
2. No topo do `modal-body` do `#buildDishModal`, acima dos grupos, um `<select>` de seleção única, placeholder **"Buscar adicional…"**, só exibido quando `addonGroups.length > 0`.
3. Ao abrir o modal (`openBuilder`, inclusive ao editar uma montagem), o Tom Select é (re)criado com as opções de `addonGroups`: um `optgroup` por grupo (rótulo = `group.label`), cada opção com o nome e "+ R$ x.xx"; com o campo vazio, na mesma ordem da grade; com texto digitado, por relevância da busca (padrão do Tom Select — ver log). A busca casa por parte do nome, sem diferenciar maiúsculas/acentos (comportamento padrão do Tom Select).
4. Escolher uma opção chama `stepAddon(id, 1)` e limpa o campo (sem item selecionado, texto digitado apagado), pronto para a próxima busca. A grade e o rodapé refletem a nova quantidade, como se o `+` tivesse sido tocado.
5. Ao fechar o modal (`closeBuilder`, Cancelar, ×, ou após confirmar), a instância é destruída.

## Functional requirements

1. FR1 — O caixa carrega o JS e o CSS do Tom Select de `/vendor/tom-select-2.6.2/`, nunca de CDN.
2. FR2 — Com ao menos um adicional disponível, o modal mostra o campo "Buscar adicional…" acima do primeiro grupo; sem adicionais, o campo não aparece e a mensagem "Nenhum adicional disponível." continua.
3. FR3 — As opções são exatamente os itens de `addonGroups`, agrupados com os mesmos rótulos; grupos na ordem da grade, e itens na ordem da grade enquanto nada é digitado.
4. FR4 — Escolher um adicional com quantidade `q < MAX_ADDON_QTY` deixa a quantidade em `q + 1`; com `q = MAX_ADDON_QTY`, nada muda.
5. FR5 — Após escolher, o campo volta vazio.
6. FR6 — Abrir o modal de novo (outro prato ou edição) mostra as opções atuais do cardápio, sem duplicar instâncias nem deixar o dropdown anterior no DOM.
7. FR7 — Nada mais no modal muda de comportamento: −/+, toque no nome, chips, total, "Adicionar ao pedido"/"Salvar montagem".

## Non-functional requirements

- Sem dependência nova; `public/vendor/` e seus SHA-256 ficam intactos.
- A tela continua funcionando sem internet (`tests/e2e/specs/offline-assets.spec.ts`).
- O dropdown não pode ficar cortado nem atrás do modal (`modal-dialog-scrollable` tem `overflow` no corpo): validado no navegador; se cortar, usar `dropdownParent: 'body'` com `z-index` acima do modal (registrar no log).
- Sem erro de JavaScript no console ao abrir/fechar o modal várias vezes.
- Funciona no tema escuro (estilos já existentes em `style.css`).

## User flows

**Caixa, montando prato:**
1. Toca no cartão do prato montável → modal abre com o campo de busca no topo.
2. Digita "fra" → o dropdown mostra "Frango…" sob "Proteínas".
3. Escolhe → o cartão do Frango fica com 1, o chip "1x Frango" aparece, o total sobe; o campo fica vazio.
4. Repete com outros, ou usa −/+ na grade; confirma como hoje.

**Caixa, editando montagem:** toca em editar no item do pedido → modal abre com as quantidades salvas e o campo vazio; escolher soma sobre elas.

## API changes

Not applicable — só front do caixa; `GET /api/menu` já traz tudo.

## Data model and migrations

Not applicable — nenhuma tabela ou coluna muda.

## Architecture and affected components

- `public/cashier/index.php` — `<link>` do CSS e `<script>` do JS do Tom Select; o `<select>` no `modal-body` do `#buildDishModal`.
- `public/cashier/app.js` — instância do Tom Select numa variável de closure `addonSearch` em `cashierApp()` (fora do estado reativo do Alpine — ver log), métodos `initAddonSearch()` / `destroyAddonSearch()`, chamados de `openBuilder()` (após `$nextTick`, como o Admin faz) e `closeBuilder()`.
- Acrescentados durante a implementação (ver log): `public/assets/css/style.css` (tema escuro do Tom Select); `public/kitchen/index.php` e `public/admin/_partials/head.php` (`?v=` no `style.css`/`version-badge.js`, também no caixa); `tests/Integration/ItemDiscountTest.php` (`tearDown()` após skip); `CLAUDE.md` (convenção do `?v=`); `tests/e2e/specs/build-dish-search.spec.ts` (novo).
- Nenhum Controller/Service/Repository/Validator/Model/Middleware.

## Security considerations

Os nomes dos adicionais vêm do Admin. Montar as opções com `textContent`/a API `addOption` do Tom Select e renderizar via `escape` do próprio Tom Select (o `render` customizado, se usado, deve passar o nome por `escape()`), nunca concatenando HTML cru. Sem mudança de autenticação ou de dados enviados.

## Backward compatibility

O pedido enviado é o mesmo (`components` vindos de `builderLines`). Quem não usar o campo não percebe diferença além dele estar lá. Kitchen, impressão e relatórios intocados.

## Acceptance criteria

- AC1 — `/cashier/` com toda requisição externa bloqueada carrega o Tom Select do próprio servidor (`window.TomSelect` definido) e o `offline-assets.spec.ts` continua passando.
- AC2 — Com o cardápio de teste (Adicionais: "Frango Grelhado" protein R$ 8, "Arroz Branco" grain R$ 3, "Molho Pesto" sauce R$ 2; prato montável base R$ 15), abrir o modal mostra o campo "Buscar adicional…" acima de "Proteínas".
- AC3 — Digitar "fran" mostra só "Frango Grelhado", sob "Proteínas"; escolher deixa o cartão do Frango com quantidade 1, o chip "1x Frango Grelhado" e o total "R$ 23.00"; o campo fica vazio.
- AC4 — Escolher "Frango Grelhado" 11 vezes deixa a quantidade em 10.
- AC5 — Fechar e reabrir o modal 3 vezes deixa exatamente um `.ts-wrapper` no modal e nenhum erro no console.
- AC6 — Com a categoria Adicionais vazia, o campo não aparece e "Nenhum adicional disponível." é exibido.
- AC7 — Confirmar a montagem feita pelo campo adiciona ao pedido o item com os mesmos `components` que a grade produziria (verificado pelo item/chips na lista do pedido).
- AC8 — PHPStan, PHP-CS-Fixer e PHPUnit continuam passando (nenhum PHP de `src/` muda; checagem de regressão).

## Implementation plan

1. `public/cashier/index.php`: incluir CSS (no `<head>`, após o Bootstrap) e JS (antes de `app.js`) do Tom Select vendorizado.
2. `public/cashier/index.php`: adicionar o `<select id="addon-search">` no topo do `modal-body`, com `x-show="addonGroups.length > 0"`.
3. `public/cashier/app.js`: `initAddonSearch()` — destrói instância anterior, cria `new TomSelect` com `optgroups`/`options` de `addonGroups`, `maxItems: 1`, `onItemAdd` → `stepAddon(Number(id), 1)`, depois `clear(true)` e `setTextboxValue('')` (não `clearOptions`, que apagaria as opções); `destroyAddonSearch()`. Ligar em `openBuilder()` e `closeBuilder()`.
4. Validar no navegador o corte do dropdown dentro do modal; ajustar `dropdownParent`/CSS se necessário.
5. Teste e2e novo `tests/e2e/specs/build-dish-search.spec.ts` (cardápio via `page.route`, como `item-discount.spec.ts`) cobrindo AC2–AC7.
6. Docs/changelog: entrada no `CHANGELOG.md` (seção em andamento), conferir `docs/architecture.md`, `README.md`, `specs/000-project-baseline.md`, `public/vendor/README.md` (a tabela não diz onde cada lib é usada — conferir se algo precisa mudar).

## Testing and validation strategy

Observação: a instrução do `/spec-plan` diz que o projeto não tem testes automatizados, mas isso está desatualizado — há PHPUnit (`tests/Smoke`, `Unit`, `Integration`) e Playwright (`tests/e2e/`, spec 040). Esta mudança só quebra no navegador, então a validação é pela suíte e2e:

- AC1: `offline-assets.spec.ts` + checagem de `window.TomSelect` no caixa (pode entrar no próprio teste novo).
- AC2–AC7: `build-dish-search.spec.ts`, contra a instância e2e na porta 8081 (`docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e`, depois `cd tests/e2e && npx playwright test`). Nunca contra 8080.
- AC8: `vendor/bin/phpstan analyse`, `vendor/bin/php-cs-fixer fix --dry-run --diff`, `vendor/bin/phpunit` no container `web`.
- Manual: abrir o modal em tema claro e escuro e conferir que o dropdown aparece inteiro, por cima da grade.

## Rollout and rollback

Mudança só em arquivos estáticos do caixa; entra na próxima tag como qualquer spec (fora de milestone → patch abaixo do próximo milestone reservado, conforme `docs/ROADMAP.md`). Rollback: reverter o commit; nenhum dado é afetado.

## Open questions

- Em qual seção do `CHANGELOG.md`/card do Trello entra (milestone atual ou "Não lançado")? Não bloqueia — decidir no passe de docs.

Nenhuma questão bloqueante.

## Task checklist

- [x] Carregar CSS/JS do Tom Select no caixa
- [x] `<select>` de busca no `#buildDishModal`
- [x] `initAddonSearch()` / `destroyAddonSearch()` ligados a `openBuilder()`/`closeBuilder()`
- [x] Conferir dropdown dentro do modal (claro/escuro) — sem corte; tema escuro precisou de CSS (ver log)
- [x] `tests/e2e/specs/build-dish-search.spec.ts`
- [x] PHPStan / CS-Fixer / PHPUnit / e2e rodados
- [x] CHANGELOG + passe de docs

## Implementation log

- 2026-10-31 — `/spec-implement` invocado com a spec em Draft e sem questão bloqueante: tratado como aprovação (Draft → Approved → In Progress).
- A instância do Tom Select fica numa variável de closure em `cashierApp()`, não no objeto do Alpine: no estado reativo ela seria embrulhada num Proxy. O Admin guarda a dele no estado (`tomSelect`) e funciona; aqui preferi não depender disso.
- `onItemAdd` → `stepAddon(id, 1)`, depois `clear(true)` (silencioso, sem disparar `change`) e `setTextboxValue('')`. O dropdown fecha após escolher (padrão do modo single); a próxima busca começa com um clique/toque no campo.
- **Dropdown dentro do modal:** não corta (screenshots 1280×720 com 14 adicionais, claro e escuro): o `modal-body` rola, e a lista do Tom Select abre por cima da grade. `dropdownParent` não foi necessário.
- **Desvio — CSS do tema escuro (`public/assets/css/style.css`, não listado em "Architecture"):** o tema bootstrap5 do Tom Select deixava o campo com foco e o cabeçalho de grupo brancos, e a opção destacada com texto escuro sobre fundo escuro. Ajustei as regras `[data-theme="dark"] .ts-*` que já existiam ali (cor do texto da opção ativa, fundo do campo com foco, placeholder, cabeçalho de grupo, destaque do trecho buscado). O CSS é global e vale também para o Tom Select do Admin; não conferi o Admin no navegador.
- **Desvio — ordem com texto digitado (FR3):** com o campo vazio as opções seguem a ordem da grade; ao digitar, o Tom Select ordena por relevância (padrão). Testei `sortField: '$order'` e tirei: com ele "feij" mostrava Feijão Preto antes de Carioca de qualquer jeito, e a ordem por relevância é a esperada numa busca. Os grupos continuam na ordem da grade.
- **Achado à parte, corrigido nesta spec a pedido do dono (2026-10-31):** PHPUnit sem `MYSQL_DATABASE_TEST` dava 16 erros em `Tests\Integration\ItemDiscountTest` (`no such table: settings`). Causa: o `setUp()` do `IntegrationTestCase` pula o teste (`markTestSkipped`) quando não há banco de teste, mas o PHPUnit roda o `tearDown()` mesmo assim — e o `tearDown()` do `ItemDiscountTest` regravava `discount_max_percent` sem conferir se o `setUp()` tinha chegado lá. Essa escrita caía na conexão padrão do processo (aqui, um SQLite sem a tabela, por isso o erro; nada foi gravado no banco de desenvolvimento). Correção: flag `$settingsSaved`, ligada no `setUp()` só depois de ler o valor antigo; o `tearDown()` só restaura com ela ligada. Os outros `tearDown()` da suíte (`MigrationStatusTest`, `MigrationUpgradeTest`, `IntegrationTestCase`) já só mexem no que o `setUp()` criou — conferido.
- **`?v=` em todo JS/CSS próprio (pedido do dono, 2026-10-31):** `style.css` e `version-badge.js` eram os únicos arquivos próprios de `public/` carregados sem `?v=<mtime>`, nas três telas — um navegador podia manter o CSS antigo depois de um deploy (inclusive o ajuste de tema escuro desta spec). Caixa e cozinha ganharam o `filemtime()` inline, como já faziam com `gf.js`/`app.js`; o Admin passa os dois pelo `$asset()` de `_partials/head.php`. Regra registrada em `CLAUDE.md` (Code conventions).
- **Teste manual do dono (2026-10-31):** testou a busca no Monte Seu Prato no caixa.
- **`/spec-review` (2026-10-31):** apontou o arquivo vazio `tests/e2e/specs/zz-shot.spec.ts` (sobra do bind mount do script de screenshot — o Docker cria o alvo no host) e texto da spec desatualizado em Non-goals, Proposed behavior 3/FR3, Architecture e plano passo 3. Arquivo apagado e seções ajustadas antes do PR. AC8: a suíte PHPUnit completa com banco de teste sobre o código final fica com o CI.

## Validation evidence

Ambiente: `web-e2e` (porta 8081, banco `restaurant_test`) subido com `docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e`; Playwright na imagem `mcr.microsoft.com/playwright:v1.63.0-noble` (mesma versão do `package-lock.json`), como na spec 069:
`docker run --rm --network host --ipc=host -u 1000:1000 -e HOME=/tmp -v …/tests/e2e:/work -w /work mcr.microsoft.com/playwright:v1.63.0-noble npx playwright test …`

**AC1–AC7** — `npx playwright test specs/build-dish-search.spec.ts specs/offline-assets.spec.ts` → `15 passed (24.4s)`:
- AC1: `caixa: Tom Select carregado do próprio servidor` (`typeof window.TomSelect === 'function'`) + `offline-assets` 8/8 (inclui `/cashier/ carrega sem internet`).
- AC2: `campo de busca acima de "Proteínas"` (placeholder `Buscar adicional…`; topo do `.ts-wrapper` acima do título do grupo).
- AC3: `"fran" acha o Frango sob Proteínas; escolher soma 1 e limpa o campo` (1 opção, grupo `Proteínas`, quantidade 1, chip `1x Frango Grelhado`, total `R$ 23.00`, input vazio, nenhum item selecionado).
- AC4: `escolher 11 vezes para em 10`.
- AC5: `fechar e reabrir não duplica o campo nem gera erro` (3 ciclos; 1 `.ts-wrapper`; nenhum `pageerror`).
- AC6: `sem adicionais, sem campo de busca`.
- AC7: `montagem feita pela busca vai ao pedido com os mesmos adicionais` (lista do pedido `+ 1x Frango Grelhado`, `+ 1x Molho Pesto`; "Editar montagem" reabre com 1 e a busca leva a 2).

**Os testes novos falham sem a mudança:** com `public/cashier` revertido (`git stash push public/cashier`), o mesmo arquivo → `6 failed, 1 passed` — só o AC6 (caso negativo) passa no código antigo. Mudança restaurada com `git stash pop`.

**Suíte de navegador inteira** (após o ajuste de CSS) → `40 passed, 5 failed`; as 5 falhas são todas em `printer-block.spec.ts`, `Error: spawnSync docker ENOENT` em `setPrinterState` (o teste chama `docker compose`, que não existe na imagem do Playwright) — a mesma limitação de ambiente registrada na spec 069; o CI roda esses testes.

**Visual (tema claro e escuro):** script temporário de screenshot (fora do repositório) com 14 adicionais em 5 grupos, 1280×720: dropdown inteiro por cima da grade, sem corte, nos dois temas; no escuro, conferido de novo após o ajuste de CSS (campo, cabeçalhos e opção ativa legíveis).

**AC8** (nenhum PHP mudou; regressão):
- `docker compose exec web vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`
- `docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 117 files that can be fixed`
- `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit` → `OK, but some tests were skipped! Tests: 322, Assertions: 826, Skipped: 2.`
- Sintaxe: `node --check public/cashier/app.js` (imagem `node:22-alpine`) → sem saída (ok).

**Achado à parte + `?v=`** (após as correções):
- `docker compose exec web vendor/bin/phpunit` (sem banco de teste) → `OK, but some tests were skipped! Tests: 322, Assertions: 502, Skipped: 74.` (antes: 16 erros)
- `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration --filter ItemDiscountTest` → `OK (16 tests, 106 assertions)`
- PHPStan `[OK] No errors`; CS-Fixer `Found 0 of 117 files that can be fixed`.
- HTML servido por `web-e2e` (`curl` de dentro do container) em `/cashier/`, `/kitchen/`, `/admin/index.php` → `style.css?v=1793463762` e `version-badge.js?v=1793230688` nas três.
- Playwright `offline-assets` + `build-dish-search` + `connection-status` → `26 passed`.

Pendente: CI no PR.
