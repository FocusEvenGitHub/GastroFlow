# Support

GastroFlow Community is free, open-source software ([Apache License 2.0](LICENSE)) maintained by one person. This page says what you can count on — and what isn't promised.

## Where to ask

| You have | Go to |
|---|---|
| A bug, a question, an idea | [GitHub issues](https://github.com/FocusEvenGitHub/GastroFlow/issues) — search first, then open one with the version (`GET /version` or the tag), what you did, what you expected and what happened |
| A security vulnerability | **Not an issue** — [`SECURITY.md`](SECURITY.md) |
| A problem installing, upgrading or printing | The guides first: [`docs/installation.md`](docs/installation.md), [`docs/upgrading.md`](docs/upgrading.md), [`docs/printing.md`](docs/printing.md), [`docs/troubleshooting.md`](docs/troubleshooting.md) |

There is no private, paid or real-time support channel.

## Supported versions

Only the **latest release** is supported. Fixes ship as a **new release tag**; an existing tag is never changed. Until `v2.0.0`, "latest release" means the most recent tag; from `v2.0.0` on, the latest minor release of the `2.x` line.

Run a release tag, not `master`: `master` is the next version in development. If you hit a problem on an older release, upgrade first ([`docs/upgrading.md`](docs/upgrading.md)) — the problem may already be fixed.

## What GastroFlow Community 2.x may keep receiving

- security fixes;
- critical bug fixes;
- dependency updates;
- compatibility improvements;
- documentation;
- selected community contributions ([`CONTRIBUTING.md`](CONTRIBUTING.md)).

What's planned is in [`docs/ROADMAP.md`](docs/ROADMAP.md).

## What is not promised

- **Response or fix times.** Issues are answered as soon as possible.
- **Every future GastroFlow capability.** Not everything GastroFlow gains in the future will be added to Community.
- **Support for older releases, `master`, or modified installations** (changed source code, a different stack than the documented Docker setup).
- **Fitness for a particular use**, as the [license](LICENSE) states (sections 7 and 8).
