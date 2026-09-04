# GastroFlow Community Roadmap

> **Current version:** v1.6.0
> **Target:** v2.0.0
> **Edition:** GastroFlow Community
> **Scope:** Self-hosted restaurant management for a single restaurant/location.

---

# Vision

GastroFlow Community is a free, self-hosted restaurant management system designed to run the daily operation of **one restaurant location** reliably.

The goal of the v2 cycle is not to dramatically increase the number of features.

The goal is to transform the current working application into a **stable, secure, tested, documented and reproducible product**.

```text
GastroFlow v1.5.6
       │
       ▼
v1.6 — Baseline & Security
       │
       ▼
v1.7 — Domain & Architecture
       │
       ▼
v1.8 — Reliability & Quality
       │
       ▼
v1.9 — Community Productization
       │
       ▼
v2.0.0
GastroFlow Community
       │
       ▼
v2.1 — Fiscal / NFC-e
```

---

# Community Product Definition

GastroFlow Community is intentionally designed for:

> **One restaurant, one location, one self-hosted installation.**

Its primary responsibilities are:

* cashier ordering;
* kitchen operation;
* menu management;
* ingredient/component management;
* restaurant settings;
* thermal printing;
* reports;
* staff access;
* local network operation;
* reliable self-hosting.

Community should remain simple enough to install and operate without unnecessary infrastructure.

---

# Product Principles

## Reliability before features

Priority order:

```text
Correctness
    ↓
Security
    ↓
Tests
    ↓
Operational reliability
    ↓
Usability
    ↓
New features
```

A new feature should not be prioritized over an unreliable critical workflow.

---

## Single-location first

Community should not carry architectural complexity that does not benefit a single restaurant.

Avoid introducing infrastructure or abstractions only because they may be useful to another edition in the future.

Every architectural decision in Community must be justified by a Community requirement.

---

## Local-first operation

After installation, the restaurant's core workflow should not depend on external internet services.

Normal operation should remain available over the restaurant LAN whenever possible.

Priority workflows:

```text
Cashier
   ↓
Orders
   ↓
Kitchen
   ↓
Printing
   ↓
Reports
```

---

## Keep the stack proportional

Current core stack remains appropriate:

* PHP 8.2+
* Slim 4
* PHP-DI
* Eloquent
* MySQL 8
* Alpine.js
* Bootstrap
* Docker
* PHPUnit

Do not rewrite GastroFlow solely to adopt another framework.

Technology changes should solve concrete problems.

---

# Development Workflow

The roadmap defines **direction**.

Implementation details belong in `specs/`.

Non-trivial changes should continue using the project workflow:

```text
Problem
   ↓
/spec-plan
   ↓
Draft
   ↓
Human review
   ↓
Approved
   ↓
/spec-implement
   ↓
Tests
   ↓
/spec-review
   ↓
Verified
```

GitHub Issues should track individual pieces of work.

---

# Current Baseline — v1.6.0

GastroFlow already includes:

* cashier ordering workflow (pickup-ticket "Senha" numbering — never a physical table);
* kitchen workflow, including order edit/delete/reprint;
* menu administration, with search;
* ingredients/components;
* restaurant settings;
* ESC/POS thermal printing;
* asynchronous DB-backed jobs;
* reports;
* JWT authentication with self-service password change (no logout — stateless JWT, documented rationale);
* role-based authorization (`admin`/`manager`/`cashier`/`kitchen`) enforced on `/api/admin/*`;
* no default admin or database credentials — `bin/create-admin` and environment-provided DB config only;
* sanitized production error responses (`APP_ENV=production`);
* `.env` never baked into Docker images;
* configurable CORS;
* SQL migrations;
* Docker Compose;
* `composer.lock` tracked for reproducible builds;
* PHPUnit;
* GitHub Actions CI;
* spec-driven development;
* tagged releases;
* CHANGELOG.

`v1.6.0 — Baseline & Security` (below) is complete — see `CHANGELOG.md` and tag `v1.6.0`. The v2 cycle continues with `v1.7.0 — Domain & Architecture`.

---

# v1.6.0 — Baseline & Security

**Status: Complete.** Tagged `v1.6.0`, documented in `CHANGELOG.md`. Per-subsection status is noted inline below.

## Objective

Remove legacy assumptions, insecure defaults and documentation contradictions.

At the end of v1.6, the repository should accurately represent the real application and be safe to deploy outside a development-only environment.

---

## Project baseline synchronization

Create a code-verified baseline of the current project.

Review and synchronize:

* `README.md`
* `CLAUDE.md`
* architecture documentation
* technical decisions
* API documentation
* roadmap
* current project baseline spec

Remove documentation referring to functionality that no longer reflects the implementation.

**Status (spec 006)**: Verified — `README.md`, `CLAUDE.md`, `docs/architecture.md`, `docs/technical-decisions.md`, the API docs and `specs/000-project-baseline.md` synchronized with the real codebase. Follow-up doc-sync passes (README/ROADMAP updates) continued as later specs in this milestone landed.

---

## Order terminology cleanup

`order_number` is the canonical operational identifier for an order.

Review remaining usage of:

```text
table_number
```

and determine whether each occurrence is:

* obsolete;
* migration-related;
* documentation-only;
* or a genuine future table concept.

Do not use `table_number` as a replacement for order identification.

Any future restaurant table feature must be modeled independently.

### Completion criteria

Searching the active application for old table-based ordering assumptions should not reveal incorrect business behavior.

**Status (spec 010)**: Implemented — confirmed `table_number` is a customer-facing pickup ticket ("Senha"), never a physical table; unified `POST /api/orders`'s request field (was `table`) with `PUT`'s (`table_number`); corrected `README.md`/OpenAPI docs that described a physical-table model. Concurrency-safe numbering and the `order_number` rename itself are deferred to `v1.7.0`'s "Order number integrity".

---

## Application environment

Introduce explicit environment concepts:

```env
APP_ENV=development
APP_DEBUG=true
APP_TIMEZONE=America/Sao_Paulo
```

Supported environments should include at least:

```text
development
test
production
```

Production defaults must never silently enable insecure development behavior.

**Status (spec 011)**: Implemented — `APP_ENV`/`APP_DEBUG`/`APP_TIMEZONE` are now read via `Settings`, documented in `.env.example`; application timezone is configurable without editing Docker/OS config.

---

## Production error handling

Detailed application errors may remain available during development.

Production API responses must not expose:

* source paths;
* stack traces;
* SQL details;
* credentials;
* infrastructure information.

Example production response:

```json
{
    "success": false,
    "error": "Internal server error",
    "code": "INTERNAL_ERROR"
}
```

Full exceptions should remain available through application logs.

**Status (spec 012)**: Verified — `APP_ENV=production` responses no longer expose stack trace, file path or SQL detail; full exceptions still logged to `logs/app.log`.

---

## Docker secret handling

Ensure runtime secrets are not embedded into Docker images.

Review:

* Dockerfile;
* `.dockerignore`;
* Compose configuration;
* environment loading.

Production images must not contain:

* `.env`;
* database passwords;
* JWT secrets;
* development-only files;
* local logs.

**Status (spec 013)**: Verified — `.env` is no longer copied into the built Docker image.

---

## Database bootstrap cleanup

Remove globally known default database credentials from schema/bootstrap logic.

Database users and passwords should come from environment/deployment configuration.

Schema migrations should only manage application database structures and data required by GastroFlow itself.

**Status (spec 014)**: Verified — hardcoded DB credentials removed from schema/bootstrap; database users/passwords now come exclusively from environment/deployment configuration.

---

## Administrator bootstrap

Remove permanent default credentials such as:

```text
admin
admin123
```

Provide an explicit administrator creation process.

Suggested:

```bash
php bin/create-admin
```

Requirements:

* secure password hashing;
* duplicate-user validation;
* empty/invalid password rejection;
* no predictable production credentials.

**Status (spec 015)**: Verified — the seeded `admin`/`admin123` row is gone from `001_schema.sql`; `bin/create-admin` creates the first administrator (password confirmed twice, bcrypt hash, duplicate-username/empty-password rejection). Existing, already-initialized databases are unaffected — the schema only runs on a genuinely empty volume.

---

## Authentication hardening

Review the authentication lifecycle.

Define and test:

* token expiration;
* invalid tokens;
* expired tokens;
* logout behavior;
* password changes;
* password hashing;
* login throttling.

Document the authentication strategy clearly.

**Status (spec 016)**: token expiration/invalid/expired handling and password hashing were already correct, just undocumented — now written up in `docs/architecture.md`'s "Authentication" section. Password changes shipped (`PATCH /api/admin/account/password`). Logout was confirmed as a deliberate non-feature (stateless JWT, documented rationale) rather than a gap. **Login throttling is explicitly not done** — deferred to its own follow-up spec (not yet planned), since it needs its own design decisions (per-IP vs per-username tracking, storage mechanism — no Redis/cache exists in this project, so it likely needs a small schema addition — and lockout duration) that don't fit cleanly alongside the rest of this checklist.

---

## Authorization / RBAC

Move beyond:

```text
authenticated
vs
unauthenticated
```

toward simple role-based authorization.

Suggested roles:

```text
ADMIN
MANAGER
CASHIER
KITCHEN
```

Examples:

* administrative configuration requires admin-level permission;
* cashier mutations require appropriate permission;
* kitchen mutations require appropriate permission;
* reports require authorized access;
* intentionally public endpoints must be explicitly documented.

Avoid over-engineering the permission system.

**Status (spec 018)**: implemented, scoped to `/api/admin/*` only — an explicit decision made before implementation, not an omission. Taken literally, "cashier/kitchen mutations require appropriate permission" would mean moving `/api/orders*`/`/api/kitchen/*` behind login, but those are deliberately public, trusted-network endpoints (see Current Baseline and `docs/architecture.md`) with no login UI; reversing that was judged a much larger, riskier change than adding role checks, and wasn't requested. `users.role` now supports the full suggested set (`admin`, `manager`, `cashier`, `kitchen`), but `cashier`/`kitchen` don't yet gate anything — their operational domain remains the public endpoints above. Within `/api/admin/*`: settings/logs/printer config require `admin`; menu management and reports allow `admin` or `manager`; changing one's own password is open to any authenticated role.

---

## Dependency reproducibility

* version `composer.lock`;
* run `composer validate --strict`;
* run `composer audit`;
* ensure development, CI and Docker use the same dependency graph.

**Status (spec 017)**: Verified — `composer.lock` is now tracked and committed (as of tag `v1.6.0`); `composer validate --strict` and `composer audit` both pass clean.

---

# v1.6 Exit Gate

v1.6 is complete when:

> GastroFlow no longer depends on insecure development defaults and the repository documentation accurately describes the current application.

**Met.** No default admin or database credentials remain, production error responses are sanitized, `.env`/secrets are excluded from Docker images, dependencies are reproducible (`composer.lock` tracked), and `README.md`/`CLAUDE.md`/this roadmap are synchronized with the actual `v1.6.0` implementation.

---

# v1.7.0 — Domain & Architecture

## Objective

Make critical restaurant business rules explicit, predictable and testable.

---

## Order number integrity

Define the exact behavior of `order_number`.

Decide whether numbering:

* increases continuously; or
* resets according to an explicit restaurant/business-day rule.

The implementation must be safe under concurrent requests.

Avoid relying on unsafe:

```text
MAX(order_number) + 1
```

behavior without concurrency protection.

Enforce the approved uniqueness rule at database level.

Add concurrency-focused tests.

---

## Order lifecycle

Document and enforce the real order state machine.

Possible model:

```text
PENDING
   ↓
PREPARING
   ↓
READY
   ↓
DONE
```

Cancellation and reopening must be explicit business operations.

Invalid state transitions must fail predictably.

Examples:

```text
CANCELED → READY
```

should not happen unless explicitly supported.

Statuses that are not actually used should not remain merely for theoretical completeness.

---

## Pricing domain

Move pricing rules away from persistence code.

Preferred direction:

```text
OrderService
     ↓
PricingService
     ↓
OrderRepository
```

Pricing should explicitly calculate:

* items subtotal;
* packaging;
* discounts, if applicable;
* final total.

Repositories should persist values, not decide restaurant pricing policy.

---

## Money representation

Financial calculations must use exact arithmetic.

Avoid binary floating-point arithmetic for money.

Use:

* integer cents; or
* another tested exact Money representation.

MySQL may continue using appropriate `DECIMAL` fields.

Financial rules require dedicated tests.

---

## Historical order snapshots

Historical orders must remain accurate after menu changes.

Order items should preserve information required for history, such as:

```text
item name
unit price
packaging value
```

Reports and receipts for old orders should not depend on current menu prices.

---

## Order validation

Orders should be rejected before persistence when:

* there are no items;
* a menu item does not exist;
* a menu item is unavailable;
* quantity is invalid;
* quantity is zero or negative;
* quantity exceeds a reasonable limit;
* dining option is invalid;
* notes exceed defined limits.

An invalid menu item must never silently become a zero-price item.

---

## API error standardization

Create one consistent API error format.

Example:

```json
{
    "success": false,
    "error": "Menu item not found",
    "code": "MENU_ITEM_NOT_FOUND"
}
```

Avoid each controller inventing its own error response shape.

---

## Input validation

Add dedicated validation where external input modifies application state.

Priority domains:

* orders;
* menu items;
* ingredients;
* settings;
* authentication;
* users.

Validators validate input shape.

Services enforce business rules.

---

## Controller responsibilities

Review oversized or unrelated controller responsibilities.

Split controllers when multiple unrelated domains are being handled together.

Example:

```text
AdminController
     ↓
SettingsController
PrinterController
LogController
```

Do not split merely to increase the number of files.

---

## Persistence boundaries

HTTP concerns and persistence logic should remain separated where useful.

Preferred direction:

```text
Controller
    ↓
Service
    ↓
Repository / Model
```

Repositories should exist when they provide a useful persistence/domain boundary.

Do not introduce empty abstraction layers only for architectural symmetry.

---

## Query performance

Review queries used by growing operational data.

Priority indexes/queries include:

```text
orders.order_number
orders.status
orders.created_at
order_items.order_id
jobs.status
```

Add indexes based on real query patterns.

Avoid speculative optimization.

---

## Pagination

Paginate endpoints that may grow indefinitely.

Example:

```http
GET /api/orders?page=2&per_page=50
```

Suggested metadata:

```json
{
    "page": 2,
    "per_page": 50,
    "total": 830,
    "last_page": 17
}
```

Operational kitchen views may use status/date filtering instead when that better fits the workflow.

---

# v1.7 Exit Gate

v1.7 is complete when:

> Critical rules involving orders, money and permissions are explicit, testable and no longer depend on incidental controller or repository behavior.

---

# v1.8.0 — Reliability & Quality

## Objective

Make GastroFlow reliable enough for daily restaurant operation.

---

## Static analysis

Introduce PHPStan.

Start from a realistic baseline.

Increase strictness gradually.

CI should reject new analysis regressions after the baseline is established.

---

## Code style

Introduce deterministic style validation.

Possible tools:

* PHP-CS-Fixer;
* PHP_CodeSniffer.

CI should validate formatting/style without silently modifying production code.

---

## CI quality pipeline

Target pipeline:

```text
composer validate
        ↓
dependency/security audit
        ↓
static analysis
        ↓
code style
        ↓
unit tests
        ↓
integration tests
```

Failures should clearly indicate their cause.

---

## Integration tests

Add MySQL-backed integration tests for critical workflows.

Priority:

* authentication;
* authorization;
* order creation;
* order numbering;
* pricing;
* order completion;
* order reopening;
* menu mutations;
* reports;
* job creation.

---

## End-to-end smoke tests

Use targeted E2E tests for the most important user journey.

Example:

```text
Cashier
   ↓
Create order
   ↓
Kitchen receives order
   ↓
Order progresses
   ↓
Order completes
   ↓
Report reflects operation
```

Do not attempt to test the entire frontend through browser automation.

---

## Job reliability

The database-backed queue may remain part of GastroFlow Community.

Improve it before replacing it.

Jobs should support:

* atomic claiming;
* attempt count;
* retries;
* backoff;
* failed state;
* last error information;
* timestamps;
* safe worker execution;
* idempotency where critical.

---

## Printing reliability

Thermal printing is a core product capability.

Support:

* test printing;
* clear printer configuration;
* connection failure handling;
* retries;
* failed print-job visibility;
* reprinting;
* duplicate-print prevention where practical;
* useful operator error messages.

A printer failure must never remove or invalidate the restaurant order.

---

## Realtime reliability

The kitchen realtime mechanism should be reliable for Community's single-instance model.

Do not introduce additional infrastructure unless required.

Move toward a clear abstraction such as:

```text
OrderService
     ↓
EventPublisher
     ↓
Community event implementation
     ↓
SSE
```

The implementation should support:

* reliable event IDs;
* reconnect behavior where practical;
* event cleanup/retention;
* appropriate kitchen latency;
* safe concurrent operation.

The business domain should not depend directly on the event transport mechanism.

---

## Structured logging

Introduce request/correlation IDs.

Important logs should carry relevant context such as:

```text
request_id
user_id
order_id
job_id
event
```

This should make it possible to trace:

```text
HTTP Request
     ↓
Order
     ↓
Job
     ↓
Printing
```

Never log passwords, tokens or secrets.

---

## Audit history

Add business-level audit logging for sensitive administrative operations.

Examples:

```text
User changed menu price
User changed restaurant settings
User changed printer configuration
User reopened an order
User created another user
```

Technical logs and audit history should remain conceptually separate.

---

## Health checks

Provide at least:

```http
GET /health/live
GET /health/ready
```

Readiness should reflect important dependencies such as database availability.

---

## Migration reliability

Keep the custom migration system if it remains reliable.

Ensure:

* deterministic migration order;
* migration tracking;
* clear failure behavior;
* fresh-install testing;
* upgrade testing;
* CI migration validation.

Do not replace it solely for framework consistency.

---

## Backup & restore

Document and test database recovery.

Provide clear procedures for:

```text
backup
restore
```

Before v2.0, perform an actual restore test.

A backup that has never been restored is not considered verified.

---

# v1.8 Exit Gate

v1.8 is complete when:

> Failures involving printing, jobs, realtime, database access or application code can be detected, understood and recovered from without corrupting restaurant operations.

---

# v1.9.0 — Community Productization

## Objective

Turn GastroFlow from a project primarily operated by its developer into a product another person can install and run.

---

## Local frontend dependencies

Remove critical runtime dependency on third-party CDNs.

Bundle operational dependencies locally.

Candidates:

* Alpine.js;
* Bootstrap;
* Chart.js;
* icons;
* frontend assets.

Introduce an appropriate frontend build process such as Vite.

After installation, core restaurant operation should not require internet access.

---

## Shared frontend infrastructure

Centralize repeated frontend behavior.

Examples:

```text
API client
authentication
401 handling
network errors
loading states
toast/messages
theme
```

Avoid each screen implementing independent network behavior.

---

## Connection awareness

Cashier and kitchen interfaces should clearly communicate backend/network status.

Examples:

```text
Connected
Reconnecting...
Connection lost
```

A stale kitchen interface should never silently look healthy.

---

## LAN operation without internet

Verify GastroFlow's core workflow with WAN access disabled.

Test:

* cashier;
* kitchen;
* administration;
* database;
* printing;
* local assets.

Goal:

> A restaurant with a functioning local network can continue normal GastroFlow operation during an internet outage.

---

## Clean initial database

The default installation must not contain restaurant-specific production information.

Separate:

```text
schema
```

from:

```text
optional demo data
```

If useful, provide:

```bash
php bin/seed-demo
```

A fresh production installation should start clean.

---

## Installation experience

A new user should be able to follow documentation similar to:

```text
Clone repository
      ↓
Copy environment template
      ↓
Configure application
      ↓
Start Docker
      ↓
Run migrations
      ↓
Create administrator
      ↓
Open GastroFlow
```

No source-code editing should be required for normal installation.

---

## Upgrade process

Document the supported update procedure.

Example:

```text
Backup
   ↓
Update source/image
   ↓
Install dependencies
   ↓
Run migrations
   ↓
Restart services
   ↓
Validate installation
```

Existing restaurant data must remain intact.

---

## Printer documentation

Create practical documentation for thermal printing.

Include:

* supported ESC/POS expectations;
* network configuration;
* printer address configuration;
* test print;
* common failures;
* troubleshooting.

---

## Documentation structure

Before Community v2, documentation should cover at least:

```text
README
Installation
Configuration
Architecture
Security
Printing
Backup & Restore
Upgrading
API
Troubleshooting
Contributing
```

Documentation should reflect actual behavior.

---

## Open-source readiness

Before the official Community release:

* choose an explicit license;
* create/update `CONTRIBUTING.md`;
* create `SECURITY.md`;
* document vulnerability reporting;
* define contribution expectations;
* define support expectations.

The license must be an intentional project decision.

---

## Community support policy

GastroFlow Community should define what maintainers commit to supporting.

Community 2.x may continue receiving:

* security fixes;
* critical bug fixes;
* dependency updates;
* compatibility improvements;
* documentation;
* selected community contributions.

No promise should be made that every future GastroFlow capability will be added to Community.

---

## Release automation

For official release tags:

```text
Git tag
   ↓
CI
   ↓
Quality checks
   ↓
Tests
   ↓
Build
   ↓
Release artifacts
   ↓
GitHub Release
```

If Docker images are published, use versioned tags.

Example:

```text
2.0.0
2.0
2
```

Avoid relying only on:

```text
latest
```

---

# v1.9 Exit Gate

v1.9 is complete when:

> A developer unfamiliar with GastroFlow can install the project from the documentation, create an administrator, configure the restaurant, create an order, operate the kitchen and print successfully without modifying application source code.

---

# v2.0.0 Release Candidate

Once v1.9 is complete, development enters release-candidate mode.

During the RC cycle, only the following should normally be accepted:

* bug fixes;
* security fixes;
* documentation corrections;
* release blockers;
* dependency fixes.

Avoid introducing significant new product features during the release candidate.

Possible progression:

```text
v2.0.0-rc.1
      ↓
validation
      ↓
v2.0.0-rc.2
      ↓
final fixes
      ↓
v2.0.0
```

---

# v2.0 Verification Matrix

## Fresh installation

* [ ] Clone/install from a clean environment
* [ ] Follow documented configuration only
* [ ] Containers/services start successfully
* [ ] Database migrations succeed
* [ ] Administrator can be created
* [ ] No private restaurant data exists in the default database
* [ ] Application becomes usable without source-code modifications

---

## Authentication

* [ ] Valid login works
* [ ] Invalid login fails safely
* [ ] Expired authentication fails safely
* [ ] Invalid tokens are rejected
* [ ] Roles are enforced
* [ ] Unauthorized operations are blocked
* [ ] Password changes work

---

## Ordering

* [ ] Order creation succeeds
* [ ] `order_number` is generated correctly
* [ ] Concurrent order creation does not produce duplicate numbers
* [ ] Invalid items are rejected
* [ ] Invalid quantities are rejected
* [ ] Pricing is exact
* [ ] Historical item name remains correct
* [ ] Historical price remains correct

---

## Kitchen

* [ ] New orders appear correctly
* [ ] Order status transitions work
* [ ] Invalid transitions fail
* [ ] Reopening behaves as documented
* [ ] Realtime reconnection works
* [ ] Network failure is visible to the operator

---

## Printing

* [ ] Test print works
* [ ] Order printing works
* [ ] Printer failure does not lose the order
* [ ] Failed prints are visible
* [ ] Retry works
* [ ] Reprint works

---

## Reports

* [ ] Sales totals match actual orders
* [ ] Historical prices are respected
* [ ] Date filtering works
* [ ] Timezone boundaries behave correctly
* [ ] Pagination works where applicable

---

## Jobs

* [ ] Jobs are claimed safely
* [ ] Failed jobs can retry
* [ ] Attempt count is tracked
* [ ] Failed jobs expose meaningful diagnostics
* [ ] Multiple worker execution does not duplicate critical work

---

## Recovery

* [ ] Database backup succeeds
* [ ] Database restore succeeds
* [ ] GastroFlow works after restore
* [ ] Recovery procedure matches documentation

---

## Network

* [ ] Cashier works without WAN access
* [ ] Kitchen works without WAN access
* [ ] Printing works without WAN access
* [ ] Local frontend dependencies work without WAN access

---

## Upgrade

* [ ] Supported v1.x installation can be upgraded
* [ ] Existing orders survive
* [ ] Existing users survive
* [ ] Existing settings survive
* [ ] Migrations preserve historical information

---

## Quality

* [ ] `composer validate` passes
* [ ] Dependency audit passes or exceptions are documented
* [ ] Static analysis passes
* [ ] Code style validation passes
* [ ] Unit tests pass
* [ ] Integration tests pass
* [ ] Core E2E smoke flow passes

---

## Documentation

* [ ] README reflects actual functionality
* [ ] CLAUDE.md reflects actual tooling/workflow
* [ ] Architecture documentation reflects code
* [ ] Technical decisions reflect current implementation
* [ ] Installation instructions were tested
* [ ] Backup instructions were tested
* [ ] Printing instructions were tested
* [ ] CHANGELOG is complete

---

# GastroFlow Community v2.0.0

When all release gates are satisfied:

```bash
git tag -a v2.0.0 -m "GastroFlow Community 2.0"
```

v2.0.0 represents:

> **The first official stable release of GastroFlow Community.**

The Community v2 promise is:

> A secure, tested, documented and self-hostable restaurant management system intentionally designed to operate one restaurant location reliably.

---

# Community 2.x Maintenance

After v2.0.0, GastroFlow Community enters a stable maintenance lifecycle.

Examples of appropriate Community 2.x releases:

```text
v2.0.1 — critical bug fix
v2.0.2 — security/dependency fix
v2.1.0 — Fiscal / NFC-e
v2.1.1 — regression fix
```

Community may continue evolving, but stability takes priority over rapid feature expansion.

The first concrete Community 2.x feature milestone is described below.

---

# v2.1.0 — Fiscal / NFC-e

## Objective

Give GastroFlow Community the ability to issue Brazilian NFC-e (Modelo 65) directly against SEFAZ, using an A1 digital certificate, without depending on a mandatory paid fiscal API (Focus NFe, Nuvem Fiscal, etc.).

This is a self-hosted, single-location feature in the same spirit as the rest of Community: no forced external service, no recurring third-party billing to operate the core product.

Fiscal issuance must remain **optional and disabled by default**. A restaurant that does not need fiscal documents (or is not yet in Brazil/not yet configured) must be unaffected by this milestone.

---

## Why this starts after v2.0.0

Fiscal issuance is not a self-contained feature — it depends on architectural foundations planned earlier in this roadmap:

* **RBAC and authenticated admin endpoints** (`v1.6.0` — Authorization / RBAC) — certificate upload and fiscal settings must only be reachable by an authorized admin.
* **Production-safe error handling and secret discipline** (`v1.6.0` — Production error handling, Docker secret handling) — the same discipline that keeps `.env`/DB/JWT secrets out of responses and images must extend to the A1 password and the CSC.
* **Order lifecycle, money representation and historical snapshots** (`v1.7.0` — Order lifecycle, Pricing domain, Money representation, Historical order snapshots) — a fiscal document reports amounts and items that must match the order exactly as it was at the time of issuance, using exact arithmetic.
* **Input validation and API error standardization** (`v1.7.0`) — fiscal settings, certificate upload and menu fiscal metadata are new external-input surfaces that must follow the same validation discipline already established for orders.
* **Job reliability** (`v1.8.0` — atomic claiming, attempts, retries, backoff, idempotency) — fiscal issuance reuses and depends on exactly this hardened queue, not the current one, since a duplicate NFC-e is a legal/financial incident, not a cosmetic bug.
* **Structured logging without secrets** (`v1.8.0`) — the same "never log passwords, tokens or secrets" rule extends to the certificate and the CSC.
* **Migration reliability** (`v1.8.0`) — the new `fiscal_documents` table and fiscal columns on menu/category go through the same hardened, tracked migration path.
* **Printing reliability** (`v1.8.0` — retries, reprinting, failed-job visibility) — DANFE printing is a new document type layered on infrastructure that must already be dependable.

None of the above are blocking in the sense of "impossible before v1.8 lands" — the domain/provider abstraction (issue below) can be designed in parallel — but **actually issuing a real NFC-e against SEFAZ, with money and legal consequences, should not happen on top of a job queue or logging setup that is still being hardened.** Placing this as its own `v2.x` milestone, after the `v2.0.0` stability baseline, keeps the `v2.0.0` release candidate free of a large new feature (per the RC rule above: "avoid introducing significant new product features during the release candidate") and gives fiscal work a queue/logging/RBAC foundation that is already proven in production.

## Relationship to the local-first principle

Every other Community workflow (cashier, kitchen, printing, reports) must keep working over the LAN without WAN access. NFC-e issuance is the one workflow that **cannot** be local-first — SEFAZ is a remote government service. This is a deliberate, named exception, not an oversight:

* Fiscal issuance must be asynchronous (queued), so a SEFAZ outage or a lost internet connection never blocks the cashier from closing an order.
* When fiscal issuance is disabled, or SEFAZ is unreachable, the existing local workflow (order → kitchen → non-fiscal receipt) must be completely unaffected.
* "No internet → no fiscal document yet, order still open/closed normally" is an acceptable and expected state; "no internet → cashier cannot close orders" is not.

---

## Fiscal domain and provider abstraction

**Labels:** `type: fiscal`, `area: architecture`
**Description:** Introduce a `FiscalProviderInterface` (status/issue/query/cancel) so checkout and fiscal domain logic never call `nfephp-org/sped-nfe` directly. First and only implementation for this milestone: `SefazNFePhpProvider`, wrapping NFePHP for direct SEFAZ communication. This mirrors the project's existing Controller → Service → Repository layering (`CLAUDE.md`) rather than inventing a new pattern, and keeps the door open for a future alternate provider without rewriting checkout.
**Files likely involved:** new `src/Fiscal/FiscalProviderInterface.php`, `src/Fiscal/SefazNFePhpProvider.php`, `src/Fiscal/FiscalStatus.php`/`FiscalResult.php` (or equivalent value objects), `src/Services/FiscalService.php`.
**Dependencies:** none beyond a `/spec-plan` for the interface shape; everything else in this milestone builds on it.
**Acceptance criteria:**
- [ ] `FiscalProviderInterface` has no reference to NFePHP types in its own signature (only in the concrete provider).
- [ ] No controller or service outside `Fiscal*`/`FiscalService` imports an NFePHP class directly.
- [ ] A fake/mock provider can be substituted in tests without touching checkout code.

---

## Checkout / payment foundation

**Labels:** `type: fiscal`, `area: orders`, `area: domain`
**Description:** NFC-e issuance requires payment information (amount received, payment method) that GastroFlow does not currently model — orders today move straight from creation to kitchen without a payment/checkout step. Introduce an explicit payment boundary (`payment_status`, `payment_method`, `paid_at` or equivalent, exact scope to be confirmed in `/spec-plan`) before fiscal issuance can be requested. Fiscal issuance is requested at checkout time, not at order-creation time.
**Files likely involved:** `src/Models/Order.php`, `src/Services/OrderService.php`, `src/Controllers/OrderController.php`, a new migration under `common/migrations/`.
**Dependencies:** Order lifecycle work (`v1.7.0`), which this extends rather than replaces.
**Acceptance criteria:**
- [ ] An order can be created and progress through the kitchen without any fiscal/payment data present.
- [ ] Fiscal issuance can only be requested once an order has a defined checkout/payment state (exact rule confirmed in `/spec-plan`).
- [ ] The existing non-fiscal flow (no payment concept) keeps working unchanged for restaurants with fiscal issuance disabled.

---

## Fiscal settings + secure A1 certificate handling

**Labels:** `type: fiscal`, `area: admin`, `area: security`
**Description:** Add a `Fiscal / NFC-e` section to the existing Admin settings area (`public/admin/settings.php`), alongside `Restaurant` and `Impressão Térmica`, not a separate settings screen. Fields to actually model (enabled/disabled, environment, CNPJ, IE, CRT, series/number strategy, CSC, A1 upload, certificate status/expiration, SEFAZ connectivity test) are confirmed during `/spec-plan`, not assumed from this roadmap. The A1 `.pfx` is uploaded through this screen; `.pfx` never lives under `public/`, is never returned by any API response, and the certificate password/CSC are treated as write-only secrets. Homologation is the default/safe environment; switching to production requires an explicit action.
**Files likely involved:** `public/admin/settings.php` (+ its JS), a new `FiscalSettingsController`/`FiscalSettingsService` (or extension of an existing settings path — decided in `/spec-plan`), storage location for the certificate file outside `public/`, `.gitignore`.
**Dependencies:** RBAC (`v1.6.0`), Docker/secret handling discipline (`v1.6.0`).
**Acceptance criteria:**
- [ ] `.pfx`/`.p12` files, certificate passwords and CSC values are never committed to Git (verified via `.gitignore` and a manual check before merge).
- [ ] The certificate file is stored outside `public/` and is not reachable via a direct URL.
- [ ] The certificate password is never returned by any admin API response after upload.
- [ ] Only authenticated Admin requests can view, upload or replace fiscal credentials.
- [ ] Certificate upload validates file type/format/content before accepting it.
- [ ] Production environment is not the default; enabling it is an explicit, separate action from enabling the fiscal feature.
- [ ] Automated tests never use production fiscal credentials.

---

## Menu and category fiscal metadata

**Labels:** `type: fiscal`, `area: menu`
**Description:** Investigate the actual NFC-e field requirements (NCM, CEST when applicable, CFOP, unit, origin, CST/CSOSN, and any IBS/CBS-related fields the applicable layout requires) in a dedicated `/spec-plan` before touching `Category`/`MenuItem`. Do not add every possible fiscal field to every item. Model as **category-level defaults with optional per-item overrides** unless the investigation shows a category-only or item-only model is actually sufficient — this avoids repeating identical tax data on every dish in a category (`categories`/`menu_items` today: `src/Models/Category.php`, `src/Models/MenuItem.php`). Group fiscal fields under a "Fiscal information" section in the menu/category editing UI, collapsed or hidden while fiscal issuance is disabled.
**Files likely involved:** `src/Models/Category.php`, `src/Models/MenuItem.php`, `public/admin/index.php` (menu editing UI), a new migration for fiscal columns, `src/Services/MenuService.php`/`MenuRepository`.
**Dependencies:** Fiscal domain/provider abstraction (needs to know what a `FiscalResult`/issuance actually consumes from an item).
**Acceptance criteria:**
- [ ] A `/spec-plan` documents exactly which fiscal fields are required, citing the NFC-e/NFePHP layout, before any schema change.
- [ ] Fiscal fields exist on `categories`, with override support on `menu_items` only where the investigation shows it's needed.
- [ ] Menu items without fiscal data configured do not block non-fiscal ordering.
- [ ] The fiscal fields are visually grouped/collapsible in the editing UI, not scattered across the existing form.

---

## Separate "print receipt" from "issue NFC-e"

**Labels:** `type: fiscal`, `area: orders`, `area: printing`
**Description:** Today `OrderService::createOrder()` decides printing from a single `print_ticket` boolean (`src/Services/OrderService.php`). Introduce an independent `issue_fiscal_document` concept (naming confirmed in `/spec-plan`) so print and fiscal are two separate checkboxes/flags with all four combinations meaningful: print+no-fiscal (today's behavior), fiscal+no-print (NFC-e without the current receipt), print+fiscal (both), neither (order only). Fiscal issuance must never be triggered implicitly by `print_ticket=true`.
**Files likely involved:** `src/Services/OrderService.php`, `src/Controllers/OrderController.php`, `src/Validators/OrderValidator.php`, cashier UI (`public/cashier/`).
**Dependencies:** Checkout/payment foundation (fiscal can only be requested from checkout, not order creation).
**Acceptance criteria:**
- [ ] `print_ticket=true, issue_fiscal_document=false` prints only the current non-fiscal receipt (unchanged behavior).
- [ ] `print_ticket=false, issue_fiscal_document=true` issues NFC-e without printing the non-fiscal receipt.
- [ ] `print_ticket=true, issue_fiscal_document=true` does both.
- [ ] `print_ticket=false, issue_fiscal_document=false` only persists the order.
- [ ] No code path sets `issue_fiscal_document` from `print_ticket` or vice versa.

---

## NFePHP dependency, certificate loading and SEFAZ status check

**Labels:** `type: fiscal`, `area: architecture`
**Description:** First real integration point with `nfephp-org/sped-nfe`, entirely inside `SefazNFePhpProvider`: load and validate the uploaded A1, read certificate metadata (expiration, thumbprint), and implement `FiscalProviderInterface::status()` against SEFAZ's status service for the configured environment. This is the first step that actually requires the dependency to be installed — do not install it before this issue is approved and started.
**Files likely involved:** `src/Fiscal/SefazNFePhpProvider.php`, `composer.json`/`composer.lock`.
**Dependencies:** Fiscal domain and provider abstraction; Fiscal settings (needs a certificate to load).
**Acceptance criteria:**
- [ ] An invalid/expired/corrupt `.pfx` produces a clear error surfaced to Admin, not a crash.
- [ ] Certificate expiration is readable and shown in the fiscal settings screen.
- [ ] `status()` correctly reports SEFAZ homologation availability without requiring production credentials.

---

## NFC-e XML generation, signing and schema validation

**Labels:** `type: fiscal`
**Description:** Implement `FiscalProviderInterface::issue()` for `SefazNFePhpProvider`: build the NFC-e XML from an order + fiscal settings + menu fiscal metadata, sign it with the A1 certificate, and validate it against the official schema before sending to SEFAZ. Scope (exact fields, contingency handling) is defined in `/spec-plan`, not here.
**Files likely involved:** `src/Fiscal/SefazNFePhpProvider.php`, `src/Services/FiscalService.php`.
**Dependencies:** NFePHP dependency/certificate loading; Menu and category fiscal metadata; Checkout/payment foundation (needs payment data for the XML).
**Acceptance criteria:**
- [ ] Generated XML validates against the current NFC-e schema before any SEFAZ submission is attempted.
- [ ] Missing required fiscal data (e.g. an item with no fiscal classification) fails fast with an actionable error instead of producing an invalid document.

---

## Fiscal document persistence and state machine

**Labels:** `type: fiscal`, `area: db`
**Description:** A dedicated `fiscal_documents` table (order_id, model, environment, series, number, access_key, protocol, status, error_code/message, XML reference, issued_at/authorized_at/cancelled_at, timestamps — exact schema confirmed in `/spec-plan`), not fiscal columns bolted onto `orders`. An order's fiscal lifecycle must be observable independently of its kitchen/order lifecycle. Conceptual statuses (`pending`, `processing`, `authorized`, `rejected`, `failed`, `cancelled`) are a starting point for the spec, not a locked-in final set.
**Files likely involved:** new migration under `common/migrations/`, `src/Models/FiscalDocument.php`, `src/Services/FiscalService.php`.
**Dependencies:** Migration reliability (`v1.8.0`); Checkout/payment foundation.
**Acceptance criteria:**
- [ ] `fiscal_documents` has a foreign key to `orders` and can be queried by order without touching `orders` columns.
- [ ] Every documented status is reachable through an actual code path (no theoretical-only statuses).
- [ ] An order can be inspected for its current fiscal status independently of its `orders.status` value.

---

## Async fiscal issuance job, retries and idempotency

**Labels:** `type: fiscal`, `area: jobs`
**Description:** An `IssueFiscalDocumentJob`, dispatched through the same `jobs` table + `JobService` + `bin/worker` infrastructure used by `PrintOrderJob` today (`src/Jobs/PrintOrderJob.php`, `src/Services/JobService.php`), not a synchronous SEFAZ call inside the checkout HTTP request. **A retry must never issue a second valid NFC-e for the same order/transaction** — this is a critical acceptance criterion, not a nice-to-have. The job must distinguish a technical failure (network/timeout/SEFAZ unavailable — safe to retry) from a SEFAZ rejection (may require correction, not a blind retry) from an already-authorized document (must never be re-issued).
**Files likely involved:** `src/Jobs/IssueFiscalDocumentJob.php`, `src/Services/JobService.php` (only if job reliability improvements from `v1.8.0` need extending for fiscal-specific idempotency), `src/Services/FiscalService.php`.
**Dependencies:** Job reliability (`v1.8.0`); Fiscal document persistence.
**Acceptance criteria:**
- [ ] Two concurrent workers processing the same fiscal job never produce two authorized NFC-e for the same order.
- [ ] A retried job checks the `fiscal_documents` state before re-issuing; an already-`authorized` document short-circuits instead of re-submitting.
- [ ] Job/order/fiscal-document correlation is present in every log line for a fiscal job.
- [ ] Logs for this job never contain certificate contents, certificate password, CSC or private key material.
- [ ] A SEFAZ timeout/connection failure leaves the fiscal document in a retryable state, not silently `failed` or silently `authorized`.

---

## Admin Order History and fiscal monitoring

**Labels:** `type: fiscal`, `area: admin`, `area: reports`
**Description:** A new or expanded Admin "Order History" area listing historical orders with fiscal status clearly visible (`Not requested`, `Pending`, `Authorized`, `Rejected`, `Failed`, `Cancelled`), distinct at a glance from a plain order-status column. For authorized documents, expose NFC-e number, access key, protocol, authorization timestamp and environment — never secrets, never the raw certificate/XML signing material.
**Files likely involved:** new `public/admin/order-history.php` (or extension of an existing admin view), `src/Controllers/OrderController.php` or a new controller, `src/Services/FiscalService.php`.
**Dependencies:** Fiscal document persistence; pagination work (`v1.7.0`) if order volume warrants it.
**Acceptance criteria:**
- [ ] The UI distinguishes all six fiscal states listed above without ambiguity.
- [ ] No secret (CSC, certificate password, private key) is ever rendered in this view.
- [ ] Only authenticated Admin can access this area (same guard as the rest of `/api/admin/*` and the admin panel).

---

## Manual fiscal retry from Order History

**Labels:** `type: fiscal`, `area: admin`
**Description:** From Order History, Admin can retry a failed fiscal issuance. Only recoverable technical failures are retryable from the UI; an already-authorized document must never be retried/re-issued; a rejected document may need correction rather than a blind retry (exact rule per rejection reason, confirmed in `/spec-plan`). Retrying enqueues a new attempt through the same job infrastructure as initial issuance, and the action is auditable.
**Files likely involved:** the Order History admin view/controller, `src/Services/FiscalService.php`.
**Dependencies:** Admin Order History; Async fiscal issuance job (retry eligibility rules live in the same duplicate-prevention logic).
**Acceptance criteria:**
- [ ] The retry action is not shown (or is disabled) for `authorized`/`cancelled` documents.
- [ ] Retrying enqueues a new job rather than re-using/mutating the failed job record silently.
- [ ] The retry action and its outcome are recorded for audit (who retried, when, previous error).
- [ ] Duplicate-issuance protection from the async job issue above still holds when the retry path is used.

---

## DANFE NFC-e thermal printing

**Labels:** `type: fiscal`, `area: printing`
**Description:** Printing a valid DANFE NFC-e (access key, QR code, authorization info, totals, payment data, consumer identification when applicable) is a distinct responsibility from the existing non-fiscal receipt built by `PrintService` (`src/Services/PrintService.php`). Evaluate a separate `FiscalPrintService` rather than growing `PrintService` with fiscal-specific branches — keep "order receipt printing" and "fiscal DANFE printing" conceptually separate, reusing the same `mike42/escpos-php` + `NetworkPrintConnector` plumbing.
**Files likely involved:** new `src/Services/FiscalPrintService.php`, `src/Jobs/IssueFiscalDocumentJob.php` (trigger point after authorization), printer settings already in `Setting`/`PrintService`.
**Dependencies:** Fiscal document persistence (needs an authorized document to print); Printing reliability (`v1.8.0`).
**Acceptance criteria:**
- [ ] DANFE printing happens only after SEFAZ authorization, never before.
- [ ] The existing non-fiscal receipt format/behavior is unchanged by this addition.
- [ ] A DANFE print failure does not invalidate the already-authorized NFC-e (the fiscal document stays `authorized`; only the print can be retried).

---

## Homologation end-to-end validation

**Labels:** `type: fiscal`
**Description:** Manual, staged validation against real SEFAZ homologation before anything touches production: load/validate A1 → certificate metadata → SEFAZ status → CSC homologation config → basic XML → signing → schema validation → send to homologation → process authorization/rejection → persist XML/protocol/access key → DANFE → integrate with real checkout → only then prepare production enablement. This is a manual/integration validation step, not something CI runs with a real company certificate.
**Files likely involved:** none new — this is a validation pass across everything above, documented in the corresponding `/spec-plan`/`/spec-review` cycles.
**Dependencies:** every issue above.
**Acceptance criteria:**
- [ ] Each of the 13 steps in the suggested sequence below has been exercised against SEFAZ homologation with recorded evidence (per `CLAUDE.md`'s "never claim a test passed without running it").
- [ ] No step in this validation used production credentials.

```text
1. Load and validate A1
2. Read certificate metadata
3. Test SEFAZ service status
4. Configure CSC homologation
5. Generate basic NFC-e XML
6. Sign XML
7. Validate schema
8. Send to SEFAZ homologation
9. Process authorization/rejection
10. Persist XML/protocol/access key
11. Generate/print DANFE
12. Integrate with real GastroFlow checkout
13. Only then prepare production enablement
```

---

## Production readiness and security review

**Labels:** `type: fiscal`, `area: security`
**Description:** Before enabling production issuance for any real restaurant, a dedicated security pass over everything fiscal-related: confirm `.pfx`/`.p12`/passwords/CSC/private keys/production credentials never entered source control (`.gitignore` review), confirm fiscal logs never contain certificate/CSC/key material, confirm every fiscal admin endpoint requires authentication, and confirm certificate download is not exposed as a normal Admin feature.
**Files likely involved:** `.gitignore`, a manual audit of every file touched by this milestone (candidate for the `security-review` skill).
**Dependencies:** every issue above.
**Acceptance criteria:**
- [ ] `.gitignore` explicitly excludes `*.pfx`, `*.p12` and any fiscal credential storage path introduced by this milestone.
- [ ] A `git log`/history check confirms no certificate or credential ever landed in a commit.
- [ ] Fiscal admin endpoints are exercised without a valid JWT and confirmed to be rejected.
- [ ] Certificate raw-file download is either absent from Admin or has documented, explicit justification.

---

## Tests

**Labels:** `type: fiscal`, `area: tests`
**Description:** Test coverage before any production rollout, added incrementally as each issue above lands rather than as one final pass.

* **Unit:** fiscal configuration validation, menu fiscal field inheritance/override, fiscal state machine transitions, retry eligibility, duplicate-issuance prevention, provider result handling, payment mapping.
* **Integration:** using a mocked/fake `FiscalProviderInterface` implementation — authorized, rejected, timeout, connection failure, retry, already-authorized.
* **Homologation:** manual, per the dedicated issue above — GitHub Actions must never need access to a real `.pfx`.

**Files likely involved:** `tests/` (mirroring the existing PHPUnit setup from `v1.8.0`'s CI quality pipeline), a fake `FiscalProviderInterface` implementation for tests.
**Dependencies:** Fiscal domain/provider abstraction (the interface is what makes a fake provider possible); CI quality pipeline (`v1.8.0`).
**Acceptance criteria:**
- [ ] `vendor/bin/phpunit` covers the fiscal state machine and idempotency/retry-eligibility logic without hitting real SEFAZ.
- [ ] CI never references a real certificate, CSC or production credential.
- [ ] The fake provider can simulate each of: authorized, rejected, timeout, connection failure.

---

## Suggested execution order

```text
1.  Fiscal domain and provider abstraction        → feature/fiscal-domain-abstraction
2.  Checkout / payment foundation                 → feature/checkout-payment-foundation
3.  Fiscal settings + secure A1 upload             → feature/fiscal-settings-a1-upload
4.  Menu/category fiscal metadata                  → feature/menu-fiscal-metadata
5.  Separate print vs fiscal issuance               → feature/fiscal-print-separation
6.  NFePHP dependency + certificate loading         → feature/nfephp-certificate-loading
7.  NFC-e XML generation/signing                    → feature/nfce-xml-signing
8.  Fiscal document persistence/state machine        → feature/fiscal-document-persistence
9.  Async fiscal issuance job + retry/idempotency     → feature/fiscal-issuance-job
10. Admin Order History                              → feature/admin-order-history
11. Manual retry/recovery UI                          → feature/fiscal-manual-retry
12. DANFE NFC-e thermal printing                       → feature/danfe-printing
13. Homologation end-to-end validation                  → (validation pass, no new branch)
14. Production readiness/security review                 → feature/fiscal-production-readiness
```

Each step is its own GitHub Issue and its own `/spec-plan` → `/spec-implement` → `/spec-review` cycle, per the project's standard workflow. Issue numbers above are placeholders (`TBD`) — no GitHub Issues exist yet for this milestone.

## Suggested labels

The repository does not currently define a formal GitHub label taxonomy (`.github/ISSUE_TEMPLATE/default.md` leaves `labels:` empty). If labels are introduced alongside this milestone, `type: fiscal` is the minimum useful one to group all issues above; `area: admin`, `area: orders`, `area: printing`, `area: jobs`, `area: security` (already implied by existing scopes in `docs/COMMIT_CONVENTION.md`) can be reused rather than inventing a parallel taxonomy.

---

# v2.1.0 Exit Gate

v2.1.0 is complete when:

> A restaurant can enable NFC-e issuance, configure fiscal data once (settings + menu), and have GastroFlow issue, persist, monitor, retry and print DANFE for NFC-e documents directly against SEFAZ homologation and production — without any restaurant using GastroFlow being forced to pay for or depend on a third-party fiscal API, and without a fiscal failure or retry ever compromising an order, duplicating a legal document, or leaking a secret.

---

# Future Editions

GastroFlow Community represents the open-source, self-hosted edition focused on single-location restaurants.

Other GastroFlow editions may evolve independently with different product goals.

Their roadmap, architecture and implementation are outside the scope of this repository.

No feature parity between editions is implied.

---

# Definition of Done — Community v2

GastroFlow Community v2 is ready when it is:

## Functional

A restaurant can perform its core ordering workflow using GastroFlow.

## Secure

Normal operation does not depend on development credentials or unsafe debug behavior.

## Correct

Orders, pricing and permissions follow explicit business rules.

## Tested

Critical operational and financial behavior has automated coverage.

## Reliable

Printing, jobs and realtime failures do not corrupt restaurant operations.

## Recoverable

Backup and restore procedures have been executed successfully.

## Self-hostable

Installation can be completed using documentation rather than source-code knowledge.

## Local-first

Core restaurant operations remain usable over the LAN without WAN connectivity.

## Maintainable

The codebase, tests and documentation describe the same application.

## Community-ready

Licensing, contribution, security and maintenance expectations are explicit.

---

# Final Principle

GastroFlow Community does not need to solve every possible restaurant problem.

It needs to become exceptionally good at its defined purpose:

> **Running one restaurant reliably.**
