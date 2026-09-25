# Spec 040 — Testes de UI no navegador com Playwright

## Metadata

- Status: Verified
- Created: 2026-09-24
- Updated: 2026-09-24
- Owner: Henry
- Related issue: Not applicable (decisão do dono, após a spec 039)
- Related branch: `040`

## Context

Durante a spec 039 o dono pediu para instalar Playwright e verificar as telas. A verificação
rodou **fora do repositório**, num diretório temporário, e encontrou **dois defeitos que os 187
testes de backend não pegariam**:

1. O aviso de bloqueio **nunca sumia da tela**. `x-show="printerBlocked"` perde para
   `.d-flex { display: flex !important }` do Bootstrap 5, então a API respondia
   `blocked: false`, o botão de reimprimir voltava a funcionar, e só o texto na tela mentia.
2. O toggle "Imprimir" do caixa ficava **desabilitado sem parecer desabilitado** — continuava
   azul e com cara de ativo, e um operador não entenderia por que clicar não faz nada.

Nenhum dos dois é alcançável por teste de backend: o primeiro é interação entre dois frameworks
de frontend, o segundo é aparência. O roteiro que os encontrou está num diretório temporário e
**não é reproduzível por outra pessoa nem pelo CI** — é isso que esta spec resolve.

## Problem

1. **O frontend não tem cobertura automatizada nenhuma.** `docs/architecture.md` passou a listar
   isso como limitação conhecida (spec 039). Toda a verificação de tela é manual e, na prática,
   não acontece.
2. **A verificação que funcionou não é reproduzível.** Rodou de
   `…/scratchpad/ui-check/`, com `package.json` e `node_modules` fora do controle de versão.
   Ninguém mais consegue rodá-la, e ela desaparece quando o diretório temporário for limpo.
3. **Verificar UI hoje suja o banco de desenvolvimento.** Para ter um card na tela da cozinha foi
   preciso **criar um pedido real no banco de desenvolvimento e cancelá-lo depois**. Como rotina
   isso é inaceitável: deixa resíduo a cada execução e depende de eu lembrar de limpar.

## Goals

- Um conjunto pequeno de testes de navegador, versionado e executável por um comando documentado.
- Nenhuma escrita no banco de desenvolvimento.
- A suíte falha de verdade quando a UI quebra — provado reintroduzindo o defeito da spec 039.
- O roadmap passa a dizer o que o projeto realmente faz.

## Non-goals

- **Não automatizar "o frontend inteiro".** A restrição do roadmap continua correta em espírito;
  o que muda é a fronteira, não o princípio. Ver a emenda proposta abaixo.
- **Não duplicar o que a suíte de integração já prova.** A spec 035 cobre os fluxos pela camada
  HTTP; repetir isso pelo navegador só adiciona lentidão e fragilidade.
- **Nenhuma dependência PHP nova.** O Playwright é Node, isolado do `composer.json`.
- **Não alterar código de aplicação para facilitar teste.** Se um teste for impossível sem isso,
  reportar em vez de fazer.
- **Não substituir a verificação manual de aparência.** Estes testes checam comportamento
  observável (existe/não existe, habilitado/desabilitado, texto), não se a tela está bonita.

## Current behavior

Confirmado lendo o código e **medido** em 2026-09-24.

- **Não existe Node no projeto**: sem `package.json`, sem `node_modules`, sem build step. O
  frontend é PHP/HTML servido direto, com Alpine.js e Bootstrap 5 por CDN (`CLAUDE.md`).
- **O `.github/workflows/ci.yml` não tem Node**: usa `shivammathur/setup-php@v2` e um serviço
  `mysql:8.0`, e roda `composer validate` → `audit` → PHPStan → PHP-CS-Fixer → PHPUnit (Smoke,
  Unit) → criação do banco de teste → PHPUnit (Integration).
- **O app lê a configuração assim** (`public/index.php`): `Dotenv::createImmutable(...)->load()`,
  depois `new Settings()`. **Imutável não sobrescreve** o que já estiver em `$_ENV`.
- **`variables_order = EGPCS`** no container, ou seja `$_ENV` já vem do ambiente do processo.
- **Medido, não suposto**: rodando com `-e MYSQL_DATABASE=restaurant_test` e carregando
  exatamente como o `index.php` faz, `Settings` enxerga `restaurant_test` e o MySQL confirma
  `SELECT DATABASE() = restaurant_test`. **A variável de ambiente vence o arquivo `.env`.**
- O `docker-compose.yml` já passa `MYSQL_DATABASE: ${MYSQL_DATABASE}` para o serviço `web`.
- A suíte de integração (spec 035) isola banco por `MYSQL_DATABASE_TEST` com duas guardas, mas
  isso vive no bootstrap do PHPUnit — **o navegador não passa por ele**, porque fala com um
  servidor já em execução.
- `docs/ROADMAP.md:860` diz textualmente: *"Do not attempt to test the entire frontend through
  browser automation."*

## Proposed behavior

### 1. A emenda ao roadmap, antes de qualquer código

A linha 860 é substituída por uma que distingue as duas coisas que ela hoje mistura:

> Não automatize o frontend inteiro pelo navegador — a suíte de integração (spec 035) cobre os
> fluxos pela camada HTTP, que é mais rápida e mais estável. **Reserve o navegador para o que só
> quebra nele**: interações entre Alpine e as utilitárias do Bootstrap, estados
> habilitado/desabilitado que dependem de binding, e elementos que aparecem ou somem por
> condição. A spec 039 mostrou o custo de não ter isso — um aviso que nunca sumia da tela,
> invisível para 187 testes de backend.

A dependência só entra depois que o roadmap disser isso. Adicionar Playwright enquanto o
documento manda não fazer transformaria o roadmap em ficção.

### 2. Isolamento de banco: uma segunda instância do app, e a medição que a torna possível

A suíte de navegador **não fala com o app de desenvolvimento**. Um segundo container `web`,
definido num `docker-compose.e2e.yml`, sobe na porta **8081** com
`MYSQL_DATABASE: restaurant_test` — que, pela medição acima, é suficiente para apontar o app
inteiro ao banco de teste sem tocar no `.env`.

Isso é o que torna a suíte aceitável como rotina: hoje verificar UI exige criar pedido no banco
de desenvolvimento. Com a segunda instância, o banco de desenvolvimento **nunca é aberto**.

O schema do `restaurant_test` já é construído pela suíte de integração (spec 035), pelo caminho
real de migrações. A suíte de navegador reaproveita, e prepara seus próprios dados por HTTP
contra a porta 8081 — não por SQL direto, para não reinventar o bootstrap.

### 3. Onde o Node mora

`package.json`, `playwright.config.ts` e os testes ficam em **`tests/e2e/`**, não na raiz. O
projeto continua sem build step: nada em `public/` passa a depender de Node, e `composer.json`
não muda. `.gitignore` ganha `tests/e2e/node_modules/`, `tests/e2e/test-results/` e
`tests/e2e/playwright-report/`.

`CLAUDE.md` e `docs/architecture.md` passam a registrar a nova stack e o comando — hoje ambos
dizem que o projeto não tem build step nem Node, e isso deixaria de ser inteiramente verdade.

### 4. O que é testado — critério de inclusão explícito

Só entra o que **quebra apenas no navegador**:

| Teste | Por que só no navegador |
|---|---|
| O aviso de bloqueio some ao reativar | É a classe de defeito da spec 039: `x-show` versus `!important` |
| Botão de reimprimir desabilita/reabilita | Depende de binding do Alpine, não da API |
| Toggle do caixa desabilita e fica visivelmente esmaecido | Aparência de estado, invisível para a API |
| Aviso por falha aparece com o número do pedido | Polling + toast, nenhum dos dois existe no backend |
| Nenhum erro de JavaScript nas duas telas | Só o navegador sabe |

**Fica de fora, por já ser coberto pela spec 035 via HTTP**: criar pedido, numeração, preço,
conclusão, reabertura, relatórios, autenticação e autorização.

### 5. Esperas: por condição, nunca por tempo

A verificação manual teve um **falso positivo** por `waitForTimeout(2000)`: o ciclo
reset + refresh leva ~2,4s, e o `POST /api/printer/reset` sozinho levou **1,27s medido por
`curl`**. A espera curta acusou defeito onde não havia e, pior, **mascarou o defeito real** por
uma rodada.

A suíte proíbe `waitForTimeout` para sincronizar; espera-se por condição observável
(`waitForFunction`, `toBeVisible`, `toBeDisabled`). Um `waitForTimeout` só é aceitável como
pausa deliberada com o motivo escrito ao lado.

### 6. CI: roda, ou pula por condição declarada — nunca em silêncio

Um job separado, `e2e`, para não misturar o custo com o pipeline atual e para a falha dizer
claramente qual camada quebrou. Ele precisa de Node, do download do Chromium e do app de pé.

O custo real em CI **não foi medido** e será medido na implementação. Se passar de ~3 minutos, a
decisão a tomar é declarar a condição de execução (por exemplo, só quando o PR tocar `public/`),
e essa condição vai escrita no workflow — nunca um `continue-on-error` que esconde falha.

## Functional requirements

1. FR1 — `docs/ROADMAP.md:860` é emendado **antes** de a dependência entrar.
2. FR2 — Playwright é dependência de desenvolvimento em `tests/e2e/package.json`; `composer.json`
   não muda.
3. FR3 — `docker-compose.e2e.yml` define um segundo `web` na porta 8081 com
   `MYSQL_DATABASE: restaurant_test`.
4. FR4 — A suíte aponta para `http://localhost:8081` e nunca para 8080.
5. FR5 — Todo dado de teste é criado pela API na porta 8081.
6. FR6 — Nenhum `waitForTimeout` usado para sincronizar; espera por condição.
7. FR7 — Os cinco testes da tabela acima existem.
8. FR8 — `.gitignore` cobre `node_modules/`, `test-results/` e `playwright-report/` sob `tests/e2e/`.
9. FR9 — Um job `e2e` separado no CI, executando ou pulando por condição escrita.
10. FR10 — `CLAUDE.md` e `docs/architecture.md` registram a stack e o comando; o `CHANGELOG.md`
    entra na mesma passada.

## Non-functional requirements

- A suíte deve rodar em menos de ~2 minutos localmente; se não, reduzir o escopo em vez de
  aceitar suíte lenta que ninguém roda.
- Nenhum segredo nos testes: o usuário admin é criado pela API com senha gerada por execução,
  como a suíte de integração já faz.
- O navegador roda headless.

## User flows

Não aplicável como fluxo de produto — nada muda para o operador. O usuário afetado é o
desenvolvedor: hoje, verificar a UI significa abrir duas telas na mão e lembrar de limpar o banco
depois; com esta spec, é um comando.

## API changes

Não aplicável — nenhum endpoint é criado, alterado ou removido. A suíte consome os que existem.

## Data model and migrations

Não aplicável — sem mudança de schema. O `restaurant_test` já é construído pela suíte de
integração (spec 035) pelo caminho real de migrações.

## Architecture and affected components

- `tests/e2e/` (novo) — `package.json`, `playwright.config.ts`, specs de teste.
- `docker-compose.e2e.yml` (novo) — a segunda instância na 8081.
- `.github/workflows/ci.yml` — job `e2e`.
- `.gitignore`, `CLAUDE.md`, `docs/architecture.md`, `docs/ROADMAP.md`, `CHANGELOG.md`.
- **Nenhum arquivo de `src/` ou `public/`** deve mudar. Se algum precisar, é sinal de que a
  aplicação está difícil de testar — e isso vira achado reportado, não mudança silenciosa.

## Security considerations

A segunda instância expõe o app na 8081 **sem autenticação adicional**, exatamente como a 8080 já
faz — cozinha e caixa são públicas por decisão da spec 018. Como ela aponta para o banco de
teste, o que vaza numa rede local é dado de teste, não de cliente; ainda assim a porta só deve
subir durante a execução, não como serviço permanente do `docker-compose.yml` principal.

O usuário admin criado pelos testes usa senha gerada por execução e nunca uma credencial real, a
mesma regra da spec 035.

## Backward compatibility

- **Nada em produção muda.** Nenhum arquivo servido ao usuário final é tocado.
- `composer install` e `vendor/bin/phpunit` seguem idênticos; quem não instalar Node não perde
  nada além dos testes novos.
- O `docker-compose.yml` principal não muda: a segunda instância vive em arquivo separado, então
  `docker compose up -d` continua subindo os mesmos três serviços.

## Acceptance criteria

- AC1 — `docs/ROADMAP.md` não contém mais a frase "Do not attempt to test the entire frontend
  through browser automation" sem a ressalva; a emenda está no mesmo commit que introduz a
  dependência ou antes dele.
- AC2 — Um comando documentado roda a suíte e sai `0` com a UI íntegra.
- AC3 — **Reintroduzindo o defeito da spec 039** (trocar `<template x-if>` por
  `x-show` no aviso de bloqueio), a suíte **falha** e nomeia o teste do aviso; revertendo,
  volta a passar. Sem isso a suíte não prova nada.
- AC4 — Durante uma execução completa, as contagens de `orders`, `jobs` e `users` no banco
  **`restaurant`** são idênticas antes e depois.
- AC5 — `grep -r "waitForTimeout" tests/e2e` não retorna uso para sincronização (só, se houver,
  com comentário justificando).
- AC6 — O job `e2e` aparece nomeado numa execução real do CI, com resultado `success` ou
  `skipped` por condição declarada — verificado pela API do GitHub, não deduzido do YAML.
- AC7 — `vendor/bin/phpunit`, PHPStan e PHP-CS-Fixer continuam passando sem alteração.
- AC8 — `docker compose up -d` sem o arquivo `e2e` continua subindo exatamente os três serviços
  de hoje.

## Implementation plan

1. Emendar `docs/ROADMAP.md` (FR1) — primeiro, para a dependência entrar num projeto que a
   permite.
2. `docker-compose.e2e.yml` com a segunda instância na 8081; confirmar por requisição real que
   ela responde e que aponta para `restaurant_test`.
3. `tests/e2e/` com `package.json`, config e `.gitignore`.
4. Os cinco testes, com preparação de dados por API.
5. **AC3 antes de declarar pronto**: quebrar a UI de propósito, ver a suíte falhar, reverter.
6. Job `e2e` no CI; medir o tempo real e decidir a condição de execução com esse número.
7. `CLAUDE.md`, `docs/architecture.md`, `CHANGELOG.md` na mesma passada.

## Testing and validation strategy

**Correção, pela sexta spec seguida:** a instrução da skill `/spec-plan` de afirmar que este
projeto não tem infraestrutura de testes está desatualizada (PHPUnit spec 004, CI spec 005,
PHPStan e PHP-CS-Fixer spec 034, integração com MySQL spec 035). Vale corrigir o arquivo da
skill em `.claude/skills/spec-plan/` — seis specs repetindo a mesma correção é sinal de que a
fonte está errada, não os textos.

- **AC3 é o critério que dá sentido aos outros.** Uma suíte verde que nunca foi vista falhando
  não prova nada; por isso o defeito real da spec 039 é reintroduzido de propósito.
- **AC4 é a guarda de segurança** e precisa ser medida lendo o banco de desenvolvimento antes e
  depois, não deduzida do fato de a suíte apontar para 8081.
- **AC6 exige execução real no CI**, lida pela API do GitHub — a spec 035 já estabeleceu que ler
  o YAML não é evidência de que o passo roda.
- **O que esta suíte não prova**: que as telas estão visualmente corretas. Ela checa
  comportamento observável. Regressão de layout continua sendo verificação humana, e isso deve
  ficar escrito para ninguém confundir cobertura com garantia.
- Docker precisa estar rodando. `act` está em espera por decisão do dono.

## Rollout and rollback

Rollout: mesclar. Quem não instalar Node não é afetado; o CI ganha um job a mais.

Rollback: reverter o commit e remover `tests/e2e/`. Como nada em `src/` ou `public/` muda, o
rollback é completo por construção. A emenda do roadmap deve ser revertida junto, senão o
documento passa a prometer uma suíte que não existe.

## Open questions

Todas resolvidas na implementação:

- **Custo real no CI** — medido: a suíte de navegador roda em **5,6s** no runner, e os três
  passos que a habilitam (Node, `npm ci` + download do Chromium, subir o app) somam pouco mais
  de um minuto. O job inteiro passou de ~1m a ~1m38s. Bem abaixo do limite de ~3 min que
  motivaria uma condição de execução, então ela **roda em todo PR**, sem condição.
- **Como a segunda instância sobe no CI** — servidor embutido do PHP, não Docker, já que o
  workflow instala PHP direto. Exigiu três coisas que só apareceram executando: um roteador,
  `variables_order=EGPCS` e recusar o SSE. Ver o log de implementação.
- **`tests/e2e` no PHPStan/PHP-CS-Fixer** — não: são arquivos TypeScript. Confirmado que ambos
  seguem limpos (`0 of 81 files`, mesma contagem de antes).

## Task checklist

- [x] 1. Emenda do roadmap
- [x] 2. Segunda instância na 8081, confirmada por requisição real
- [x] 3. `tests/e2e/` com config e `.gitignore`
- [x] 4. Os cinco testes
- [x] 5. AC3 — quebrar a UI, ver falhar, reverter
- [x] 6. Job `e2e` no CI, com o tempo medido
- [x] 7. `CLAUDE.md`, `docs/architecture.md`, `CHANGELOG.md`

## Implementation log

- **2026-09-24 — 1. A emenda do roadmap foi o primeiro commit**, antes de a dependência entrar,
  como a spec exigia. A redação separa o que continua valendo (não automatizar o frontend
  inteiro) do que passa a valer (cobrir o que só quebra no navegador), e cita o custo medido —
  o aviso invisível da spec 039 — em vez de argumentar em abstrato.
- **2026-09-24 — 2. O isolamento funcionou como medido.** `orders=88 jobs=51 users=8
  settings=7` idênticos no banco `restaurant` antes e depois de uma execução completa. Verificar
  UI deixou de exigir criar pedido no banco de desenvolvimento.
- **2026-09-24 — 3. Desvio do FR5, declarado.** `setPrinterState()` não usa a API porque **não
  existe endpoint que ative o bloqueio** — só `POST /api/printer/reset`, que o limpa. Chegar lá
  pela API exigiria três jobs falhando de verdade (~42s de backoff) e um worker contra o banco
  de teste; criar um endpoint só para testar seria mudar a aplicação para facilitar teste, que a
  spec proíbe. Pedidos e cardápio continuam vindo da API.
- **2026-09-24 — 4. Desvio do FR9, declarado.** Os testes rodam no **mesmo job** do CI, não num
  job separado: o `restaurant_test` e seu schema acabam de ser construídos pelo passo de
  integração, e um job separado duplicaria serviço MySQL, `composer install`, schema e migrações
  para não reaproveitar nada. Os passos são nomeados, então a falha continua dizendo qual camada
  quebrou — que era a razão real de pedir separação.
- **2026-09-24 — 5. Vazamento corrigido de passagem (fora do escopo original).** O banco de
  teste tinha **510 categorias e 5368 itens**, com `/api/menu` devolvendo **1 MB em 2s**: o
  `001_schema.sql` semeia com `INSERT`s sem guarda de idempotência e o `buildSchema()` da spec
  035 roda uma vez por **processo**, ou seja a cada invocação do `phpunit`. O seed passa a rodar
  uma vez por banco. **Primeira tentativa estava errada**: eu fiz o método inteiro retornar cedo,
  o que impediria migrações novas de alcançarem um banco existente — corrigido para guardar só o
  seed, já que o `MigrationRunner` é idempotente por construção. As 5368 linhas já acumuladas
  **não foram apagadas**: o `CLAUDE.md` proíbe operação destrutiva em banco e a regra não abre
  exceção para banco de teste.

### As cinco falhas de CI, e o que cada uma ensinou

Nada disto reproduzia localmente. O valor desta seção é exatamente esse: as cinco causas são
diferenças entre Apache/Docker e o servidor embutido do runner.

1. **`php -S` não lê o `.htaccess`** → `/api/menu` devolvia 404 e o passo morria com exit 22.
   Resolvido com `tests/e2e/router.php`, que reproduz a regra do `.htaccess`. Aproveitei para
   fazer o passo **imprimir o log do servidor ao falhar** — na primeira execução ele escreveu em
   `/tmp` e nunca mostrou, o que atrasou o diagnóstico sem necessidade.
2. **Faltava a ponte `getenv()` → `$_ENV`** no subprocesso PHP que prepara o estado, a mesma
   armadilha que a spec 035 já tinha encontrado. Ao corrigir, percebi que a ponte sozinha abriria
   risco pior — se o Dotenv injetasse `MYSQL_DATABASE` do `.env`, o helper escreveria no banco de
   **desenvolvimento** — então o alvo passou a ser fixado explicitamente e há uma **guarda que
   recusa rodar** se o banco não for `restaurant_test`.
3. **O SSE travava o servidor** (primeira aparição): `php -S` é single-threaded por padrão e a
   conexão de longa duração da cozinha consumia o único worker. Resolvido com
   `PHP_CLI_SERVER_WORKERS=8`.
4. **Hipótese de paralelismo, REFUTADA.** Atribuí quatro falhas a testes concorrentes
   compartilhando o estado global da impressora. Testei antes de empurrar: rodando com
   `--workers=4` **localmente a suíte passou**, porque o Playwright só paraleliza entre arquivos
   e há um só. `workers: 1` ficou no config como proteção para quando houver um segundo arquivo,
   com o comentário corrigido para não registrar uma causa que não existiu. No lugar de teorizar
   de novo, adicionei `expectServerBlocked()`, que consulta o próprio endpoint e **põe o JSON
   recebido na mensagem de falha** — para a próxima falha dizer se o problema é a tela ou o
   backend. Foi o que localizou a causa seguinte em uma execução.
5. **⚠ A causa raiz, e a mais séria: o servidor do CI lia o banco ERRADO.** O phpdotenv imutável
   decide "esta chave já está definida?" olhando `$_ENV`/`$_SERVER` — **não** o `getenv()`. No
   runner, o `variables_order` do CLI não inclui `E`, então `$_ENV` nasce vazio, o Dotenv conclui
   que `MYSQL_DATABASE` não está definida e carrega o valor do `.env`: `restaurant`. A variável
   **chegava ao processo** (confirmado lendo `/proc/PID/environ`), mas o app nunca a via.
   **Provado antes de corrigir**: escrever `printer_blocked_at` em `restaurant.settings` fez o
   servidor da 8081 responder `blocked: true`. Corrigido com `-d variables_order=EGPCS`.
   **Consequência a registrar**: nas execuções anteriores deste job, o servidor de teste apontava
   para `restaurant`. No CI esse banco é efêmero, então não houve dano — mas a garantia de
   isolamento esteve **silenciosamente quebrada**, e foi só porque um diagnóstico explícito foi
   construído que isso apareceu em vez de passar como "teste instável".
6. **O SSE travava o servidor (segunda aparição)**, agora esgotando os 8 workers ao longo de
   cinco testes. Como nenhum teste exercita SSE — o estado chega por polling — o roteador passou
   a devolver `204` para `/api/events/*`. O Apache de produção não tem essa limitação.

## Validation evidence

- **AC1** — `docs/ROADMAP.md` emendado no primeiro commit da branch; a frase original agora vem
  acompanhada da ressalva e do motivo medido.
- **AC2** — `npx playwright test` em `tests/e2e/` → `5 passed (42.5s)` local, `5 passed (5.6s)`
  no CI. Comando documentado no `CLAUDE.md`.
- **AC3 — o critério que dá sentido aos outros.** Reintroduzi o defeito real da spec 039
  (`<template x-if>` de volta para `x-show`): a suíte **reprovou com exit 1**, nomeando
  `cozinha: o aviso de bloqueio SOME ao reativar` e apontando `toHaveCount(0)` na linha 83.
  Revertido, `5 passed`, exit 0.
- **AC4** — contagens no banco `restaurant` antes e depois de uma execução completa:
  `orders=88 jobs=51 users=8 settings=7` → **idênticas**.
- **AC5** — `grep -rn "waitForTimeout" tests/e2e/specs/` retorna **uma única linha, um
  comentário** explicando por que não se usa espera fixa. Nenhum uso para sincronização.
- **AC6** — execução real no CI (run `36086433877`), lida pela API do GitHub:
  `16. Set up Node 22 — success`, `17. Install browser test dependencies — success`,
  `18. Start app for browser tests (test database) — success`,
  `19. Browser tests (Playwright) — success`. Saída do passo: `5 passed (5.6s)`.
- **AC7** — `vendor/bin/phpunit` → `OK (187 tests, 417 assertions)`; `phpstan analyse` →
  `[OK] No errors`; `php-cs-fixer --dry-run` → `Found 0 of 81 files that can be fixed`.
- **AC8** — `docker compose config --services` → `db`, `print-worker`, `web`. Com o arquivo
  `e2e` somado: os mesmos três mais `web-e2e`. O compose principal não mudou.

**Não validado, declarado em vez de subentendido:**

- **Esta suíte não prova que as telas estão visualmente corretas.** Ela checa comportamento
  observável — existe/não existe, habilitado/desabilitado, texto. Regressão de layout continua
  sendo verificação humana, e confundir cobertura com garantia aqui seria erro.
- **A correção do SSE no roteador não foi verificada localmente**: o Docker Desktop caiu antes
  de eu conseguir subir o servidor embutido para testá-la. Foi verificada pelo CI, que é o
  ambiente onde o problema aparece.
- **As 5368 linhas duplicadas continuam no banco de teste.** O vazamento foi estancado, a
  limpeza não foi feita — depende de decisão do dono, por ser operação destrutiva.
- **Nenhum navegador além do Chromium**; nenhum teste em resolução móvel.
