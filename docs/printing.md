# Printing

How GastroFlow prints the order ticket on a thermal printer: what is supported, how to connect and test the printer, what happens when it fails, and how to recover. First installation: [`docs/installation.md`](installation.md). Every command: [`docs/commands.md`](commands.md).

## What works

| | |
|---|---|
| **Printer** | Any **ESC/POS** thermal printer reachable **over the network** (Ethernet or Wi-Fi). |
| **Connection** | Raw TCP to the printer's IP and port — usually **9100** ("RAW", "JetDirect", "Socket" in the printer's config page). 5-second timeout. |
| **Not supported** | USB, serial or Bluetooth printers, and printers shared through Windows/CUPS. A USB-only printer needs a network print server in front of it. |
| **Paper** | The ticket is laid out for **32 columns**: 58 mm paper. On 80 mm paper it prints narrower, not wider. |
| **Logo** | Optional, uploaded in Admin. PNG, JPG or WebP, preferably square and high-contrast: it is printed in black and white, scaled down to 384 dots (the width of 58 mm paper). Transparent areas print white. |

## 1. Put the printer on the network

1. Connect the printer to the same network as the server — same router, same address range (e.g. both `192.168.1.x`).
2. Give it a **fixed IP**: either set a static IP in the printer, or reserve its address in the router (DHCP reservation). A printer whose IP changes after a power cut stops printing.
3. Find its IP: print the printer's **self-test / configuration page** (on most models, hold the FEED button while turning it on). It lists the IP and the port.
4. From the server, check that it answers:

   ```bash
   ping <printer-ip>
   ```

## 2. Configure GastroFlow

Admin › **Configurações** › Impressora:

1. **IP da Impressora**: the IP from the self-test page — an IP, not a hostname.
2. **Porta**: usually `9100`.
3. **Salvar Configurações**, then **Imprimir Teste**.

The test ticket shows the restaurant name, the logo (if any), the configured address, a line of accented characters and "Se você está lendo isso, a impressora está funcionando!".

If it fails, the screen says why — e.g. `IP da impressora não configurado`, or that the printer at `<ip>:<port>` did not answer (HTTP `503 PRINTER_UNAVAILABLE`). Fix the address or the network and try again.

> **New installs:** the printer IP starts as `192.168.0.100`. It is an example, not a detected value — always replace it with your printer's IP.

### The logo

Upload it in Admin › Configurações › **Logo**. GastroFlow converts it to PNG on upload and refuses a file it cannot read. If a logo is on disk but cannot be printed, a yellow warning under the logo says **"Esta logo não sai no cupom"** and why — the ticket still prints, without the logo.

## 3. How printing works

1. An order is sent with **Imprimir** on → a print job goes into a queue (`jobs` table). The order itself is already saved: a printer problem never loses an order.
2. The `print-worker` service sends the job to the printer.
3. If the printer doesn't answer, the job is retried — **3 attempts** in total, about 2 s and 4 s apart. After the third, the job is marked failed (kept for diagnosis).
4. After **3 orders in a row** fail this way, printing is **blocked**: the cashier and the kitchen show **"Impressão bloqueada"**. While blocked, orders are still created normally, but nothing is sent to the printer — so a dead printer doesn't pile up jobs.
5. To recover: fix the cause, run **Imprimir Teste** in Admin, then press **Reativar impressão** (cashier or kitchen). Orders taken while blocked were not printed — reprint the ones you need from the kitchen (**Reimprimir nota** on the order).

A successful print resets the failure count.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Printing blocked after the first few orders of a new install | Printer IP still the example `192.168.0.100`, or the printer is on another network (`192.168.0.x` vs `192.168.1.x`) | Step 1 and 2; then **Reativar impressão** |
| "Imprimir Teste" → printer did not answer | Wrong IP/port, printer off, cable/Wi-Fi down, or IP changed (no fixed IP) | Self-test page for the real IP; `ping` from the server; fixed IP (step 1) |
| "Imprimir Teste" → `IP da impressora não configurado` | IP field empty | Step 2 |
| `ping` works but nothing prints | Wrong port, or the printer is not in raw/ESC/POS mode | Port from the self-test page (usually 9100); check the printer's emulation setting |
| Ticket prints, logo doesn't | The warning under the logo in Admin says why (e.g. the file is not a valid image); on versions before v1.9, the logo never printed | Upload the logo again; `docker compose logs print-worker` shows "Logo não pôde ser carregada" |
| Accents come out as wrong symbols | The printer's character table differs from the generic ESC/POS profile GastroFlow uses | Check the accented line of the test ticket; there is no setting for this in GastroFlow yet |
| Nothing prints, no error, not blocked | `print-worker` stopped or restarting | `docker compose ps`; if it shows `Restarting`, see `docker compose logs print-worker` (a missing `vendor/` is the usual cause — [installation](installation.md#troubleshooting) step 6) |
| Jobs seem stuck | A worker died mid-job, or jobs failed for good | `bin/jobs-status print` (below) |

## Commands

Run from the repository folder on the server (**Git Bash** on Windows).

| Command | What it does |
|---|---|
| `docker compose logs -f print-worker` | Follows the print worker's output: each print, failure and retry. |
| `docker compose exec web php bin/jobs-status print` | Read-only: print jobs that failed for good, and jobs held by a worker that died. |
| `docker compose exec web php bin/worker print --once` | Processes one print job and exits — for diagnosis. |
| `docker compose restart print-worker` | Restarts the worker. |

The Admin **Logs** screen shows the same print failures (`logs/app.log`).
