# Technical decisions

Decisions with a real trade-off, not a stack list. See [`docs/architecture.md`](architecture.md) for how each of these plays out in the running system. Short versions of the top picks are in the [README](../README.md#technical-decisions).

| Decision | Why | Trade-off / cost |
|---|---|---|
| Slim 4 micro-framework, not a full framework | Routing/middleware/DI without adopting everything a framework like Laravel brings, for a project that started as raw PHP | No built-in test scaffolding, scheduler, or CLI tooling — those are added by hand as needed |
| Eloquent standalone (`Capsule\Manager`), not full Laravel | Familiar, expressive query builder/ORM without the framework around it | Pulls in several `illuminate/*` packages as implicit dependencies; no Laravel-style Schema-builder migrations |
| Hand-rolled `MigrationRunner` over plain `.sql` files, not an ORM migration framework | Schema changes are explicit SQL, easy to read as a diff, framework-agnostic | Forward-only — no `down()`/rollback semantics |
| `public/.htaccess` bypass for cashier/kitchen/admin views, instead of routing everything through Slim | Those pages are static Alpine.js shells that only need to call the JSON API — no reason to pay framework overhead for them | Two separate request-handling paths to reason about; anything needing CORS/auth middleware has to live under `/api/*` |
| Signal-file SSE instead of Redis/RabbitMQ for kitchen live updates | Zero extra infrastructure for a single-location deployment | Not safe under concurrent writes or multiple app instances — `ROADMAP.md` #5 plans to replace it |
| DB-backed `jobs` table + `bin/worker`, not a message broker | Avoids adding Redis/RabbitMQ for one background job type (printing) | Needs a long-running worker process supervised separately — `docker compose up -d` alone does not run it |

## Open, not yet resolved

- The hardcoded JWT-secret fallback at `src/Routes.php:19` is a known, named security gap (`ROADMAP.md` #9) — not fixed incidentally; `CLAUDE.md` reserves that change for its own spec.
- CORS currently allows any origin; making it configurable via env var is planned (`ROADMAP.md` #8) but not done.
- Whether raw-SQL migrations remain the right choice as the schema grows, versus adopting a Schema-builder-based migration layer, is unresolved.
- `common/config.php`/`common/db.php` (dead PDO helpers) — kept for now; whether to delete them or whether something external still depends on them is an open question in `specs/000-project-baseline.md`.
