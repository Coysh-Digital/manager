# Upgrading

Manager for Craft upgrades are a new image and a migration. There is no build step on the server and
Node is not required there: compiled assets are committed.

## Before you start

Back up the database. [backup.md](backup.md) covers this. An upgrade that goes wrong is recoverable
from a backup and not much else.

Read the release notes. Anything needing manual action is called out there, and
[CHANGELOG.md](https://github.com/Coysh-Digital/manager/blob/main/CHANGELOG.md) has the same content
under **Before you upgrade**.

## Upgrade

Replace `v1.2.0` below with the version you are moving to.

```bash
cd /opt/manager
git fetch --tags

git checkout v1.2.0
cd deploy/docker

# Record which release this is, so the interface can say so rather than "unreleased build".
sed -i 's/^MANAGER_VERSION=.*/MANAGER_VERSION=1.2.0/' .env

docker compose up -d --build

docker compose exec app php artisan manager:doctor
docker compose exec app php artisan manager:audit:verify
```

### Checking what you downloaded

Releases carry a `SHA256SUMS` manifest covering every artifact. If you installed from the tarball
rather than from a clone, verify it before unpacking:

```bash
sha256sum -c SHA256SUMS
```

The tarball is built reproducibly — `git archive` takes its timestamps from the commit and gzip is
told not to stamp its header — so building the same tag yourself produces byte-identical output. That
is what lets somebody other than us confirm a published artifact came from the published source.

**Releases are not signed, and nothing here verifies a signature.** This page used to say the
opposite and told you to run `git verify-tag`, which fails on every release there has ever been: tag
signing was removed deliberately, there is no `allowed_signers` file, and the release workflow checks
nothing. The manifest above proves a download is intact. It does not prove who produced it, and
neither did the instruction it replaced.

Migrations run automatically when the web container starts, under `--isolated`, so only one replica
applies them however many are running.

`manager:audit:verify` afterwards is worth the few seconds: a restore from an older backup is
otherwise indistinguishable from deliberate truncation.

## Connector versions

Connectors and the platform are versioned independently and speak a versioned protocol. A platform
upgrade does not require every site to be updated at once.

Where a protocol change is not backwards compatible, the release notes say so explicitly and the
platform continues accepting the previous version for at least one minor release.

## Zero-downtime

Bring up the new web container before retiring the old one, and let the orchestrator poll `/ready`
rather than `/up`: `/up` says PHP is running, `/ready` says this instance can actually serve a
request.

Migrations are written to be safe against the previous version running concurrently - additive
first, destructive changes only in a later release. Where that is not possible the release notes say
so and the upgrade needs a brief outage.

## If it goes wrong

See [rollback.md](rollback.md).
