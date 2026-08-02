# Manager for Craft

One screen for every Craft CMS site you look after: versions, updates, findings and encrypted
backups. Answers "what version is it, is it patched, did the backup run" without logging into ten
control panels.

### 📖 **[Documentation → managerforcraft.com/docs](https://managerforcraft.com/docs/)**

Installation, configuration, pairing, backups and the security model all live there. This file covers
what Manager for Craft is and how to work on it; everything about *running* it is in the docs.

Free software under the **AGPL-3.0-or-later**. Two editions from one core: **Manager Cloud**, hosted
by Coysh Digital, and **Manager Self-Hosted**, which runs on your own infrastructure. This repository
is the core, and it is what Self-Hosted ships. Nothing is held back for the paid edition.

Requires PHP 8.3+, PostgreSQL 15+ and Redis 7+.

## What it holds, and what it does not

**No credentials.** Manager for Craft never holds an administrator password, an SSH credential or a
managed site's database password. There is nowhere in its schema to put one, and a test walks the
live schema on every run to keep that true.

**No ability to impersonate a site.** Connectors generate their own keypair on the site and send only
the public half, so a stolen copy of this database confers nothing.

**No ability to read your backups.** A site encrypts its database to recovery keys you generated on
your own machine, and uploads ciphertext. The platform is not a recipient, so what an attacker gets
from the server — or what we could be compelled to hand over — is a file nobody here can open. A
recovery key is required before any backup is taken, so there is no configuration in which this is
quietly not true. See [Recovery keys](https://managerforcraft.com/docs/recovery-keys.html).

**It watches; it does not act.** No deploys, no update installs, nothing executed on a site. That is
a design decision rather than a roadmap gap, and
[What it does, and does not](https://managerforcraft.com/docs/what-it-does.html) explains where the line
sits and why.

**Sites come to it.** Every exchange starts at the Craft site and goes outbound. Nothing listens,
nothing is pushed, and a site behind NAT needs no inbound firewall rule.

## Self-hosted or hosted by us

This repository **is** Manager Self-Hosted, and it is feature-complete. There is no reduced edition
and nothing held back: every monitoring, findings, jobs and backup feature is here, free to run for
your own and your clients' sites.

Running it means running a security-sensitive service: a patched server, Postgres and Redis, TLS, a
signing keypair backed up separately from the database, and a backup store holding a copy of every
managed site's database — as ciphertext, but still. The
[installation guide](https://managerforcraft.com/docs/install.html) is honest about that before it tells
you how.

If you would rather not, **[Manager Cloud](https://managerforcraft.com)** is the same core hosted by
Coysh Digital: same connector, same protocol, same security boundaries, with the server, the storage
and the on-call rota ours rather than yours. The connector is identical, so moving between the two
means re-pairing sites rather than rebuilding anything.

## Quick start

The full guide is [managerforcraft.com/docs/install](https://managerforcraft.com/docs/install.html). In
outline:

```bash
cd deploy/docker
cp ../../.env.example .env     # then edit it; the container refuses to start on defaults
docker compose up -d
docker compose exec app php artisan manager:keys:generate
docker compose exec app php artisan manager:doctor
```

`manager:doctor` is the one to read. It reports what is wrong *and* what to do about it, and it is
worth running after every install and upgrade.

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

The ones an operator runs by hand. The scheduler runs the rest; `php artisan list manager` is the
full set, and the [environment reference](https://managerforcraft.com/docs/env.html) covers what they
read.

| Command | Purpose |
|---|---|
| `manager:doctor` | Check configuration and security. Run after installing or upgrading. |
| `manager:keys:generate` | Mint the platform signing keypair. Do this once, before pairing anything. |
| `manager:audit:verify` | Verify the append-only audit chains. Run after any restore. |
| `manager:user:password` | Set a password from the server, for when nobody can log in. |
| `manager:mail-test` | Send a test email, to prove delivery rather than configuration. |

Two more exist for backups taken **before** recovery keys, which were sealed to a platform-held key
rather than to yours: `manager:backups:keygen` mints that keypair and `manager:backups:fetch`
decrypts an artifact sealed to it. Neither is part of a current installation's routine — every backup
taken now is opened with [`manager-restore`](https://github.com/Coysh-Digital/manager-restore) on
your own machine, because the platform holds no key that would open it. See
[Restoring a backup](https://managerforcraft.com/docs/restoring.html).

## Related repositories

| | |
|---|---|
| [`craft-manager-connector`](https://github.com/Coysh-Digital/craft-manager-connector) | The Craft 4.4+/5 plugin installed on managed sites. Public, MIT. |
| [`manager-protocol`](https://github.com/Coysh-Digital/manager-protocol) | The wire contract shared by both. Public, MIT. |
| [`manager-restore`](https://github.com/Coysh-Digital/manager-restore) | The offline CLI that generates recovery keys and decrypts backups. Public, MIT. It opens no sockets, and there is a test. |
| `manager-cloud` | Private. The marketing site at managerforcraft.com. |
| `manager-private` | Private. A deployment mirror of this repository, plus the Cloud hosting layer: billing, provisioning and per-organisation storage. Nothing in it is required to run Manager for Craft. |

`manager-restore` is deliberately a separate, permissively licensed package with no dependency on
this one. If Coysh Digital stops existing, that tool plus the protocol specification is what stands
between a customer and a permanently unreadable archive.

## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities to hello@coysh.digital, privately.

Nothing here depends on the source being secret. Rejections are deliberately uniform so an endpoint
cannot be used to discover which site identifiers exist; the unknown-site path verifies against a
decoy key so it costs what the bad-signature path costs. Both are designed for a reader who has this
repository open, because that reader now exists.

The [security model](https://managerforcraft.com/docs/security.html) sets out the boundaries in full,
including what an attacker gets from each component they compromise.

## Licence

**AGPL-3.0-or-later.** Free software: see [LICENSE](LICENSE) for the full text and
[LICENSE.md](LICENSE.md) for what it means in practice.

Run it for your own sites, run it for your clients' sites, run it commercially, fork it. None of that
asks anything of you. The one obligation is section 13: modify Manager for Craft and let other people
use your modified version over a network, and you owe those users the source of your version. Running
an unmodified copy triggers nothing, and neither does modifying it for yourself.

That is deliberate rather than a default. This is a control plane holding the keys to other people's
backups, and the case for publishing it is that its security properties can be verified rather than
asserted. A fork carrying the trust of this one while hiding what it actually does would undo the only
reason for publishing in the first place.

The connector, the protocol package and the restore tool are MIT, because they run inside somebody
else's codebase or on somebody else's machine.

This repository is the whole product. Manager Cloud adds no monitoring, findings, jobs or backup
features that are missing here; it adds the fact that somebody else runs it.

## Contributing

Bug reports and patches are welcome. Two things to know first:

- **Security issues go to hello@coysh.digital, never to a public issue.**
- `tests/Invariants/` encodes the security properties this software promises. A change that makes one
  of those tests fail is a change to the promise, so it needs an explanation of why the promise should
  change — not a fix to the test.
