# Security Policy

## Reporting a vulnerability

**Report vulnerabilities privately, through GitHub:** on this repository, open the **Security** tab and choose **Report a vulnerability**, or go straight to
<https://github.com/FocusEvenGitHub/GastroFlow/security/advisories/new>.

Please **don't** open a public issue, pull request or discussion about a vulnerability, and don't publish details before a fix is released. This is the only reporting channel.

### What to include

- the GastroFlow version — the release tag, or what `GET /version` returns;
- the affected component or endpoint (e.g. `POST /api/orders`, the Admin login, the print worker);
- steps to reproduce, and a proof of concept if you have one;
- the impact: what an attacker can read, change or break, and from where (same LAN, another website open in a browser on the LAN, the internet).

Use test data. Don't send real customer or restaurant data.

## What happens next

GastroFlow is maintained by one person. Reports are answered as soon as possible; **no response or fix deadline is promised**.

When a report is confirmed:

1. the fix is developed privately, in the GitHub security advisory;
2. it ships in a **new release tag** (see "Supported versions");
3. the advisory is published with the affected and fixed versions, crediting the reporter unless they prefer not to be named.

## Supported versions

Security fixes go to the **latest release only**, and always ship as a new tag — an existing tag is never changed.

| Version | Security fixes |
|---|---|
| Latest release ([releases](https://github.com/FocusEvenGitHub/GastroFlow/releases) / [tags](https://github.com/FocusEvenGitHub/GastroFlow/tags)) | ✅ |
| Any older release | ❌ — upgrade to the latest ([`docs/upgrading.md`](docs/upgrading.md)) |
| `master` | ❌ — it is the next version in development, not a release |

Until `v2.0.0`, "latest release" means the most recent tag. From `v2.0.0` on, it means the latest minor release of the `2.x` line.

A restaurant should always run a release tag, never `master` ([`docs/installation.md`](docs/installation.md), [`docs/upgrading.md`](docs/upgrading.md)).

## Scope

In scope:

- GastroFlow's own code (`src/`, `public/`, `bin/`, `common/`);
- its Docker image and compose files;
- the third-party copies it ships in `public/vendor/` (if the problem is in the library itself, report it upstream too).

Out of scope, unless the report shows an impact beyond what is documented:

- the known, documented limitations of the deployment model in [`docs/security.md`](docs/security.md#known-limitations) — e.g. cashier and kitchen screens without a login, no HTTPS, trust in the restaurant's local network;
- vulnerabilities in upstream projects (PHP, MySQL, Composer packages) — report them to that project; open a regular issue if GastroFlow needs to update a dependency that already has a public fix.

How GastroFlow is meant to be deployed and what it does and doesn't protect: [`docs/security.md`](docs/security.md).
