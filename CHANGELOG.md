# Changelog

All notable changes to GastroFlow are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-07-09

### Added
- Customer name field on cashier screen, displayed in kitchen and on receipt
- Print toggle switch (on/off) in cashier header with hover tooltip
- OpenAPI 3.0 spec (`openapi.yaml`) + Swagger UI at `/api/docs`
- Automatic redirect `/` → `/cashier/`
- Async printing job queue (database-backed)
- Real-time kitchen updates via Server-Sent Events (SSE)
- CHANGELOG.md following Keep a Changelog

### Changed
- Kitchen now shows customer name instead of "Pedido #N" when available
- Kitchen transitions from 5s polling to real-time SSE
- Thermal receipt includes customer name when present
- `OrderRepository` validates `customer_name` is a string before saving

### Infrastructure
- `common/migrations/006_settings.sql` — added `customer_name` column
- `common/migrations/007_jobs.sql` — job queue table for async processing
- `bin/worker` — CLI worker to process queued print jobs
- `public/api/events/stream.php` — SSE endpoint for real-time events

## [0.6.0] — Branch 006 baseline

### Added
- Admin settings page (restaurant name, printer IP/port, logo upload)
- Thermal printer support via Mike42\Escpos (TM-T20)
- Print test page from admin settings
- Print toggle on cashier (per-order)

### Fixed
- Cashier auto-fills next order number from API

## [0.5.0] — Branch 005 baseline

### Added
- Dining option per item (local / viagem_simples / viagem_vip)
- Packaging cost calculation in cashier and receipt
- Kitchen food-category summary sidebar
- Order uncomplete/reopen button in kitchen

## [0.4.0] — Branch 004 baseline

### Added
- Admin item delete with foreign-key conflict handling
- Migration runner system (`php bin/migrate`)
- Idempotent SQL migrations
- Composer dependency management documented in README

## [0.3.0] — Branch 003 baseline

### Added
- Ingredient CRUD (categories, ingredients, dish components)
- Dish recipe editor with Tom Select component picker in admin
- Kitchen food category summary API

## [0.2.0] — Branch 002 baseline

### Added
- JWT authentication (`POST /api/login`)
- Admin menu endpoints with JWT guard
- Menu item add/edit/toggle availability
- JSON body parser and CORS middleware

## [0.1.0] — Branch 001 baseline

### Added
- Slim 4 application skeleton
- Eloquent ORM + MySQL
- Cashier interface (Alpine.js + Bootstrap)
- Kitchen interface with order polling
- Public API endpoints (`/api/menu`, `/api/orders`, etc.)
- Docker Compose setup (web + db)
- `.env` configuration
