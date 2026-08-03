# Backups

Manager for Craft can ask a Craft site for a copy of its database, encrypted so that only you can
open it.

This page covers how it works, what the states mean, how retention decides what to keep, and where
the guarantees stop. If you have not set up a recovery key yet, start at [Recovery
keys](/recovery-keys) - nothing here works without one.

::: info Backups *of* Manager for Craft
This page is about backups Manager takes of your Craft sites. Backing up Manager's own database is a
different job and lives in [Platform backups](/backup).
:::

## Switching it on

Three things, in order:

1. **A recovery key**, active, on the organisation. [Recovery keys](/recovery-keys).
2. **The fingerprint pinned** in `config/manager-connector.php` on each site. Same page. Do not skip
   it. Most sites do not have that file - nothing creates it - so this usually means writing it.
3. **The `backups:create` permission**, granted per site.

That third one is not a checkbox. On the site's Capabilities tab you will be asked to confirm your
password, type the site's name, and give a reason that gets recorded verbatim.

The friction is deliberate. Every other permission lets Manager for Craft read version numbers off a
site. This one lets it ask for the whole database.

## What happens when a backup runs

On the Craft server:

1. **Check the keys first.** The site fingerprints every recovery key Manager for Craft offered and
   compares them against your pinned list. Anything unexpected and it stops here, before anything is
   written to disk.
2. **Dump the database** using Craft's own backup, with the connection and credentials the site
   already has. No `mysqldump` composed by the plugin, no shell, nothing Manager for Craft
   influenced. Manager asked for a backup; it did not say how to make one.
3. **Encrypt it** as a chunked authenticated stream, so nothing large is ever held in memory and a
   truncated file is detected as truncated rather than read as a shorter backup.
4. **Seal the encryption key** to each of your recovery keys. The site holds only public halves, so
   it cannot reopen what it just sealed - a site compromised in September cannot recover the keys to
   backups it uploaded in June.
5. **Write a manifest and sign it** with the site's own key, then wrap it around the encrypted data.
   The result describes itself: everything needed to decrypt it is inside it.
6. **Tell Manager for Craft what is coming** - sizes, checksums, the manifest. Metadata only.
7. **Upload the bytes.**
8. **Delete both temporary files**, whether it worked or not.

On Manager for Craft's side, it checks the manifest hashes to what was declared, that it was signed
by that site's key, that each recipient's fingerprint really belongs to its key, and that the set of
keys matches exactly what that job was issued for. Then it checks the uploaded bytes against the
declared checksum before committing anything.

What it cannot check is whether the sealed keys actually contain the encryption key - that would
require opening them. That is the definition of zero-knowledge rather than a gap, and the honest
control for it is you running `manager-restore verify` occasionally.

## Scheduling

Set it per site, on the site's **Settings** tab: off, daily or weekly, at an hour you pick. A weekly
schedule also takes a day of the week.

Per site rather than per organisation, because a busy shop and a brochure site do not warrant the
same cadence, and one policy across a fleet means picking the more expensive one.

Times are in your organisation's time zone (**Settings → Backup retention**), so "03:00" means the
quiet hour where the site is rather than where your server is.

The scheduler refuses rather than queues when:

- the organisation has no active recovery key - there would be nothing to encrypt to, and a nightly
  failed job whose real cause is an unset key is worse than a clear refusal;
- the site has no live connector, or has not been granted `backups:create`;
- a backup for that site is already outstanding. A dump that takes longer than an hour must not have
  another queued behind it. Two concurrent dumps of one database is how a backup schedule becomes an
  outage.

A schedule decides **when** to ask and nothing else. There is no field on that form naming a
destination, a recipient or a format - those come from your recovery keys and from the site's own
config file, and a timing screen that could change who reads your backups would be a bad idea.

## Backup states

| State | What it means |
|---|---|
| `pending` | The site said what it was about to send. The bytes have not arrived. |
| `uploaded` | The bytes are somewhere, but Manager for Craft has not confirmed them yet. |
| `stored` | Present, and verified against the checksum the site declared. |
| `failed` | The upload did not finish, or did not match what was declared. |
| `expired` | Past retention, waiting on the sweep. |
| `deleted` | Removed. The row survives so the audit trail still shows it existed. |

`uploaded` only shows up on the direct-to-storage path. When bytes come through Manager for Craft
they are hashed as they stream past, so storing and verifying happen in the same instant. When they
go straight to object storage Manager for Craft did not witness them, so it asks the storage service
- and until something other than the site agrees, calling it `stored` would be claiming a check
nobody ran.

There is also a **stage** shown while a backup is in progress. Treat it as a hint: it is what the
site last said it was doing, it can be stale, and nothing in Manager for Craft decides anything
based on it.

### What "stored" does and does not mean

It means the bytes arrived, they are the bytes that were declared, and they have not changed since.

It does **not** mean the backup restores. Nothing Manager for Craft can do from here would establish
that. The only thing that proves a backup restores is loading it into a database, which is why
[Restoring](/restoring) asks you to schedule exactly that.

## Where backups are kept

**Self-hosted, local disk.** The default. Bytes stream through Manager for Craft and land on the
disk you configured. Fine for most people.

**Self-hosted, your own S3.** Point `MANAGER_BACKUP_DRIVER=s3` at any S3-compatible service. Bytes
still stream through Manager for Craft on the way. `manager:doctor` checks the endpoint you gave it
and warns if it resolves somewhere private - an endpoint pointing at `169.254.169.254` would turn
every upload into a request for your cloud instance credentials, which is a mistake worth catching
early.

**Cloud.** Sites upload straight into storage using a short-lived, single-use permission, so a
gigabyte of ciphertext no longer passes through a web server for no reason. That permission is bound
to the file's checksum, cannot overwrite anything, and - importantly - names no host. The site
builds the URL from a hostname in its own config file, so the most a compromised Manager for Craft
could do is vary the path inside a bucket you already approved.

Whatever the route, the file is encrypted before it leaves the Craft server, so none of these
destinations can read it.

### Generic S3-compatible services

Not all of them are equivalent, and the differences matter:

| Feature | Why you want it | If it's missing |
|---|---|---|
| Object checksums | Manager for Craft verifies a direct upload by asking storage | Direct upload falls back to going through Manager |
| Conditional writes | A replayed upload cannot overwrite a verified backup | Overwrite protection is weaker |
| Versioning | An accidental delete is recoverable | It isn't |
| Object Lock | Ransomware cannot delete your backups | It can, if it reaches your credentials |
| Server-side encryption | Defence in depth at rest | You still have the artifact's own encryption |

Manager for Craft will not pretend a service has these. If a storage service answers a confirmation
request without a checksum, that is treated as a failure rather than as close enough, because
"stored" is the word we use to mean verified.

## Retention

Retention is by **period**, not by count:

- Everything from the last *N* days.
- Then one a week, for *N* weeks.
- Then one a month, for *N* months.

Defaults are 30 days, 4 weeks, 12 months. Change them in **Settings → Backup retention**, owner
only. The screen reads the policy back as a sentence - "every backup from the last 30 days, then one
a week for 4 weeks, then one a month for 12 months" - so you can check it against what you meant
rather than reading three numbers.

The same form sets your organisation's **time zone**, which is what a site's backup schedule reads.
That is why "03:00" means the quiet hour where your sites are rather than where your server is.

### Why not "keep the last 30"

Because it fails in the worst possible direction, and it is worth understanding before you ask for
it.

Picture a site that starts producing bad backups - a plugin broke the dump, a table got corrupted,
whatever. It produces them on a schedule. Under "keep the last 30", each bad backup pushes out the
oldest good one. Thirty nights later the only copies you have are thirty copies of the problem. The
count never dropped below thirty. Nothing looked wrong. The dashboard was green the entire time.

With periods, the old backup is the representative of its month and the recent run cannot displace
it - they are not competing for the same slot. The oldest copy you hold is genuinely old, from
before whatever started going wrong.

Two more rules:

- **Nothing is deleted for being surplus inside a window.** Five backups on one day all stay while
  that day is inside the daily window. Retention answers "how far back", not "how many".
- **You are never left with nothing.** If every window is empty - a site that stopped reporting six
  months ago - the newest backup survives regardless. Somebody whose backups stopped is exactly the
  person who will need the one they have.

### When deletion actually happens

Expiry is calculated when a backup is stored, from the policy in force at that moment. Shortening
retention today does not retroactively re-date backups you already have. You are saying what should
happen to future ones; deciding it applies to the past is not something a settings form should
assume.

The sweep runs nightly. So a backup may show as expired for a few hours before it goes. If your
storage has an immutability window, deletion may take longer still - Manager for Craft will show the
actual date rather than claiming it happened immediately.

## Quotas and limits

- **Per artifact**: **no ceiling by default.** Set `MANAGER_BACKUP_MAX_BYTES`, in bytes, if you want
  one, and the refusal then names both the artifact's size and this setting.

  It used to default to 2 GB, inherited from the wire contract: until `manager-protocol` 1.5.0
  `backup.v2` carried its own 2 GB maximum, so this setting could only refuse more, never permit it.
  When the protocol stopped enforcing a maximum the default stayed, and it went on refusing real
  backups on sites whose databases had simply grown — a wall nobody had chosen and few people knew
  was there. A limit you set is a policy; a limit you inherited is an accident.

  With no ceiling set, the real limit is whatever your reverse proxy and PHP will carry. That is
  worth knowing because it fails badly: a proxy refuses the upload before Manager for Craft sees it,
  so there is no correlation ID and nothing in the log. See
  [Reverse proxy and TLS](/reverse-proxy) for the settings and for how to test it.
  `manager:doctor` reports PHP's limit under **Upload path ceiling** and fails when it is below a
  ceiling you configured; it cannot see the proxy.

  Above 5 GB an artifact cannot be uploaded in one request and has to arrive in parts, which requires
  an edition that issues presigned uploads. A self-hosted installation streams every byte through the
  application instead, so `manager:doctor` warns if the ceiling is raised past that point. Sites
  uploading straight to their own storage are unaffected.
- **Per organisation**: unset by default on self-hosted. Set `MANAGER_BACKUP_QUOTA_BYTES` if you
  want one - a single site filling a volume takes down backups for every site sharing it.
- **On the Craft side**: `maxBackupMegabytes`, default 2048. A safety valve so an unexpectedly huge
  dump fails early with a clear message rather than late with a full disk.

From connector 1.11.0 the per-artifact ceiling is sent to sites on the signed claim response, so a
site whose database is larger than it will refuse **before** taking a dump rather than after dumping,
encrypting and offering one. With no ceiling set the platform sends zero, which sites read as "no
limit" and skip the check — the same as an older platform that sends nothing at all.

## What Manager for Craft is never told

The declaration is metadata. No database credentials, no connection string, no table names, no row
counts, no schema, no sample of the contents.

The progress report is a job identifier, one word from a fixed list, and a timestamp. Not a file
path, not a running byte count, not the table currently being written - all of which describe your
data under a heading that looks harmless.

## Deleting a backup

Owners only, with a reason, and it is recorded. The row survives so the audit trail still shows the
backup existed and who removed it.

Deleting also destroys the stored copies of the encryption key before it touches storage. So if the
storage deletion half-fails, whatever bytes remain are bytes nobody holds a key for.

## Where the guarantees stop

Worth stating plainly, because the temptation is to round these up.

**We cannot read your backups.** True, for backups taken since you added a recovery key, provided
you pinned the fingerprint. That is the whole design.

**Anyone who steals our server cannot read them either.** True, and for the same reason.

**A compromised Craft site cannot read old backups.** True. It never held a key it could reopen. It
*can* stop future backups happening, and it can read the database directly - but it does not need a
backup for that.

**Without pinning, a compromised Manager for Craft could name its own key.** True, and this is the
one people miss. Pinning is not an advanced option.

**Backups taken before recovery keys existed are readable by Manager for Craft.** They were sealed
to Manager's key at the time. Adding a recovery key does not change that retroactively, and the
Backups screen labels them.

**Encryption does not protect you from losing your key.** Losing it means losing the backups. There
is no recovery path and no support process.

**"Stored" does not mean restorable.** Only a restore test establishes that.

## Related

- [Recovery keys](/recovery-keys) - set-up, rotation, and the pinning step
- [Restoring a backup](/restoring) - how to get your data back out
- [The Craft plugin](/craft-plugin) - what happens on the site's side
- [Security](/security) - the threat model in full
