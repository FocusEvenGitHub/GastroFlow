# Spec 083 — ROADMAP sincronizado com a decisão NF-F7

## Metadata

- Status: Verified
- Created: 2026-11-16
- Updated: 2026-11-16
- Owner: Henry
- Related issue: Trello "[Docs] ROADMAP sincronizado com a decisão NF-F7 (spec 083)" (https://trello.com/c/VEGotHN6)
- Related branch: `docs/083-roadmap-nf-f7-sync`

Spec reduzida (só documentação, nenhum código), conforme `specs/README.md`.

## Context

Em 2026-11-10 o owner decidiu que o motor fiscal não pertence ao GastroFlow Community: ele fica no NF-F7 (FocusEven Fiscal), um produto separado, e o Community terá no máximo uma integração opcional por API HTTP. A decisão foi registrada só no Trello (cards "Decisão: motor fiscal pertence ao NF-F7" e "Plano de fechamento — GastroFlow Community v2.0", do qual este é o passo 2).

## Problem

Conferido no `master` de 2026-11-16 (`9e2b967`): `docs/ROADMAP.md` ainda trazia a seção `v2.1.0 — Fiscal / NFC-e` (cerca de 300 linhas: NFePHP, upload de certificado A1, CSC, XML, tabela `fiscal_documents`, DANFE), e `grep -rn "NF-F7"` no repositório não achava nada. O repositório é a fonte da verdade e contradizia o board. O `CLAUDE.md` também listava as listas do board com nomes que não existem mais (`🚧 v1.8`, `🧾 v2.1`).

## Goals

- O ROADMAP descreve a decisão e a fronteira Community × NF-F7, sem plano de emissão fiscal interna.
- Os outros docs que apontavam para a v2.1 fiscal ganham nota datada.

## Non-goals

- Especificar a integração (contrato, versão, telas): fica para depois da v2.0 e da API do NF-F7 estável.
- Reescrever specs antigas, entradas já lançadas do CHANGELOG ou comentários de teste que citam "fiscal"/"v2.1" (033, 037, 025, 081, `AuditLogTest`, `JobServiceTest`): são registro histórico do que se pensava na data.
- Qualquer código.

## Current behavior

Ver Problem.

## Proposed behavior

`docs/ROADMAP.md`:

- A seção `v2.1.0 — Fiscal / NFC-e` e o `v2.1.0 Exit Gate` saem, e entra `Post-v2.0 — Optional NF-F7 integration`: a decisão com data, a fronteira, o que continua valendo do plano antigo (opcional, desligado por padrão, falha fiscal nunca perde pedido, recibo e fiscal independentes), os quatro itens futuros dos cards do board e a regra de que nenhuma versão está reservada.
- O diagrama da Visão e o exemplo de releases 2.x deixam de citar `Fiscal / NFC-e`.
- Notas datadas nos status das specs 025 (paginação) e 033 (idempotência), que adiavam trabalho para a v2.1 fiscal.

`docs/architecture.md` › Audit history: nota datada. `CLAUDE.md` › Trello board: nomes atuais das listas. `CHANGELOG.md`: entrada em Não lançado › Manutenção, e a entrada ainda não lançada da spec 081 deixa de citar "configurações fiscais da v2.1".

## Functional requirements

Not applicable — nenhum comportamento muda.

## Non-functional requirements

Not applicable.

## User flows

Not applicable.

## API changes

Not applicable.

## Data model and migrations

Not applicable.

## Architecture and affected components

`docs/ROADMAP.md`, `docs/architecture.md`, `CLAUDE.md`, `CHANGELOG.md`. Nenhum arquivo de código.

## Security considerations

O ROADMAP passa a dizer que nenhum certificado fiscal vive no Community. A credencial de uma futura integração é um segredo de configuração: o item do ROADMAP lembra a allowlist da spec 081 e que `GET /api/admin/settings` devolve o valor de toda chave.

## Backward compatibility

Not applicable — só documentação. O plano antigo continua legível em `git show v1.9.0:docs/ROADMAP.md`.

## Acceptance criteria

1. `docs/ROADMAP.md` não tem mais um título `# v2.1.0 …` e tem `# Post-v2.0 — Optional NF-F7 integration`.
2. Em `docs/ROADMAP.md`, NFePHP, `.pfx`, CSC e `fiscal_documents` só aparecem na nota que aposenta o plano antigo.
3. Nenhum código fiscal existe no repositório (a afirmação "nada foi implementado" do ROADMAP e do CHANGELOG).
4. O plano antigo é recuperável pela tag `v1.9.0`.
5. As listas citadas em `CLAUDE.md` › Trello board são as do board.

## Implementation plan

1. ROADMAP. 2. Notas nos outros docs. 3. CHANGELOG.

## Task checklist

- [x] `docs/ROADMAP.md`
- [x] `docs/architecture.md`, `CLAUDE.md`
- [x] `CHANGELOG.md`
- [x] Spec 082 → `Verified` (CI do PR #76), carregado neste PR: o commit chegou depois do merge do PR #76 e ficou fora do `master`

## Open questions

Nenhuma.

## Implementation log

- 2026-11-16: a seção antiga foi removida em vez de riscada. A regra do projeto é corrigir no lugar com nota datada, e ela foi seguida com a nota de decisão no topo da seção nova e o ponteiro para a tag `v1.9.0`; manter 300 linhas riscadas de um plano que nunca foi implementado deixaria o ROADMAP ilegível.
- 2026-11-16: o link do quadro do NF-F7 no Trello não foi para o ROADMAP: o repositório é público e o quadro é interno.
- 2026-11-16: nenhum número de versão foi reservado para a integração (o card da decisão diz que ela "será decidida depois do v2.0"). O exemplo de releases 2.x ficou com um `v2.1.0` genérico.
- 2026-11-16: `README.md`, `SUPPORT.md`, `SECURITY.md`, `CONTRIBUTING.md` e os demais `docs/*.md` conferidos por `grep`: nenhum cita v2.1, NFC-e ou SEFAZ.
- 2026-11-16: a descrição do board no Trello continua desatualizada ("versão publicada v1.7.1"): o conector não edita boards.

## Validation evidence

- 2026-11-16, `grep -c "^# v2\.1\.0" docs/ROADMAP.md` → `0`; `grep -n "^# Post-v2.0" docs/ROADMAP.md` → `1723:# Post-v2.0 — Optional NF-F7 integration` (AC1).
- 2026-11-16, `grep -n -i "nfephp\|sped-nfe\|\.pfx\|CSC\|fiscal_documents" docs/ROADMAP.md` → só a linha 1725, a nota da decisão (AC2).
- 2026-11-16, `grep -rn -i "fiscal\|nfc-e\|sefaz\|nfephp" src public/admin public/cashier public/kitchen public/assets common bin composer.json .env.example .gitignore` → nada (AC3).
- 2026-11-16, `git show v1.9.0:docs/ROADMAP.md | grep -c "Fiscal / NFC-e"` → `4` (AC4).
- 2026-11-16, listas lidas do board pelo conector do Trello: `📌 Visão & Regras`, `✅ Concluído / Histórico`, `📦 v1.9 — Community Productization`, `🧪 v2.0 — Release Candidate`, `🔌 Pós-v2 — Integração NF-F7`, `🧹 Backlog / Follow-ups` (AC5).
