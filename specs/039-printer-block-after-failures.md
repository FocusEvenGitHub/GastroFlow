# Spec 039 — Bloquear impressão após 3 falhas consecutivas e notificar o operador

## Metadata

- Status: Verified
- Created: 2026-09-24
- Updated: 2026-09-24
- Owner: Henry
- Related issue: Not applicable (pedido direto do dono, fora dos itens do `docs/ROADMAP.md`)
- Related branch: `039`

## Context

Pedido do dono, textualmente: *"Faça para que desabilite o botão de imprimir após 3 erros
consecutivos de impressão, sempre que falhar faça uma notificação UI que informe que houve erro
no pedido"*.

Isto só é possível agora porque a **spec 038** (mesclada hoje) fez uma impressão que não
aconteceu **realmente falhar** — antes, uma impressora não configurada marcava o job como
`completed`, então não havia falha para contar.

Três decisões foram tomadas pelo dono antes deste rascunho e são premissas, não perguntas em
aberto:

1. A contagem é **global, no nível da impressora** — três jobs falhando em sequência, de
   qualquer pedido, indicam impressora fora. Não é por pedido.
2. A reativação é **manual** — nada volta sozinho por tempo nem automaticamente.
3. O bloqueio vale na **cozinha e no caixa**, mas no caixa o pedido continua sendo criado; só a
   impressão deixa de ser enfileirada.

## Problem

1. **Com a impressora fora, o operador fica tentando contra um equipamento morto.** Nada na
   interface indica que as últimas impressões falharam; o botão de reimprimir continua clicável
   e cada clique enfileira mais um job que vai falhar. A spec 033 encontrou **47 jobs de
   impressão falhos acumulados** no banco de desenvolvimento exatamente assim.
2. **A UI nunca fica sabendo de uma falha.** Depois da spec 038 a cozinha diz honestamente "na
   fila de impressão", mas o que acontece com essa fila é invisível: o operador só descobre a
   falha se alguém abrir o visualizador de Logs do Admin ou rodar `bin/jobs-status` no terminal.
   Nenhum dos dois é o fluxo de quem está atendendo.

## Goals

- Três falhas consecutivas de impressão bloqueiam novas impressões, na cozinha e no caixa.
- O operador é avisado na tela a cada falha, com o pedido afetado.
- Um botão explícito reativa a impressão quando a impressora for resolvida.
- Criar pedido continua funcionando com a impressão bloqueada, sem exceção.

## Non-goals

- **Não mudar o modelo assíncrono.** Criar pedido e reimprimir continuam retornando antes de a
  impressora ser tocada (spec 008 FR8 e a regra dura do roadmap).
- **Não construir canal de tempo real novo.** O item "Realtime reliability" do `v1.8.0` já
  planeja substituir o mecanismo de arquivo-sinal; antecipar isso aqui invadiria aquele item.
- **Não reenfileirar jobs falhos automaticamente.** A spec 033 os preserva de propósito como
  registro de diagnóstico.
- **Não mexer em `bin/jobs-status` nem criar UI de gestão em massa da fila** — continua fora de
  escopo, como a spec 038 já registrou.
- **Nenhuma dependência nova.**

## Current behavior

Confirmado lendo o código em 2026-09-24.

- **Nenhum polling existe no projeto.** `grep setInterval` em `public/kitchen/app.js` e
  `public/cashier/app.js` não retorna nada. A cozinha descobre estado só por SSE
  (`connectSSE()`) e por `fetch` disparado por esses eventos; o caixa não busca nada sozinho.
- **O SSE atual não pode carregar falha de impressão.** `public/api/events/stream.php:38` e
  `OrderService::triggerKitchenEvent()` (`src/Services/OrderService.php:111`) usam
  `sys_get_temp_dir() . '/gastroflow-events.json'` — um arquivo no sistema de arquivos **do
  próprio container**. O worker de impressão roda em outro container
  (`restaurant_print_worker`, `docker-compose.yml`), então um evento emitido por ele escreveria
  no `/tmp` dele, invisível ao container `web` que serve o SSE. Confirmado: os dois lados não
  compartilham volume algum em `/tmp`.
- **A tabela `jobs` é o único estado que os dois containers enxergam**, porque o MySQL é
  compartilhado.
- `src/Jobs/PrintOrderJob.php` recebe `handle(array $data, ?Job $job = null)` e já tem o
  contexto do job (`id`, `attempts`, `max_attempts`); `PrintService::printOrder()` lança em
  qualquer falha (spec 008 + 038), e `JobService::recordFailure()` decide entre retry e falha
  permanente comparando `attempts >= max_attempts`.
- `src/Models/Setting.php` expõe `getValue(string $key, $default = null): ?string` e
  `setValue(string $key, ?string $value): void` sobre uma tabela `settings` chave/valor.
- `public/cashier/app.js:14` tem `printTicket: true`, enviado como `print_ticket` em `:267`.
- `public/kitchen/app.js` tem `reprintOrder()` e `showMessage(text, type = 'info')`, com estilos
  para `success`/`danger`/`warning`/`info` já em `public/assets/css/style.css`.
- `/api/orders*` e `/api/kitchen/*` são **deliberadamente públicos** (spec 018): rede confiável,
  e nem cozinha nem caixa têm tela de login.

## Proposed behavior

### 1. O estado mora em `settings`, e não é derivado da tabela `jobs`

Duas chaves novas, via o `Setting` que já existe:

- `printer_consecutive_failures` — inteiro, zerado a cada impressão bem-sucedida.
- `printer_blocked_at` — timestamp de quando o bloqueio disparou, ou vazio.

**Derivar contando os últimos jobs da fila seria mais simples, e está errado.** O
`bin/jobs-prune` (spec 033) apaga jobs **concluídos** e preserva os falhos. Depois da retenção,
uma sequência real de `falha, falha, sucesso, falha` vira, na tabela, `falha, falha, falha` — o
sucesso que quebrava a sequência foi podado. O estado derivado passaria a afirmar três falhas
consecutivas que nunca existiram. Persistir um contador não tem esse problema e ainda sobrevive
à poda. Sem mudança de schema: `settings` já é chave/valor.

### 2. "Consecutivas" conta **jobs que falharam de vez**, não tentativas

A spec 008 dá `max_attempts = 3` por job, com backoff de 2s/4s/8s — um único job leva ~14s até
falhar permanentemente. Contar **tentativas** bloquearia a impressora depois de ~14s por causa
de **um** pedido, o que não é evidência de impressora fora. Contar **jobs falhos** exige ~45s e
três pedidos distintos falhando, que é a evidência que o pedido do dono descreve.

O incremento acontece em `PrintOrderJob`, que sabe que o assunto é impressora — não em
`JobService`, que é código genérico de fila e não deve conhecer impressoras. O job já recebe o
`Job` e portanto sabe se esta é a última tentativa (`attempts >= max_attempts`).

### 3. O bloqueio é servido por um endpoint público, e vale também no servidor

- `GET /api/printer/status` → `{"blocked": bool, "consecutive_failures": int,
  "last_failed_order_id": int|null, "last_error": string|null}`.
- `POST /api/printer/reset` → zera o contador, limpa o bloqueio, responde o novo status.

**Públicos**, como `/api/orders*` e `/api/kitchen/*`. Protegê-los os tornaria inúteis: cozinha e
caixa não têm login, então um endpoint com JWT não seria consumível exatamente pelas duas telas
que precisam dele. É a mesma decisão consciente que a spec 018 registrou, e está declarada na
seção de segurança em vez de ficar implícita.

**O bloqueio também vale no servidor**: com a impressora bloqueada, `OrderService::createOrder()`
**não enfileira** o job de impressão, mesmo com `print_ticket=true`. Sem isso o bloqueio seria
cosmético — qualquer cliente da API continuaria empilhando jobs condenados. O pedido é criado
normalmente e retorna `201`; apenas a impressão é pulada, e isso é registrado no log.

### 4. A UI descobre por polling, com a frequência declarada

Cozinha e caixa consultam `GET /api/printer/status` a cada **15 segundos**. Não há polling algum
no projeto hoje, então este intervalo é uma escolha nova e explícita: rápido o bastante para o
operador perceber dentro de um ciclo de atendimento, lento o bastante para não pesar (4
requisições por minuto por tela, contra uma tabela pequena).

**Notificação por falha**: a resposta traz `last_failed_order_id`. A tela guarda o último id
visto e mostra um toast `danger` quando ele muda — "Erro ao imprimir o pedido #N". É assim que a
UI cumpre "sempre que falhar faça uma notificação" sem um canal de tempo real novo.

**Quando bloqueado**: a cozinha desabilita o botão de reimprimir e mostra um aviso persistente
com o botão "Reativar impressão"; o caixa desabilita o `print_ticket` e mostra o mesmo aviso. O
caixa continua criando pedidos.

### 5. Reativar destrava, e só isso

`POST /api/printer/reset` zera o contador e o bloqueio. **Não reenfileira nada**: os jobs já
`failed` continuam `failed`, porque a spec 033 os mantém de propósito como registro. Se o
operador quiser os tickets, ele reimprime os pedidos — o botão volta a funcionar.

## Functional requirements

1. FR1 — Um job de impressão que falha permanentemente (`attempts >= max_attempts`) incrementa
   `printer_consecutive_failures`.
2. FR2 — Uma impressão bem-sucedida zera `printer_consecutive_failures` e limpa
   `printer_blocked_at`.
3. FR3 — Ao atingir 3, `printer_blocked_at` é gravado e a impressora fica bloqueada.
4. FR4 — Uma tentativa que ainda vai ser repetida (`attempts < max_attempts`) **não** incrementa
   o contador.
5. FR5 — `GET /api/printer/status` retorna `blocked`, `consecutive_failures`,
   `last_failed_order_id` e `last_error`, sem exigir autenticação.
6. FR6 — `POST /api/printer/reset` zera contador e bloqueio e retorna o status novo.
7. FR7 — Com a impressora bloqueada, `POST /api/orders` com `print_ticket=true` retorna `201`,
   cria o pedido e **não** cria job na fila `print`.
8. FR8 — Com a impressora bloqueada, `POST /api/orders/{id}/print` não enfileira job e responde
   informando o bloqueio.
9. FR9 — A cozinha desabilita o botão de reimprimir enquanto bloqueado e oferece "Reativar
   impressão".
10. FR10 — O caixa desabilita `print_ticket` enquanto bloqueado, sem impedir a criação do pedido.
11. FR11 — Cozinha e caixa consultam o status a cada 15s e mostram um toast `danger` quando
    `last_failed_order_id` muda.
12. FR12 — Reativar não altera nenhum job existente.

## Non-functional requirements

- O endpoint de status precisa ser barato: é chamado 4×/min por tela aberta. Leitura de duas
  chaves em `settings` mais, no máximo, uma consulta ao último job falho.
- Nenhuma mensagem nova pode vazar internals (mesma regra da spec 038): o `last_error` exposto
  deve ser a mensagem já saneada que a spec 038 produz, nunca um trace.
- Sem dependência nova; sem mudança de schema.

## User flows

- **Impressora cai durante o serviço**: três pedidos falham ao imprimir (~45s). Cozinha e caixa
  mostram um toast por falha e, na terceira, o aviso de bloqueio. O botão de reimprimir fica
  desabilitado; o caixa segue lançando pedidos normalmente, sem ticket.
- **Operador resolve a impressora**: clica "Reativar impressão". O aviso some, os botões voltam,
  e ele reimprime os pedidos que precisar.
- **Falha isolada**: um pedido falha, o próximo imprime. O contador zera e nada é bloqueado.

## API changes

Dois endpoints novos, ambos públicos:

| Método | Rota | Resposta |
|---|---|---|
| `GET` | `/api/printer/status` | `200` com `{blocked, consecutive_failures, last_failed_order_id, last_error}` |
| `POST` | `/api/printer/reset` | `200` com o status atualizado |

`POST /api/orders` e `POST /api/orders/{id}/print` mantêm seus códigos de status; muda apenas o
efeito colateral (não enfileirar) e, no segundo, a mensagem.

## Data model and migrations

**Não aplicável como mudança de schema.** As duas chaves novas vivem na tabela `settings`, que
já é chave/valor e já guarda `printer_ip`/`printer_port`. Nenhuma migração é necessária — o que
deve ser confirmado na implementação, não assumido.

## Architecture and affected components

- `src/Jobs/PrintOrderJob.php` — incrementa na falha permanente, zera no sucesso.
- `src/Services/PrintService.php` — possivelmente onde moram os helpers de contador/bloqueio, já
  que é o serviço do domínio de impressão. Decidir na implementação entre ali e um método
  estático próximo de `Setting`; **não criar camada nova**.
- `src/Controllers/PrinterController.php` — `status()` e `reset()`, ao lado do `testPrint()` que
  já existe.
- `src/Routes.php` — as duas rotas públicas.
- `src/Services/OrderService.php` — pular o dispatch quando bloqueado (FR7/FR8).
- `public/kitchen/app.js` + `public/kitchen/index.php` — polling, aviso, botão desabilitado.
- `public/cashier/app.js` + `public/cashier/index.php` — idem para `print_ticket`.
- `tests/Unit/` e `tests/Integration/` — ver estratégia de testes.

## Security considerations

Os dois endpoints são **públicos por decisão consciente**, pelo mesmo motivo que `/api/orders*` e
`/api/kitchen/*` são (spec 018): a rede é confiável e as telas que precisam deles não têm login.
O que isso expõe a alguém na rede: saber que a impressora está com problema, e conseguir
destravar o bloqueio. Nenhum dos dois revela dado de cliente, credencial ou configuração — o
`last_error` exposto é a mensagem saneada da spec 038, não a exceção crua. `reset` é idempotente
e não destrói nada: não apaga jobs, não altera pedidos, não muda configuração de impressora.

Se essa exposição for indesejada, a alternativa é mover as duas telas para trás de login, o que
é uma mudança de produto muito maior e não foi pedida.

## Backward compatibility

- **Mudança de comportamento intencional**: com a impressora bloqueada, pedidos deixam de gerar
  job de impressão. O pedido em si é idêntico ao de hoje.
- Nenhum endpoint existente muda de contrato; `POST /api/orders` continua `201`.
- Sem migração, sem alteração de dados. Instalações que nunca tiverem 3 falhas seguidas nunca
  veem diferença alguma.
- As duas chaves novas em `settings` são criadas na primeira escrita; a ausência delas equivale a
  zero/desbloqueado.

## Acceptance criteria

- AC1 — Três jobs de impressão falhando permanentemente em sequência deixam
  `printer_consecutive_failures = 3` e `printer_blocked_at` preenchido.
- AC2 — Duas falhas seguidas de uma impressão bem-sucedida deixam o contador em `0` e
  `printer_blocked_at` vazio.
- AC3 — Uma tentativa intermediária (`attempts < max_attempts`) não altera o contador.
- AC4 — `GET /api/printer/status` sem token retorna `200` e `blocked: true` quando bloqueado.
- AC5 — **A regra dura**: com a impressora bloqueada, `POST /api/orders` com `print_ticket=true`
  retorna `201`, o pedido existe no banco, e `jobs` na fila `print` tem **zero** linhas novas.
- AC6 — `POST /api/orders/{id}/print` com a impressora bloqueada não cria job.
- AC7 — `POST /api/printer/reset` retorna `200`, e um `GET` seguinte traz `blocked: false` e
  `consecutive_failures: 0`.
- AC8 — `reset` não altera nenhuma linha de `jobs`: contagem por status idêntica antes e depois.
- AC9 — Depois do reset, `POST /api/orders` com `print_ticket=true` volta a criar job.
- AC10 — `last_error` retornado não contém `/var/www`, `Exception`, `Mike42` nem `.php`.
- AC11 — Suíte completa, PHPStan e PHP-CS-Fixer passam.

## Implementation plan

1. Helpers de contador/bloqueio sobre `Setting` (incrementar, zerar, consultar), com testes
   unitários.
2. `PrintOrderJob` incrementa na falha permanente e zera no sucesso (FR1, FR2, FR4).
3. `PrinterController::status()` e `reset()` + rotas públicas (FR5, FR6).
4. `OrderService` pula o dispatch quando bloqueado (FR7, FR8).
5. Integração: AC4 a AC10 por requisição real.
6. Cozinha: polling de 15s, toast por falha, botão desabilitado, botão de reativar.
7. Caixa: polling, aviso, `print_ticket` desabilitado.
8. PHPStan, PHP-CS-Fixer, suíte completa.

## Testing and validation strategy

**Correção, pela quinta spec seguida:** a instrução da skill `/spec-plan` de afirmar que este
projeto não tem infraestrutura de testes está desatualizada. PHPUnit (spec 004), CI (spec 005),
PHPStan e PHP-CS-Fixer (spec 034) e a suíte de integração com MySQL (spec 035) existem;
`specs/000-project-baseline.md:143` foi corrigido em 2026-09-03.

- **Unitário (SQLite)**: os helpers de contador, e o comportamento do `PrintOrderJob` em falha
  permanente versus tentativa intermediária — usando a costura de fábrica de conector que o
  `PrintServiceTest` já tem.
- **Integração (MySQL real, HTTP real)**: AC4 a AC10. A regra dura (AC5) é o critério mais
  importante desta spec e precisa ser exercitada por requisição real, não deduzida.
- **Chegar a 3 falhas sem esperar o backoff**: o `PrintingTest` da spec 038 já resolveu isso
  chamando `processNext()` diretamente e reiniciando `available_at` entre tentativas; reutilizar
  a mesma técnica, e dizer no teste que o atalho está sendo tomado.
- **O que os testes automatizados não vão cobrir**: o comportamento visual das duas telas
  (botão desabilitado, aviso, toast). Não há infraestrutura de teste de frontend no projeto, e
  esta spec não a introduz. Isso precisa ser verificado manualmente no navegador, e declarado
  como manual em vez de alegado.
- `act` está em espera por decisão do dono; a verificação do workflow é o CI depois do push.

## Rollout and rollback

Rollout: mesclar e reiniciar o `print-worker` para ele rodar o código novo. Sem migração. Em uma
instalação com a impressora já fora do ar, as próximas três falhas vão disparar o bloqueio — que
é o comportamento pedido.

Rollback: reverter o commit. As duas chaves em `settings` podem ficar; o código antigo não as lê.

## Open questions

Todas resolvidas na implementação:

- **Onde ficam os helpers** — em `PrintService`, que já é o serviço do domínio de impressão e já
  lia `Setting`. Nenhuma camada nova foi criada.
- **Texto do aviso e do botão** — cozinha: alerta vermelho com *"Impressão bloqueada. As últimas
  N impressões falharam. Verifique a impressora e reative."*; caixa: alerta amarelo com *"Os
  pedidos continuam sendo registrados, sem ticket."* — a diferença de cor e texto é proposital,
  porque o impacto é diferente em cada tela. Botão: **"Reativar impressão"** nas duas.
- **Desabilitar ou desmarcar `print_ticket`** — **desabilitar**, mantendo o estado visível, com o
  alerta ao lado explicando. Desmarcar em silêncio esconderia a causa.

## Task checklist

- [x] 1. Helpers de contador/bloqueio + testes unitários
- [x] 2. `PrintOrderJob` incrementa/zera
- [x] 3. `status()` / `reset()` + rotas públicas
- [x] 4. `OrderService` pula dispatch quando bloqueado
- [x] 5. Integração AC4–AC10
- [x] 6. Cozinha: polling, toast, bloqueio, reativar
- [x] 7. Caixa: polling, aviso, `print_ticket`
- [x] 8. PHPStan, PHP-CS-Fixer, suíte completa

## Implementation log

- **2026-09-24 — 1. Quatro chaves em `settings`, não duas.** A spec previa
  `printer_consecutive_failures` e `printer_blocked_at`, mas o FR5 exige também
  `last_failed_order_id` e `last_error` na resposta de status — é o que permite a UI dizer
  *qual* pedido falhou. Foram adicionadas `printer_last_failed_order` e `printer_last_error`.
  Continua sem migração: `settings` é chave/valor.
- **2026-09-24 — 2. O `OrderService` já tinha o `PrintService` injetado e nunca lido.** A spec
  034 registrou isso como achado real do PHPStan e o deixou na baseline porque removê-lo mudaria
  a assinatura do construtor. Agora a propriedade passou a ter uso legítimo (`isPrintingBlocked()`),
  então **o achado se resolveu sozinho** — e a entrada da baseline ficou órfã, quebrando o
  PHPStan com `ignore.unmatched`. A entrada obsoleta foi removida (de 15 para 14). Um efeito
  colateral bom, mas que só apareceu porque o PHPStan reclama de baseline não correspondida.
- **2026-09-24 — 3. `OrderService::printOrder()` passou a retornar `bool`.** Era `void`. O
  controller precisa distinguir "enfileirou" de "bloqueado" para responder `409 PRINTING_BLOCKED`
  em vez de mentir dizendo `Print job queued`. Mudança de assinatura interna, nenhum contrato de
  API alterado além do novo código de erro.
- **2026-09-24 — 4. O bloqueio é servidor + UI, não só UI.** `createOrder()` e `printOrder()`
  consultam `isPrintingBlocked()`. Sem isso o bloqueio seria cosmético: qualquer cliente da API
  continuaria empilhando jobs condenados. A cozinha também reage ao `409` corrigindo o próprio
  estado, para o caso de a tela estar defasada entre dois ciclos de polling.
- **2026-09-24 — 6. ⚠ A verificação no navegador encontrou um bug que nenhum teste pegaria.**
  O dono pediu para instalar Playwright e conferir as telas. O banner de bloqueio **nunca
  sumia** após reativar: ele tem `x-show="printerBlocked"`, mas as utilitárias do Bootstrap 5
  são `!important`, então `.d-flex { display: flex !important }` vence o `display: none` inline
  que o Alpine aplica. A API respondia `blocked: false`, o botão de reimprimir voltava a
  habilitar, e o aviso continuava na tela dizendo que a impressão estava bloqueada. Corrigido
  trocando `x-show` por `<template x-if>`, que remove do DOM, nas duas telas. **Nenhum teste de
  backend pegaria isso** — é uma interação entre dois frameworks de frontend.
- **2026-09-24 — 7. Um falso positivo meu, antes de achar o bug real.** A primeira execução
  acusou o banner e o toast como falhos com `waitForTimeout(2000)`. O ciclo
  reset + refresh leva ~2,4s (o `POST /api/printer/reset` sozinho leva 1,27s medido por `curl`),
  então a espera fixa era curta demais. Trocado por `waitForFunction` sobre o estado do Alpine.
  O bug do banner só apareceu depois disso — a espera curta estava mascarando qual era o
  problema real.
- **2026-09-24 — 8. O toggle do caixa estava desabilitado sem parecer desabilitado.** A captura
  de tela mostrou o switch "Imprimir" ainda azul e aparentemente ativo, embora
  `isDisabled()` retornasse `true`. Um operador não entenderia por que clicar não faz nada.
  Adicionado `opacity-50` e um `title` explicando, condicionados ao bloqueio.
- **2026-09-24 — 5. Polling de 15s, decisão explícita.** Não existia `setInterval` algum no
  projeto — confirmado por `grep` antes de escrever. O intervalo é novo e está no código com o
  motivo: o SSE atual não pode carregar o evento porque o worker roda em outro container e o
  arquivo-sinal é local a cada um.

## Validation evidence

Comandos rodados em 2026-09-24 dentro dos containers, `MYSQL_DATABASE_TEST=restaurant_test`.

- **AC1** — `PrinterBlockTest::testThreeConsecutiveFailuresBlockPrinting`: status inicial
  `blocked: false`; após três pedidos com job de impressão esgotado, `blocked: true`,
  `consecutive_failures: 3`, `last_failed_order_id` igual ao último pedido, `blocked_at`
  preenchido. Complementado no unitário
  `PrintServiceTest::testThreeFailuresBlockPrintingAndSuccessClearsIt`, que afirma que **uma** e
  **duas** falhas não bloqueiam.
- **AC2** — `testASuccessfulPrintResetsTheCounter`: com o contador em 2, um
  `recordPrintSuccess()` leva a `consecutive_failures: 0` e `blocked: false`.
- **AC3** — coberto por construção e verificado no código do `PrintOrderJob`: o incremento está
  dentro de `if ($job->attempts >= $job->max_attempts)`. **Não há teste dedicado** para a
  tentativa intermediária — ver "Não validado".
- **AC4** — `testStatusIsReachableWithoutAToken`: `GET /api/printer/status` sem token → `200`
  com a chave `blocked`.
- **AC5 (a regra dura)** — `testOrderIsStillCreatedWhilePrintingIsBlocked`: com `blocked: true`,
  `POST /api/orders` com `print_ticket: true` → `201`, o pedido existe no banco, e a contagem de
  jobs na fila `print` é **idêntica** antes e depois.
- **AC6** — `testReprintIsRefusedWhileBlocked`: `POST /api/orders/{id}/print` → `409` com
  `code: PRINTING_BLOCKED`, e nenhum job novo.
- **AC7** — `testResetUnblocksWithoutTouchingJobs`: `POST /api/printer/reset` → `200`; o `GET`
  seguinte traz `blocked: false`, `consecutive_failures: 0`, `last_failed_order_id: null`.
- **AC8** — o mesmo teste compara a contagem de jobs **agrupada por status** antes e depois do
  reset: idêntica.
- **AC9** — `testPrintingWorksAgainAfterReset`: após reativar, um pedido novo com
  `print_ticket: true` volta a criar exatamente um job.
- **AC10** — `testExposedErrorLeaksNoInternals`: o `last_error` exposto não contém `/var/www`,
  `#0 `, `Exception`, `Mike42` nem `.php`.
- **AC11** — suíte completa `OK (187 tests, 417 assertions)` (era `176/371` antes desta spec);
  `phpstan analyse` → `[OK] No errors` após remover a entrada órfã da baseline;
  `php-cs-fixer --dry-run` → `Found 0 of 81 files that can be fixed`.
- **Sintaxe do frontend** — `node --check` limpo em `public/kitchen/app.js` e
  `public/cashier/app.js`.

**Verificação no navegador (Playwright, a pedido do dono):**

Playwright + Chromium instalados **fora do repositório**, no diretório temporário da sessão — o
roadmap desaconselha explicitamente automação de navegador no item de E2E ("Do not attempt to
test the entire frontend through browser automation"), então adicioná-lo como dependência
versionada merece decisão própria. Resultado: **12 de 12 verificações passaram**, contra o app
real em `localhost:8080`:

- cozinha: banner visível com a contagem correta; botão de reimprimir **desabilitado**;
- caixa: banner visível dizendo que os pedidos seguem sendo registrados; checkbox **desabilitado**;
- reativar: banner sai do DOM, toast "Impressão reativada" aparece, API confirma
  `blocked: false`, botão de reimprimir volta a habilitar;
- nenhum erro de JavaScript em nenhuma das páginas.

Capturas de tela confirmam também o toast **"Erro ao imprimir o pedido #123"** — a notificação
por falha que o pedido original descrevia.

Essa verificação encontrou **dois defeitos** que os 187 testes de backend não pegariam; ambos
corrigidos e reverificados (log 6 e 8).

**Não validado, declarado em vez de subentendido:**
- **A verificação de navegador não está versionada.** Ela rodou do diretório temporário desta
  sessão e não é reproduzível por outra pessoa nem pelo CI. Transformá-la em teste do projeto é
  decisão em aberto, por causa da linha do roadmap citada acima.
- **AC3 não tem teste dedicado.** A condição `attempts >= max_attempts` é verificável lendo o
  código e é exercitada indiretamente (os testes esgotam as três tentativas e o contador sobe
  exatamente 1 por job, não 3 — o que só acontece se as intermediárias não contarem). Mas um
  teste que afirme diretamente "uma tentativa intermediária não incrementa" não foi escrito.
- **A notificação por falha depende do polling**, então ela aparece com até 15s de atraso, e só
  enquanto a tela estiver aberta. Uma falha ocorrida com a tela fechada não gera toast
  retroativo — apenas o estado de bloqueio persiste.
- **Nenhuma impressora física envolvida**; todas as falhas foram provocadas com `printer_ip`
  vazio.
