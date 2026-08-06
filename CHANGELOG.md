# Changelog

What changed, and what you have to do about it.

Entries are written for somebody about to upgrade a running installation. Anything needing manual
action is under **Before you upgrade** - that section is the one to read, and `docs/upgrade.md` points
here for exactly that reason.

## 1.1.0 — 2026-08-06

Mostly a security release, with a set of things Manager could not see about itself now visible.

**Read *Before you upgrade* first.** One of the changes below will stop a container that started
yesterday from starting today, and that is deliberate - the configuration it refuses is the one the
old install page produced.

### Before you upgrade

**The container now refuses to boot on three settings, unconditionally.** Following
`docs/install.md` exactly used to produce a production control plane running with `APP_DEBUG` on,
because the entrypoint's checks were conditional on `APP_ENV=production` and the shipped
`.env.example` said `local`. The setting that made the configuration dangerous was the setting that
switched off the guard against it.

Check your `.env` before deploying. The image now refuses to start if:

- `DB_PASSWORD` is still the default;
- `APP_DEBUG` is true;
- `APP_ENV` is set to anything other than `production`.

`.env.example` ships `APP_ENV=production` and `APP_DEBUG=false`. Turn debug on deliberately for local
work; ddev serves development and has its own container and entrypoint. The third refusal is not a
security check - `Model::preventLazyLoading()` is enabled outside production, so a lazy load that is
merely inefficient in development throws here, and nothing in the resulting stack trace points at
`APP_ENV`.

**Backups taken before this release will still never be pruned.** `expires_at` is fixed at storage
time by design, so the retention fix below applies to future backups only. If you had set a policy
with no daily window - "no daily, four weeks, twelve months" - every artifact you hold has a null
expiry and is not eligible for pruning, and nothing in this release re-dates them. A command to
recompute them deliberately is worth having and is not here. Until then that is disk you have to
reclaim by hand.

**If you have ever run `php artisan db:seed`, delete the account it made.** The stock Laravel seeder
created `test@example.com` through `UserFactory`, whose default password is the string `password` —
on a control plane holding the keys to every managed installation. It also permanently closed
first-run setup, which returns 404 as soon as any user exists. The seeder now creates nothing, but it
cannot clean up after the version that did.

**Signing out one device now signs out every remembered device.** There is one `remember_token` per
user rather than one per device, so revoking a session rotates it for the account. The screen and the
flash message both say so. Tell anyone who uses the sessions list.

**Somebody who already belongs to another organisation can no longer be invited.** An account reaches
exactly one organisation and there is no switcher, so this was previously accepted, listed on the
team screen as having access, and silently ineffective. It is now refused with a message. This is a
product limit rather than a rule, and `SecondOrganisationRefused` names it so the two places to
revisit are findable when a switcher exists.

**Self-hosted installations lose the email catalogue screen and get nothing back.** It answered an
operator's question on a tab strip belonging to a customer, and it moves to the hosting layer's
back-office, which is the only place the wording can be edited from. The registry stays and
`EmailCatalogueTest` still fails the build on a notification class added without an entry.

### Added

- **Manager can see its own failures.** Three things it could not: `failed_jobs` had been written to
  since the first migration and nothing ever read it; the stalled-queue check answered only for
  `database` while `.env.example` ships `QUEUE_CONNECTION=redis`, so on a stock installation a
  stopped worker had no symptom at all; and `manager:audit:verify` is scheduled, so the one run most
  likely to find a broken audit chain - the unattended nightly one - reported it to nobody. Failed
  jobs warn rather than fail, because one transient error is not a reason to pull an instance out of
  rotation. A broken chain logs at `critical` with the problems included, because that is evidence
  history has been rewritten rather than a job to retry. Nothing is logged when the chains are
  intact.
- **The site's public key is shown in full, with the command to use it.** Manager signs nothing about
  where a backup came from; the site does, and `manager-restore verify --site-key` is how somebody
  checks that signature with no Manager installation, no network and no trust in us. The screen
  printed the last six characters, so the one check the zero-knowledge story rests on had no
  obtainable input. Shown to every member, not administrators only - the person holding the recovery
  key at three in the morning is not necessarily the person who can administer the site. Nothing is
  shown for a site that has never paired. `docs/restoring.md` says to copy it and keep it beside the
  recovery key, because somebody reading that page because Manager is gone cannot open this screen
  either.
- **Fleet screen: Backup and Reporting columns.** When could I last restore this, and has it been
  talking to us. Called Reporting rather than Uptime on purpose, and there is a test pinning the
  word: Manager never calls out to a site, so this measures whether the connector spoke to us, and a
  column headed Uptime would be the only place in the product making the stronger claim. Backup shows
  the last *stored* artifact - a refused backup leaves no artifact row at all - with any failure
  since riding alongside rather than replacing it. Neither column answers when it has nothing to
  answer with, and the test carries a query-count budget so neither can become a query per row.
- **Editable email copy**, as a mechanism with no screen in this edition. An email whose wording is
  genuinely editorial carries an `EmailCopyTemplate`; reverting is a delete, so there is no third
  state. Installation-scoped, because what an invitation says is a property of the installation
  sending it. Three emails are deliberately excluded and a test pins each: the monitoring alerts go
  out as plain text, because an HTML mail about a security finding is a phishing template somebody
  has been trained to click. No override can reach a link, an expiry sentence stating a number the
  code enforces, or the closing "if you were not expecting this" paragraph.

### Security

- **Password reset links could be generated on an attacker's hostname.** Laravel derives the host of
  a generated URL from the `Host` header unless told otherwise. Forcing the scheme was already here
  and closed half the problem; the host is the half an attacker controls. A request carrying
  `Host: attacker.example` produced a reset link on that host, which Manager then emailed to the
  account being taken over - and the recipient has every reason to trust it, because they asked for
  it. `forceRootUrl` fixes it where URLs are generated, so it holds for `route()`, `url()` and every
  signed URL, in a queued job and a console command as much as in a request. `TrustHosts` was
  considered and deliberately not added: it refuses the request outright, and `/up` and `/ready` exist
  to be probed by an orchestrator on a container address.
- **A TOTP code was reusable for about ninety seconds.** Valid for its own 30-second step and one
  either side, with nothing recording that a code had been spent - so within the window it simply
  worked again, shoulder-surfed or read off a lock-screen notification. The second factor stopped
  being something you have and became something briefly observable. Recovery codes were already
  single-use; the other half was not. The last accepted step is now recorded and google2fa starts its
  search past it, so a spent code is never a candidate rather than being rejected on inspection.
- **Login throttling bounded neither axis on its own.** The limiter was keyed on address and source
  together, which leaves both ordinary attacks unbounded: spraying one password across many addresses
  from one source got a fresh bucket per address, and stuffing one address from many sources got a
  fresh bucket per source. Three buckets now, the composite keeping its original job as the tightest.
  A successful sign-in clears the composite and the account buckets and never the source - somebody
  spraying will eventually guess a password that works, and clearing the source bucket then would
  hand them a reset of the limit that exists to stop them.
- **The application set no response headers at all.** Three existed in `deploy/docker/nginx.conf`
  beside a comment claiming the application set its own, which was true of nothing - and it mattered
  most where it was least visible, since the hosted console deploys through Ploi and never reads that
  file. Now set in middleware, applied globally rather than to the web group. `X-Frame-Options` is
  `DENY`, `Referrer-Policy` is `strict-origin-when-cross-origin` because a backup download URL and an
  enrolment link both carry an identifier in the path. HSTS is sent only when `APP_URL` is already
  HTTPS, for the same reason the session cookie's secure flag is keyed on it; the duration is
  `MANAGER_HSTS_SECONDS` and preload is not offered, because sending HSTS once commits every browser
  that saw it for the whole max-age. **No Content-Security-Policy, deliberately** - a useful one is
  not a one-line addition here, and a wrong directive on a live console fails as a blank screen
  rather than a warning. It is recorded as an outstanding gap rather than quietly treated as done.
- **Signing a device out left it signed in.** "Stay signed in on this device" issues a recaller
  cookie checked against `users.remember_token`, which has no relationship to the sessions table, so
  a device signed out here re-authenticated on its very next request - silently, and skipping the
  second factor, because a recaller login is not a fresh login. That matters more here than on most
  products: revoking a session is the only control Manager offers for a lost laptop or a contractor
  who has left. A revocation matching no row now rotates nothing, so nobody can sign all their own
  devices out by guessing an identifier.
- **The documented install produced an unsafe configuration**, and `manager:doctor` reported a blank
  `MANAGER_TRUSTED_PROXIES` as a clean pass. Blank is safe against forgery and is not safe for rate
  limiting: every caller then appears to come from the proxy, so the per-network connector limit and
  the pairing limit collapse into one bucket that any unauthenticated caller can exhaust, and the
  audit log records the proxy as the source of everything. Warned rather than failed - an
  installation with no proxy is correctly configured this way - and keyed on the canonical URL being
  HTTPS, which is what a proxy in front looks like from a console command with no request to inspect.
- **The stock seeder created a known-password account and closed first-run setup.** See *Before you
  upgrade*. `tests/Invariants/SeederTest.php` runs the seeder and asserts no user exists afterwards,
  so a user created indirectly - through another seeder, a factory in a callback, an observer - is
  caught the same way.

### Fixed

- **A retention policy with no daily window kept everything for ever**, while the screen read back
  the policy it thought it had set. `expiryFor()` computed expiry from the daily window alone and
  returned null when it was zero - and zero is a value the form explicitly offers, described on
  screen as "no window of this kind" rather than "keep indefinitely". Nothing with a null expiry is
  ever eligible for pruning, so the weekly and monthly windows were computed on every sweep and could
  never apply to anything. Silent, permanent accumulation of customer database dumps, on exactly the
  sites whose operator had gone to the trouble of setting a policy. Expiry now comes from the widest
  of the three windows and is null only when all three are zero.
- **Storage was admitted on one number and reported on another.** Admission control measured
  `artifact_bytes`, the whole file settled to its real size; every meter summed `ciphertext_bytes`,
  which is the encrypted stream without its envelope, is declared by the connector, and is never
  compared to anything. A connector under-declaring it would have had its storage counted as almost
  nothing while the disk filled, and self-hosted operators saw a used-storage figure on two screens
  that did not match the limit being enforced against them. Both screens now call
  `expectedUploadBytes()`; the quota check uses the same rule in SQL, because it runs on the upload
  path and must not hydrate an organisation's entire artifact history to add up a column. The v1
  fallback is kept: a v1 artifact is a bare stream with no envelope, so its ciphertext is the whole
  file.
- **Inbound connector payloads were not actually being validated.** The lockfile pinned
  `manager-protocol` 1.6.0, whose `SchemaValidator` accepted an empty JSON object against every
  published schema however many fields it declared required. Manager validates every inbound payload
  with it. Confirmed against the installed package rather than assumed: with 1.7.0 in `vendor/`, `{}`
  is now refused by `inventory.v1`, `system.v2`, `updates.v2`, `logins.v1` and `backup.v3`; on 1.6.0
  all five returned no errors. `^1.6` already admitted 1.7.0, so the diff is a single lockfile line.
- **The licence was stated four ways and one disagreed.** `composer.json` said `proprietary` on a
  public repository shipping the GNU AGPL text in `LICENSE`, explaining it in `LICENSE.md`, and
  describing itself in `README.md` as free software. It is now `AGPL-3.0-or-later`. Not a formatting
  detail: it is what Packagist, GitHub and every automated licence scanner read, none of which open
  `LICENSE` to check, and "proprietary" is the answer that stops a prospective self-hoster.
- A failed SBOM step said nothing about why; the compiled bundle was stale for the fleet columns; and
  `CanonicalUrlTest` restated what `AppServiceProvider::boot()` does instead of re-running it, so it
  passed against a local `.env` and failed in CI. The production code was right throughout - a test
  that reimplements the thing it is testing asserts its own reimplementation.

### Migrations

```
2026_08_06_000100_add_totp_replay_marker_to_users
2026_08_08_000100_create_email_copy_overrides_table
```

Both safe to run on a live installation. The first adds one nullable column and there is nothing to
backfill - a step from before the column existed was never recorded, so the honest starting position
is that the next code is the first one counted.

### Also

- Dependency advisories are scanned on a schedule rather than only when somebody opens a pull
  request, for both ecosystems. An advisory is published when it is published, not when somebody next
  happens to touch the repository; `npm audit` is there because its absence once let a high-severity
  advisory sit here, through an older Vite in the documentation toolchain.

### Requires

Unchanged from 1.0.0 except where noted.

- **PHP 8.3 or later**, PostgreSQL 15+ and Redis 7+. The Docker image ships PHP 8.4.
- **Manager Connector 1.12.2** on managed sites. 1.12.1 and older keep working; 1.12.2 is the first
  connector that cleans up after a failed encryption and refuses to follow a redirect on every
  upload path, and it is worth taking for that alone.
- **`manager-protocol` ^1.6**, which is what `composer.json` requires. The committed lockfile now
  resolves 1.7.0 and you want it - see the schema validation entry above.
- **`manager-restore` 1.1.0 or later** on the machine where you keep your recovery key. 1.1.1 changed
  no code and corrected the install instructions.

## 1.0.0 — 2026-08-05

The first release.

If you are installing Manager for the first time, there is nothing here you need to act on: the
**Before you upgrade** notes below describe moving an installation from an earlier state, and a fresh
install starts in the later one. Follow [docs/install.md](docs/install.md) and skip to **Requires** at
the end for the versions to pair against.

They do apply to anyone who has been running from a clone of `main` before this tag. That is a real
installation with real data, and the recovery-key change below will stop its backups until a key is
registered.

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

- **PHP 8.3 or later**, PostgreSQL 15+ and Redis 7+. The Docker image ships PHP 8.4.
- **Manager Connector 1.12.1** on managed sites, for the backup format described above. Older
  connectors keep working for everything else and refuse backups rather than producing readable ones.
- **`manager-protocol` ^1.6**, which is what `composer.json` requires and what the connector requires
  too — both sides of the wire resolve against the same package.
- **`manager-restore` 1.1.0** on the machine where you keep your recovery key. It is the only thing
  that can open a backup, and it is installed separately on purpose: it must keep working if this
  platform does not.

---

Older history predates this file. `git log` is the record for anything before it.
