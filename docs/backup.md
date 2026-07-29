# Backups

Two different things are called backups here, and conflating them causes trouble.

**Platform backups** are backups of Manager's own database — your responsibility on a self-hosted
installation, and covered below.

**Site backups** are backups Manager takes *of managed Craft installations*. Those arrive in Phase 3
and are governed by the `backups:create` capability, which is off by default and granted per site.

## What is in the platform database

Organisations and users, sites and their connectors' **public** keys, capability grants and their
history, connector telemetry, and the audit log.

What is not in it: any managed site's administrator password, SSH credential or database password.
There is nowhere in the schema to put one, and there is a test asserting that stays true. A stolen
copy of this database does not let anyone impersonate a site — only public keys are stored.

It does contain user password hashes, TOTP secrets encrypted with `APP_KEY`, and recovery code
hashes. Treat a dump as sensitive, and note that **`APP_KEY` is what makes the encrypted values
readable**: back it up separately from the database, or a restore yields a database whose second
factors cannot be decrypted.

## Backing up

```bash
docker compose exec -T postgres \
    pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom \
    > "manager-$(date -u +%Y%m%dT%H%M%SZ).dump"
```

Also keep, somewhere other than beside the dump:

- `APP_KEY` — without it, TOTP secrets are unreadable.
- `MANAGER_SIGNING_SECRET_KEY` — without it, every site has to be re-paired.

## Restoring

```bash
docker compose stop app worker scheduler

docker compose exec -T postgres \
    pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists < manager-….dump

docker compose up -d
docker compose exec app php artisan manager:doctor
docker compose exec app php artisan manager:audit:verify
```

Verify the chain afterwards, every time. A restore rewinds the audit log to the moment the dump was
taken, and a rewind is otherwise indistinguishable from somebody deliberately truncating history.
The chain will verify as intact but shorter — record when and why.

## Retention

Keep enough history to survive a problem you notice late. Encrypt backups at rest, restrict who can
read them, and delete them on a schedule rather than accumulating them indefinitely.

**Test a restore.** An untested backup is a hypothesis.
