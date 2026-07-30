# Backups

Two different things are called backups here, and conflating them causes trouble.

**Platform backups** are backups of Manager's own database — your responsibility on a self-hosted
installation, and covered below.

**Site backups** are backups Manager takes *of managed Craft installations*, governed by the
`backups:create` capability. Those have their own page now — see [Backups](/backups) — because they
work very differently: a site encrypts its own database to keys you hold, and Manager stores something
it cannot open. This page is only about Manager's own data.

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

- `APP_KEY` — without it, TOTP secrets are unreadable, and so is every stored site backup.
- `MANAGER_SIGNING_SECRET_KEY` — without it, every site has to be re-paired.
- `MANAGER_BACKUP_SECRET_KEY` — without it, every stored site backup is permanently unreadable.

Note that the platform database dump does **not** contain site backups. Those live in object storage
and have to be backed up, or deliberately not backed up, separately.

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

# Site backups

Backups of managed Craft installations have their own page: **[Backups](/backups)**.

They changed substantially. They used to be encrypted to a keypair *this platform* held, which meant
anybody with the platform's backup secret key and its storage could read every backup it held — and
the documentation said so.

They are now encrypted to keys the organisation generates on its own machines. This platform stores,
verifies, serves and deletes something it cannot open, and has nowhere in its schema to put a recovery
private key.

That is a big enough change that keeping a summary here would eventually contradict the real page, so
this section is a signpost rather than a second copy:

- **[Backups](/backups)** — how they work, the states, retention, and where the guarantees stop
- **[Recovery keys](/recovery-keys)** — generating one, proving it, and the pinning step people skip
- **[Restoring a backup](/restoring)** — getting your data back out with `manager-restore`

::: warning Backups taken before recovery keys existed
Those were sealed to this platform's own key and remain readable by it. Adding a recovery key does not
change that retroactively. They are labelled as legacy on the Backups screen, and
`php artisan manager:backups:fetch` is still how you retrieve one.
:::
