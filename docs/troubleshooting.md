# Troubleshooting

Start here:

```bash
docker compose exec app php artisan manager:doctor
```

It checks the database, Redis, the keypairs, the queue, the audit log's tamper protection, where
backups are going and whether the edition label matches the code actually running. Most problems
show up in that output before you have to go looking.

## Manager for Craft won't start

**"APP_KEY is not set"** - run `php artisan key:generate --show` and put it in `.env`. The container
refuses to boot without it rather than starting with a default, because a default one would silently
make everything it encrypts readable.

**"DB_PASSWORD is one of the obvious ones"** - it is. Change it.

**"APP_DEBUG is on in production"** - turn it off. Debug mode renders stack traces containing
configuration values to whoever triggered the error.

**Migrations fail on start-up.** Check Postgres is actually up and reachable. Manager for Craft
needs Postgres 15+, not MySQL - the audit log depends on triggers and revoked table privileges that
MySQL has no equivalent for.

## Everything returns 503

Almost always Redis.

Replay protection fails **closed**: if Manager for Craft cannot check whether it has seen a nonce
before, it refuses the request rather than accepting it and hoping. That is the correct behaviour
and it is still an outage.

```bash
docker compose exec app php artisan manager:doctor
docker compose logs redis
```

`/ready` will tell you the same thing without authentication, which is what to point an orchestrator
at. `/up` is liveness only - it deliberately stays green during a brief Redis blip, because
restarting the container would turn a blip into a real outage.

## A site won't pair

**"the enrolment code was not accepted"** - expired, used, or superseded. Issue a fresh one.

**"the platform URL must use https"** - no override exists. Pairing sends the enrolment code, and
sending it in the clear would hand it to anyone watching.

**Paired but showing as pending.** The hostname did not match the expected domain. This is the
designed behaviour, not a bug - approve it on the site's page in Manager for Craft, or correct the
expected domain and re-pair. [Pairing a site](/pairing) covers it.

**"could not verify the platform's response"** - something between the site and Manager for Craft is
altering response bodies. Usually a proxy. The site refuses to pair with something it cannot verify.

## A site is paired but silent

```bash
php craft manager-connector/status
```

If it says `active` but nothing arrives, the schedule is not running. Two possibilities:

- `webTrigger` is on but Craft's queue is not running. Try `php craft queue/run`.
- `webTrigger` is off and cron is not set up. See [The Craft plugin](/craft-plugin).

If it says `pending_confirmation`, see the pairing section above.

If it says `disconnected`, the site disconnected locally. Re-pair it.

## Behind a proxy, everything looks like it came from 127.0.0.1

`MANAGER_TRUSTED_PROXIES` is not set, so Manager for Craft is refusing to believe the forwarded
headers - which is right, because trusting them from an untrusted source lets anybody spoof their
address.

Set it to your proxy's address. Never to `*`. [Behind a reverse proxy](/reverse-proxy) has the
detail.

## Backups

**"the platform has no artifact encryption key configured"** - the organisation has no active
recovery key, or the connector is too old to use them. Add one: [Recovery keys](/recovery-keys).

**"the platform offered recovery key MGRK-…, which this site has not pinned"** - either a key you
enrolled and forgot to pin, or a key you have never seen. Those need very different responses, which
is why the message names the fingerprint. Check it against your own `.pub` file, not against Manager
for Craft's screen.

**"this site has not been granted permission to create backups"** - grant `backups:create` on the
site's Capabilities tab. It needs confirmation and a reason.

**"the database backup did not complete"** - Craft's own backup failed. Usually disk space or a
`mysqldump` binary the site's PHP cannot reach. Try `php craft db/backup` directly and see what it
says.

**"the database is larger than this connector is configured to back up"** - raise
`maxBackupMegabytes`, or ask whether a 3 GB database should be going through this route at all.

**A backup shows as pending and never moves.** The declaration arrived and the bytes did not. Check
the site's logs, and check `MANAGER_BACKUP_UPLOAD_WINDOW` has not written it off. The nightly sweep
cleans these up.

**"This platform cannot decrypt that artifact"** - working as intended. Backups are encrypted to
your recovery keys. Download it and use `manager-restore decrypt`. See [Restoring a
backup](/restoring).

## Scheduled backups aren't happening

The scheduler runs hourly and skips a site when:

- the hour or day does not match its schedule (times are in the **organisation's** time zone, not
  the server's);
- the organisation has no active recovery key;
- the site has no live connector, or lacks `backups:create`;
- a backup for that site is already outstanding.

Run it by hand to see which:

```bash
docker compose exec app php artisan manager:backups:schedule --dry-run
```

## Certificate checks report "could not be reached"

Manager for Craft opens a TLS connection to the site's expected domain. If that fails it records the
fact rather than guessing at an expiry.

Common causes: the domain does not resolve from the Manager for Craft server; a firewall blocks
outbound 443; the expected domain is an internal name; or the domain resolves to a private address,
which is refused on purpose - a domain pointing at `169.254.169.254` would otherwise turn a
monitoring check into a request for cloud instance credentials.

## The audit chain fails verification

```bash
docker compose exec app php artisan manager:audit:verify
```

If this fails, something modified or removed audit rows. The chain is tamper-evident by design, so
this is a real signal rather than a glitch.

Check who has database access, check whether a restore replayed an older database over a newer one,
and treat it as an incident until you know which.

## Where the logs are

```bash
docker compose logs -f app        # Manager
docker compose logs -f worker     # queue
docker compose logs -f scheduler  # scheduled commands
```

Craft-side, the connector logs to `storage/logs/` under the `manager-connector` category.

Every Manager for Craft response carries a `Manager-Correlation-Id` header, and rejected connector
requests log their reason against that id. If a site reports a failure with a correlation id, that
is the thing to grep for.

## Still stuck

Open an issue at
[github.com/Coysh-Digital/manager](https://github.com/Coysh-Digital/manager/issues), with the output
of `manager:doctor` and the correlation id if you have one.

Please do not paste `.env` contents, keys, or enrolment codes into an issue. If a value matters, say
which one and whether it is set.
