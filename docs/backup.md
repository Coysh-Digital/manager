# Backups

Two different things are called backups here, and conflating them causes trouble.

**Platform backups** are backups of Manager's own database — your responsibility on a self-hosted
installation, and covered below.

**Site backups** are backups Manager takes *of managed Craft installations*, governed by the
`backups:create` capability. They are covered in [Site backups](#site-backups) below.

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

Backups of managed Craft installations. Off by default, per site, and granted through a confirmation
flow rather than a switch.

## What has to be true before one can be taken

1. A backup encryption keypair exists: `php artisan manager:backups:keygen`. Without it no backup is
   attempted at all — a connector with no key to seal to refuses rather than uploading a database in
   the clear. `manager:doctor` reports this.
2. A backup destination is configured. The default is a local disk, which works but is a poor place
   for the only copy of a customer's database; see [Where artifacts are stored](#where-artifacts-are-stored).
3. The site has been granted `backups:create` from its Capabilities screen. This asks for the site's
   name typed out, an acknowledgement of what a backup contains, and a reason, all of which are
   recorded. It is never granted when a site is paired.

## What actually happens

1. Manager queues a `backup.create` job. It carries **no parameters** — in particular nothing naming
   where the artifact should go.
2. The connector claims it on its next check-in, and asks Craft to back up its own database using the
   connection the site already has. There is no shell command, and nothing Manager could influence
   about how the dump is taken.
3. The connector generates a fresh encryption key, encrypts the dump as a chunked authenticated
   stream, and seals the key to the platform's public key. It holds only the public half, so it
   cannot reopen what it sealed.
4. It declares the artifact — sizes, checksums, sealed key — then uploads the bytes to the platform
   it is paired with. The destination is the URL stored at pairing; no job payload can change it.
5. The platform hashes the bytes as they arrive and commits the artifact only if the hash matches
   what was declared and signed. Anything else is discarded.
6. The plaintext dump on the site is deleted, whether the upload succeeded or not.

## This is not end-to-end encryption

Said plainly because the phrase gets used loosely. Artifacts are encrypted before they leave the
site, which protects them in transit and at rest in storage — a stolen bucket yields nothing. But
**this platform holds the key that opens them.** Anyone with `MANAGER_BACKUP_SECRET_KEY` and access
to the artifact store can read every backup.

That is a deliberate trade: a key only the customer held would mean a backup nobody could restore
after the one person who wrote the key down left. What it means in practice is that the Manager
installation should be treated as being as sensitive as the sites it manages, because in effect it is.

## Where artifacts are stored

Configured by `MANAGER_BACKUP_DISK` and the `backups` disk in `config/filesystems.php`. The default
is a local directory outside the web root and outside anything served over HTTP.

For anything beyond a single site, point it at an S3-compatible bucket:

```dotenv
MANAGER_BACKUP_DRIVER=s3
MANAGER_BACKUP_S3_BUCKET=manager-backups
MANAGER_BACKUP_S3_REGION=eu-west-2
MANAGER_BACKUP_S3_KEY=…
MANAGER_BACKUP_S3_SECRET=…
```

Use credentials scoped to that bucket and nothing else. A key with access to the backup store is a
key with access to every managed site's database.

Uploads are staged to a temporary file on the platform before being committed to storage, so the
platform needs free disk space of roughly the largest artifact you expect. Streaming straight into
the bucket would leave unverified artifacts there whenever an upload failed part way.

## Retention

Two settings per organisation, and both apply:

- `backup_retention_days` (default 30) — how long an artifact is kept.
- `backup_keep_count` (default 3) — how many are kept regardless of age.

Whichever keeps an artifact alive wins. Expiry alone would leave a site that has been quiet for a
month with nothing; a count alone would keep a departed client's database forever.

`manager:backups:prune` runs nightly from the scheduler. It deletes expired artifacts and destroys
their keys — the row survives so the audit log still shows the artifact existed, but the key does
not, so anything left in storage after a partly failed deletion is unreadable. Use `--dry-run` to see
what it would do.

An artifact's expiry is set when it is stored, from the policy in force at that moment. Changing the
retention setting affects future backups and does not retroactively re-date existing ones.

## Retrieving a backup

```bash
docker compose exec app php artisan manager:backups:fetch <identifier> /path/to/backup.sql
```

This streams the artifact, decrypts it, and verifies it against the checksum recorded when the backup
was taken. If verification fails nothing is written, because a partially written file that looks like
a backup is worse than no file at all.

There is no download button in the interface, deliberately. Decrypting a multi-gigabyte artifact
through a web request means holding a worker against a timeout it will probably lose, and possibly
leaving a truncated file that cannot be told apart from a complete one.

The decrypted file is the site's entire database in plain text, including user accounts and password
hashes. Delete it when you are done.

## Restore is not implemented

Manager will not restore a backup into a site, and this is not an oversight or a missing feature to
be added quietly in a patch release.

Restoring a production database is destructive and irreversible. Doing it safely needs a threat model
for a compromised platform issuing a restore, a confirmation flow that makes the target unmistakable,
a defined behaviour when a restore fails half way, and a tested recovery path from that state. None
of that follows from being able to take a backup, and a restore button that mostly worked would be
more dangerous than no button.

Until then, retrieve the artifact with the command above and restore it with the tooling you would
use for any other dump — `mysql` or `psql` — on a host you have chosen deliberately.

## Rotating the encryption key

Destructive, unlike rotating the signing key. Every existing artifact was encrypted to a key wrapped
for the current pair, and a new pair will not open them. `manager:backups:keygen` refuses when
artifacts exist and reports how many would be lost; `--force` overrides it.

If you need to rotate without losing history, fetch the artifacts you want to keep first, rotate, and
treat the retrieved files as ordinary encrypted-at-rest backups from that point on.

## Test a restore

An untested backup is a hypothesis. Fetch one, load it into a scratch database, and check that Craft
boots against it. Do it before you need it.
