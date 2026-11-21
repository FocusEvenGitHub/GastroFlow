# Spec 085 — Docs: NF-F7 só como módulo fiscal do GastroFlow

## Metadata

- Status: Verified
- Created: 2026-11-21
- Updated: 2026-11-21
- Owner: Henry
- Related issue: Not applicable
- Related branch: `docs/085-nff7-discontinued`

Spec reduzida (só documentação, nenhum código), conforme `specs/README.md`.

## Problem

A documentação do GastroFlow descrevia o NF-F7 como um produto separado. Por decisão do owner
(2026-11-21), ele deixa de ser produto separado e segue só como o módulo fiscal fechado do
GastroFlow, via compose, como hoje, para a NFC-e do restaurante.

## Expected result

- `docs/adr/0001-descontinuacao-nf-f7.md` com data, decisão, impacto e o que saiu de escopo.
- Notas datadas no ROADMAP (seção pós-v2, fronteira) e em `docs/architecture.md`; linha do
  fiscal no `CLAUDE.md`; linha do ADR no `README.md`; entrada no CHANGELOG (só acréscimo).
- Integração da spec 084, `docs/fiscal-nff7.md`, `docs/configuration.md`, `.env.example`,
  `.gitignore`/`.dockerignore` e impressão do cupom: **sem mudança**, porque continuam certos.
- Specs 083 e 084 sem mudança: as decisões delas continuam valendo.
- O repositório é público: nenhum detalhe do negócio do NF-F7 fora do GastroFlow entra aqui.

## Validation

- 2026-11-21, `git diff --name-only master` → só `CHANGELOG.md`, `CLAUDE.md`, `README.md`,
  `docs/ROADMAP.md`, `docs/architecture.md`, `docs/adr/0001-descontinuacao-nf-f7.md` e esta
  spec; nada em `src/`, `public/`, `bin/`, `tests/`, compose, `.env.example` ou `*ignore`.
- 2026-11-21, `docs/fiscal-nff7.md` igual ao do `master`.
- 2026-11-21, os links `adr/0001-descontinuacao-nf-f7.md` (ROADMAP, architecture) e
  `docs/adr/` (README) apontam para o arquivo criado.
