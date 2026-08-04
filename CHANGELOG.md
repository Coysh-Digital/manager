# Changelog

What changed, and what you have to do about it.

Entries are written for somebody about to upgrade a running installation. Anything needing manual
action is under **Before you upgrade** - that section is the one to read, and `docs/upgrade.md` points
here for exactly that reason.

## Unreleased

Nothing is tagged yet. When the first release is cut this section becomes its version.

### Backups are now encrypted to keys you hold, and this platform cannot read them

The headline change, and the one with an upgrade action.

Previously a site sealed each backup's encryption key to *this platform's* key, and this platform
opened it on arrival. That was stated honestly at the time - the connector's documentation said in as
many words that it was not end-to-end encryption - but it meant anybody holding
`MANAGER_BACKUP_SECRET_KEY` and the object store could read every backup held.

Now the key is sealed to recovery keys the organisation generates on its own machines. This platform
stores, verifies, serves and deletes something it cannot open, and there is no column in the schema
where a recovery private key could go.

#### Before you upgrade

**Backups will stop until you add a recovery key.** That is deliberate - a backup this platform could
read is the thing being removed - but it means a fleet on a nightly schedule stops backing up the
night you deploy this. Do the following first, or straight after.

1. Install the restore tool and generate a key. It runs on your machine; nothing is generated here.

   ```bash
   composer global require coysh-digital/manager-restore
   manager-restore keygen --label="Ops laptop" --out=~/keys/recovery
   ```

2. Register the `.pub` half in **Settings → Recovery keys**, then answer the challenge it gives you:

   ```bash
   manager-restore prove --key=~/keys/recovery.secret --challenge=<paste>
   ```

3. **Pin the fingerprint on every site**, in `config/manager-connector.php`:

   ```php
   'recoveryKeyFingerprints' => ['MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1'],
   ```

   This is the step that makes the rest worth anything. Without it, this platform chooses which keys
   your sites encrypt to, and a compromised installation could choose its own. See
   `docs/recovery-keys.md`.

4. **Upgrade the Craft plugin to 1.7.0** on each site. Older connectors cannot produce the new format
   and will refuse rather than quietly producing a readable backup.

5. **Keep two keys.** Losing your only one means losing every backup encrypted to it, permanently.
   There is no recovery path and no support process, by design.

#### Artifacts you already have

Untouched and still readable. They keep their old format, stay retrievable with
`php artisan manager:backups:fetch`, and are labelled as legacy on the Backups screen. Adding a
recovery key does not change them retroactively - they were readable by this platform when they were
taken, and no amount of re-sealing alters that.

`MANAGER_BACKUP_PUBLIC_KEY` and `MANAGER_BACKUP_SECRET_KEY` are now **legacy**. They exist only to
read those older artifacts. A fresh installation does not need them, and once your last legacy
artifact expires you can remove them.

### A recovery key is required before any backup, not only after the first one

The paragraph above says "Backups will stop until you add a recovery key". Until now that was only
true of organisations that already had one.

The format floor ratchets from `v1` to `v2` when the first key is activated, and the requirement was
written against the floor - so it applied to organisations that had complied with it and not to the
ones that had not. A new organisation could take a `v1` backup, sealed to *this platform's* key,
which is the arrangement the whole zero-knowledge change exists to end. Meanwhile the nightly
schedule refused those same organisations outright and the settings screen told them "No backups can
be taken yet", so three components held two rules between them and the strictest was the invisible
one.

Now: no active recovery key, no backup - manual, scheduled, or asked for by anything else. The
button is not drawn, `JobService::enqueue()` refuses regardless of caller, and a schedule cannot be
switched on until there is a key to encrypt to. Turning a schedule *off* never requires one.

**Before you upgrade** - the steps are the same as above, and `coysh-digital/manager-restore` is now
published on Packagist, so step 1 works as written. If you run a fleet with no recovery key today,
its backups stop at this deploy rather than continuing in a form we could read.

### `MANAGER_EDITION` removed

This repository is the self-hosted product. It carried an edition variable and a health check about a
managed key service, neither of which a self-hoster could use - the variable was not even in
`.env.example`, because there was nothing to set it to.

Remove `MANAGER_EDITION` from your `.env` if you have it. Nothing reads it, and nothing breaks if you
leave it.

### Retention is by period, not by count

`backup_keep_count` said "always keep the most recent N", and that rule fails in the worst direction.
A site producing bad backups produces them on a schedule: each one pushes out the oldest good copy,
until the only backups you hold are N copies of the problem. The count never drops and nothing looks
wrong.

Retention is now everything for some days, then one a week, then one a month. The oldest copy you hold
is genuinely old - from before whatever started going wrong.

Defaults are 30 days, 4 weeks, 12 months, set in **Settings → Backup retention**. `backup_keep_count`
is left in the schema and no longer read; it will be dropped in a later release.

**This can delete more than the old rule did**, on an organisation whose backups are all older than
every window. It will never leave you with nothing: if every window is empty, the newest artifact
survives regardless.

### Added

- **Scheduled backups.** Per site, daily or weekly, at an hour you choose, in your organisation's
  time zone. Refuses rather than queues when there is no recovery key to encrypt to - a nightly failed
  job whose real cause is an unset key is worse than a clear refusal.
- **TLS certificate monitoring.** Daily, with findings at 30 days, 7 days and expired. The one thing
  this platform goes and looks at itself, because a connector cannot see the certificate a visitor
  validates - TLS terminates at the edge. Guarded against loopback, private and metadata addresses.
- **Recovery key management**, with mandatory proof of possession. Almost nothing can be checked about
  a submitted public key, so the only meaningful test is a decryption - which also turns enrolling a
  key into a restore rehearsal.
- **A per-artifact timeline** (`backup_events`), separate from the audit log. Observations go there;
  decisions and accesses stay in the hash-chained log.
- **`manager:backups:schedule`** and **`manager:certificates:check`**, both scheduled.
- **Documentation.** `docs/` is now a VitePress site with a route through it, starting at
  `docs/getting-started.md`.

### Fixed

- **`MANAGER_BACKUP_DRIVER=s3` did not work.** It has been documented as supported for a while, but
  `league/flysystem-aws-s3-v3` was never a dependency, so pointing the backup disk at S3 threw
  driver-not-supported on the first upload. An operator would have found out when their first backup
  failed. Now a dependency, with tests covering AWS and custom endpoints.
- **A private package reference reached this public repository.** `composer.json` briefly declared a
  dependency on a private package and a path repository to a directory that is not here, which also
  made the repo impossible to `composer install`. No private source code was ever committed - verified
  across the full history. Removed, and `tests/Invariants/NoCloudCodeTest.php` now checks for it.
- Retention tests depended on the day of the week they ran, passing on a Thursday and failing on the
  Friday. The clock is frozen and the dates are written out.

### Migrations

Five, all additive. None drops a column or rewrites a row.

```
2026_08_05_000100_create_recovery_keys_table
2026_08_05_000200_add_backup_v2_tables
2026_08_05_000300_add_period_retention_to_organisations
2026_08_05_000400_add_backup_schedule_and_certificates
```

Safe to run on a live installation. `docs/rollback.md` covers going back; the recovery keys table is
the one that would need attention, since artifacts taken after the upgrade reference it.

### Requires

- **Manager Connector 1.7.0** on managed sites, for the new backup format. Older connectors keep
  working for everything else and refuse backups rather than producing readable ones.
- **`manager-protocol` 1.2.0.**
- **`manager-restore` 1.0.0** on the machine where you keep your recovery key.

---

Older history predates this file. `git log` is the record for anything before it.
