# Spec 059 — PHP update for PHP2Shell (CVE-2026-109618 / CVE-2026-109619)

<!-- Reduced spec (CLAUDE.md "Small and obvious fixes"): urgent security update. -->

## Metadata

- Status: Implemented
- Created: 2026-10-23
- Updated: 2026-10-23
- Owner: Henry
- Related issue: Not applicable (urgent security fix — out of milestone, patch release)
- Related branch: `059`

## Context

On 2026-10-21 PHP shipped emergency releases for two critical core flaws, publicly named "PHP2Shell": CVE-2026-109618 (out-of-memory during request startup leaves half-initialized request data that cleanup treats as valid) and CVE-2026-109619 (request-owned state survives after being freed and is reused by a later request on the same worker). One unauthenticated request is reportedly enough, before any application code runs; Apache with mod_php is named the highest-risk setup, and exploitation was reported in the wild. Fixed releases: 8.2.35, 8.3.36, 8.4.27, 8.5.12; 8.1 and older get no official fix ([Help Net Security](https://helpnetsecurity.com/2026/10/21/php2shell-php-vulnerabilities-patched-cve-2026-109618-cve-2026-109619), [BleepingComputer](https://bleepingcomputer.com/news/security/php-ships-emergency-patches-for-critical-php2shell-flaws)).

## Problem

`Dockerfile:1` is `FROM php:8.2-apache` — mod_php, the highest-risk SAPI — with a floating tag: an install whose image was built before 2026-10-21 keeps running a vulnerable PHP until the image is rebuilt with `--pull`, and nothing in the repository says so.

## Goals

- Production image on a patched PHP, with the patch version pinned so a stale cached base can't be silently reused.
- Reduce exposure to CVE-2026-109619 where it costs nothing.
- Tell operators exactly how to apply it.

## Non-goals

- Moving to PHP 8.3/8.4/8.5 (a minor-version upgrade is a separate change with its own compatibility testing; 8.2.35 is a supported, patched release).
- Raising `composer.json`'s `"php": ">=8.1"` (needs a `composer.lock` regeneration; flagged as a follow-up below).
- The upstream mitigation `memory_limit=-1` for CVE-2026-109618: it trades one risk for unbounded memory per request; the real fix is the patched binary.
- CI's `setup-php` `'8.2'` (resolves to the latest 8.2 patch on each run, CLI only, never serves requests).

## Current behavior

Confirmed: `Dockerfile:1` `FROM php:8.2-apache`; `docker-compose.yml` builds the same image (`gastroflow-web`) for `web` and `print-worker`, and `docker-compose.e2e.yml` for `web-e2e`. Authentication is JWT (`src/Middleware/JwtMiddleware.php`); no `session_start()` anywhere in `src/` or `public/`.

## Proposed behavior

- `FROM php:8.2.35-apache` (tag confirmed on Docker Hub, pushed 2026-10-21 21:59 UTC).
- `session.upload_progress.enabled=0` in `/usr/local/etc/php/conf.d/zz-security.ini` (upstream's mitigation for CVE-2026-109619; harmless — no PHP sessions are used).
- `CHANGELOG.md` ("Não lançado" › Segurança) with the rebuild command; `README.md`, `CLAUDE.md`, `docs/architecture.md` updated to the pinned tag.

## Functional requirements

1. FR1 — The built image runs PHP 8.2.35.
2. FR2 — `php -i` in the image reports `session.upload_progress.enabled => Off`.

## Non-functional requirements

Not applicable beyond FR1/FR2 — no application code changes.

## User flows

Operator: `git pull` → `docker compose build --pull web print-worker` → `docker compose up -d` → `docker compose exec web php -v` shows `8.2.35`.

## API changes

Not applicable — no API change.

## Data model and migrations

Not applicable — no schema change.

## Architecture and affected components

`Dockerfile`; docs only (`CHANGELOG.md`, `README.md`, `CLAUDE.md`, `docs/architecture.md`).

## Security considerations

This is the security fix. Installs outside Docker must use 8.2.35+, 8.3.36+, 8.4.27+ or 8.5.12+; 8.1 is unsupported upstream and stays exposed.

## Backward compatibility

Same minor version (8.2) — patch release, no language/extension change expected. The pin means future 8.2 patches need an explicit bump of this line (intended: updates become visible in Git).

## Acceptance criteria

1. AC1 — `docker compose build --pull web` succeeds and `docker compose exec web php -v` prints `PHP 8.2.35`.
2. AC2 — `docker compose exec web php -i | grep upload_progress.enabled` prints `Off`.
3. AC3 — The CI suite passes on the branch.
4. AC4 — Cashier, kitchen and admin load and an order can be created on the rebuilt container.

## Implementation plan

1. Pin the base image; add the security ini line.
2. Docs + CHANGELOG.
3. Rebuild and validate (AC1–AC4).

## Testing and validation strategy

No application code changed, so the existing suites are the regression check (AC3, CI). AC1, AC2 and AC4 need a real image build.

## Rollout and rollback

Rollout: rebuild with `--pull` and recreate `web`/`print-worker` (see User flows). Rollback: not recommended — any older 8.2 base is vulnerable; if 8.2.35 broke something, the fix is to move forward (8.3.36+), not back.

## Open questions

None blocking.

- Follow-up: raise `composer.json` `"php"` to `>=8.2` so nobody installs on 8.1, which has no upstream fix (needs `composer update --lock` run with network access to the registry).
- Pre-existing, unrelated: `Dockerfile` does `COPY legacy/ ./legacy/`, but `legacy/` is not in Git (only local); a fresh clone fails `docker compose build` until an empty `legacy/` exists. Belongs to v1.9's "Installation experience".

## Task checklist

- [x] 1. Pin base image + security ini
- [x] 2. Docs + CHANGELOG
- [ ] 3. Rebuild and validate (AC1–AC4) — pending a machine with Docker Hub access

## Implementation log

- 2026-10-23: chose the minimal patched release on the same minor (8.2.35) for an urgent, low-risk change; the minor-version upgrade is left out of scope.
- 2026-10-23: tried `docker build --pull` in the development sandbox; the base image metadata request reached Docker Hub but `composer:latest` was refused (`Forbidden`) by the sandbox's network proxy, so no image could be built there.

## Validation evidence

- Tag exists: Docker Hub API `GET /v2/repositories/library/php/tags/?name=8.2.35-apache` → `8.2.35-apache` (last updated 2026-10-21T21:59:43Z), plus `-bookworm`/`-trixie` variants.
- Directive valid: `php -d session.upload_progress.enabled=0 -r 'var_dump(ini_get("session.upload_progress.enabled"));'` → `string(1) "0"` (PHP 8.3.6 CLI).
- Image build: **not run** — Docker Hub blocked from the sandbox (`Forbidden`). AC1–AC4 pending; CI (AC3) runs on the PR.
