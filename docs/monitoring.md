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

## Findings

Findings are conclusions, not raw data. Manager for Craft applies a set of rules to what a site
reported and tells you what it thinks is wrong.

Currently eighteen rules, covering roughly:

**Security** - dev mode on in production, HTTPS not enforced, admin changes allowed in production,
security releases available for Craft or a plugin, repeated failed sign-ins, accounts locked out,
TLS certificates expiring.

**Maintenance** - updates available, abandoned plugins, PHP approaching end of life, pending
migrations, invalid or trial licences.

**Operational** - disk nearly full, failed queue jobs, opcache disabled in production, slow
responses, sites not reporting.

Several rules only fire in production, which is why setting a site's environment correctly matters.
"Dev mode is on" is a finding on a live site and completely normal on a staging one.

Acknowledge a finding and it drops out of the list without being deleted. Reopen it if it comes
back. The point is that the list should be short enough to actually read.

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

With `runtime:read`, sites report disk usage, PHP limits and opcache state.

Disk is the one that catches people. A backup job on a site with 200 MB free is how a monitoring
system causes an outage, which is why "disk almost full" is a finding and why the connector has its
own size ceiling.

Asset volumes are walked with a time budget. A volume that runs out of budget is reported as
**unmeasured**, not as empty - those are different facts and only one of them is alarming.

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

Email or webhook, per organisation, for the events worth waking up for: a site stops reporting, a
security release lands, a backup fails, a finding opens.

Webhook deliveries are signed with a per-destination secret you are shown once. Verify it, and
reject anything whose timestamp is not recent, or a captured delivery can be replayed at you.

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

## Related

- [What it does, and does not](/what-it-does) - where the line is drawn, and why
- [Permissions](/capabilities) - what each capability actually permits
- [The Craft plugin](/craft-plugin) - what each site sends, field by field
