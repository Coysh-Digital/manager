# Manager

A control plane for a fleet of Craft CMS installations. Answers "what version is it, is it patched,
did the backup run" without logging into ten control panels.

Two editions from one core: **Manager Cloud**, hosted by Coysh Digital, and **Manager Self-Hosted**,
which runs on your own infrastructure. This repository is the core, and it is what Self-Hosted ships.

## What it holds, and what it does not

Manager never holds an administrator password, an SSH credential or a managed site's database
password. There is nowhere in its schema to put one, and a test walks the live schema on every run
to keep that true.

Connectors generate their own keypair on the site and send only the public half, so a stolen copy of
this database confers no ability to impersonate any site.

## Getting started

- [Installation](docs/install.md)
- [Environment reference](docs/env.md)
- [Reverse proxy](docs/reverse-proxy.md)
- [Upgrading](docs/upgrade.md) and [rolling back](docs/rollback.md)
- [Backups](docs/backup.md)
- [Security model and runbooks](docs/security.md)

```bash
cd deploy/docker
cp ../../.env.example .env     # then edit it; the container refuses to start on defaults
docker compose up -d
docker compose exec app php artisan manager:keys:generate
docker compose exec app php artisan manager:doctor
```

## Local development

Uses ddev, with Postgres and Redis matching what the shipped Compose deployment runs.

```bash
ddev start
ddev artisan migrate
ddev artisan manager:doctor
```

Assets are built on the host and the compiled output is committed, so deploying is a git pull and a
migrate with no Node on the server:

```bash
npm install
npm run build     # commit public/build
```

### Tests

```bash
ddev exec vendor/bin/pest --testsuite=Invariants   # the security suite
ddev exec vendor/bin/pest
ddev exec vendor/bin/pint --test
ddev exec vendor/bin/phpstan analyse
```

Tests run against Postgres rather than SQLite, and against a real Redis. The audit log depends on a
trigger and on revoked table privileges, and replay protection depends on an atomic store — testing
either against a substitute would be testing something other than what ships.

`tests/Invariants/` holds one file per numbered requirement in the specification, so a reviewer can
map the suite to the document.

## Commands

| Command | Purpose |
|---|---|
| `manager:doctor` | Check configuration and security. Run after installing or upgrading. |
| `manager:audit:verify` | Verify the audit chain. Run after any restore. |
| `manager:keys:generate` | Mint the platform signing keypair. |

## Related repositories

| | |
|---|---|
| `craft-manager-connector` | The Craft 5 plugin installed on managed sites. Public, for review. |
| `manager-protocol` | The wire contract shared by both. Public, zero runtime dependencies. |
| `manager-cloud` | Cloud-only services: managed keys, billing, provisioning. |

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities to security@coysh.digital.
