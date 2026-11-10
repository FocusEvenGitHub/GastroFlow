# Spec 080 — Sincronizar docs e status de specs com o estado real

## Metadata

- Status: Verified
- Created: 2026-11-10
- Updated: 2026-11-10
- Owner: Henry
- Related issue: Trello "Sincronizar docs/status restantes com o estado real" (https://trello.com/c/m1FDYeep)
- Related branch: `docs/080-docs-status-sync`

Spec reduzida (só documentação e status, nenhum código), conforme `specs/README.md`.

## Context

O card é de 2026-09-26 e listava seis divergências. Cada uma foi conferida no `master` de 2026-11-10 (`18775d7`):

| Item do card | Estado em 2026-11-10 |
|---|---|
| `README.md` trata a spec 033 como "in progress" e põe PHPStan/E2E em "Next up" | **Já corrigido** (spec 054 reescreveu o README; `grep -n "033\|Next up" README.md` → nada) |
| `docs/ROADMAP.md` v1.8 sem status por item | **Já corrigido** — toda subseção da v1.8 tem `**Status (spec …)**` e o Exit Gate diz "Met (audited 2026-09-27)" |
| `CHANGELOG.md` › "Itens ainda abertos" sem `Line endings` | **Já corrigido** — a seção não existe mais (`grep -n "ainda abertos" CHANGELOG.md` → nada) |
| `CHANGELOG.md` diz que `OrderService::$printService` não é usada | **Ainda falso** — `src/Services/OrderService.php:48,147` lê `printService->isPrintingBlocked()` (spec 039) |
| `docs/architecture.md` com contagens antigas | **Ainda falso** — Services 8 (são 12), Middleware 4 (são 5), Models 9 (são 12), migrations "21 files" (são 23) |
| Specs entregues com status `Implemented`/`Approved` | **Ainda aberto** — 24 specs não estavam `Verified` |

## Problem

Os docs afirmam coisas que o código não faz mais, e há specs com status atrasado em relação à própria evidência ou ao que já foi entregue.

## Goals

- Corrigir, com nota datada, as duas afirmações ainda falsas.
- Subir o status só onde há evidência real: a que a spec já registrava, ou uma conferência feita agora e registrada.

## Non-goals

- Inventar evidência. Spec que depende de checagem manual ainda não feita (navegador, impressora, dono) continua como está.
- Mudar código. A divergência de `JWT_SECRET` vazio achada no caminho (ver Implementation log) vira card próprio.

## Current behavior

Ver Context.

## Proposed behavior

Docs corrigidos com notas datadas; status das specs abaixo alterado com a evidência acrescentada em cada uma.

| Spec | Antes | Depois | Base |
|---|---|---|---|
| 079 | Implemented | Verified | CI do PR #73, run `42749064096` |
| 072 | Implemented | Verified | AC3/AC4 (runs do PR) e AC8 (tag `v1.9.0`: run, tags no GHCR, `VERSION`, notas) conferidos agora |
| 059 | Implemented | Verified | AC1–AC3 no release `v1.8.4` + conferidos agora na imagem `1.9.0`; AC4 executado agora na instância 8081 |
| 011 | Implemented | Verified | AC7 (`.env.example`) lido agora |
| 006-dishes-default-menu | Approved | Verified | evidência já registrada de AC 1–5 ("All acceptance criteria satisfied") |
| 007-empanado-protein | Approved | Implemented | AC 1–6 com evidência; AC 7 só inferido |
| 002 | Approved | Implemented | entregue na `v1.5.6`; sem seção de critérios; ver achado do `JWT_SECRET` vazio |

Continuam como estão, com o motivo que a própria spec registra:

| Spec | Status | O que falta |
|---|---|---|
| 000 | Implemented | baseline estática por definição; nunca será runtime-verified |
| 001 | Implemented | AC3 (padrão `*`) foi revertido de propósito pela spec 079; o AC4 (`.env.example`) está atendido desde a spec 011 |
| 007-cashier-kitchen-print-adjustments, 009, 010 | Implemented | checagem no navegador |
| 008 | Implemented | impressora física inalcançável na validação |
| 030, 031, 032, 036 | Implemented | checagem visual/no navegador |
| 034 | Implemented | AC10: falta observar uma falha proposital no passo de estilo do CI |
| 060 | Implemented | AC5 (reinício sem internet) pendente com o dono |
| 061 | Implemented | AC2 e AC5 parciais |
| 062 | Implemented | AC1 sem o passo 10 (impressão de teste — não havia impressora) |
| 065 | Implemented | AC8: logo impressa na impressora do cliente (dono) |
| 069 | Implemented | AC6 parcial |
| 073 | Implemented | AC9 com duas lacunas no stack de desenvolvimento |

## Functional requirements

1. `CHANGELOG.md` e `docs/architecture.md` não afirmam mais o que o código contradiz nos itens acima.
2. Cada mudança de status tem, na própria spec, evidência datada que a sustenta.

## Non-functional requirements

Not applicable — só documentação.

## User flows

Not applicable.

## API changes

Not applicable.

## Data model and migrations

Not applicable. (A verificação do AC4 da spec 059 criou um pedido, id 763, no `restaurant_test`, o banco da suíte E2E, nunca no de desenvolvimento.)

## Architecture and affected components

`CHANGELOG.md`, `docs/architecture.md`, `docs/ROADMAP.md`, `specs/000-project-baseline.md` e as specs 002, 006-dishes-default-menu, 007-empanado-protein, 011, 059, 072, 079.

## Security considerations

Nenhuma mudança de código. O achado do `JWT_SECRET` vazio fica registrado na spec 002 e num card próprio, para decidir a correção numa spec de segurança.

## Backward compatibility

Not applicable.

## Acceptance criteria

1. `grep -n "nunca lido" CHANGELOG.md` → a linha da spec 034 traz a correção datada citando a spec 039.
2. A tabela de camadas de `docs/architecture.md` traz as contagens atuais (Services 12, Middleware 5, Models 12) e a linha de migrations diz 23 arquivos, todas com nota datada.
3. Cada spec da tabela "Antes/Depois" tem o novo `Status` e uma entrada de evidência datada de 2026-11-10.
4. Nenhum arquivo fora de `CHANGELOG.md`, `docs/` e `specs/` muda.

## Implementation plan

1. Conferir cada item do card no `master`.
2. Corrigir as duas afirmações falsas.
3. Para cada spec não `Verified`, ler o motivo e conferir de novo só o que é verificável sem efeito colateral.
4. Spec, CHANGELOG, card do achado.

## Task checklist

- [x] Itens do card conferidos (3 já resolvidos, 3 abertos)
- [x] `CHANGELOG.md` (`printService`) e `docs/architecture.md` (contagens) com notas datadas
- [x] Nota no baseline apontando para as contagens atuais
- [x] Status das specs 079, 072, 059, 011, 006, 007-empanado e 002, com evidência
- [x] `docs/ROADMAP.md`: nota do `Verified` da spec 011
- [x] `CHANGELOG.md` › Não lançado
- [x] Card do achado `JWT_SECRET` vazio
- [x] CI do PR verde

## Open questions

Nenhuma.

## Implementation log

- 2026-11-10: **Achado fora de escopo — `JWT_SECRET` vazio.** `src/Routes.php:33` usa `$_ENV['JWT_SECRET'] ?? throw …`, que não pega string vazia, e o `.env.example` traz `JWT_SECRET=`. Com o bootstrap real e `$_ENV['JWT_SECRET'] = ''` (processo PHP avulso no `restaurant_web_e2e`): `/health/ready` 200, `/api/menu` 200, `/api/admin/settings` 401. O php-jwt v7.1.0 recusa a chave vazia, então não dá para forjar token, mas o login do Admin falha e o app sobe normalmente. `docs/configuration.md:38` diz que tudo falha com 500 e a mensagem "not set"; `docs/installation.md:48` diz "The app refuses to start without it" e `:156` liga "`JWT_SECRET` error in the log" a "Empty `JWT_SECRET`". Código e doc divergem; a correção (código ou doc) fica para uma spec própria, registrada como card no Backlog. Nada mudou aqui além do registro na spec 002.
- 2026-11-10: `gh api` para listar as versões do pacote no GHCR devolveu 403 (o token não tem `read:packages`); as tags `1.9.0`, `1.9` e `1` foram conferidas com `docker buildx imagetools inspect`.

## Validation evidence

Ver as entradas de 2026-11-10 em cada spec alterada. Os comandos que sustentam esta spec:

- `git grep -n "033\|Next up" README.md`, `grep -n "ainda abertos" CHANGELOG.md` → nada (itens 1 e 3 do card); `grep -n "Status (spec" docs/ROADMAP.md` na seção v1.8 → todas as subseções com status (item 2).
- `grep -n printService src/Services/OrderService.php` → linhas 13, 20, 26, 48, 147 (leitura nas linhas 48 e 147).
- `ls src/Services | wc -l` → 12; `ls src/Middleware` → 5; `ls src/Models` → 12; `ls common/migrations | wc -l` → 23.
- `grep -H -m1 "^- Status:" specs/0*.md | grep -v Verified` → 24 specs antes desta; a tabela acima mostra o destino de cada uma.
- `/spec-review` (2026-11-10): ACs 1–4 conferidos; dois motivos da tabela "continuam" estavam imprecisos (001 e 062) e foram corrigidos.
- 2026-11-10 (registrado na spec 081): CI do PR #74 verde — run `42751863165`; merge `9abfd50`. ACs 1–4 com evidência → `Verified`.
