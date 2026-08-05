# Manager for Craft

One control panel for every Craft CMS site you look after: versions, updates, findings and encrypted
backups. Answers the question we asked ourselves a lot - "what version is it, is it patched, did the backup run" without logging into ten control panels.

### 📖 **[Documentation → managerforcraft.com/docs](https://managerforcraft.com/docs/)**

Installation, configuration, pairing, backups and the security model all live in the docs.

Free software under the **AGPL-3.0-or-later**.

Requires PHP 8.3+, PostgreSQL 15+ and Redis 7+. The Docker image ships PHP 8.4.

## What it holds, and what it does not

**No credentials.** Manager for Craft never holds an administrator password, an SSH credential or a
managed site's database password. There is nowhere in its schema to put one, and a test walks the
live schema on every run to keep that true.

**No ability to impersonate a site.** Connectors generate their own keypair on the site and send only
the public half, so a stolen copy of this database confers nothing.

**No ability to read your backups.** A site encrypts its database to recovery keys you generated on
your own machine, and uploads ciphertext. The platform is not a recipient, so what an attacker gets
from the server - or what we could be compelled to hand over - is a file nobody here can open. A
recovery key is required before any backup is taken, so there is no configuration in which this is
quietly not true. See [Recovery keys](https://managerforcraft.com/docs/recovery-keys.html).

**It watches; it does not act.** No deploys, no update installs, nothing executed on a site. That is
a design decision rather than a roadmap gap, and
[What it does, and does not](https://managerforcraft.com/docs/what-it-does.html) explains where the line
sits and why.

**Sites come to it.** Every exchange starts at the Craft site and goes outbound. Nothing listens,
nothing is pushed, and a site behind NAT needs no inbound firewall rule.

## What running it involves

This repository **is** Manager, and it is feature-complete. There is no reduced edition and nothing
held back: every monitoring, findings, jobs and backup feature is here, free to run for your own and
your clients' sites.

Running it means running a security-sensitive service: a patched server, Postgres and Redis, TLS, a
signing keypair backed up separately from the database, and a backup store holding a copy of every
managed site's database — as ciphertext, but still. The
[installation guide](https://managerforcraft.com/docs/install.html) sets out what that takes.

A hosted option exists at [managerforcraft.com](https://managerforcraft.com) if you would rather not
run it yourself. It is the same code with the same boundaries; the connector is identical either way,
so nothing here is a trial of it and nothing is withheld to make it more attractive.

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
trigger and on revoked table privileges, and replay protection depends on an atomic store - testing
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

Every backup taken is opened with [`manager-restore`](https://github.com/Coysh-Digital/manager-restore) on your own machine, because the platform holds no key that would open it. See
[Restoring a backup](https://managerforcraft.com/docs/restoring.html).

## Related repositories

| | |
|---|---|
| [`craft-manager-connector`](https://github.com/Coysh-Digital/craft-manager-connector) | The Craft 4.4+/5 plugin installed on managed sites. Public, MIT. |
| [`manager-protocol`](https://github.com/Coysh-Digital/manager-protocol) | The wire contract shared by both. Public, MIT. |
| [`manager-restore`](https://github.com/Coysh-Digital/manager-restore) | The offline CLI that generates recovery keys and decrypts backups. Public, MIT. It opens no sockets, and there is a test. |


## Security

See [SECURITY.md](SECURITY.md). Report vulnerabilities to support@managerforcraft.com, privately.

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
asks anything of you. The obligation the AGPL adds is narrow and only bites in one direction: if you
modify Manager and offer it to other people over a network, those people are entitled to your
modified source. Running an unmodified copy, or a modified one only you and your colleagues use,
triggers nothing.

The connector, the protocol package and the restore tool are MIT, because they run inside somebody
else's codebase or on somebody else's machine.

This repository is the whole product. The hosted option adds no monitoring, findings, jobs or backup
feature that is missing here; it adds the fact that somebody else runs it.

## Contributing

Bug reports and patches are welcome. Two things to know first:

- **Security issues go to us at support@managerforcraft.com, never to a public issue please!**
- **The invariant suites are not negotiable.** `vendor/bin/pest --testsuite=Invariants` here, and
  `php bin/verify-invariants.php` in the connector, are written to fail rather than warn, and every
  rule in them exists because something went wrong once. A patch that makes one red needs a different
  approach, not a weaker check. If you think a rule is wrong, say so in the issue — that is a
  conversation worth having, and changing it quietly in the same commit as a feature is not.

Run the suite before opening a pull request:

```bash
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

