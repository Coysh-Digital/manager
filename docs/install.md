# Installing Manager Self-Hosted

Manager for Craft is a control plane for a fleet of Craft CMS installations. It holds no
administrator password, no SSH credential and no site database password - there is nowhere in its
schema to put one - so the main thing to get right at install time is the platform's own security.

## Before you start: is self-hosting the right choice?

Self-hosted Manager for Craft is free, complete, and yours. Every monitoring, findings, jobs and
backup feature is here - there is no reduced edition and no feature held back. If you want to run
it, run it.

Be clear-eyed about what you are taking on, though, because it is a security-sensitive service
holding the keys to your clients' databases:

- **A server, kept patched.** Docker, a reverse proxy, TLS certificates that renew.
- **Postgres and Redis**, backed up and monitored. Redis failing closed means connectors stop being
  trusted, which is the correct behaviour and still an outage.
- **Two keypairs and `APP_KEY`, backed up separately from the database.** Lose the signing key and
  every site needs re-pairing. Lose the backup key and every stored backup is permanently
  unreadable, deliberately, with no recovery path.
- **The backup store.** A copy of every managed site's database, which is the most sensitive thing
  you will hold anywhere.
- **Upgrades**, on your schedule, including reading the release notes before running migrations.
- **Somebody on call**, because a monitoring system nobody watches is decoration.

That is an afternoon to install and an ongoing responsibility to run. Plenty of people want exactly
that, and this documentation is written for them.

### Or let us run it

**[Manager Cloud](https://coysh.digital/manager)** is the same core, hosted, maintained, patched and
backed up by Coysh Digital. Same connector, same protocol, same security boundaries - the difference
is that the server, the keys, the storage and the on-call rota are ours.

It is the right answer if you would rather spend your time on client sites than on this one. You can
move between the two: the connector is identical, so migrating means re-pairing your sites, not
rebuilding anything.

The rest of this document assumes you are self-hosting.

## Requirements

| | |
|---|---|
| Docker | Engine 24+ with the Compose plugin |
| PostgreSQL | 15+. Not MySQL: the audit log relies on a trigger and on privileges that are not portable |
| Redis | 7+. Backs replay protection, which **fails closed** - if Redis is unreachable, connector requests are rejected rather than accepted |
| TLS | Mandatory. Signed requests protect integrity and replay, not confidentiality |

Two CPUs and 2 GB of memory is comfortable for a few dozen sites.

## Install

```bash
git clone https://github.com/Coysh-Digital/manager.git /opt/manager
cd /opt/manager/deploy/docker

cp ../../.env.example .env
```

Edit `.env`. At minimum set `APP_KEY`, `APP_URL` and `DB_PASSWORD` - the container refuses to start
without them, and refuses to start in production on a well-known default password or with
`APP_DEBUG` on. Every variable is documented in [env.md](env.md).

Generate a key:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

## Keys

Manager for Craft needs two keypairs beyond `APP_KEY`, and they are separate on purpose: one signs
responses to connectors, the other encrypts backups. Using one keypair for both would weaken both.

Generate them **before** starting the stack, and put them in `.env` yourself:

```bash
docker compose run --rm --no-deps app php artisan manager:keys:generate --show
docker compose run --rm --no-deps app php artisan manager:backups:keygen --show
```

Each prints two lines. Add all four to `.env`.

They are printed rather than saved because the container has no writable `.env` - the environment
arrives from this file and the container's root filesystem is read-only, which is deliberate. If you
run these against a container that *does* have a writable `.env`, they write to it and say so.

Back both secret keys up with your other application secrets, and keep the backup key somewhere
other than alongside the backups themselves. Losing the signing key means re-pairing every site;
losing the backup key makes every stored backup permanently unreadable, with no recovery path. See
[backup.md](backup.md).

## Start it

```bash
docker compose up -d
docker compose exec app php artisan manager:doctor
```

## If nobody can log in

The setup route closes permanently once an account exists, and the password reset flow needs working
mail - which a fresh installation may not have. So there is a way in from the server:

```bash
docker compose exec app php artisan manager:user:password you@example.org --generate
```

It prints a strong password once. Add `--reset-second-factor` if you have also lost the
authenticator, which is a separate flag on purpose: a password reset does not remove multi-factor
authentication, and a command that did both quietly would be a way to strip it from any account.

Both are recorded in the audit log. Neither the password nor its hash is.

This grants nothing new - anybody who can run it already has the database and `APP_KEY`, and
therefore the installation. It just means you do not have to edit a password hash by hand to get
back in.

`manager:doctor` must report no failures. It checks the things that are easy to get wrong and
expensive to discover later: a wildcard trusted-proxy setting, a non-atomic replay store, missing
audit-log triggers, an insecure session cookie, a superuser database role.

Put a reverse proxy in front - see [reverse-proxy.md](reverse-proxy.md) - and only then visit
`/setup`.

## First run

`/setup` creates the organisation and its owner. It closes permanently once an account exists: the
route stops resolving, so there is nothing left to probe for.

**Until you complete it, anyone who can reach the installation can create the first owner.**
`manager:doctor` warns while it is open. Either complete setup immediately or keep the installation
unreachable until you have.

Set up two-factor authentication straight afterwards. Manager for Craft will prompt you.

## Adding a site

1. Create the site in Manager for Craft, recording the domain you expect it to pair from.
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
database so only Manager for Craft can reach it.

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

Migrations need a more privileged role; run them separately. `manager:doctor` warns if Manager for
Craft is connecting as a superuser, because a superuser bypasses privilege checks entirely.

## Ports

| Direction | Purpose |
|---|---|
| Inbound 443 | Browsers, and connectors reporting in |
| Outbound 443 | Release and advisory checks |
| Internal 5432, 6379 | Postgres and Redis. Never expose these |

Connectors never receive an inbound connection, so managed sites need no inbound firewall rule at
all.
