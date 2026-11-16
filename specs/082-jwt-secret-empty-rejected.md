# Spec 082 — `JWT_SECRET` vazio recusado no boot

## Metadata

- Status: Verified
- Created: 2026-11-16
- Updated: 2026-11-16
- Owner: Henry
- Related issue: Trello "JWT_SECRET vazio não é recusado no boot (doc diz que é)" (https://trello.com/c/IVcY68M2)
- Related branch: `fix/082-jwt-secret-empty-rejected`

Spec reduzida (correção pequena e óbvia, conforme `specs/README.md`).

## Context

Achado da spec 080 (2026-11-10), registrado em `specs/002-jwt-secret-required.md`. É o passo 1 do plano de fechamento da v2.0 (backlog que seja bug/security/docs).

## Problem

Confirmado no código (2026-11-16): `src/Routes.php:33` fazia `$_ENV['JWT_SECRET'] ?? throw …`, que só lança quando a chave **não existe**. O `.env.example` traz `JWT_SECRET=` vazio, então quem esquece de preencher sobe com segredo `''`: caixa, cozinha e `/health/ready` respondem 200 e só o login do Admin falha (o php-jwt recusa a chave vazia, então não dá para forjar token). `docs/configuration.md` e `docs/installation.md` dizem que o app não sobe nesse caso.

## Goals

- `JWT_SECRET` vazio ou só com espaços é tratado como ausente: mesma exceção, mesma mensagem.

## Non-goals

- Tamanho mínimo do segredo. O php-jwt já recusa chave curta ao assinar; um mínimo próprio no boot é outra decisão e ninguém pediu.
- Mudar como a falha aparece (HTTP 500 em toda requisição, containers "Up"): é o comportamento que os docs já descrevem para a variável ausente.

## Current behavior

Ver Problem.

## Proposed behavior

`Routes::register()` lê `$_ENV['JWT_SECRET']` e lança `RuntimeException('JWT_SECRET environment variable is not set.')` se o valor, depois de `trim`, for vazio. Um valor preenchido é usado exatamente como está (o `trim` serve só para a checagem), então nenhuma sessão do Admin aberta é afetada.

## Functional requirements

1. Com `JWT_SECRET` ausente, `''` ou só espaços, `Routes::register()` lança `RuntimeException` com a mensagem acima.
2. Com `JWT_SECRET` preenchido, as rotas são registradas como antes.

## Non-functional requirements

Not applicable.

## User flows

Not applicable — nenhuma tela muda.

## API changes

Nenhuma no contrato. Numa instalação com `JWT_SECRET` vazio, toda requisição passa a responder 500 em vez de só o login do Admin falhar.

## Data model and migrations

Not applicable.

## Architecture and affected components

`src/Routes.php`, `tests/Unit/RoutesJwtSecretTest.php` (novo). A checagem fica em `Routes`, onde já estava: `Settings::getRequired()` faria o mesmo para `''`, mas `Routes` não recebe `Settings` e a regra é uma linha.

## Security considerations

Fecha a divergência entre o código e o que `docs/configuration.md`/`docs/installation.md` prometem: uma instalação sem segredo deixa de parecer saudável. Não havia como forjar token antes (php-jwt recusa a chave vazia), então não há advisory.

## Backward compatibility

Uma instalação que roda hoje com `JWT_SECRET` vazio (Admin já sem login) passa a responder 500 em tudo, caixa e cozinha inclusive, até preencher a variável. `docs/upgrading.md` › 4 avisa.

## Acceptance criteria

1. Teste unitário: `Routes::register()` com `JWT_SECRET` ausente, `''` e `'   '` → `RuntimeException` com `JWT_SECRET environment variable is not set.`.
2. Teste unitário: com `JWT_SECRET` preenchido, `register()` não lança e registra rotas.
3. Os testes do critério 1 para `''` e `'   '` falham no código anterior.
4. Bootstrap real (`App::get()`) com `$_ENV['JWT_SECRET'] = ''` lança a mesma exceção.
5. PHPUnit (com integração), PHPStan e PHP-CS-Fixer limpos.
6. `docs/configuration.md`, `docs/upgrading.md`, `CLAUDE.md`, `specs/002` e `specs/000` refletem a regra.

## Implementation plan

1. Checagem de vazio (com `trim`) em `Routes::register()`.
2. Teste unitário.
3. Docs, CHANGELOG.

## Task checklist

- [x] `Routes::register()` recusa segredo vazio
- [x] `tests/Unit/RoutesJwtSecretTest.php`
- [x] `docs/configuration.md`, `docs/upgrading.md`, `CLAUDE.md`, `specs/002`, `specs/000`
- [x] `CHANGELOG.md` › Não lançado › Segurança
- [x] Spec 081 → `Verified` (CI do PR #75), carregado neste PR
- [x] CI do PR verde

## Open questions

Nenhuma.

## Implementation log

- 2026-11-16: checagem mantida em `Routes` em vez de `Settings::getRequired()` (ver Architecture). `trim` incluído na checagem porque um valor só com espaços é o mesmo erro de preenchimento.
- 2026-11-16: achado do `/spec-review`, corrigido antes do PR: a primeira versão guardava o segredo já com `trim`, o que mudaria a chave de quem tem espaços nas pontas e derrubaria as sessões do Admin. Agora o `trim` vale só para a checagem. Testes, PHPStan e CS-Fixer rodados de novo depois da troca (os números de Validation evidence são dessa última rodada).
- 2026-11-16: o caminho HTTP de ponta a ponta (requisição real → 500) não foi exercitado: exigiria esvaziar o `.env` de uma instância, que não é lido nem alterado (regra de segurança). O critério 4 cobre o bootstrap real com o valor trocado em memória.

## Validation evidence

- 2026-11-16, `docker compose exec web vendor/bin/phpunit --filter RoutesJwtSecretTest` → `OK (4 tests, 7 assertions)` (AC1, AC2).
- 2026-11-16, mesmo comando com `src/Routes.php` de antes (`git stash`): `Tests: 4, Assertions: 5, Failures: 2` — falharam `"empty" ('')` e `"only spaces" ('   ')` com `Failed asserting that exception of type "RuntimeException" is thrown` (AC3).
- 2026-11-16, `docker compose exec web php -r '…'` carregando o `.env`, pondo `$_ENV["JWT_SECRET"] = ""` e chamando `(new App\App(new App\Settings()))->get()` → `RuntimeException: JWT_SECRET environment variable is not set.` (AC4).
- 2026-11-16, `vendor/bin/phpunit` com `MYSQL_DATABASE_TEST=restaurant_test` → `OK, but some tests were skipped! Tests: 352, Assertions: 929, Skipped: 2` (os 2 pulados já existiam); `vendor/bin/phpstan analyse` → `[OK] No errors`; `php-cs-fixer fix --dry-run --diff` → `Found 0 of 120 files that can be fixed` (AC5).
- AC6: ver o diff dos arquivos listados.
- 2026-11-16: CI do PR #76 verde — `gh pr checks 76` → `test pass` (run `43961317447`), no commit `63fd5c3`. Todos os ACs com evidência → `Verified`.
