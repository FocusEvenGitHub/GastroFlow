# Spec 079 — CORS fechado por padrão

## Metadata

- Status: Verified
- Created: 2026-11-09
- Updated: 2026-11-09
- Owner: Henry
- Related issue: Trello "CORS: default permissivo `*`" (https://trello.com/c/9mXdbWNu)
- Related branch: `fix/079-cors-safe-default`

Spec reduzida (correção pequena e óbvia, conforme `specs/README.md`).

## Context

`docs/architecture.md` › Known architectural limitations registrava o padrão `*` do CORS como dívida aceita na v1.8, "flagged for `v2.0`'s release-candidate hardening pass". `docs/security.md` › Known limitations também o listava. A spec 001 tornou a origem configurável, mas manteve `*` como padrão por compatibilidade.

## Problem

Confirmado no código (2026-11-09): `src/App.php:76` passava `Settings::get('CORS_ALLOWED_ORIGIN', '*')` ao `CorsMiddleware`, cujo construtor também tinha `'*'` como padrão. O `.env.example` trazia `CORS_ALLOWED_ORIGIN=*`. Resultado: toda resposta de `/api/*` levava `Access-Control-Allow-Origin: *`, e qualquer site aberto num navegador da rede podia chamar a API aberta (caixa, cozinha) e ler as respostas.

## Goals

- Sem configuração, a API não manda nenhum header CORS (só a mesma origem chama pelo navegador).
- Valor explícito em `CORS_ALLOWED_ORIGIN` continua valendo, `*` inclusive.

## Non-goals

- Lista de várias origens, validação do formato da origem, comportamento diferente por `APP_ENV`. Ninguém pediu e não é preciso para fechar o padrão.
- Mexer no `.env` de instalações existentes (o código nunca toca o `.env`; o passo vai em `docs/upgrading.md`).

## Current behavior

Ver Problem.

## Proposed behavior

`CORS_ALLOWED_ORIGIN` ausente, vazio ou só com espaços → `CorsMiddleware` não adiciona nenhum header `Access-Control-*`, nem no preflight `OPTIONS` (que continua respondido com 200 vazio, mas o navegador o recusa por falta do header). Qualquer outro valor → mesmos headers de antes, com esse valor como origem.

As telas (`public/cashier/`, `public/kitchen/`, `public/admin/`) usam `fetch` com URLs relativas, ou seja, na mesma origem, e não dependem de CORS.

## Functional requirements

1. Sem `CORS_ALLOWED_ORIGIN`, ou com ela vazia, nenhuma resposta tem `Access-Control-Allow-Origin`.
2. Com `CORS_ALLOWED_ORIGIN=http://x`, as respostas e o preflight têm `Access-Control-Allow-Origin: http://x` e os demais headers de antes.
3. `CORS_ALLOWED_ORIGIN=*` mantém o comportamento antigo.

## Non-functional requirements

Not applicable — sem impacto de desempenho.

## User flows

Not applicable — nenhuma tela muda.

## API changes

Só os headers CORS, como descrito acima. Corpos e status iguais.

## Data model and migrations

Not applicable.

## Architecture and affected components

`src/Middleware/CorsMiddleware.php`, `src/App.php`, `.env.example`, `tests/Unit/CorsMiddlewareTest.php` (novo).

## Security considerations

Fecha o padrão permissivo. A API aberta (caixa/cozinha) continua sem login, mas um site de outra origem deixa de conseguir ler as respostas pelo navegador da vítima. Requisições "simples" (sem preflight) ainda chegam ao servidor, como em qualquer API sem CORS: isso é a limitação "Cashier and kitchen without login" de `docs/security.md`, não muda aqui.

## Backward compatibility

Uma instalação que copiou `CORS_ALLOWED_ORIGIN=*` do `.env.example` antigo continua com `*` até esvaziar a linha. `docs/upgrading.md` › 4 e o CHANGELOG pedem isso. Uma integração de terceiros em outra origem que dependia do `*` implícito precisa configurar a origem dela.

## Acceptance criteria

1. `CorsMiddleware` sem argumento: `GET` e `OPTIONS` com `Origin: http://evil.example` → sem `Access-Control-Allow-Origin` nem `Access-Control-Allow-Methods`.
2. `CorsMiddleware('http://192.168.1.10:8080')`: `GET` e `OPTIONS` → `Access-Control-Allow-Origin: http://192.168.1.10:8080`.
3. `CorsMiddleware('*')`: `GET` → `Access-Control-Allow-Origin: *`.
4. `.env.example` traz `CORS_ALLOWED_ORIGIN=` vazio; `docs/configuration.md`, `docs/security.md`, `docs/architecture.md` e `docs/upgrading.md` refletem o novo padrão.
5. PHPUnit, PHPStan e PHP-CS-Fixer limpos.

## Implementation plan

1. Padrão `''` no construtor do middleware; com origem vazia, nenhum header.
2. `App.php` lê a variável com padrão `''` e `trim`.
3. Teste unitário; docs; CHANGELOG.

## Task checklist

- [x] `CorsMiddleware` com padrão vazio
- [x] `App.php` sem fallback `*`
- [x] `tests/Unit/CorsMiddlewareTest.php`
- [x] `.env.example`, `docs/configuration.md`, `docs/security.md`, `docs/architecture.md`, `docs/upgrading.md`
- [x] `CHANGELOG.md` (Não lançado › Correções)
- [x] CI do PR verde

## Open questions

Nenhuma.

## Implementation log

- 2026-11-09: padrão escolhido = nenhum header (só a mesma origem), em vez de depender de `APP_ENV`: as telas nunca precisam de CORS, então não há motivo para um padrão diferente em desenvolvimento.
- 2026-11-09: a instância de desenvolvimento (8080) continuou mandando `*` depois da mudança. Isso é esperado se o `.env` local tiver `CORS_ALLOWED_ORIGIN=*`, copiado do exemplo antigo (requisito 3). O `.env` não foi lido para confirmar (regra de segurança), então o caminho de ponta a ponta sem a variável ficou coberto só pelo teste unitário do middleware mais a leitura da linha de `App.php`.

## Validation evidence

- 2026-11-09, `vendor/bin/phpunit --filter CorsMiddlewareTest` → `OK (3 tests, 7 assertions)` (AC 1–3).
- 2026-11-09, `vendor/bin/phpunit` → `OK, but some tests were skipped! Tests: 347, Assertions: 567, Skipped: 78` (integração pulada sem `MYSQL_DATABASE_TEST`).
- 2026-11-09, `git grep -n CORS_ALLOWED_ORIGIN` → só `.env.example:25` (`CORS_ALLOWED_ORIGIN=` vazio), `src/App.php:76` (padrão `''`) e as notas datadas da spec 079 em `docs/configuration.md`, `docs/security.md`, `docs/architecture.md` e `docs/upgrading.md`; nenhum outro fallback para `*` (AC 4). Achado do `/spec-review`.
- 2026-11-09, `vendor/bin/phpstan analyse` → `[OK] No errors`; `vendor/bin/php-cs-fixer fix --dry-run --diff` → `Found 0 of 119 files that can be fixed` (AC 5).
- 2026-11-10 (registrado na spec 080): CI do PR #73 verde — run `42749064096` (`test` success); merge `18775d7`. Todos os ACs com evidência → `Verified`.
