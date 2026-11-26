# Spec 088 — Links para o repositório renomeado (spec reduzida)

## Metadata

- Status: Verified
- Created: 2026-11-25
- Updated: 2026-11-25
- Owner: FocusEven
- Related issue: Not applicable (mudança de endereço observada no push da spec 087)
- Related branch: `docs/088-repo-rename`

## Context

O repositório no GitHub foi renomeado de `FocusEvenGitHub/GastroFlow` para `FocusEvenGitHub/GastroFlow-Community` (o `git push` da spec 087, em 2026-11-25, respondeu "This repository moved"). O GitHub redireciona o endereço antigo, mas os docs que um operador ou contribuidor lê ainda o citam.

## Problem

Nove arquivos versionados (README, `docs/installation.md`, `docs/upgrading.md`, `docs/security.md`, SECURITY, SUPPORT, CONTRIBUTING, `.github/ISSUE_TEMPLATE/config.yml`, `public/api/docs/index.html`) apontam para o endereço antigo. O redirecionamento pode parar se outro repositório for criado com o nome antigo.

## Goals

- Todo link vivo aponta para `GastroFlow-Community`.
- O `git clone` da instalação continua criando a pasta `GastroFlow`.

## Non-goals

- Não reescreve `CHANGELOG.md` antigo nem `specs/*` (registro histórico).
- Não muda a imagem `ghcr.io/focusevengithub/gastroflow` (`.github/workflows/release.yml`), que é nomeada à parte e não depende do nome do repositório.
- Não muda código, schema nem compose.

## Current behavior

Confirmado com `git grep -nE "FocusEvenGitHub/GastroFlow([^-]|$)"`: 13 ocorrências nesses 9 arquivos, além das de CHANGELOG e specs.

## Proposed behavior

Os 9 arquivos passam a citar `FocusEvenGitHub/GastroFlow-Community`. Os dois `git clone` (README, `docs/installation.md`) passam a `git clone https://github.com/FocusEvenGitHub/GastroFlow-Community.git GastroFlow`. Sem isso o clone criaria `GastroFlow-Community/`, quebrando o `cd GastroFlow` seguinte e mudando o nome do projeto Compose, logo do volume `<pasta>_db_data` (`docs/architecture.md`).

## Functional requirements

1. FR1 — Nenhum dos 9 arquivos cita o endereço antigo.
2. FR2 — Os dois `git clone` informam a pasta `GastroFlow`.

## Non-functional requirements

Not applicable — só texto.

## User flows

Operador segue `docs/installation.md`: o clone cria `GastroFlow/` e o `cd GastroFlow` funciona como antes.

## API changes

Not applicable — nenhum endpoint muda (`public/api/docs/index.html` só troca o link do rodapé).

## Data model and migrations

Not applicable — sem schema.

## Architecture and affected components

Só os 9 arquivos de documentação listados acima.

## Security considerations

`SECURITY.md` e o modelo de issue passam a apontar para o formulário de advisory privado do endereço novo (conferido: responde 200).

## Backward compatibility

Instalações existentes não mudam: o remote antigo continua funcionando pelo redirecionamento. Trocar o remote é opcional (comando no CHANGELOG).

## Acceptance criteria

1. AC1 — `git grep -nE "FocusEvenGitHub/GastroFlow([^-]|$)"` não acha nada fora de `CHANGELOG.md` e `specs/`.
2. AC2 — Toda URL nova do diff responde HTTP 200, sem redirecionamento.
3. AC3 — `git ls-remote https://github.com/FocusEvenGitHub/GastroFlow-Community.git HEAD` responde, e os dois clones terminam em ` GastroFlow`.

## Implementation plan

1. Trocar o endereço nos 9 arquivos.
2. Fixar a pasta nos dois `git clone`.
3. CHANGELOG ("Não lançado" › Documentação).

## Testing and validation strategy

Só texto: `git grep` (AC1), `curl -w '%{http_code}'` sem `-L` em cada URL nova (AC2), `git ls-remote` + leitura do diff (AC3).

## Rollout and rollback

Merge publica. Rollback: reverter o commit.

## Open questions

Nenhuma.

## Task checklist

- [x] Endereço trocado nos 9 arquivos
- [x] Pasta fixada nos dois `git clone`
- [x] CHANGELOG

## Implementation log

- 2026-11-25 — O remote local foi trocado pelo dono (`git remote set-url origin https://github.com/FocusEvenGitHub/GastroFlow-Community.git`).
- 2026-11-25 — `docs/security.md` cita o advisory `GHSA-rp7v-47mr-x2wx`; o link novo foi conferido (200).

## Validation evidence

2026-11-25:

- **AC1** — `git grep -nE "FocusEvenGitHub/GastroFlow([^-]|$)" -- <9 arquivos>` → nenhuma saída. O resto das ocorrências está só em `CHANGELOG.md` e `specs/`.
- **AC2** — `curl -s -o /dev/null -w '%{http_code}'` → `200` para as 8 URLs web do diff: raiz do repositório, `actions/workflows/ci.yml`, `ci.yml/badge.svg`, `blob/master/SUPPORT.md`, `issues`, `releases`, `tags`, `security/advisories/GHSA-rp7v-47mr-x2wx`. Não conferi `security/advisories/new`: sem sessão, a página só redireciona para o login.
- **AC3** — `git ls-remote …GastroFlow-Community.git HEAD` → `755fed8a5db1ae5c3b8a7f51527f49350db34af9 HEAD`. Diff: `+git clone https://github.com/FocusEvenGitHub/GastroFlow-Community.git GastroFlow` em `README.md:167` e `docs/installation.md:22`.
