# The Craft plugin

Everything Manager for Craft knows about a site, that site told it. The Manager Connector is the
plugin that does the telling.

It is worth reading before you install it. That is what this page is for.

## What it can't do

Not "won't" - can't. There is no code in the plugin that could:

| It cannot | Why that matters |
|---|---|
| Install or run updates | Manager for Craft reports that an update exists. Applying it is your deployment, on your schedule. |
| Change any Craft setting | Nothing writes to project config or the settings tables. |
| Run console commands, PHP or SQL | There is no runner, no `eval`, no query interface. A build check fails if one appears. |
| Read or write your files | No file browser, no path parameter anywhere. |
| Restore a backup | The plugin has no restore code at all. |
| Accept an inbound request | It registers zero URL rules. Nothing listens. |

The plugin performs a fixed set of named operations and refuses anything it does not already
implement - including things Manager for Craft might ask for. If a future Manager sent a job type
this version does not know, the site declines and says so.

## Installing

```bash
composer require coysh-digital/craft-manager-connector
php craft plugin/install manager-connector
```

Requires Craft 5, PHP 8.2+, and the `sodium` extension (which you almost certainly already have -
Craft uses it too).

## Configuring

Settings live in `config/manager-connector.php`, in your version control, rather than in the Craft
database. That means pointing a site at a different Manager for Craft takes a deployment - which is
the right amount of friction for a setting that decides who your site reports to.

```php
<?php

return [
    // Where this site reports. HTTPS only; there is no override.
    'platformUrl' => 'https://manager.example.com',

    // The recovery keys this site will encrypt backups to, and no others.
    // See below - this is the important one.
    'recoveryKeyFingerprints' => [
        'MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1',
    ],

    // Once your whole fleet is pinned, make an unpinned site refuse rather than fall back.
    'requirePinnedRecoveryKeys' => false,

    // Run the schedule off ordinary web traffic, for hosting with no cron.
    'webTrigger' => true,

    // Seconds to wait for Manager. Short: a slow Manager must never become a slow website.
    'timeout' => 10,

    // Seconds to wait while uploading a backup. Longer, because it's measured in megabytes.
    'uploadTimeout' => 900,

    // Largest database this site will attempt to back up, in MB.
    'maxBackupMegabytes' => 2048,

    // Time the site's own responses for the runtime report.
    'sampleResponseTimes' => true,

    // Seconds to spend measuring asset volumes before giving up on the rest.
    'storageWalkSeconds' => 5,
];
```

Three of these - `recoveryKeyFingerprints`, `requirePinnedRecoveryKeys` and `backupUploadHost` - are
**deliberately not editable from the Craft control panel**, and there is a build check that fails if
one ever appears there. Somebody who hijacked a CP session and could re-pin the site would undo the
only control that actually protects your backups.

## Pairing

Manager for Craft generates a single-use enrolment code, good for fifteen minutes.

```bash
php craft manager-connector/pair mgr_enrol_xxxxxxxxxxxx
```

Or from the Craft control panel, under **Utilities → Manager Connector**, if you would rather not
use the command line. Either way needs the `utility:manager-connector` permission.

What happens:

1. The site generates an Ed25519 keypair. The private half is encrypted with Craft's own security
   key and stored in the site's database. It never leaves the server.
2. It sends the public half, the enrolment code and its hostname to Manager for Craft, over HTTPS.
3. Manager checks the code, records the public key, and signs its reply.
4. The site verifies that signature against the key Manager for Craft just offered, and against the
   nonce it sent. Trust on first use, but verified - the reply has to be signed by the key it is
   presenting.

From then on, every request the site makes is signed with that key. Manager for Craft only ever
holds the public half, so a Manager database compromise does not let anybody impersonate your sites.

If the hostname does not match what was entered in Manager for Craft, pairing is **held for
confirmation** rather than accepted. That is not an error - it is the expected result when a site is
behind a CDN under a different name, and it means a human looks at it. Approve it in Manager for
Craft and the site starts reporting.

See [Pairing a site](/pairing) if something goes wrong.

## What it sends, and when

Nothing is sent for a capability you have not granted. A freshly paired site can report version
numbers and that is all.

| Report | How often | Needs | Contains |
|---|---|---|---|
| Heartbeat | 5 min | - | That the site is alive. Nothing else. |
| Jobs | 5 min | - | Asks whether there is anything to do. |
| Sign-ins | 30 min | `logins:read` | Counts of failed CP sign-ins. Never usernames or addresses. |
| Inventory | 1 hour | `inventory:read` | Craft and PHP versions, plugins, Composer packages. |
| Runtime | 6 hours | `runtime:read` | Disk usage, PHP limits, response timings. |
| Updates | 24 hours | `updates:read` | Which updates exist, and whether any are security releases. |

### What it never sends

Entries, assets, users, password hashes, session data, logs, environment values, security keys,
licence keys, database credentials, connection strings, table names, row counts, file paths, or any
sample of your content.

That is not a policy, it is a schema. Every report is checked against a strict allowlist before it
is sent, and again when Manager for Craft receives it. A field that is not on the list gets the
report **rejected** rather than stripped - if this plugin ever started collecting more than it
should, through a bad change or a bad actor, the report fails at the door instead of quietly
succeeding.

You can see exactly what your site would send, without sending it:

```bash
php craft manager-connector/preview
```

## Scheduling

**Cron is optional. Everything works out of the box without it.**

By default the plugin runs its schedule off ordinary web traffic. Each task fires at most once per
interval however busy the site is, and all a request does is push a queue job, so the visitor waits
for nothing. It costs one cache read per request. Install the plugin, pair the site, and it reports;
there is nothing further to set up.

That works on hosting with no cron at all, which is most shared hosting.

What cron changes is *when*, not *whether*. Off web traffic, a task fires on the first request after
its interval has elapsed, so a quiet site reports late and a site with no visitors overnight may not
report until morning. With cron the timing is exact and independent of traffic, which is worth
having if you care that a heartbeat is genuinely every five minutes rather than roughly.

If you want that, turn off `webTrigger` and add:

```cron
*/5 * * * *  cd /path/to/site && php craft manager-connector/heartbeat
*/5 * * * *  cd /path/to/site && php craft manager-connector/jobs
*/30 * * * * cd /path/to/site && php craft manager-connector/logins
0 * * * *    cd /path/to/site && php craft manager-connector/report
0 */6 * * *  cd /path/to/site && php craft manager-connector/system
0 3 * * *    cd /path/to/site && php craft manager-connector/updates
```

Schedule all six even for capabilities you have not granted. Each one checks before it sends
anything, so an ungranted task is a no-op - and when you do grant it later, it just starts working.

## Backups

Only if you grant `backups:create`, and only if the organisation has an active recovery key.

The site dumps its own database with Craft's own backup, encrypts it, seals the key to your recovery
keys, and uploads it. Both temporary files are deleted whatever happens - while the plaintext dump
exists it is the most dangerous file on the server, so it exists for as short a time as possible.

Before any of that, the site checks the keys Manager for Craft offered against your pinned list.
Anything unexpected and it refuses **before dumping anything**, so a refused backup never leaves a
copy of your database on disk.

If you see this in your logs:

```
the platform offered recovery key MGRK-…, which this site has not pinned
```

that is either a key you enrolled and forgot to pin, or a key you have never seen. Those need very
different responses, which is why the message names it.

[Backups](/backups) has the full picture.

## Console commands

| Command | What it does |
|---|---|
| `manager-connector/status` | What this site is paired to, and what it may do |
| `manager-connector/preview` | Print what would be sent, without sending it |
| `manager-connector/pair <code>` | Pair with Manager for Craft |
| `manager-connector/disconnect` | Stop reporting and forget the keypair |
| `manager-connector/heartbeat` | Send a heartbeat now |
| `manager-connector/report` | Send inventory now |
| `manager-connector/updates` | Check for updates now |
| `manager-connector/jobs` | Ask Manager for Craft whether there is anything to do |

## Disconnecting

```bash
php craft manager-connector/disconnect
```

This deletes the keypair from the site's database. It does not tell Manager for Craft, because a
site that has been compromised should not be able to remove itself from your dashboard - you would
want to see it sitting there having gone quiet.

So do both: disconnect on the site, then revoke the site in Manager for Craft. Two steps with two
different purposes.

If a site is compromised, revoke it in Manager for Craft **first**. That stops it being trusted
immediately, whatever is happening on the server.

## Troubleshooting

**Nothing appears in Manager for Craft after pairing.** Check `manager-connector/status`. If it says
`pending_confirmation`, the hostname did not match and somebody needs to approve it in Manager for
Craft.

**Paired, but silent.** Either the queue is not running (with `webTrigger` on) or cron is not (with
it off). `php craft queue/run` will tell you which.

**Backups fail with "the platform has no artifact encryption key configured".** The organisation has
no active recovery key, or this connector is too old to use them. Check [Recovery
keys](/recovery-keys).

**Backups fail with "not pinned".** See the Backups section above.

**Response timings never appear.** They need at least twenty samples in the last six hours. A quiet
site will not have them, which is correct.

The plugin's own docs go deeper:
[github.com/Coysh-Digital/craft-manager-connector](https://github.com/Coysh-Digital/craft-manager-connector).

## Verifying what you installed

The plugin is source-available and its releases are signed:

```bash
git tag -v v1.7.0
```

Every release also runs a set of build checks that fail the release rather than warning - no URL
rules, no shell execution, no dependency outside a fixed allowlist, no destination or recovery key
accepted from Manager for Craft. They are in `bin/verify-invariants.php` and they are short enough
to read.
