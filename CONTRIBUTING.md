# Contributing to GastroFlow

Thanks for wanting to help. GastroFlow is a restaurant order system that runs on a restaurant's own server and local network ([`README.md`](README.md)); contributions are reviewed with that in mind — reliability and simple operation come before new features ([`docs/ROADMAP.md`](docs/ROADMAP.md) › "Product Principles").

- **Found a security vulnerability?** Don't open an issue or PR — follow [`SECURITY.md`](SECURITY.md).
- **Questions and support:** [`SUPPORT.md`](SUPPORT.md).

## Before you start

- **Small fixes** (a typo, a clear bug with an obvious fix, a doc correction): just open a pull request.
- **Anything bigger** — a new feature, a behavior change, a new dependency, a schema change: open an [issue](https://github.com/FocusEvenGitHub/GastroFlow/issues) first and describe the problem. Not everything fits GastroFlow Community ([`SUPPORT.md`](SUPPORT.md) › "What is not promised"), and it's better to agree before you spend time on it.

Welcome: bug fixes, tests, documentation, compatibility fixes, and features that were discussed in an issue first.

## Development setup

Everything runs in Docker — you don't need PHP or MySQL on your machine. Node is only needed for the browser tests.

1. Follow [`docs/installation.md`](docs/installation.md) to get the `.env` and the containers, then switch to the **development** setup by adding to `.env`:

   ```bash
   COMPOSE_FILE=docker-compose.dev.yml
   ```

   The development compose mounts your working tree into the containers, so edits in `src/` and `public/` are live. Without that line you get the production setup, where edits only show up after a rebuild.

2. Install dependencies and apply migrations by hand (development doesn't do it for you):

   ```bash
   docker compose up -d
   docker compose exec web composer install
   docker compose exec web php bin/migrate
   docker compose exec web php bin/create-admin <username>
   ```

Every command, by task: [`docs/commands.md`](docs/commands.md).

## Checks your change must pass

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs these on every pull request; run the relevant ones locally first:

```bash
# Static analysis (PHPStan level 5) and code style (PSR-12)
docker compose exec web vendor/bin/phpstan analyse
docker compose exec web vendor/bin/php-cs-fixer fix --dry-run --diff

# Unit tests
docker compose exec web vendor/bin/phpunit --testsuite Smoke,Unit

# Integration tests against MySQL — on a separate test database, never the development one
docker compose exec -e MYSQL_DATABASE_TEST=restaurant_test web vendor/bin/phpunit --testsuite Integration
```

The integration tests write real rows: they skip themselves without `MYSQL_DATABASE_TEST` and fail on purpose if it equals `MYSQL_DATABASE`. One-time setup: `CREATE DATABASE restaurant_test` plus a `GRANT` on it for `MYSQL_USER`.

If you changed the cashier, kitchen or Admin screens, also run the browser tests. They use a second app instance on port **8081**, pointed at the test database — never run them against 8080:

```bash
docker compose -f docker-compose.dev.yml -f docker-compose.e2e.yml up -d web-e2e
cd tests/e2e && npm install && npx playwright install chromium && npx playwright test
```

CI also checks `composer validate --strict`, `composer audit`, and that no committed file has CRLF line endings.

## Code conventions

- **PHP:** PSR-12, enforced by PHP-CS-Fixer — run it instead of formatting by hand. Every PHP file under `src/`, `bin/` and `tests/` starts with `declare(strict_types=1)` (the fixer enforces it there; the view scripts in `public/` don't have it). In new classes, prefer promoted `private readonly` constructor properties.
- **Layers:** follow the ones that exist (`src/Controllers`, `Services`, `Repositories`, `Validators`, `Middleware`, `Models`) — don't add a Repository/Validator/Service for a domain that doesn't have one just for symmetry ([`docs/architecture.md`](docs/architecture.md)).
- **Language:** identifiers, docblocks and docs in English; text the restaurant staff see (screens, API error messages) in Portuguese. Code comments are in either language — many domain and frontend comments are in Portuguese; follow the surrounding code rather than translating it.
- **Database:** schema changes only through a new file in `common/migrations/` — never edit a migration that was already released.
- **Frontend:** no build step and no CDN in the operational screens. Third-party libraries are vendored and pinned in `public/vendor/` ([`public/vendor/README.md`](public/vendor/README.md)). Reference the project's own JS/CSS with `?v=<filemtime>` so browsers don't keep an old copy after an upgrade.
- **Dependencies:** no new Composer/npm dependency or version bump without discussing it in an issue first.
- **Line endings:** LF everywhere (`.gitattributes`). On Windows, see README › "Line endings (Windows)".
- **Secrets:** never commit `.env`, credentials or tokens.

## Commits, branches and pull requests

- **Commits:** follow [`docs/COMMIT_CONVENTION.md`](docs/COMMIT_CONVENTION.md) — `<emoji> <type>(<scope>): <description>`, imperative, title up to 72 characters, one logical change per commit. Recent history is in Portuguese; English is fine too.
- **Branches:** `feature/<name>` or `fix/<name>`. (Maintainers name branches after the spec number, e.g. `070`.)
- **Specs:** non-trivial changes in this repository are planned in a spec under [`specs/`](specs/) — problem, plan, acceptance criteria, implementation log and validation evidence ([`specs/README.md`](specs/README.md), template [`specs/_template.md`](specs/_template.md)). Writing one is welcome but not required from outside contributors; a maintainer may write it from your issue or PR.
- **Pull requests:** one pull request per change, using the template. Include, in the same PR:
  - an entry in [`CHANGELOG.md`](CHANGELOG.md) for anything a user or operator would notice;
  - updates to any doc your change makes false (README, `docs/`).
- Say how you tested it — the commands you ran and what you saw.

## License

GastroFlow is licensed under the [Apache License 2.0](LICENSE). By submitting a contribution you agree that it is licensed under the same terms (section 5 of the license). There is no separate contributor agreement (CLA).
