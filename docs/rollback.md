# Rolling back

## Application only

If a release misbehaves but the schema has not changed:

```bash
cd /opt/manager
git checkout v1.0.0
cd deploy/docker
docker compose up -d --build
docker compose exec app php artisan manager:doctor
```

## After a migration

**Do not run `migrate:rollback` on a production installation as a first response.** A down migration
that drops a column discards data that the previous release cannot recover either. Rolling back a
schema is a data-loss operation dressed as a convenience.

The safe order is:

1. Stop the workers and scheduler so nothing writes while you decide:
   `docker compose stop worker scheduler`
2. Restore the database from the backup taken before the upgrade.
3. Check out the previous tag and rebuild.
4. Run `manager:doctor` and `manager:audit:verify`.
5. Start the workers again.

Restoring a backup rewinds the audit log to the moment it was taken. That is expected, and it is why
`manager:audit:verify` runs afterwards: the chain will be intact but shorter, and anyone reviewing
the history needs to know when a rewind happened.

## Connector compatibility

Rolling the platform back below a connector's protocol version will make those connectors fail
verification. They will keep retrying and report the failure locally; nothing is lost, but the
fleet shows as not reporting until the versions line up again.

If you have to stay rolled back, pin affected connectors to the matching release.

## Emergency: stop accepting connector traffic

To stop the platform accepting reports without taking it down:

```bash
docker compose stop redis
```

Replay protection fails closed, so every connector request is rejected with a 503 while Redis is
unavailable, and the interface keeps working. This is a blunt instrument, and it is the fastest one
available.
