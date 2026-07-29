# Upgrading

Manager upgrades are a new image and a migration. There is no build step on the server and Node is
not required there: compiled assets are committed.

## Before you start

Back up the database. [backup.md](backup.md) covers this. An upgrade that goes wrong is recoverable
from a backup and not much else.

Read the release notes. Anything needing manual action is called out there.

## Upgrade

```bash
cd /opt/manager
git fetch --tags

# Signed tags. Verify before you deploy: this is what stops a tampered release being deployed as a
# genuine one.
git verify-tag v1.1.0

git checkout v1.1.0
cd deploy/docker

docker compose pull
docker compose up -d --build

docker compose exec app php artisan manager:doctor
docker compose exec app php artisan manager:audit:verify
```

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

Migrations are written to be safe against the previous version running concurrently — additive
first, destructive changes only in a later release. Where that is not possible the release notes say
so and the upgrade needs a brief outage.

## If it goes wrong

See [rollback.md](rollback.md).
