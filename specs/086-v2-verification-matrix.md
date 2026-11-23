# Spec 086 — Fechamento da v2.0.0: Verification Matrix

## Metadata

- Status: In Progress
- Created: 2026-11-21
- Updated: 2026-11-23
- Owner: Henry
- Related issue: Trello "🧭 Plano de fechamento — GastroFlow Community v2.0" (https://trello.com/b/8wJbH7PY) e os 12 cards "v2.0 Verification — …" da lista `🧪 v2.0`
- Related branch: `release/086-v2-verification` (a criar)

## Context

O Henry pediu, em 2026-11-21, para **concluir** a v2.0.0. O card de plano de fechamento no Trello define a ordem:

1. backlog restante que seja bug, security ou docs → feito (specs 076–085, todas mergeadas);
2. ROADMAP, Trello e docs sem fiscal interno → feito (specs 083 e 085);
3. CI verde no HEAD → feito (`CI` do master em `43eb8a1`, run 44950866298, success, 2026-11-21);
4. feature freeze → em vigor;
5. `v2.0.0-rc.1`;
6. passar a Verification Matrix contra o RC, **reaproveitando a evidência anterior que ainda vale** e repetindo só o que mudanças posteriores possam ter invalidado;
7. specs só para os blockers encontrados;
8. `rc.2` só se houver correção;
9. `v2.0.0`.

Esta spec cobre os passos 5 a 9.

## Problem

Hoje a matriz do `docs/ROADMAP.md` (seção "v2.0 Verification Matrix") tem **0 de 60 itens marcados**. No Trello, 5 dos 12 cards de verificação estão completos, com comentário de 2026-11-10: Fresh installation, Printing, Recovery, Network/offline e Upgrade. Esses são os cinco que dependem de pessoa e hardware, confirmados pelo owner. Os outros 7 (Authentication, Ordering, Kitchen, Reports, Jobs, Quality, Documentation) não têm nenhum item marcado. O ROADMAP e o board divergem, e nenhum dos dois aponta a evidência de cada item.

## Goals

- Cada um dos 60 itens da matriz tem uma evidência nomeada: um teste automatizado pelo nome, um comando observado com a saída, ou a validação do owner com data.
- A matriz é passada contra a tag `v2.0.0-rc.1`, não contra um `master` que continua andando.
- As lacunas de teste que a matriz revela viram testes novos, sem mudança de comportamento.
- O ROADMAP e o Trello mostram o mesmo estado.
- Com tudo verde, a `v2.0.0` sai pelo fluxo normal (PR de release, CHANGELOG datado, tag com confirmação).

## Non-goals

- Features novas (RC).
- Os cards de `🧹 Backlog / Follow-ups` (SSE travado, logo fora do backup, rotação do `app.log` etc.), salvo se a matriz mostrar que algum deles é blocker.
- Os passos 10 a 12 do plano (renomear o repositório, mirror `gastroflow-cloud`), que vêm depois da tag.
- Paginação: o ROADMAP já registra (spec 025) que nenhum endpoint tem resultado ilimitado. O item "Pagination works where applicable" fica marcado como *não aplicável*, com essa referência.

## Current behavior

Confirmado em 2026-11-21:

- **Mudanças desde a `v1.9.0`** (`git diff --stat v1.9.0..HEAD`): nenhuma migration, nenhuma mudança em `docker-compose*.yml`, `Dockerfile` ou `bin/`. Mudaram `CorsMiddleware` (079), `Routes.php` com o `JWT_SECRET` vazio (082), `OrderValidator` e a senha numérica no caixa e na cozinha (076), a redação no audit (081), `Nff7Client`/`FiscalController`/cartão no Settings (084), e foi removido código morto (078). Portanto as validações físicas de 2026-11-10 continuam valendo, com três pontos a reconfirmar:
  - instalação limpa e upgrade: `JWT_SECRET` vazio agora derruba o app (082) e o `.env.example` traz `CORS_ALLOWED_ORIGIN` vazio (079). Os docs de instalação e upgrade foram atualizados, mas não houve uma instalação seguindo-os depois disso;
  - impressão: a senha impressa no cupom agora é só numérica (076). O formato do cupom não mudou, só a validação da entrada;
  - rede: o NF-F7 está desligado por padrão e, quando ligado, é LAN (compose). Nenhum caminho de pedido chama o NF-F7 (084).
- **Quality**: rodado no `master` `43eb8a1`:
  - `composer validate --strict`: OK;
  - `composer audit`: "No security vulnerability advisories found";
  - PHPStan: `[OK] No errors`;
  - PHP-CS-Fixer: 0 de 124;
  - PHPUnit com `MYSQL_DATABASE_TEST=restaurant_test`: 367 testes, 991 asserções, OK, 2 skipped;
  - Playwright contra a porta 8081: 48 passed.
- **Cobertura automatizada que já existe**, por área da matriz:
  - Authentication: `AuthenticationTest` (login certo, senha errada, usuário inexistente, throttle, token ausente, token malformado, cashier barrado nos relatórios, manager aceito nos relatórios, manager barrado nas configurações), `RoutesJwtSecretTest`, `LoginThrottleServiceTest`. **Lacunas: token expirado** (o `JwtMiddleware.php:49` trata `ExpiredException` com 401 `TOKEN_EXPIRED`, mas nenhum teste cobre) **e troca de senha** (`PATCH /api/admin/account/password`, `AuthController::changePassword`, sem nenhum teste).
  - Ordering: `OrderWorkflowTest`, `ConcurrencyTest::testConcurrentOrdersGetDistinctOrderNumbers`, `OrderValidatorTest`, `PricingServiceTest`, `ItemDiscountTest`, `MoneyTest`, e os snapshots históricos em `MigrationUpgradeTest::testUpgradeFromV171PreservesHistoricalOrderAndReachesHead`.
  - Kitchen: `OrderWorkflowTest` (concluir e reabrir, cancelado não reabre), `RealtimeEventTest`, e no E2E `realtime-events`, `connection-status` ("pedido criado durante a queda aparece ao reconectar"), `api-errors`, `printer-block`.
  - Printing: `PrintingTest`, `PrinterBlockTest`, `PrintServiceTest`, mais a impressora física (owner, 2026-11-10).
  - Reports: `ReportServiceTest`, `MenuReportsAndJourneyTest::testEndToEndJourneyFromOrderToReport`, `ItemDiscountTest::testReportsAreNetOfDiscounts`. Os relatórios filtram por `orders.business_date` (`ReportService.php:35`), não por `created_at`. **Lacuna: borda de fuso horário** (nenhum teste cria pedido perto da meia-noite em `America/Sao_Paulo`).
  - Jobs: `JobServiceTest`, `ConcurrencyTest` (dois workers no mesmo job; quatro workers, cada handler roda uma vez), `bin/jobs-status`.
  - Recovery, Network, Upgrade, Fresh install: validação do owner (2026-11-10), `MigrationUpgradeTest`, `MigrationStatusTest`, E2E `offline-assets`.

## Proposed behavior

Nenhum comportamento do produto muda. A entrega é:

1. testes novos para as três lacunas;
2. a tag `v2.0.0-rc.1` e a pré-release que o `release.yml` publica sozinho a partir dela;
3. a matriz passada contra a rc.1, com a evidência de cada item registrada nesta spec e no card de cada área;
4. o ROADMAP marcado item a item;
5. o PR de release da `v2.0.0`.

## Functional requirements

1. FR1: um teste de integração envia um JWT assinado com o segredo do app e com `exp` no passado para uma rota com `JwtMiddleware` e recebe 401 com código `TOKEN_EXPIRED`.
2. FR2: um teste de integração cobre a troca de senha:
   - senha atual errada → 401 `CURRENT_PASSWORD_INCORRECT`, e a senha não muda;
   - nova senha inválida (abaixo do mínimo do `AuthValidator`) → 400 `VALIDATION_FAILED`;
   - sem token → 401;
   - troca válida → 200; depois disso o login com a senha antiga falha e o login com a nova funciona.
3. FR3: um teste de integração cria pedidos às 23:59 e às 00:01 (horário de `America/Sao_Paulo`) de dias consecutivos e verifica que cada um entra no `business_date` e no relatório do seu próprio dia. **Se o teste revelar um bug, a correção vira uma spec própria** e esta spec fica bloqueada até ela ser mergeada.
4. FR4: cada item da matriz tem, nesta spec, uma linha com o item, a evidência (teste/comando/validação do owner) e a data.
5. FR5: os itens de instalação limpa e upgrade são repetidos com a rc.1, porque as specs 079 e 082 mudaram o `.env` que os docs mandam preencher:
   - instalação limpa: um checkout da tag numa pasta descartável do scratchpad, com nome de projeto Compose próprio e portas sem conflito, seguindo só `docs/installation.md`;
   - upgrade: a instalação piloto do owner, seguindo `docs/upgrading.md`.
6. FR6: o resultado de um item nunca é inventado. Um item sem evidência fica `[ ]`, e a `v2.0.0` não é proposta enquanto houver algum assim.

## Non-functional requirements

- Os testes novos usam a base de integração (`IntegrationTestCase`) e nunca tocam o banco de desenvolvimento.
- O segredo JWT só é lido dentro do processo de teste, para assinar o token, e nunca é impresso.
- A instalação limpa da FR5 usa um volume próprio. Nada de `docker compose up` com o arquivo de produção sobre o stack de desenvolvimento.

## User flows

Não aplicável: nenhuma tela ou fluxo de operador muda.

## API changes

Não aplicável: nenhuma.

## Data model and migrations

Não aplicável: nenhuma.

## Architecture and affected components

- `tests/Integration/AuthenticationTest.php`: FR1 e FR2.
- `tests/Integration/` (o teste de relatório mais próximo, provavelmente `MenuReportsAndJourneyTest.php`): FR3.
- `docs/ROADMAP.md`: matriz marcada; o cabeçalho passa a `Current version: v2.0.0` no release.
- `CHANGELOG.md`: "Não lançado" vira `## v2.0.0-rc.1 (data) — …`, depois `## v2.0.0 (data) — GastroFlow Community 2.0`.
- `README.md`: a versão, se citada.
- Esta spec (evidência).
- Nenhum arquivo em `src/` ou `public/`, salvo uma correção que vier de uma spec própria (FR3).

## Security considerations

FR1 e FR2 aumentam a cobertura de segurança (token expirado, troca de senha) sem mudar o código. Nenhum segredo vai para log, spec ou card.

## Backward compatibility

Não aplicável: só entram testes e documentação.

## Acceptance criteria

1. AC1: `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --filter 'Expired|Password'` passa com os testes de FR1 e FR2.
2. AC2: o teste de FR3 passa ou gera uma spec de correção mergeada antes da rc.1 (ou da rc.2).
3. AC3: a tag `v2.0.0-rc.1` existe e o `release.yml` publicou a pré-release: imagem `ghcr.io/focusevengithub/gastroflow:2.0.0-rc.1`, sem as tags `2.0` e `2`, e a GitHub Release marcada como pre-release.
4. AC4: com o checkout da rc.1, seguindo `docs/installation.md`, os itens são verificados por comando, nesta ordem:
   - `docker compose up -d` sobe `db`, `web` e `print-worker`, e o `migrate` sai com 0;
   - `bin/create-admin` cria o administrador;
   - o login responde 200;
   - `/health/ready` responde 200;
   - o menu está vazio.
5. AC5: a CI de qualidade completa e o E2E passam no commit da tag rc.1.
6. AC6: os 60 itens do ROADMAP estão `[x]` (ou marcados como *não aplicável* com referência) e cada um tem a sua linha de evidência nesta spec.
7. AC7: os 12 cards de verificação estão completos, com comentário apontando esta spec.
8. AC8: a tag `v2.0.0` sai só depois da confirmação explícita do owner e do PR de release mergeado, e `.github/scripts/release-notes.sh v2.0.0` imprime a seção datada.

## Implementation plan

1. Branch `release/086-v2-verification`; testes de FR1, FR2 e FR3; rodar a suíte.
2. Registrar nesta spec a evidência de Quality que já foi coletada (Current behavior) e mapear os itens de Authentication, Ordering, Kitchen, Reports e Jobs aos testes pelo nome.
3. No mesmo PR: o CHANGELOG ganha a entrada desta spec e "Não lançado" vira `v2.0.0-rc.1 (data)`; o ROADMAP vira `Current milestone: v2.0.0 — rc.1`. Rodar `/spec-review`, abrir o PR, e o owner mergeia.
4. **Com a confirmação do owner**: tag `v2.0.0-rc.1` no merge; conferir o `release.yml` (AC3).
5. Passar a matriz contra a rc.1:
   - Quality: rodar de novo no commit da tag;
   - instalação limpa no scratchpad (AC4);
   - documentação: passe de docs (README, CLAUDE.md, architecture, technical-decisions, installation/backup/printing);
   - itens do owner, que eu listo para ele: upgrade da instalação piloto para a rc.1, impressão de um pedido real com a senha numérica, caixa sem WAN.
6. Blockers → spec própria → `rc.2`, voltando ao passo 5 só para o que a correção afetar.
7. PR de release da `v2.0.0`: a matriz marcada no ROADMAP, a seção `## v2.0.0 (data) — GastroFlow Community 2.0` no CHANGELOG (o conteúdo da rc.1, mais o que entrou depois), as versões no ROADMAP e no README, e esta spec `Verified`. Depois do merge e com a confirmação do owner, a tag `v2.0.0`.
8. Trello: cards de verificação completos ao longo do caminho; o card 🚀 vai para `✅ Concluído / Histórico` com PR e data da tag.

## Testing and validation strategy

Existe infraestrutura automatizada: PHPUnit com Unit, Smoke e Integration contra MySQL real, Playwright E2E e a CI. A nota do template sobre "no automated test infrastructure" está desatualizada e não se aplica. Os itens físicos (impressora, WAN, instalação piloto) não são automatizáveis aqui e são registrados como validação do owner com data. Cada item da matriz é mapeado na tabela de Validation evidence.

## Rollout and rollback

- A rc.1 é uma pré-release: imagem só com a tag exata, sem `2.0`, `2` ou `latest` (spec 072), e as instalações que seguem tag estável não a pegam.
- Rollback da rc: não promovê-la. Uma tag publicada não é apagada; corrige-se com a rc.2.
- A `v2.0.0` segue o rollback normal do `docs/upgrading.md` (voltar à tag anterior e restaurar o backup).

## Open questions

*(Ambas resolvidas em 2026-11-21: o Henry respondeu "segue o plano com a rc.1, pode implementar", aprovando a rc.1 e o reaproveitamento da evidência de 2026-11-10 previsto no plano.)*

1. ~~**(bloqueante) Cortar a `v2.0.0-rc.1` antes de passar a matriz?**~~ O plano de fechamento no Trello (passos 5 e 6) diz que sim: a matriz é passada contra o RC. Em 2026-11-21 o Henry disse "não quero cortar a 2.0, quero concluí-la". Como entendi: ele quer chegar à `v2.0.0` final, e a rc.1 é um passo do caminho, não a versão final. Precisa de confirmação. A alternativa é passar a matriz contra o `master` (que está congelado) e ir direto para a `v2.0.0`, sem RC. Isso é mais rápido, mas foge do plano e da política de RC do ROADMAP.
2. Os itens físicos de 2026-11-10 (impressora, WAN, backup→restore, instalação por outra pessoa, upgrade real) podem ser reaproveitados, com a ressalva da FR5 (repetir só a instalação limpa e o upgrade). O plano diz para reaproveitar a evidência que ainda vale, e o diff desde a `v1.9.0` não toca compose, imagem, `bin/` nem migrations. Precisa de confirmação do owner.

## Task checklist

- [x] Testes FR1 (token expirado), FR2 (troca de senha), FR3 (borda de fuso)
- [x] Mapeamento de evidência de Authentication/Ordering/Kitchen/Reports/Jobs (tabela abaixo; marcação final contra a rc.1)
- [x] CHANGELOG `v2.0.0-rc.1 (2026-11-23)` e cabeçalho do ROADMAP no working tree
- [x] `/spec-review` (2026-11-23): sem bloqueador; corrigidos data do heading, variável sem uso e comentário do teste
- [ ] PR da spec aberto e mergeado
- [ ] Tag `v2.0.0-rc.1` (confirmação) e pré-release conferida
- [ ] Quality no commit da rc.1
- [ ] Instalação limpa da rc.1 (scratchpad)
- [ ] Passe de documentação
- [ ] Itens do owner: upgrade piloto, impressão real, caixa sem WAN
- [ ] Blockers resolvidos (ou nenhum)
- [ ] PR de release `v2.0.0`, matriz completa no ROADMAP
- [ ] Tag `v2.0.0` (confirmação)
- [ ] Trello sincronizado

## Implementation log

- 2026-11-21: `/spec-implement` iniciado com a aprovação explícita do Henry (Draft → Approved → In Progress). Seguindo a regra do skill, trabalho no working tree do `master` sem trocar de branch; a branch `release/086-v2-verification` é criada quando ele pedir o commit e o PR.
- FR1 e FR2 entraram em `tests/Integration/AuthenticationTest.php` (`testExpiredTokenIsRejectedAsExpired`, `testPasswordChangeRefusesBadInputAndThenChangesThePassword`). O token vencido é assinado dentro do processo de teste com o `JWT_SECRET` do ambiente de teste, que nunca é impresso.
- **Desvio da FR3, por limite técnico:** não dá para congelar o relógio do PHP num teste de integração, então não existe um pedido *criado* de fato às 23:59. `MenuReportsAndJourneyTest::testOrdersAroundMidnightBelongToTheirOwnBusinessDay` faz o seguinte:
  - confere que o `business_date` de um pedido novo é a data local (`date_default_timezone_get()`, que vem do `APP_TIMEZONE`/`TZ`) (não prova "não UTC": se o fuso configurado fosse UTC, passaria igual);
  - leva dois pedidos concluídos para 2026-03-10 23:59 e 2026-03-11 00:01;
  - confere que os relatórios `sales` e `peak-hours` de cada dia mostram só o pedido do dia, na hora certa (23h e 0h).

  O limite está anotado no código como `shortcut:`. Nenhum bug foi encontrado, então nenhuma spec de correção foi aberta.
- Achado de ambiente, não do produto: localmente `MigrationUpgradeTest` (2 testes: upgrade da v1.7.1 e instalação limpa sem dados) é *skipped*, porque o `restaurant_test_upgrade` não existe e o usuário do app não pode criá-lo. A CI cria esse banco (`.github/workflows/ci.yml:130-141`), e o run do master passou. O log do run não pôde ser lido daqui (`HTTP 403: Must have admin rights`), então a prova de que esses dois testes rodaram (e não foram pulados) na CI ainda falta. Fica como item para o Henry: abrir o log do run, ou criar o banco localmente com `CREATE DATABASE restaurant_test_upgrade` mais o `GRANT` (ver Validation evidence).
- CHANGELOG: "Não lançado" passou a `## v2.0.0-rc.1 (2026-11-23) — Release Candidate 1` (escrito 2026-11-21, redatado para 2026-11-23 no /spec-review), com um parágrafo de abertura e a entrada desta spec em "Qualidade". **Se a tag sair em outro dia, a data do heading precisa ser corrigida antes da tag.** O ROADMAP marca a rc.1 no cabeçalho, e o README continua em `v1.9.0`: é a versão estável, e a rc é pré-release.
- Passe de docs: `architecture.md`, `technical-decisions.md`, `000-project-baseline.md` e `README.md` não citam esses testes nem contagens de testes atuais, então não há nada que tenha ficado falso.

## Validation evidence

Coletado antes da implementação (2026-11-21, `master` `43eb8a1`, ainda não a rc.1):

| Comando | Resultado |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `composer audit` | `No security vulnerability advisories found.` |
| `vendor/bin/phpstan analyse` | `[OK] No errors` |
| `vendor/bin/php-cs-fixer fix --dry-run --diff` | `Found 0 of 124 files that can be fixed` |
| `MYSQL_DATABASE_TEST=restaurant_test vendor/bin/phpunit` | `Tests: 367, Assertions: 991, Skipped: 2` — OK |
| `npx playwright test` (porta 8081) | `48 passed (1.2m)` |
| `gh run list --branch master --workflow ci.yml` | run 44950866298 `success` em `43eb8a1` |

Depois dos testes novos (2026-11-21, working tree sobre `43eb8a1`):

| Comando | Resultado |
|---|---|
| `phpunit --filter 'Expired\|PasswordChange\|AroundMidnight'` (com `MYSQL_DATABASE_TEST`) | `OK (7 tests, 32 assertions)` — AC1, e AC2 sem bug |
| `phpunit` completo (com `MYSQL_DATABASE_TEST`) | `Tests: 370, Assertions: 1019, Skipped: 2` — OK |
| `phpstan analyse` | `[OK] No errors` |
| `php-cs-fixer fix --dry-run --diff` | `Found 0 of 124 files that can be fixed` |
| `.github/scripts/release-notes.sh --title v2.0.0-rc.1` | `v2.0.0-rc.1 — Release Candidate 1` |
| `phpunit --display-skipped` | os 2 skipped são `MigrationUpgradeTest` (sem `MYSQL_DATABASE_TEST_UPGRADE`); com a variável, `1044 Access denied … 'restaurant_test_upgrade'` (o banco não existe localmente) |

### Matriz item a item

Legenda: **T** = teste automatizado (passou nos runs acima); **O** = validação do owner em 2026-11-10 (comentário nos cards do Trello), ainda válida porque o diff `v1.9.0..HEAD` não toca compose, `Dockerfile`, `bin/` nem migrations; **rc.1** = repetir contra a tag; **NA** = não aplicável. Nada está marcado no ROADMAP ainda: a marcação acontece contra a rc.1.

| Área | Item | Evidência |
|---|---|---|
| Fresh install | Clone/install limpo · só a config documentada · containers sobem · migrations · admin criado · sem dados privados · usável sem mexer no código | O (spec 062, instalação por outra pessoa) + T `MigrationUpgradeTest::testFreshInstallStartsWithoutRestaurantData` (só na CI) + **rc.1** (AC4, por causa das specs 079/082) |
| Auth | Login válido | T `AuthenticationTest::testLoginSucceedsWithCorrectCredentials` |
| Auth | Login inválido falha com segurança | T `testLoginFailsWithWrongPassword`, `testLoginFailsForUnknownUser`, `testLoginIsThrottledAfterTenFailures` |
| Auth | Autenticação vencida falha com segurança | T `testExpiredTokenIsRejectedAsExpired` (novo) |
| Auth | Tokens inválidos recusados | T `testAdminRouteRejectsMissingToken`, `testAdminRouteRejectsMalformedToken`, `RoutesJwtSecretTest` |
| Auth | Papéis respeitados · operações não autorizadas bloqueadas | T `testReportsRefuseACashierToken`, `testReportsAcceptAManagerToken`, `testSettingsRefuseAManagerToken`, `AuditLogTest::testAuditLogEndpointRequiresAdminRole`, `FiscalStatusTest::testRequiresAnAdmin`, `PrintingTest::testTestPrintStillRequiresAnAdminToken`, `MenuReportsAndJourneyTest::testMenuMutationIsRefusedWithoutAToken` |
| Auth | Troca de senha funciona | T `testPasswordChangeRefusesBadInputAndThenChangesThePassword` (novo) |
| Ordering | Pedido criado | T `OrderWorkflowTest::testOrderCreationPersistsOrderAndItems` |
| Ordering | `order_number` correto | T `testOrderNumberIsAssignedPerBusinessDate`, `testNonNumericOrderNumberIsRejectedAndPersistsNothing` |
| Ordering | Sem número duplicado em concorrência | T `ConcurrencyTest::testConcurrentOrdersGetDistinctOrderNumbers` |
| Ordering | Itens inválidos recusados | T `testOrderWithNonexistentMenuItemIsRejectedAndPersistsNothing`, `OrderValidatorTest` |
| Ordering | Quantidades inválidas recusadas | T `OrderValidatorTest::testInvalidQuantityIsRejectedOnOrderCreation`, `testOrderItemAddRejectsQuantityAboveMax`, `testOrderItemUpdateRejectsQuantityAboveMax` |
| Ordering | Preço exato | T `testPersistedTotalMatchesPricingService`, `PricingServiceTest`, `MoneyTest`, `ItemDiscountTest` |
| Ordering | Nome e preço históricos | T `MigrationUpgradeTest::testUpgradeFromV171PreservesHistoricalOrderAndReachesHead` (só na CI), `ReportServiceTest::testNameIsTheMostRecentSnapshotAndTiesAreSortedByName` |
| Kitchen | Pedidos novos aparecem | T `MenuReportsAndJourneyTest::testEndToEndJourneyFromOrderToReport` (passo 2), E2E `realtime-events` |
| Kitchen | Transições de status · transições inválidas falham · reabrir como documentado | T `OrderWorkflowTest::testCompleteThenReopenMovesStatusBothWays`, `testCancelledOrderCannotBeReopened`, `testUnknownOrderReturns404`, `AuditLogTest::testReopeningOrderWritesAuditRow` |
| Kitchen | Reconexão do tempo real | T `RealtimeEventTest::testQueryHonorsLastEventIdCursor`; E2E `connection-status` "pedido criado durante a queda aparece ao reconectar", `realtime-events` (Last-Event-ID) |
| Kitchen | Falha de rede visível ao operador | E2E `connection-status` (Reconectando → Conexão perdida → Conectado; stream caído nunca "Conectado"), `api-errors` |
| Printing | Teste de impressão · impressão de pedido · falha não perde o pedido · falhas visíveis · retry · reimpressão | O (impressora física) + T `PrintingTest`, `PrinterBlockTest`, `OrderWorkflowTest::testPrintTicketEnqueuesExactlyOnePendingPrintJob`, `JobServiceTest::testFailingJobIsReleasedForRetryBeforeMaxAttempts`, E2E `printer-block` + **rc.1** (owner: um cupom real com a senha numérica da spec 076) |
| Reports | Totais batem com os pedidos | T `testEndToEndJourneyFromOrderToReport` (centavos), `ItemDiscountTest::testReportsAreNetOfDiscounts` |
| Reports | Preços históricos respeitados | T `ReportServiceTest::testNameIsTheMostRecentSnapshotAndTiesAreSortedByName`, `testMainDishRevenueIsNetOfItemDiscounts` |
| Reports | Filtro de data | T `testOrdersAroundMidnightBelongToTheirOwnBusinessDay` (novo), `ReportServiceTest::testCountsOnlyCompletedMainDishesWithinTheRange` |
| Reports | Borda de fuso horário | T `testOrdersAroundMidnightBelongToTheirOwnBusinessDay` (novo; ver o desvio no log) |
| Reports | Paginação onde aplicável | NA: spec 025 / ROADMAP "Pagination", nenhum endpoint com resultado ilimitado |
| Jobs | Claim seguro · sem duplicar com vários workers | T `ConcurrencyTest::testTwoWorkersCannotClaimTheSameJob`, `testManyWorkersDrainTheQueueRunningEachHandlerOnce` |
| Jobs | Retry · tentativas contadas · diagnóstico | T `JobServiceTest` (`testFailingJobIsNotDeletedAndIncrementsAttempts`, `testMaxAttemptsReachedMarksJobFailedAndNotReprocessed`, `testPermanentFailureLogCarriesRequestIdFromPayload`, `testStaleReservation*`), `PrintingTest::testUnconfiguredPrinterFailsTheJobAndLeavesTheOrderIntact` |
| Recovery | Backup · restore · funciona depois · procedimento bate com o doc | O (backup→restore real; spec 045) |
| Network | Caixa, cozinha e impressão sem WAN · dependências locais | O (operação sem WAN) + E2E `offline-assets` (8 telas sem internet); a spec 084 não pôs chamada externa no caminho do pedido (NF-F7 desligado por padrão e, quando ligado, LAN) |
| Upgrade | v1.x atualiza · pedidos, usuários e settings sobrevivem · migrations preservam o histórico | O (upgrade real) + T `MigrationUpgradeTest` (só na CI) + **rc.1** (owner: piloto → rc.1 com `docs/upgrading.md`, por causa da 082) |
| Quality | validate · audit · static analysis · style · unit · integration · E2E | T, tabelas acima; repetir no commit da tag (AC5) |
| Docs | README · CLAUDE.md · arquitetura · decisões técnicas · instalação/backup/impressão testados · CHANGELOG completo | **rc.1**: passe de docs + AC4; instalação/backup/impressão também cobertos por O |

### Ainda sem evidência (bloqueia a `v2.0.0`)

1. AC3: tag `v2.0.0-rc.1` e pré-release publicada (precisa de commit, PR, merge e a confirmação da tag).
2. AC4: instalação limpa da rc.1 no scratchpad.
3. AC5: Quality + E2E no commit da tag.
4. Prova de que `MigrationUpgradeTest` rodou de fato na CI, e não foi pulado.
5. Owner: upgrade do piloto para a rc.1, um cupom real impresso pela rc.1, e a confirmação de que a evidência de 2026-11-10 vale para a rc.1.
6. Passe de documentação contra a rc.1.
7. AC6–AC8: ROADMAP marcado, cards completos, PR de release e tag `v2.0.0`.
