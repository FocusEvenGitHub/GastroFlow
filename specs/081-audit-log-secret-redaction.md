# Spec 081 — Segredos fora do audit log de configurações

## Metadata

- Status: Implemented
- Created: 2026-11-10
- Updated: 2026-11-10
- Owner: Henry
- Related issue: Trello "Audit log: redação de segredos antes do fiscal (v2.1)" (https://trello.com/c/Co609LrB)
- Related branch: `fix/081-audit-log-secret-redaction`

Spec reduzida (mudança pequena e localizada, conforme `specs/README.md`).

## Context

Revisão externa de 2026-09-26, registrada em `docs/architecture.md` › Audit history e como pré-requisito do item "Fiscal settings + secure A1 certificate handling" da v2.1 em `docs/ROADMAP.md`. A v2.1 pretende guardar senha do certificado A1 e CSC na tabela genérica `settings`.

## Problem

Confirmado no código (2026-11-10): `SettingsController::updateSettings()` (`src/Controllers/SettingsController.php:82-87`) grava em `audit_log` o `old`/`new` de **toda** chave enviada a `PUT /api/admin/settings`, sem filtro. O `SettingsValidator` aceita qualquer chave (só exige texto ou null). `audit_log` é append-only e nunca podado (spec 043). Assim, a primeira alteração de uma configuração secreta gravaria o valor para sempre.

## Goals

- Nenhuma chave fora de uma lista explícita tem o valor gravado em `audit_log`.
- As chaves de hoje continuam auditadas com `old`/`new` reais.

## Non-goals

- `GET /api/admin/settings`, que devolve o valor de toda chave. Segredos write-only precisam de tratamento próprio na v2.1; anotado no ROADMAP.
- Mexer em linhas antigas de `audit_log` (nenhuma tem segredo: nenhuma configuração secreta existe).
- Restringir quais chaves o endpoint aceita.

## Current behavior

Ver Problem.

## Proposed behavior

`SettingsController::AUDITABLE_KEYS` = `restaurant_name`, `printer_ip`, `printer_port`, `discount_max_percent`, `print_logo`, as cinco chaves que `public/admin/settings.js` envia. Para essas, `old`/`new` como antes. Para qualquer outra chave, a mudança continua registrada, com `{"old": "[REDACTED]", "new": "[REDACTED]"}`. O valor é salvo normalmente em `settings`.

Allowlist em vez de denylist: uma chave fiscal nova fica protegida sem ninguém lembrar de incluí-la numa lista; o erro possível é o seguro (uma chave inofensiva nova aparece como `[REDACTED]` até entrar na lista).

## Functional requirements

1. `PUT /api/admin/settings` com uma chave fora da lista grava a chave em `audit_log.details` com `old` e `new` iguais a `[REDACTED]`, e o valor real em `settings`.
2. Chaves da lista continuam com `old`/`new` reais.

## Non-functional requirements

Not applicable.

## User flows

Admin › Configurações: nada muda. Admin › Auditoria: uma configuração fora da lista aparece com `[REDACTED]`.

## API changes

Nenhuma no contrato. Só o conteúdo de `details` em `GET /api/admin/audit-log` para chaves fora da lista.

## Data model and migrations

Not applicable.

## Architecture and affected components

`src/Controllers/SettingsController.php` (constante + `updateSettings()`), `tests/Integration/AuditLogTest.php`. Sem Service novo: `SettingsController` não tem camada de Service (`docs/architecture.md` › Audit history).

## Security considerations

Fecha o caminho pelo qual um segredo guardado como configuração chegaria ao `audit_log`. Continua aberto, e anotado para a v2.1: `GET /api/admin/settings` devolve os valores.

## Backward compatibility

Linhas antigas de `audit_log` não mudam. As cinco chaves do Admin são auditadas como antes.

## Acceptance criteria

1. Teste de integração: com `fiscal_csc` = `old-secret-123`, `PUT /api/admin/settings` com `fiscal_csc` = `new-secret-456` e `restaurant_name` = `Casa Teste` → 200; `settings.fiscal_csc` = `new-secret-456`; o `details` da linha `settings.updated` não contém `secret`, tem `fiscal_csc` = `{old: [REDACTED], new: [REDACTED]}` e `restaurant_name.new` = `Casa Teste`.
2. O teste existente `testUpdatingSettingsWritesAuditRowWithOldAndNewValues` (`printer_ip` `10.0.0.1` → `10.0.0.5`) continua passando.
3. `docs/architecture.md` › Audit history descreve a regra; `docs/ROADMAP.md` marca o pré-requisito da v2.1 como atendido.
4. PHPUnit (com integração), PHPStan e PHP-CS-Fixer limpos.

## Implementation plan

1. Constante e ramo em `updateSettings()`.
2. Teste de integração.
3. Docs (architecture, ROADMAP, security), CHANGELOG.

## Task checklist

- [x] `AUDITABLE_KEYS` + redação em `updateSettings()`
- [x] `testUnlistedSettingIsAuditedRedacted`
- [x] `docs/architecture.md`, `docs/ROADMAP.md`, `docs/security.md`
- [x] `CHANGELOG.md` › Não lançado › Segurança
- [x] Spec 080 → `Verified` (CI do PR #74), carregado neste PR
- [ ] CI do PR verde

## Open questions

Nenhuma.

## Implementation log

- 2026-11-10: allowlist escolhida (ver Proposed behavior). A lista vem de `public/admin/settings.js:50-56`, as únicas chaves que o Admin envia; as chaves internas do `PrintService` (`KEY_FAILURES` etc.) são gravadas direto por `Setting::setValue()` e não passam por este endpoint nem pelo audit log.
- 2026-11-10: a primeira execução do teste novo falhou só na ordem das chaves (`assertSame` com `['old','new']`): a coluna JSON do MySQL reordena as chaves do objeto. Trocado por `assertEquals`, com comentário.

## Validation evidence

- 2026-11-10, `docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --filter AuditLogTest` → `OK (7 tests, 34 assertions)` (AC1, AC2).
- 2026-11-10, `vendor/bin/phpunit` com `MYSQL_DATABASE_TEST=restaurant_test` → `OK, but some tests were skipped! Tests: 348, Assertions: 922, Skipped: 2` (os 2 pulados já existiam); `vendor/bin/phpstan analyse` → `[OK] No errors`; `php-cs-fixer fix --dry-run --diff` → `Found 0 of 119 files that can be fixed` (AC4).
- AC3: ver o diff de `docs/architecture.md` (Audit history, nota "Resolved 2026-11-10, spec 081") e de `docs/ROADMAP.md` (Dependencies da v2.1, nota "Updated 2026-11-10, spec 081").
