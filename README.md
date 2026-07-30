# Manager

A control plane for a fleet of Craft CMS installations. Answers "what version is it, is it patched,
did the backup run" without logging into ten control panels.

Free software under the **AGPL-3.0-or-later**. Two editions from one core: **Manager Cloud**, hosted by
Coysh Digital, and **Manager Self-Hosted**, which runs on your own infrastructure. This repository is
the core, and it is what Self-Hosted ships. Nothing is held back for the paid edition.

Requires PHP 8.3+, PostgreSQL 15+ and Redis 7+.

## What it holds, and what it does not

Manager never holds an administrator password, an SSH credential or a managed site's database
password. There is nowhere in its schema to put one, and a test walks the live schema on every run
to keep that true.

Connectors generate their own keypair on the site and send only the public half, so a stolen copy of
this database confers no ability to impersonate any site.

## Self-hosted or hosted by us

This repository **is** Manager Self-Hosted, and it is feature-complete. There is no reduced edition and
nothing held back: every monitoring, findings, jobs and backup feature is here, free to run for your own
and your clients' sites.

Running it means running a security-sensitive service: a patched server, Postgres and Redis, TLS, two
keypairs backed up separately from the database, and a backup store holding a copy of every managed
site's database. [docs/install.md](docs/install.md) is honest about that before it tells you how.

If you would rather not, **[Manager Cloud](https://managerforcraft.com)** is the same core hosted by
Coysh Digital: same connector, same protocol, same security boundaries, with the server, the keys, the
storage and the on-call rota ours rather than yours. The connector is identical, so moving between the
two means re-pairing sites rather than rebuilding anything.

## Getting started

- [What it does, and what it deliberately does not](docs/what-it-does.md) — including why it does not
  install updates
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
| `manager:backups:keygen` | Mint the backup encryption keypair. Separate from the signing one. |
| `manager:backups:fetch` | Decrypt a stored backup, verifying it against the checksum taken on the site. |
| `manager:backups:prune` | Apply retention. Runs nightly from the scheduler. |
| `manager:user:password` | Set a password from the server, for when nobody can log in. |

## Related repositories

| | |
|---|---|
| [`craft-manager-connector`](https://github.com/Coysh-Digital/craft-manager-connector) | The Craft 5 plugin installed on managed sites. Public, MIT. |
| [`manager-protocol`](https://github.com/Coysh-Digital/manager-protocol) | The wire contract shared by both. Public, MIT. |
| `manager-cloud` | Private. The marketing site at managerforcraft.com. |
| `manager-private` | Private. A deployment mirror of this repository, plus the Cloud hosting layer: billing, provisioning, managed key wrapping and per-organisation storage. Nothing in it is required to run Manager. |

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities to hello@coysh.digital, privately.

Nothing here depends on the source being secret. Rejections are deliberately uniform so an endpoint
cannot be used to discover which site identifiers exist; the unknown-site path verifies against a
decoy key so it costs what the bad-signature path costs. Both are designed for a reader who has this
repository open, because that reader now exists.

## Licence

**AGPL-3.0-or-later.** Free software: see [LICENSE](LICENSE) for the full text and
[LICENSE.md](LICENSE.md) for what it means in practice.

Run it for your own sites, run it for your clients' sites, run it commercially, fork it. None of that
asks anything of you. The one obligation is section 13: modify Manager and let other people use your
modified version over a network, and you owe those users the source of your version. Running an
unmodified copy triggers nothing, and neither does modifying it for yourself.

That is deliberate rather than a default. This is a control plane holding the keys to other people's
backups, and the case for publishing it is that its security properties can be verified rather than
asserted. A fork carrying the trust of this one while hiding what it actually does would undo the only
reason for publishing in the first place.

The connector and the protocol package are MIT, because they run inside somebody else's codebase.

This repository is the whole product. Manager Cloud adds no monitoring, findings, jobs or backup
features that are missing here; it adds the fact that somebody else runs it.

## Contributing

Bug reports and patches are welcome. Two things to know first:

- **Security issues go to hello@coysh.digital, never to a public issue.**
- `tests/Invariants/` encodes the security properties this software promises. A change that makes one
  of those tests fail is a change to the promise, so it needs an explanation of why the promise should
  change — not a fix to the test.
