# Spec 065 — Printer documentation (and the logo that never prints)

## Metadata

- Status: Implemented
- Created: 2026-10-27
- Updated: 2026-10-27
- Owner:
- Related issue: `docs/ROADMAP.md` › `v1.9.0` › "Printer documentation". Trello Backlog card "Logo não sai no cupom impresso" (reported 2026-10-26, spec 060). Also carries the `docs/ROADMAP.md` update for the v1.9 closing order agreed on 2026-10-27, and spec 064's pending CI evidence.
- Related branch: `065`

## Context

The roadmap item asks for practical thermal-printing documentation: supported ESC/POS expectations, network configuration, printer address, test print, common failures, troubleshooting. Printing knowledge today is spread over specs 008, 033, 038, 039, 042 and code comments; operators met three printing problems during this milestone with no document to turn to:

- the pilot install's printer IP was the default `192.168.0.100` on a `192.168.1.x` network (spec 060 WAN-off run) → 3 failures → printing **blocked** until "Reativar impressão";
- the owner reported that on the client's real printer tickets print but **the logo never does** (spec 060, Backlog card);
- `print-worker` crash-looping without `vendor/` (spec 062).

Investigating the logo for this spec found its cause in the code (below) — it is a defect, not a documentation gap, so the spec proposes fixing it here, subject to the owner's approval of a new image dependency (Open question 1).

## Problem

Measured on `master` @ `fc37f2e` (2026-10-27), by reading code (Docker was not running; nothing executed):

1. **No printing document.** Facts an operator needs exist only in code: raw TCP to `printer_ip:printer_port` with a 5 s timeout (`src/Services/PrintService.php:75`, `NetworkPrintConnector($ip, $port, 5)`); 32-column layout (`str_repeat('-', 32)`, `:292`, `:352`); queue retries (`JobService`, `max_attempts` 3, `:75`); blocking after `MAX_CONSECUTIVE_FAILURES = 3` permanent failures (`PrintService.php:33`, `:98`); while blocked, orders are still created but nothing is queued (`OrderService.php:48`, `:147`); test print (`POST /api/admin/settings/test-print`, `PrinterController::testPrint`, 503 `PRINTER_UNAVAILABLE` with an actionable message); reactivation (`POST /api/printer/reset`, "Reativar impressão" in cashier and kitchen).
2. **The logo never prints, on any install.** `Dockerfile:19` installs only `pdo pdo_mysql mbstring xml zip` — **no GD, no Imagick**. `EscposImage::load()` (`vendor/mike42/escpos-php/src/Mike42/Escpos/EscposImage.php:423-458`) tries Imagick, then GD, then "native", which accepts only `wbmp/pbm/bmp` (and `NativeEscposImage` is a stub, `// TODO`); for `logo.png` it throws `No suitable EscposImage implementation found`. `PrintService` catches it and logs a **warning** only (`:281`, `:335`), so the ticket prints without the logo and nothing tells the operator.
3. **A JPEG/WEBP logo would fail even with GD.** `SettingsController::uploadLogo()` accepts `image/png`, `image/jpeg`, `image/webp` (`:98`) but stores every upload as `public/assets/img/logo.png` without converting (`:110-111`). `GdEscposImage::loadImageData()` picks the decoder **by file extension** (`GdEscposImage.php:37-49`) → `imagecreatefrompng()` on JPEG/WEBP bytes returns false.
4. **The default printer address looks real.** `006_settings.sql:17` seeds `printer_ip = 192.168.0.100`. On any other subnet the first order fails three times and blocks printing; the Admin shows the value as if someone had set it.

## Goals

- `docs/printing.md`: everything an operator needs to set up, test and recover thermal printing.
- The logo prints (PNG, JPEG or WEBP upload), and a logo that cannot be loaded is visible to the operator.
- The roadmap records the agreed v1.9 closing order and the new "dev × production deployment" item.

## Non-goals

- USB, serial or Bluetooth printers (`NetworkPrintConnector` only — documented as a limitation).
- Configurable paper width / 80 mm layout (32 columns stays; documented).
- Changing the blocking rule (3 consecutive permanent failures, spec 039).
- Fiscal printing / NFC-e (v2.1).

## Current behavior

See Problem — every claim cites the file and line it was read from. The Backlog card's hypotheses ("file missing", "extension missing", "printer without bitImage") are resolved: it is the missing image extension, plus the extension-based decoder for non-PNG uploads.

## Proposed behavior

1. **`docs/printing.md`** (English, like `docs/`):
   - **What works:** ESC/POS printers reachable over the network on raw TCP (port 9100 "RAW/JetDirect"); Ethernet or Wi-Fi; not USB/serial/Bluetooth; ticket laid out for 32 columns (58 mm paper; on 80 mm it prints narrower); logo PNG/JPEG/WEBP, square.
   - **Network:** printer on the same network as the server; fixed IP (DHCP reservation, or set on the printer); how to find its IP (the printer's self-test page — usually holding FEED while powering on); check from the server (`ping <ip>`).
   - **Configure:** Admin › Configurações › IP da Impressora / Porta → Salvar → Imprimir Teste; the meaning of the 503 message.
   - **How printing works:** order with "Imprimir" → queue → `print-worker`; 3 attempts (2 s, 4 s apart); a failed job is kept; after 3 consecutive failed orders printing is **blocked** — orders keep being created, nothing is queued, cashier/kitchen show it; **Reativar impressão**; reprint from the kitchen.
   - **Troubleshooting** table: blocked after a few orders (wrong IP/subnet — the `192.168.0.100` default), test print 503, `print-worker` restarting, logo missing (check the log for "Logo não pôde ser carregada"), garbled accents, nothing printed but no error (jobs stuck: `bin/jobs-status`).
   - **Commands:** `bin/jobs-status print`, `bin/worker print --once`, `docker compose logs -f print-worker`.
2. **Logo fix** *(needs the owner's OK — Open question 1)*:
   - `Dockerfile`: add the **GD** extension with PNG/JPEG/WEBP support (`libpng-dev`, `libjpeg62-turbo-dev`, `libwebp-dev`; `docker-php-ext-configure gd --with-jpeg --with-webp`).
   - `SettingsController::uploadLogo()`: decode the upload with GD and **store a real PNG** (`imagepng`), so the extension matches the content; reject a file GD cannot decode with a 400.
   - `PrintService`: when the logo exists but cannot be loaded, keep printing without it (unchanged) **and** surface it — `GET /api/admin/settings` (or the printer status) reports `logo_printable: false` with the reason, shown next to the logo in Admin › Configurações.
   - CI: add `gd` to `setup-php`'s extension list so the tests that load the logo run there.
3. **Default printer IP** *(Open question 2)*: proposed — leave the seeded value, and document it as a placeholder that must be changed.
4. **Docs pass:** `docs/installation.md` step 10 and `docs/commands.md` link `docs/printing.md`; README Documentation table; `docs/ROADMAP.md`: "Printer documentation" status, the new item **"Separate deployment: development × Community production"** in v1.9 and the agreed closing order (1 printer docs → dev×production → final docs → license → SECURITY/CONTRIBUTING/support → release automation → golden test → v1.9.0 → v2.0.0-rc.1 → v2.0.0); CHANGELOG; spec 064 → `Verified` with its CI evidence; close the Backlog logo card when merged.

## Functional requirements

1. FR1 — `docs/printing.md` exists with the sections of Proposed behavior item 1; installation step 10, `docs/commands.md` and README link to it.
2. FR2 — In the `web`/`print-worker` image, `php -m` lists `gd`, and `gd_info()` reports PNG, JPEG and WebP support.
3. FR3 — `EscposImage::load('public/assets/img/logo.png')` succeeds in the container; a receipt printed to a capturing connector contains the ESC/POS raster-image command (`GS v 0`, bytes `1D 76 30`). *(Corrected 2026-10-27 by `/spec-review`: `EscposImage::load()` "succeeds" only because it loads lazily — with GD, its `toRasterFormat()` still throws `Failed to load image.` (escpos-php 2.2 vs PHP 8 `GdImage`, see Implementation log). The requirement is the receipt half: the logo, loaded by `PrintService::loadLogo()`, reaches the connector as `1D 76 30`.)*
4. FR4 — Uploading a JPEG or WEBP logo stores a file whose bytes start with the PNG signature (`89 50 4E 47`); uploading a file GD cannot decode → 400, previous logo kept.
5. FR5 — With an unloadable logo, the ticket still prints and the Admin settings response exposes `logo_printable: false` + a reason; with a loadable one, `true`.
6. FR6 — `docs/ROADMAP.md` lists the dev×production item and the closing order; spec 064 is `Verified` with its CI run recorded.

## Non-functional requirements

- The GD packages are the only new dependency, and only with the owner's approval (CLAUDE.md: no new dependency without explicit request). No Composer change, no PHP version change.
- No schema change.

## User flows

**Setting up a printer:** find the printer's IP (self-test page) → Admin › Configurações → IP/Porta → Salvar → Imprimir Teste → ticket (with logo, if uploaded).
**Recovering:** cashier/kitchen show printing blocked → fix the cause (IP, power, cable) → Imprimir Teste → Reativar impressão → reprint pending orders from the kitchen.

## API changes

- `POST /api/admin/settings/logo`: 400 when the image cannot be decoded (new); success response unchanged.
- `GET /api/admin/settings` (admin): adds `logo_printable` (bool) and `logo_problem` (string|null) — additive.

## Data model and migrations

Not applicable — no schema change (Open question 2 keeps the seeded `printer_ip`).

## Architecture and affected components

- New `docs/printing.md`; `docs/installation.md`, `docs/commands.md`, `README.md`, `docs/ROADMAP.md`, `CHANGELOG.md`, `docs/architecture.md` (printing section), `specs/064-stable-releases-by-tag.md`.
- `Dockerfile` (GD), `.github/workflows/ci.yml` (`gd` in `setup-php`).
- `src/Controllers/SettingsController.php` (logo conversion + settings payload), `src/Services/PrintService.php` (logo loadability check), `public/admin/settings.php`/`settings.js` (show the problem).
- Tests: `tests/Unit/PrintServiceTest.php` (raster command present when the logo loads), a settings/logo test.
- *(Added 2026-10-27 by `/spec-review`, owner's OK)* `composer.json` (+ `composer.lock` hash): `"ext-gd": "*"`, so an install without GD fails at `composer install` instead of with a 500 on the logo upload.

## Security considerations

- Decoding uploads with GD and re-encoding to PNG also strips anything that is not image data (metadata, trailing payloads) — an improvement over storing the raw upload.
- The upload stays admin-only (`$adminOnly()` on `/settings/logo`, `src/Routes.php:107`).
- `logo_problem` is a fixed, operator-facing text — never the raw exception (spec 038's rule).

## Backward compatibility

- Existing PNG logos keep working (and start printing). An existing JPEG/WEBP stored as `logo.png` stays unprintable until re-uploaded — the Admin now says so.
- Settings payload: additive fields only.
- The image grows by the GD libraries; operators get it with the normal upgrade (`docker compose up -d --build`, `docs/upgrading.md` step 5).

## Acceptance criteria

1. AC1 — `docs/printing.md` contains: what works (raw TCP 9100, not USB, 32 columns, logo formats), network setup, Admin configuration + test print, the queue/retry/block/reactivate flow, troubleshooting (≥ 6 rows incl. wrong subnet and missing logo), commands; linked from installation step 10, `docs/commands.md`, README.
2. AC2 — After `docker compose up -d --build`: `docker compose exec web php -m | grep -x gd` → `gd`; `php -r 'var_dump(gd_info());'` shows PNG, JPEG and WebP support true.
3. AC3 — Automated test: with GD, building a receipt for an order with `logo.png` present writes `1D 76 30` to the connector; existing `PrintServiceTest` still green.
4. AC4 — Automated or curl test: JPEG upload → stored file starts with `89 50 4E 47`; a text file renamed `.png` uploaded → 400, previous `logo.png` unchanged (byte-compare).
5. AC5 — `GET /api/admin/settings` → `logo_printable: true` with a valid logo; with a corrupt `logo.png` → `false` and a reason, and `POST …/test-print` still prints (or fails only for network reasons).
6. AC6 — `docs/ROADMAP.md` diff shows the new item and the closing order; spec 064 status `Verified`.
7. AC7 — CI green on the PR (with `gd` in CI's PHP).
8. AC8 *(owner, on real hardware — cannot be done here)* — a test print on the client's printer shows the logo.

## Implementation plan

1. `docs/printing.md` + links.
2. *(after Open question 1)* `Dockerfile` GD + CI `setup-php` `gd`; rebuild; AC2.
3. Logo upload → real PNG; settings payload `logo_printable`; Admin message; tests (AC3–AC5).
4. ROADMAP (item + order + status), CHANGELOG, architecture, spec 064 → Verified.
5. Owner: test print with logo on the real printer (AC8).

## Testing and validation strategy

The project has PHPUnit (Unit + MySQL Integration) and Playwright — the template's "no automated test infrastructure" note is outdated.

- **Automated:** PrintService test with a capturing connector (AC3); logo upload test (AC4); settings payload (AC5); full suite in CI with `gd` (AC7).
- **Container:** `php -m` / `gd_info()` after rebuild (AC2).
- **Hardware:** no thermal printer here — the documented printer behavior was taken from code and from the owner's report; the logo on paper is AC8, the owner's.

## Rollout and rollback

Ships with the normal upgrade (`docker compose up -d --build` installs GD). Rollback: revert; the image loses GD and the logo stops printing again (no data involved).

## Open questions

1. ***(blocking for steps 2–3 only — docs can proceed)*** **Add GD to the image?** It is the minimal fix for the logo (Imagick would be heavier). New system packages in the `Dockerfile` + `gd` in CI need your explicit OK (CLAUDE.md rule). Proposed: **yes**. If no, the docs say "logo printing is not supported" and the Admin upload is hidden or flagged.
2. *(non-blocking)* **Seeded `printer_ip = 192.168.0.100`.** Clearing it would make a fresh install say "IP da impressora não configurado" instead of failing 3× and blocking — but it needs a new migration that can tell a fresh install from one whose operator really uses `192.168.0.100`. Proposed: **leave it**, document it as a placeholder; reconsider with the dev×production deployment work.
3. *(non-blocking)* **Language of `docs/printing.md`.** Proposed: English, like the rest of `docs/`, with the Admin's Portuguese labels quoted as they appear on screen.

## Task checklist

- [x] Step 1 — `docs/printing.md` + links
- [x] Step 2 — GD in the image and in CI (after Open question 1)
- [x] Step 3 — logo upload → PNG, `logo_printable`, Admin message, tests
- [x] Step 4 — ROADMAP, CHANGELOG, architecture, spec 064 → Verified
- [ ] Step 5 — owner's test print with logo (AC8)

## Implementation log

- 2026-10-27 — Owner answered Open question 1 with "segue" (add GD); questions 2 and 3 took their proposed defaults (seeded `printer_ip` kept and documented as an example; docs in English). `/spec-implement` moved the spec Draft → Approved → In Progress.
- 2026-10-27 — **Deviation from Proposed behavior 2 — GD alone does not fix the logo.** Measured after the rebuild: `EscposImage::load('public/assets/img/logo.png')` returns a `GdEscposImage` (loading is lazy), and `->toRasterFormat()` throws `Exception: Failed to load image.` — escpos-php **2.2**'s `GdEscposImage::readImageFromGdResource()` checks `is_resource()`, and PHP 8's GD returns `GdImage` objects. Rather than upgrade the library (a version bump, not approved), `PrintService::loadLogo()` decodes the file with `imagecreatefromstring()` (by content, so JPEG/WEBP saved as `logo.png` by old uploads also print) and fills the raster in an anonymous `EscposImage` subclass, with the same pixel rule as the library. Both print paths (ticket and test page) use it.
- 2026-10-27 — **Added: logo scaled to 384 dots.** The repository's own `logo.png` is 1024×1024; a raster wider than the 58 mm head (384 dots at 203 dpi — the 32-column layout) prints clipped or garbled on most printers. `loadLogo()` scales anything wider down to 384. Not in the spec's text; needed for AC8 to have a chance.
- 2026-10-27 — `logo_printable`/`logo_problem` are top-level fields of `GET /api/admin/settings` (next to `settings`, which is the key/value map). `logo_problem` is one of two fixed Portuguese messages from `loadLogo()` (GD missing / not a valid image) — never the raw exception. No logo → `logo_printable: false`, `logo_problem: null`, no warning.
- 2026-10-27 — Upload: an empty file would make `imagecreatefromstring('')` throw `ValueError` (PHP 8), so empty bytes are treated as undecodable → 400 `INVALID_IMAGE`. `imagepng()` returning false throws (500) instead of reporting success. The client media-type check (`image/png|jpeg|webp`) stays as it was.
- 2026-10-27 — `/spec-review` findings applied with the owner's OK: FR3 corrected (dated note); `"ext-gd": "*"` added to `composer.json` — `uploadLogo()` calls GD without an extension check, so outside Docker an install without GD would answer 500; now `composer install` refuses up front. Not changed: the new tests' temp directories under `sys_get_temp_dir()` are not deleted (a few KB per run, in the container's `/tmp`).
- 2026-10-27 — Admin label "PNG/JPG quadrado" → "PNG, JPG ou WebP, quadrada 1:1"; after an upload the page reloads the settings so the warning updates.

## Validation evidence

Collected 2026-10-27 on branch `065`, dev stack rebuilt (`docker compose build web print-worker` → exit 0, then `docker compose up -d`).

| AC | Command / evidence | Result |
|---|---|---|
| AC1 | `docs/printing.md`: "What works" (raw TCP 9100, 5 s timeout, no USB/serial/Bluetooth, 32 columns / 58 mm, logo PNG/JPG/WebP scaled to 384 dots), "1. Put the printer on the network" (fixed IP, self-test page, `ping`), "2. Configure GastroFlow" (Admin fields, Imprimir Teste, 503 meaning, `192.168.0.100` as example), "3. How printing works" (queue, 3 attempts 2 s/4 s, block after 3 orders, Reativar impressão, Reimprimir nota), Troubleshooting (8 rows incl. wrong subnet/example IP and missing logo), Commands. Linked from `docs/installation.md:103` and `:143`, `docs/commands.md:65`, `README.md:53` | Met |
| AC2 | `docker compose exec web php -m \| grep -x gd` → `gd`; same in `print-worker` → `gd`; `gd_info()` → `PNG Support` true, `JPEG Support` true, `WebP Support` true | Met |
| AC3 | `PrintServiceTest::testLogoIsPrintedAsARasterImage` (PNG and JPEG-bytes-as-`logo.png` → output contains `1D 76 30`) and `testWideLogoIsScaledToThePaperWidth` (1024 px → `GS v 0` width 48 bytes = 384 dots): pass. **Against the old `PrintService.php`** (from `HEAD`, everything else the same): both **fail** — they catch the defect | Met |
| AC4 | `SettingsLogoTest::testJpegAndWebpAreStoredAsPng` (stored file starts `89 50 4E 47`) and `testUndecodableFileIsRejectedAndThePreviousLogoKept` (text as `image/png` → 400 `INVALID_IMAGE`, previous file byte-identical): pass. Automated, on a throwaway base path — not curl, so the repository's `logo.png` is never overwritten | Met |
| AC5 | `PrintingTest::testSettingsReportWhetherTheLogoPrints` (real HTTP through the app, DI included: `logo_printable` true, `logo_problem` null for the repository logo); `PrintServiceTest::testUnreadableLogoIsReportedAndTheTicketStillPrints` (corrupt `logo.png` → ticket printed with `Senha: 5`, no raster, `logoProblem()` non-null). The test page uses the same `loadLogo()` inside the same try/catch | Met |
| AC6 | `docs/ROADMAP.md`: "Printer documentation" status, new "Separate deployment: development × Community production" section, new "Closing order" section before the Exit Gate; `specs/064-stable-releases-by-tag.md` → `Verified` (CI run `40454444178`, `success`, on `1a49268`) | Met |
| AC7 | CI on the PR | Pending CI |
| AC8 | Owner, on the client's printer | Pending (owner) |

Full suite in the container (`MYSQL_DATABASE_TEST=restaurant_test`, `MYSQL_DATABASE_TEST_UPGRADE=restaurant_test_upgrade`): `OK (317 tests, 829 assertions)`. PHPStan: `[OK] No errors` — the first run of the session reported "Found 1 error … Result is incomplete because of severe errors" without naming it; after `phpstan clear-result-cache` two runs were clean (stale result cache from before the image rebuild, most likely; CI runs without a cache). PHP-CS-Fixer `--dry-run`: 0 of 116 files to fix. Playwright (`web-e2e` on 8081, rebuilt with GD): `34 passed`. `git diff --check`: clean; new files `eol: lf`. After the `/spec-review` follow-up: `composer validate --strict` → `./composer.json is valid` (lock refreshed with `composer update --lock`; its unrelated rewrite of the phpdotenv repository URL — same commit reference — was reverted).

Not verified: the Admin warning was not looked at in a browser with an unprintable logo (the Playwright suite has no such case, and the repository's `logo.png` was not corrupted to stage one); the logo on paper (AC8).

Status `Implemented`: AC7 waits for CI, AC8 for the owner.
