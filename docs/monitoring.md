# What it watches

Manager for Craft pulls together the things you would otherwise check by logging into ten control
panels. This page is a tour of what shows up and where it comes from.

Everything here is reported by the sites themselves, on their own schedule, over signed outbound
requests - with one exception, TLS certificates, which is explained below.

## Sites

The main screen. One row per site, showing whether it is reporting, what it is running, how many
updates are waiting and whether anything needs attention.

Four states, and the distinctions matter:

- **Connected** - reporting normally.
- **Never connected** - added but never paired. Nothing is wrong; it is just not set up.
- **Not connected** - it was reporting and has stopped. This is the one to look at.
- **Paused** - deliberately quiet, so it does not clutter the screen or generate findings.

A site that goes quiet is the most important thing on this screen, because every other check depends
on reports. A site that has stopped reporting has silently stopped being monitored, and would
otherwise sit there looking fine.

### Backing up several sites at once

Tick the sites you want and press **Back up selected**. There is a tick-box in each group heading and
one in the table header, so "back up everything needing attention" is one click rather than eleven.

Each site is asked separately. Nothing is bulk about it beyond the selection: a backup is requested
per site, runs when that site next checks in, and one site refusing changes nothing about the others.

**It tells you what it skipped.** A site with no recovery key, no connector, no `backups:create`
permission, or a backup already on its way is left out and named in the amber band, with the reason.
Reporting "requested" over a fleet where half the sites refused is the kind of half-truth you would
only discover when you needed the backup.

Asking for backups does not need a password. Deleting them does - see
[Backups](/backups) for the checkboxes on that screen, which do the same thing in the other
direction and sit behind a password confirmation because they destroy encryption keys.

## Findings

Findings are conclusions, not raw data. Manager for Craft applies a set of rules to what a site
reported and tells you what it thinks is wrong.

Currently twenty-nine rules, and every one of them declares a category. The category decides which
screen it appears on, so nothing is ever on both and nothing is on neither:

**Security** - dev mode on in production, HTTPS not enforced, plain HTTP served without a redirect,
admin changes allowed in production, updates allowed in production, security releases available for
Craft or a plugin, repeated failed sign-ins, accounts locked out, TLS certificates expiring, TLS
certificates a browser would refuse, missing security headers, a Content-Security-Policy that is not
enforced, a disclosed software version, files reachable over the web that should not be, and a site
with no security key set. These are on the **Security** screen.

**Maintenance** - abandoned plugins, PHP approaching end of life, pending migrations, invalid or
trial licences, deprecated code with a breaking Craft release waiting.

**Operational** - disk nearly full, failed queue jobs, opcache disabled in production, slow
responses, a short execution limit on a site whose queue runs over HTTP, directories Craft cannot
write to, missing required PHP extensions, sites not reporting.

Maintenance and operational findings are on the **Findings** screen, grouped by rule - so one
misconfigured deploy template is a single heading with twelve sites under it rather than twelve
identical cards. When any security findings are outstanding, Findings says how many and links to
them, so nothing disappears by being filed elsewhere.

Several rules only fire in production, which is why setting a site's environment correctly matters.
"Dev mode is on" is a finding on a live site and completely normal on a staging one.

Acknowledge a finding and it drops out of the list without being deleted. Reopen it if it comes
back. The point is that the list should be short enough to actually read.

Findings are re-evaluated whenever a site reports, and **hourly regardless**. The hourly pass is what
makes a site going quiet noticeable: a site that has stopped reporting cannot trigger anything by
reporting, so a check that only ran on ingest could never see it. The same pass lets a finding
resolve itself once the problem is fixed, without waiting for anyone to open a screen.

## Security

Every site, worst first, with its security findings underneath it.

Grouped by site rather than by rule, which is the opposite of Findings and the reason both screens
exist. Findings answers "what is wrong across the fleet". Security answers "is this site safe", and
that is a question asked one client at a time.

**Sites with nothing wrong are listed too.** An empty list is not automatically a clean bill of
health: a rule whose capability is not granted is skipped rather than passed, so each site says
which of the two it is, and names the checks that could not run.

### What a site serves to the public

Once a day — and whenever somebody presses **Refresh** on a site — Manager for Craft makes an
ordinary request to that site and records what came back:
`Strict-Transport-Security`, `Content-Security-Policy` (including report-only, which is a different
state from both "set" and "absent"), `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy`, whether plain HTTP is redirected, and which software the response announces.

**This is checked from outside, and that is the point.** A response header is added or removed by
whatever serves the response — nginx, a CDN, a WAF — and PHP on the origin sees none of that. A site
whose edge strips a header the application sets looks correct from inside and is not; one whose edge
adds a header the application does not looks broken from inside and is fine. A plugin checking a
site's own headers is reading them at the wrong end of the chain, and can be wrong in both
directions. Manager for Craft asks from where a visitor stands.

It also notices a header sent **twice**. Two values for the same header is not a stricter site, it is
an undefined one — browsers disagree about which wins — and the usual cause is the application and
the web server each setting it without knowing about the other. That one is invisible from inside the
site precisely because both halves are working.

### Files that should not be reachable

The same sweep asks whether a short, fixed list of files answers over the web:

```
/.env              every credential the site has
/.git/config       proof the whole repository is downloadable, history included
/composer.json
/composer.lock     the exact version of every dependency
/backup.sql
/database.sql
/db.sql.gz         somebody's entire database, to anyone who guesses the name
```

That list is a constant in the application. It is not configurable, and nothing a site sends can add
to it.

Every one of these is a `HEAD` request, and there is deliberately no fallback to `GET`. Discarding a
response body still transfers it, and a database dump left in a webroot is exactly what this is
looking for — so a fallback would pull gigabytes off a customer's server, over their bandwidth, in
order to report that the file should not be there. A server that refuses `HEAD` produces no answer,
recorded as no answer rather than as an all-clear.

::: tip A site that answers 200 for everything is told nothing
Some sites return `200` for any path at all — a catch-all route, a single-page front end, a
permissive proxy fallback. On one of those, "your `.env` returned 200" is a fact about the routing
and not about the file.

So the sweep also asks for a path that certainly does not exist. If that answers too, the file
results are marked inconclusive and **no exposure finding is raised**. Without that control, the
sites with the loosest routing would be the ones accused of publishing their credentials.
:::

A `401` or `403` is not a finding. It means the file is there and protected, which is the correct
configuration.

### Checking a site now, rather than tomorrow

Pressing **Refresh** on a site checks this as well as asking the connector to report. A site added
this morning otherwise showed an empty panel until the next sweep, with no way to hurry it — which is
the worst moment for the answer to be missing, because it is the moment somebody is fixing headers
and wants to see whether it worked.

Two things bound it. The same site is not looked at more than once every ten minutes however often
the button is pressed, and **Refresh all** on the fleet screen does not do it at all: one press
turning into ten requests to each of two hundred customers' servers is a different thing from
checking the site you are looking at. To sweep the fleet on demand, run
`php artisan manager:web:check`.

Set `MANAGER_PROBE_ON_REFRESH=false` to leave the daily sweep as the only thing that does this. See
[Environment reference](/env).

## Updates

One screen for the whole fleet: which sites are behind, on what, and by how much.

The useful column is whether a **security release** sits between what is installed and what is
available. "Three versions behind" and "three versions behind, one of which fixes a vulnerability"
are different situations, and only one of them needs doing today.

Manager for Craft reports that an update exists. It does not install it. Applying an update is a
deployment, and deployments belong to you - see [What it does, and does not](/what-it-does).

### Plugin release notes

Sites running `updates.v2` forward the release notes their own Craft install already downloaded from
the Plugin Store, and you can read them next to the version numbers. It saves the trip to somebody
else's changelog to find out whether "3.0.12 to 3.0.14" is a typo fix or an authentication bypass.

This was refused outright in `updates.v1`, and it is worth saying what changed, because the original
objection was a good one. Release notes describe what a version fixes, so a database that knows
*this named site* is three versions behind *these fixes* is a map of an exploitable installation.

The text itself was never the problem: it is public, the Plugin Store serves it to anyone who asks,
and every site running the plugin already has it. The danger was the **association** between a
described vulnerability and a named site that has not applied it. So that association is the thing
the design removes:

- Notes are stored in `plugin_release_notes`, keyed on a plugin and a version, with **no site column
  and no organisation column**. The table cannot express which of your sites is behind, even if
  something asked it to.
- The notes are stripped out of the report before it is written to `update_reports`, so the per-site
  payload does not carry them either.
- No new outbound destination was added. The text arrives from your sites, exactly as everything
  else here does.

`tests/Invariants/PluginReleaseNotesTest.php` asserts all three against the stored bytes. If it goes
red, the feature has become the thing v1 refused.

::: warning What is still deliberately not here
Download URLs are never fetched or stored, and nothing here is matched against a vulnerability
database to tell you which of your sites is exploitable today. That is the map, and holding it is
what the arrangement above exists to avoid.
:::

## TLS certificates

Manager for Craft checks the certificate each site presents and warns you before it expires - thirty
days out, then more urgently inside a week, then loudly once it has gone.

It also checks whether a browser would accept the certificate at all, which is a separate question
from how long it has left. Three faults are reported, and each has a different fix:

| What is wrong | Why it happens | What a visitor sees |
|---|---|---|
| The certificate is for a different domain | The wrong certificate was installed, or a wildcard was used on the apex it does not cover | A full-page browser warning |
| The certificate is self-signed | A placeholder left behind after the server was set up, or automatic issuance that never completed | A full-page browser warning |
| The chain is incomplete | The intermediate certificate was left out of the bundle | Often **nothing** - see below |

The third is the one worth knowing about. Desktop browsers fetch a missing intermediate themselves
and show no warning, so a site can look perfectly healthy to whoever is testing it while failing on
Android devices, on `curl`, and for anything calling it as an API. It is the shape of fault that
survives for months because the person investigating cannot reproduce it.

A certificate that has never been checked, one on a host that did not answer, and one on a server
with no certificate authorities installed are all recorded as **not determined** rather than as
passing. A tick against something nobody checked is worse than no tick at all.

This is the one thing Manager for Craft goes and looks at itself rather than waiting to be told, and
that is worth explaining because everything else works the other way round.

The connector genuinely cannot see the certificate. TLS terminates at the edge - a CDN, a load
balancer, a reverse proxy - so PHP on the origin sees whatever that proxy put in `$_SERVER`, which
is not what a visitor's browser validates. On exactly the sites where this matters most, asking the
site would produce a confidently wrong answer.

So once a day Manager for Craft opens a TLS connection to the site's own hostname, reads the
certificate and closes. Nothing is sent, no HTTP request is made, no response body is read. The
connection is guarded the same way notification webhooks are: a domain that resolves to a loopback,
private or metadata address is refused, because a site whose domain pointed at `169.254.169.254`
would otherwise turn a monitoring check into a request for cloud instance credentials.

A healthy site costs exactly one connection. The check verifies first - which answers trust, the
domain and self-signature all at once by succeeding - and only opens a second, non-verifying
connection when the first is refused, in order to describe what was wrong with it. If neither
completes, that is a host which did not answer rather than a certificate problem, and it is recorded
as one.

A site Manager for Craft could not reach is recorded as unreachable rather than as having an expiry
problem. Those are different facts and only one is about the certificate.

## Uptime and health

Sites send a heartbeat every five minutes. Manager for Craft derives uptime from those rather than
storing a number, so the history is real rather than a rolling average somebody computed once.

Three missed beats - fifteen minutes at the default - is the difference between "a queue was busy"
and "this site has stopped".

The Health tab per site shows outages with their start and end, response timings where the site is
sampling them, and queue depth.

::: tip Those response times are not TTFB
They measure how long PHP took to build the response: no DNS, no TLS handshake, no network to the
visitor. A site with a two-second time to first byte and a 40ms render looks fast here and is not.
Manager for Craft says so on the screen rather than labelling it something flattering.
:::

## Runtime and storage

With `runtime:read`, sites report disk usage, PHP limits, opcache state and - from connector 1.15
onwards - what Craft can say about itself: how many deprecation warnings it has recorded, how large
the database is, whether its writable directories are writable, whether a security key is set,
whether the control panel is still at the default address, which required PHP extensions are absent,
and which imaging library is available.

The database size is the one to watch on a growing site. A backup is a dump of it, so it is the
figure that decides whether one finishes - and until connector 1.15 the report described every asset
volume and the disk underneath them while saying nothing about it.

Counts and booleans throughout. Never a deprecation message, which names a template and a line of
your code; never the security key; never a per-table breakdown of the database; and never the
control panel's address if it has been moved, because writing that down in a dashboard would undo
the point of moving it.

Disk is the one that catches people. A backup job on a site with 200 MB free is how a monitoring
system causes an outage, which is why "disk almost full" is a finding and why the connector has its
own size ceiling.

Asset volumes are walked with a time budget. A volume that runs out of budget is reported as
**unmeasured**, not as empty - those are different facts and only one of them is alarming.

The PHP limits are mostly there to be looked at rather than warned about, with one exception.
Craft runs its queue inside a web request unless somebody has arranged otherwise, so taking a
backup, running an update and applying project config all happen under `max_execution_time`. On the
PHP default of thirty seconds a large database fails at a different point each night, having already
been dumped and encrypted, with nothing in the log that reads as a timeout.

Manager for Craft can tell which sites this applies to rather than guessing. The runtime report is
built by the connector's scheduler, which runs in the queue - so the SAPI in the report is the SAPI
the queue ran under. A site reporting `cli` has its queue on cron and is never flagged; one
reporting `fpm-fcgi` is telling you its backups happen inside a web request.

The remedy worth taking is `php craft queue/listen` from cron, which removes the limit from the
question entirely. Raising `max_execution_time` works too, and has to be raised again the next time
the database grows.

## Sign-ins

With `logins:read`, sites report counts of failed control-panel sign-ins: how many attempts, how
many accounts affected, how many locked out, and how many of those are administrators.

Counts only. Never usernames, never email addresses, never source IPs, never per-attempt records.
The useful signal is "twelve failed attempts against three accounts, one of them an admin", and you
do not need to know who to act on that.

## Activity and audit

Every decision is recorded: pairings, capability grants and revocations, backups requested and
deleted, recovery keys added and revoked, settings changed, sign-ins.

The log is hash-chained and append-only, enforced by the database rather than by the application.
Each entry carries the hash of the one before it, so removing or editing one breaks verification for
everything after it. A nightly job checks the chain and tells you if it ever does not add up.

That is tamper-**evident**, not tamper-proof. Somebody with database access can still destroy it.
What they cannot do is quietly change one line.

## Notifications

Email or webhook, for the events worth waking up for: a site stops reporting, a security release
lands, a backup fails, a finding opens.

**Each destination is either for every site or for the ones you choose.** "All sites" is the default
and includes anything added later, which is what you want for your own operations mailbox. Narrowing
one to particular sites is what makes "send this client's alerts to this client" possible without
telling them about anybody else's fleet.

Narrowing a destination does not narrow what it is subscribed to. It still hears about everything it
asked for - just not about sites it is not responsible for. Anything that is about the installation
rather than about a site reaches every destination regardless.

Webhook deliveries are signed with a per-destination secret you are shown once. Verify it, and
reject anything whose timestamp is not recent, or a captured delivery can be replayed at you.

A `finding.opened` payload carries the rule's category alongside its severity, so a receiver can act
on security findings and file the rest without needing a second subscription.

Destinations are checked before anything is sent. A webhook pointing at a private or metadata
address is refused, for the same reason certificate checks are guarded.

A destination that keeps failing gets stopped after ten consecutive failures. The failures stay on
the record; a dead endpoint is just not worth a worker.

## What it will not tell you

Manager for Craft reports the conclusion, never the evidence, when the evidence is your data.

It does not hold your entries, your users, your assets, your logs or your environment values. It
knows that a site has 4,312 entries only if that number is a count it was allowed to report - it
does not know what any of them say.

If you want to know what changed in a specific entry, Manager for Craft is the wrong tool. It
watches infrastructure, not content.

The two checks that reach out to a site — the certificate and the public-facing request — hold to the
same line from the other direction. The certificate check completes a handshake and reads no HTTP at
all. The web check reads response headers and discards every response body, so nothing a site
publishes is stored anywhere here. It is not a crawler, it does not index pages, and the only paths
it ever asks for are the fixed list above.

## Related

- [What it does, and does not](/what-it-does) - where the line is drawn, and why
- [Permissions](/capabilities) - what each capability actually permits
- [The Craft plugin](/craft-plugin) - what each site sends, field by field
