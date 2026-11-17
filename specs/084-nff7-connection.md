# Spec 084 — Conexão com o NF-F7 (configuração + teste de conectividade)

## Metadata

- Status: Verified
- Created: 2026-11-16
- Updated: 2026-11-17
- Owner: Henry
- Related issue: Trello "Integração NF-F7 — contrato API e configuração" (https://trello.com/c/ecET8cGE)
- Related branch: `feat/084-nff7-connection`

## Context

O motor fiscal fica no NF-F7, um produto separado e privado (spec 083, `docs/ROADMAP.md` › "Post-v2.0 — Optional NF-F7 integration"). Em 2026-11-16 o owner decidiu, do lado do NF-F7 (ADR 0004 e spec 023 de lá, PR #21 mergeado), que:

- o NF-F7 roda **dentro do compose do GastroFlow**, incluído por um `compose.override.yml` local que só existe na instalação com fiscal; o `docker-compose.yml` público não muda;
- o GastroFlow fala com ele só por HTTP (`http://nff7-app` na rede interna do Docker), com `Authorization: Bearer <chave>`;
- o fiscal é uma **área fechada da GastroFlow 2.1**; no futuro, uma versão Cloud em que só a URL muda.

O que o NF-F7 oferece hoje (conferido em `../NF-F7`, `main` `a5cb033`, 2026-11-16):

- `GET /api/v1/ping` com Bearer → `200 {"client":{"id","name","issuer_id"},"version":"…"}`;
- sem chave, com chave desconhecida ou revogada → `401 {"error":{"code":"API_KEY_INVALID","message","request_id"}}`;
- a chave tem o formato `nff7_<43 caracteres>` e é criada com `bin/api-client create` no NF-F7;
- **nenhum endpoint de NFC-e existe.** `POST /api/v1/nfce` é a spec 025 de lá, ainda não escrita, e depende da pesquisa fiscal (024) e do provider (026).

Atualização de 2026-11-16, mais tarde (NF-F7 PR #22, `d7166bf`, ainda aberto): a primeira imagem foi publicada, `ghcr.io/focusevengithub/nf-f7:0.1.0-gastroflow.1` (run 43994048671), a spec 023 de lá virou `Verified` e a ADR 0004 foi aceita. O contrato da API não mudou. O owner decidiu que **a subida real do GastroFlow com o override e o ping de dentro do container do GastroFlow são testados nesta spec**; um problema achado aqui volta como hotfix no NF-F7.

Por isso esta spec é a **primeira fatia** do lado GastroFlow, espelho da 023 de lá: só a conexão. Emitir nota fica para uma spec própria, quando o contrato `POST /api/v1/nfce` existir.

### Conflitos com o que está registrado (apontados, não resolvidos em silêncio)

- `docs/ROADMAP.md` (spec 083, mergeada hoje) diz "Nothing here starts before `v2.0.0`" e "No version number is reserved for this". A decisão do owner de hoje (ADR 0004 do NF-F7: "área fechada da GastroFlow 2.1") e o pedido de implementar agora mudam as duas frases. Ver Open questions 1.
- O card desta integração no Trello diz "Não implementar antes do v2.0 Community".
- O "Plano de fechamento v2.0" (Trello) tem como próximos passos CI verde e **feature freeze**. Esta spec é uma feature, pequena e desligada por padrão. Ver Open questions 1.
- `docs/ROADMAP.md` sugere guardar a credencial como "secret setting" na tabela `settings`. Esta spec propõe o `.env` (ver Proposed behavior); o ROADMAP ganha nota datada.

## Problem

O GastroFlow não tem onde configurar a URL e a chave do NF-F7, nem como dizer ao dono se a conexão está funcionando. Além disso, a pasta `nff7/` que a instalação com fiscal cria dentro da pasta do GastroFlow (com o `nff7.env`, que tem senhas e a chave de cifra dos certificados) hoje:

- não está no `.gitignore`;
- **entraria na imagem do GastroFlow** num `docker compose up -d --build`: o `.dockerignore` cobre `.env.*` e `docker-compose*.yml`, mas não `nff7/` nem `compose.override.yml`.

## Goals

- Duas variáveis no `.env`, `NF_F7_URL` e `NF_F7_API_KEY`. Vazias (padrão) = fiscal desligado e nada muda.
- Um cliente HTTP isolado que só sabe chamar `GET /api/v1/ping`.
- `GET /api/admin/fiscal/status` (só admin) e um cartão em Admin › Configurações com "conectado / sem conexão", visível só quando o fiscal está configurado.
- `nff7/` e `compose.override.yml` fora do git e fora da imagem.

## Non-goals

- Emitir, consultar ou cancelar NFC-e; referência externa do pedido; `Idempotency-Key`. Dependem do contrato `POST /api/v1/nfce` (NF-F7 spec 025).
- Pagamento/checkout, status fiscal no pedido, DANFE (os outros três itens do ROADMAP).
- Qualquer lógica fiscal, certificado ou XML no Community.
- Qualquer chamada ao NF-F7 fora do endpoint de status: o fluxo de pedido, a cozinha e a impressão não tocam nele.
- Editar a URL e a chave pela tela do Admin.
- Mexer no `docker-compose.yml` ou no repositório do NF-F7.
- Dependência nova (Guzzle etc.): a extensão `curl` já está na imagem.

## Current behavior

Conferido no código em 2026-11-16 (`master` `a6954c0`):

- `grep -rn -i "fiscal\|nff7\|nf_f7" src public bin .env.example` não acha nada.
- Não há cliente HTTP de saída em `src/` (`grep -rn "curl_\|stream_context" src` vazio). `php -m` no container `web` tem `curl` (`extension_loaded("curl")` → true).
- `App\Settings::get()` lê `$_ENV` e trata `''` como ausente (`src/Settings.php:17-21`).
- Padrão de endpoint de diagnóstico: `PrinterController::testPrint()` (`src/Controllers/PrinterController.php:56`), com `ApiResponse::error` e mensagem montada de fatos conhecidos, nunca do texto da exceção.
- Rotas de admin em `src/Routes.php:99-125`, com `$adminOnly()`.
- Tela: `public/admin/settings.php` + `settings.js` (`GFAdmin.page`, `this.api()`).
- Compose: o Docker carrega `compose.override.yml` sozinho ao lado de `docker-compose.yml` **só quando nenhum arquivo é escolhido à mão**. Testado em 2026-11-16 com o fragmento real do NF-F7: sem `-f`, `config --services` lista `nff7-db nff7-migrate nff7-app web`; com `-f docker-compose.yml`, lista só `web`. Logo, uma instalação com `COMPOSE_FILE=…` no `.env` (o desenvolvimento) precisa pôr o override na variável.

## Proposed behavior

### Configuração

`.env`:

```
NF_F7_URL=
NF_F7_API_KEY=
```

- As duas vazias → fiscal **desligado**. É o padrão e o estado de todo restaurante sem NF-F7.
- Só uma preenchida → ligado, mas `connected: false` com motivo `INCOMPLETE_CONFIG`.
- No `.env`, e não na tabela `settings`: é onde já vive o `JWT_SECRET`; `GET /api/admin/settings` devolve o valor de toda chave e o audit log precisaria de exceção; no compose de produção o `.env` já entra montado somente leitura. Trocar a chave é editar o `.env` (lido a cada requisição, sem reiniciar).
- `Settings::getNff7Url()` (sem barra final) e `Settings::getNff7ApiKey()`, ambos `?string`.

### Cliente

`src/Services/Nff7Client.php`: `ping(): array`. `GET {url}/api/v1/ping` com `Authorization: Bearer` e `Accept: application/json`, por `curl`, com 2 s para conectar e 5 s no total, sem seguir redirecionamento. O transporte é um `callable` injetável (mesma costura do `connectorFactory` do `PrintService`) para os testes não precisarem de rede.

Resultado, sempre um array, nunca exceção para o chamador:

| Situação | `connected` | `reason` |
|---|---|---|
| 200 com `client.name` e `version` | `true` | — |
| URL ou chave faltando | `false` | `INCOMPLETE_CONFIG` |
| Falha de rede, DNS, timeout | `false` | `UNREACHABLE` |
| 401 | `false` | `KEY_REJECTED` |
| Qualquer outra resposta (5xx, corpo que não é o JSON esperado) | `false` | `UNEXPECTED_RESPONSE` |

### Endpoint

`GET /api/admin/fiscal/status`, JWT + `admin`. Sempre 200:

```json
{"success": true, "enabled": false}
{"success": true, "enabled": true, "connected": true, "client_name": "gastroflow", "issuer_id": 3, "version": "0.1.0-gastroflow.1"}
{"success": true, "enabled": true, "connected": false, "reason": "KEY_REJECTED", "message": "O serviço fiscal recusou a chave. Confira NF_F7_API_KEY no .env."}
```

A chave nunca aparece na resposta nem no log. A `message` é texto fixo por `reason`, nunca o texto do erro do `curl` nem o corpo devolvido pelo NF-F7. Uma falha gera uma linha `warning` no log com `reason` e o status HTTP.

### Tela

Admin › Configurações: um cartão "Nota fiscal (NF-F7)", **renderizado só quando `enabled` é `true`**. Mostra "Conectado" (nome do cliente e versão do NF-F7) ou "Sem conexão" com a `message`, e um botão "Testar conexão" que chama o endpoint de novo. Num restaurante sem fiscal a tela fica idêntica à de hoje.

### Arquivos da instalação com fiscal

- `.gitignore`: `/compose.override.yml` e `/nff7/`.
- `.dockerignore`: `compose.override.yml` e `nff7/`.

### Docs

- `docs/configuration.md`: as duas variáveis, e a nota do `COMPOSE_FILE` (override na variável quando ela é usada).
- `docs/ROADMAP.md` › Post-v2.0: nota datada (decisão de 2026-11-16, primeira fatia entregue, credencial no `.env`), conforme a resposta à Open question 1.
- `docs/architecture.md`, `specs/000-project-baseline.md`, `CLAUDE.md` (camada/endpoint novos), `CHANGELOG.md`.

A instalação do NF-F7 em si (login no registro, `nff7.env`, emitente, certificado, criar a chave) é documentada no pacote privado dele (`deploy/gastroflow/README.md`); os docs públicos só dizem o que preencher no `.env` do GastroFlow.

## Functional requirements

1. FR-1: Sem `NF_F7_URL` e sem `NF_F7_API_KEY`, `GET /api/admin/fiscal/status` → `200 {"success":true,"enabled":false}` e nenhuma requisição de saída é feita.
2. FR-2: Com as duas preenchidas e o NF-F7 respondendo 200 ao ping, → `enabled:true, connected:true` com `client_name`, `issuer_id` e `version` vindos do ping.
3. FR-3: Resposta 401 do NF-F7 → `connected:false, reason:"KEY_REJECTED"`.
4. FR-4: Falha de transporte (conexão recusada, DNS, timeout) → `connected:false, reason:"UNREACHABLE"`, em no máximo ~5 s.
5. FR-5: 5xx, ou 200 com corpo que não é JSON ou sem `client.name`/`version` → `reason:"UNEXPECTED_RESPONSE"`.
6. FR-6: Só uma das variáveis preenchida → `enabled:true, connected:false, reason:"INCOMPLETE_CONFIG"`, sem requisição de saída.
7. FR-7: A requisição de saída vai para `{NF_F7_URL sem barra final}/api/v1/ping` com `Authorization: Bearer {NF_F7_API_KEY}`.
8. FR-8: Sem token → 401; com papel `manager` → 403.
9. FR-9: Em nenhum caso a resposta ou o log contêm o valor de `NF_F7_API_KEY`.
10. FR-10: O cartão do Admin só existe no DOM visível quando `enabled` é `true`.

## Non-functional requirements

- Nenhuma rota além de `/api/admin/fiscal/status` instancia o cliente: criar pedido, cozinha e impressão não dependem do NF-F7 (a regra "falha fiscal nunca perde pedido" vale por construção nesta fatia).
- Tempo máximo da chamada: 5 s.
- Sem dependência nova.

## User flows

- **Dono com fiscal:** instala o NF-F7 pelo pacote dele, cria a chave, põe `NF_F7_URL=http://nff7-app` e `NF_F7_API_KEY=nff7_…` no `.env`, abre Admin › Configurações e vê "Conectado".
- **Dono sem fiscal:** nada muda.
- **Chave trocada ou NF-F7 parado:** o cartão mostra "Sem conexão" com o que conferir; caixa e cozinha seguem normais.

## API changes

Novo: `GET /api/admin/fiscal/status` (ver Proposed behavior). Nenhum endpoint existente muda.

## Data model and migrations

Not applicable — nada no banco.

## Architecture and affected components

- `src/Settings.php`: dois getters.
- `src/Services/Nff7Client.php` (novo): o único lugar que fala com o NF-F7.
- `src/Controllers/FiscalController.php` (novo): `status`. Um Controller e um Service novos para um domínio novo, pedidos por esta spec; sem Repository, Validator ou Model.
- `src/Routes.php`: a rota, com `$adminOnly()`.
- `public/admin/settings.php`, `public/admin/settings.js`: o cartão.
- `.env.example`, `.gitignore`, `.dockerignore`.
- `tests/Unit/Nff7ClientTest.php` (novo), `tests/Integration/GuardTest.php` (rota nova na lista de proteção, se o teste enumera rotas).

## Security considerations

- A chave é um segredo: fica só no `.env`, nunca vai para `settings`, audit log, resposta ou log.
- Sem SSRF pelo navegador: a URL vem do `.env`, que só quem tem acesso ao servidor edita; nenhum parâmetro da requisição escolhe o destino. Redirecionamentos não são seguidos (a chave não vaza para outro host).
- O corpo devolvido pelo NF-F7 não é repassado ao navegador, só os três campos esperados, com tipo conferido.
- `nff7/nff7.env` tem as senhas do banco do NF-F7 e a chave de cifra dos certificados: sem o ajuste do `.dockerignore`, iria para dentro da imagem `gastroflow:local`.
- O repositório é público: o código do cliente (cerca de cem linhas, sem regra fiscal) fica visível. O produto fechado é o NF-F7. Ver Open questions 2.

## Backward compatibility

Instalação sem as variáveis: nenhuma mudança visível nem de comportamento. `.gitignore`/`.dockerignore` só passam a ignorar dois caminhos que hoje não existem em nenhuma instalação.

## Acceptance criteria

1. Teste unitário do `Nff7Client` com transporte falso: 200 válido → `connected:true` com os três campos (FR-2); 401 → `KEY_REJECTED` (FR-3); exceção/erro de transporte → `UNREACHABLE` (FR-4); 500 e 200 com corpo inválido → `UNEXPECTED_RESPONSE` (FR-5); o transporte recebeu a URL `…/api/v1/ping` sem barra dupla e o header Bearer (FR-7).
2. Teste (unitário do controller ou de integração): sem variáveis → `enabled:false` e o transporte não foi chamado (FR-1); só uma variável → `INCOMPLETE_CONFIG` e o transporte não foi chamado (FR-6).
3. Teste de integração HTTP: sem token → 401; `manager` → 403; `admin` sem variáveis → 200 `enabled:false` (FR-8).
4. Teste: com uma chave conhecida, nem o JSON da resposta nem o log capturado contêm a chave, nos casos de sucesso e de falha (FR-9).
5. Verificação real com a imagem publicada (`0.1.0-gastroflow.1`), incluída no compose de desenvolvimento do GastroFlow pelo `compose.override.yml` (com `COMPOSE_FILE=docker-compose.dev.yml:compose.override.yml`): `docker compose config --services` lista `nff7-db`, `nff7-migrate` e `nff7-app`; depois de subir, com uma chave criada por `bin/api-client create`, `GET /api/admin/fiscal/status` devolve `connected:true` com a versão `0.1.0-gastroflow.1`; com a chave alterada, `KEY_REJECTED`; com o `nff7-app` parado, `UNREACHABLE`. Exige `docker login ghcr.io` com leitura do pacote privado nesta máquina e mexer no `.env` (`COMPOSE_FILE`, as duas variáveis), o que é passo do owner. É este critério que cobre o teste que a spec 023 do NF-F7 deixou para o lado GastroFlow.
6. Navegador: sem variáveis o cartão não aparece; com variáveis aparece com o estado certo (FR-10).
7. `docker build` com uma pasta `nff7/` e um `compose.override.yml` de teste presentes: nenhum dos dois existe dentro da imagem; `git status` não os lista.
8. PHPUnit (com integração), PHPStan e PHP-CS-Fixer limpos; CI do PR verde.
9. Docs e CHANGELOG atualizados conforme Proposed behavior › Docs.

## Implementation plan

1. `Settings` + `Nff7Client` + teste unitário.
2. `FiscalController` + rota + testes de proteção.
3. Cartão no Admin.
4. `.env.example`, `.gitignore`, `.dockerignore`.
5. Docs, CHANGELOG, Trello.

## Testing and validation strategy

PHPUnit unitário para o cliente (transporte falso) e integração para a rota (`MYSQL_DATABASE_TEST=restaurant_test`). A chamada real ao NF-F7 é verificação manual (AC5), porque o CI do GastroFlow não tem a imagem privada. O cartão é conferido no navegador; um teste Playwright não se justifica para um `x-show`.

## Rollout and rollback

Merge não muda nada para quem não preenche as variáveis. Rollback: reverter o PR, ou esvaziar as duas variáveis.

## Open questions

Respondidas pelo owner em 2026-11-16: **1 → (a)**, mergear agora, saindo inerte na `v2.0.0`; **2 →** o cliente fica público, e a área fechada é o NF-F7.

1. ~~**(bloqueante) Em qual versão isto entra?**~~ O `master` é a v2.0 em fechamento, e o plano de fechamento prevê feature freeze agora; a decisão do NF-F7 fala em "área fechada da GastroFlow 2.1". Opções: (a) mergear agora — o código sai na `v2.0.0` inerte (desligado por padrão, sem menção no anúncio) e o ROADMAP passa a dizer que a área fiscal é a 2.1, com esta fatia já no lugar; (b) implementar agora e segurar o PR aberto até a tag `v2.0.0`. Recomendação: (a) — PR parado envelhece, e o que entra não faz nada sem as duas variáveis.
2. ~~**(bloqueante) O cliente pode ficar no repositório público?**~~ "Área fechada" pode significar só o motor (NF-F7 privado, cliente público, como o ROADMAP já descreve) ou também o código do lado GastroFlow. Recomendação: cliente público — não tem regra fiscal, e um Community com código fechado dentro exigiria outro repositório ou outra imagem.
3. (não bloqueante) Nome das variáveis: `NF_F7_URL`/`NF_F7_API_KEY` vêm da ARCHITECTURE §9 do NF-F7. Mantidos.

## Task checklist

- [x] `Settings` + `Nff7Client` + `Nff7ClientTest`
- [x] `FiscalController` + rota + testes de proteção (`FiscalStatusTest`)
- [x] Cartão em Admin › Configurações
- [x] `.env.example`, `.gitignore`, `.dockerignore`
- [x] Verificação real com a imagem publicada no compose do GastroFlow (AC5) — 2026-11-17
- [x] Cartão conferido no navegador (AC6) — 2026-11-17
- [x] Docs + `CHANGELOG.md`
- [x] Trello
- [x] CI do PR verde — PR #78, run 43996672652

## Implementation log

- 2026-11-16 (planejamento): o carregamento automático do `compose.override.yml` foi testado com o fragmento real do NF-F7 ao lado de um `docker-compose.yml` (o CI do NF-F7 só testa com `compose.yaml`): funciona sem `-f`, e não funciona com arquivo escolhido à mão. Registrado em Current behavior e em `docs/configuration.md`.
- 2026-11-16: a lógica toda ficou em `Nff7Client::status()` (inclusive "desligado" e "configuração incompleta"), e o controller só acrescenta `success`. Assim o AC2 é testado no próprio cliente, sem banco.
- 2026-11-16: o `LoggerInterface` é parâmetro obrigatório do `Nff7Client`: o PHP-DI não resolve parâmetro com valor padrão (spec 042), e um logger opcional ficaria nulo no caminho HTTP.
- 2026-11-16: `issuer_id` precisa ser inteiro e `client.name`/`version` texto; qualquer outra coisa é `UNEXPECTED_RESPONSE`. `CURLOPT_PROTOCOLS` limita a `http`/`https`.
- 2026-11-16: `tests/Integration/GuardTest.php` não enumera rotas (testa o banco de teste); a proteção da rota nova está em `FiscalStatusTest`.
- 2026-11-16: o caso `UNREACHABLE` do teste de integração usa `http://127.0.0.1:9` (porta fechada no próprio container), sem rede externa.
- 2026-11-16: para o AC5 parcial foi criada, e revogada em seguida, uma chave de teste (`gastroflow-spec084-teste`, id 1) no NF-F7 **de desenvolvimento** desta máquina. Ficam lá uma linha revogada em `api_clients` e duas no audit dele.
- 2026-11-16: o AC5 completo e o AC6 não foram feitos: pedem `docker login ghcr.io` com leitura do pacote privado e mudar o `.env` do GastroFlow (`COMPOSE_FILE`, `NF_F7_URL`, `NF_F7_API_KEY`), que não é lido nem alterado (regra de segurança). Por isso o status ficou `Implemented` no merge do PR #78 (`624ef90`).
- 2026-11-17: o owner fez a instalação com fiscal nesta máquina pelo passo a passo (login no GHCR, `nff7/` tirada da tag `v0.1.0-gastroflow.1`, `compose.override.yml`, `COMPOSE_FILE=docker-compose.dev.yml:compose.override.yml`, porta da UI 8091 porque o NF-F7 de desenvolvimento já usa a 8090). Dois tropeços do passo a passo, ambos meus: o `sed` do `nff7.env` tinha um `\|` dentro de `$( … )` (trocado por `openssl rand -base64 32`), e faltava dizer onde achar o id do emitente para `bin/api-client create`. O `README.md` de `deploy/gastroflow/` do NF-F7 não tem esses dois problemas; o passo a passo era só desta conversa.

- 2026-11-17: a pedido do owner, o passo a passo da instalação virou `docs/fiscal-nff7.md`, público, com as duas correções acima e uma tabela de problemas com os erros vistos na instalação real. Isso muda o que Proposed behavior › Docs dizia ("documentada no pacote privado dele"): o pacote do NF-F7 continua com o próprio `README.md`, e o GastroFlow passa a ter o lado dele. Nada ali é segredo; a imagem segue exigindo token de leitura.

## Validation evidence

- 2026-11-16, `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --filter "Nff7ClientTest|FiscalStatusTest"` → `OK (15 tests, 62 assertions)` (AC1, AC2, AC3, AC4).
- 2026-11-16, `vendor/bin/phpunit` com `MYSQL_DATABASE_TEST=restaurant_test` → `OK, but some tests were skipped! Tests: 367, Assertions: 991, Skipped: 2` (os 2 pulados já existiam); `vendor/bin/phpstan analyse` → `[OK] No errors`; `php-cs-fixer fix` → `Fixed 0 of 124 files` (AC8, parte local).
- 2026-11-16, **AC5 em parte** — `Nff7Client` real (transporte `curl`) rodado dentro do container `web` do GastroFlow contra o NF-F7 de desenvolvimento (`nf-f7-app-1`, pela porta 8090 do host), com as variáveis postas em memória:
  - chave criada por `bin/api-client create`, URL com barra final → `{"enabled":true,"connected":true,"client_name":"gastroflow-spec084-teste","issuer_id":1,"version":"dev"}` em 0,03 s;
  - a mesma chave com um caractere a mais → `KEY_REJECTED`;
  - porta fechada (8099) → `UNREACHABLE`.
  **Não verificado:** a imagem publicada `0.1.0-gastroflow.1` incluída pelo `compose.override.yml`, o endereço `http://nff7-app` na rede interna e a chamada pelo endpoint com as variáveis no `.env`.
- 2026-11-16, **AC6 não verificado** no navegador. Só `node --check public/admin/settings.js` → sem erro.
- 2026-11-16, AC7 — com `nff7/nff7.env` e `compose.override.yml` de teste na pasta: `git check-ignore -v` aponta `.gitignore:110:/nff7/` e `.gitignore:109:/compose.override.yml`, e `git status` não os lista; uma imagem de teste (`FROM busybox`, `COPY . /ctx`) construída com o contexto e o `.dockerignore` do repositório tem `/ctx/src` e não tem `/ctx/nff7` nem `/ctx/compose.override.yml`. Arquivos e imagem de teste removidos.
- 2026-11-16, AC8 (CI): `gh pr checks 78` → `test pass` (run 43996672652) e GitGuardian `pass`, no commit `0bc8739`.
- 2026-11-16, revisão da spec contra o diff (os quatro elos do `/spec-review`, feita depois de aberto o PR): nenhum arquivo fora do que a spec lista, nenhuma dependência nem mudança de schema, e todo FR tem teste ou evidência, menos o FR-10 (AC6, navegador) e a parte do AC5 já marcada como não verificada. O status `Implemented` confere.
- 2026-11-17, **AC5 completo** — instalação com fiscal real no compose de desenvolvimento, imagem publicada `ghcr.io/focusevengithub/nf-f7:0.1.0-gastroflow.1`:
  - owner, no navegador: Admin › Configurações mostrou "Conectado como gastroflow (NF-F7 0.1.0-gastroflow.1)";
  - `Nff7Client` real rodado no container `web`, lendo o `.env` como uma requisição (`NF_F7_URL=http://nff7-app`): como está → `{"enabled":true,"connected":true,"client_name":"gastroflow","issuer_id":1,"version":"0.1.0-gastroflow.1"}` em 0,01 s; chave com um caractere a mais (só em memória) → `KEY_REJECTED`; `http://nff7-app:81` → `UNREACHABLE`; `http://nff7-inexistente` → `UNREACHABLE`. A chave não foi impressa.
- 2026-11-17, **AC6** — com as variáveis ainda não lidas pelo app (`enabled:false`, conferido no container), o owner não achou o cartão na tela; com elas, o cartão apareceu com "Conectado". Os dois lados do `x-if` observados.
- AC9: ver o diff de `docs/configuration.md`, `docs/ROADMAP.md`, `docs/architecture.md`, `specs/000-project-baseline.md`, `CLAUDE.md` e `CHANGELOG.md`.
