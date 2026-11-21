# Spec 085 — Docs: NF-F7 só como módulo fiscal do GastroFlow, sem o escopo de condomínio

## Metadata

- Status: Verified
- Created: 2026-11-21
- Updated: 2026-11-21
- Owner: Henry
- Related issue: Not applicable
- Related branch: `docs/085-nff7-discontinued`

Spec reduzida (só documentação, nenhum código), conforme `specs/README.md`.

## Problem

O contrato com o único cliente do NF-F7 como produto próprio (um escritório contábil, NFS-e de
condomínio) foi encerrado. A documentação do GastroFlow descrevia o NF-F7 como "produto
separado" com escopo "NFC-e/NFS-e", sem registrar que ele agora só existe para o GastroFlow.

## Decisão do owner (2026-11-21)

Primeira leitura do pedido: NF-F7 descontinuado e fiscal internalizado no GastroFlow. Um commit
local foi feito assim e desfeito antes do push, porque o owner esclareceu: **o foco continua
no uso do NF-F7 no GastroFlow via compose, como está**; o que se descontinua totalmente é a
ideia de condomínio e afins. O NF-F7 vira só o módulo fiscal fechado do GastroFlow.

## Expected result

- `docs/adr/0001-descontinuacao-nf-f7.md` com data, motivo, decisão, impacto e o que saiu.
- Notas datadas no ROADMAP (seção pós-v2, fronteira) e em `docs/architecture.md`; linha do
  fiscal no `CLAUDE.md`; linha do ADR no `README.md`; entrada no CHANGELOG (só acréscimo).
- Integração da spec 084, `docs/fiscal-nff7.md`, `docs/configuration.md`, `.env.example`,
  `.gitignore`/`.dockerignore` e impressão do cupom: **sem mudança**, porque continuam certos.
- Specs 083 e 084 sem mudança: as decisões delas continuam valendo.

## Validation

- 2026-11-21, `git diff --cached --name-only` → só `CHANGELOG.md`, `CLAUDE.md`, `README.md`,
  `docs/ROADMAP.md`, `docs/architecture.md`, `docs/adr/0001-descontinuacao-nf-f7.md` e esta
  spec; nada em `src/`, `public/`, `bin/`, `tests/`, compose, `.env.example` ou `*ignore`.
- 2026-11-21, `docs/fiscal-nff7.md` existe e é igual ao do `master`.
- 2026-11-21, os links `adr/0001-descontinuacao-nf-f7.md` (ROADMAP, architecture) e
  `docs/adr/` (README) apontam para o arquivo criado.
