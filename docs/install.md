# Installing Manager Self-Hosted

Manager is a control plane for a fleet of Craft CMS installations. It holds no administrator
password, no SSH credential and no site database password — there is nowhere in its schema to put
one — so the main thing to get right at install time is the platform's own security.

## Requirements

| | |
|---|---|
| Docker | Engine 24+ with the Compose plugin |
| PostgreSQL | 15+. Not MySQL: the audit log relies on a trigger and on privileges that are not portable |
| Redis | 7+. Backs replay protection, which **fails closed** — if Redis is unreachable, connector requests are rejected rather than accepted |
| TLS | Mandatory. Signed requests protect integrity and replay, not confidentiality |

Two CPUs and 2 GB of memory is comfortable for a few dozen sites.

## Install

```bash
git clone https://github.com/Coysh-Digital/manager.git /opt/manager
cd /opt/manager/deploy/docker

cp ../../.env.example .env
```

Edit `.env`. At minimum set `APP_KEY`, `APP_URL` and `DB_PASSWORD` — the container refuses to start
without them, and refuses to start in production on a well-known default password or with
`APP_DEBUG` on. Every variable is documented in [env.md](env.md).

Generate a key:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

Then start it and check the installation before letting anyone near it:

```bash
docker compose up -d
docker compose exec app php artisan manager:keys:generate
docker compose exec app php artisan manager:backups:keygen
docker compose exec app php artisan manager:doctor
```

Both keypairs are written to `.env`. They are separate on purpose — one signs responses to
connectors, the other encrypts backups — and they need backing up separately from the database.
Losing the signing key means re-pairing every site; losing the backup key makes every stored backup
permanently unreadable. See [backup.md](backup.md).

`manager:doctor` must report no failures. It checks the things that are easy to get wrong and
expensive to discover later: a wildcard trusted-proxy setting, a non-atomic replay store, missing
audit-log triggers, an insecure session cookie, a superuser database role.

Put a reverse proxy in front — see [reverse-proxy.md](reverse-proxy.md) — and only then visit
`/setup`.

## First run

`/setup` creates the organisation and its owner. It closes permanently once an account exists: the
route stops resolving, so there is nothing left to probe for.

**Until you complete it, anyone who can reach the installation can create the first owner.**
`manager:doctor` warns while it is open. Either complete setup immediately or keep the installation
unreachable until you have.

Set up two-factor authentication straight afterwards. Manager will prompt you.

## Adding a site

1. Create the site in Manager, recording the domain you expect it to pair from.
2. Copy the enrolment code. It is shown **once** and expires in fifteen minutes.
3. On the Craft installation:

   ```bash
   composer require coysh-digital/craft-manager-connector
   php craft plugin/install manager-connector
   php craft manager-connector/pair mgr_enrol_...
   ```

4. Add the schedule:

   ```cron
   */5 * * * *  cd /path/to/site && php craft manager-connector/heartbeat
   0   * * * *  cd /path/to/site && php craft manager-connector/report
   ```

If the connector pairs from a host that differs from the one you recorded, pairing is held and
nothing is reported until you confirm it. That is deliberate: it is the check that catches a request
coming from somewhere you did not expect.

## Using managed services

Nothing here assumes Postgres and Redis are local. Point `DB_HOST` and `REDIS_HOST` at the managed
equivalents and delete those two services from `compose.yaml`. Restrict network access to the
database so only Manager can reach it.

## Least-privilege database role

The audit log is protected by a trigger that holds even against the table owner. As defence in
depth, connect as a role that cannot rewrite it at all:

```sql
CREATE ROLE manager_app LOGIN PASSWORD 'a-strong-password';
GRANT CONNECT ON DATABASE manager TO manager_app;
GRANT USAGE ON SCHEMA public TO manager_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO manager_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO manager_app;

-- The point of the exercise.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_events FROM manager_app;
```

Migrations need a more privileged role; run them separately. `manager:doctor` warns if Manager is
connecting as a superuser, because a superuser bypasses privilege checks entirely.

## Ports

| Direction | Purpose |
|---|---|
| Inbound 443 | Browsers, and connectors reporting in |
| Outbound 443 | Release and advisory checks |
| Internal 5432, 6379 | Postgres and Redis. Never expose these |

Connectors never receive an inbound connection, so managed sites need no inbound firewall rule at
all.
