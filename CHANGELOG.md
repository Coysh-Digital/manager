# Changelog

What changed, and what you have to do about it.

Entries are written for somebody about to upgrade a running installation. Anything needing manual
action is under **Before you upgrade** - that section is the one to read, and `docs/upgrade.md` points
here for exactly that reason.

## 1.6.0 — 2026-08-12

Pressing a button stops reloading the page, and a TLS certificate is judged rather than just read.

**Before you upgrade:** there are two migrations — four nullable columns on `sites`, and one new
table. Both run in well under a second on any fleet. The webhook payload is unchanged.

**Manager now makes one more set of outbound requests.** A daily check asks each site for its home
page and a fixed list of file paths, from your Manager server. If your sites sit behind a firewall
that rate-limits or alerts on repeated requests, this is the thing that will trigger it. The requests
identify themselves as `Manager/1.0 (+security-check)` in the user agent.

### A certificate is now judged, not just read

- **Three faults a browser refuses are now reported, none of which were visible before.** The check
  recorded when a certificate expires, who issued it and what name it carries, and said nothing about
  whether a browser would accept it — so a certificate issued for somebody else's domain, or served
  without the intermediate joining it to a root, showed on the fleet screen as *"expires in 88 days"*
  right up until a visitor saw an interstitial.

  A certificate for the wrong domain and a self-signed one are **high**; an incomplete chain is
  **medium**, and that ranking is deliberate rather than tidy. Desktop browsers fetch a missing
  intermediate themselves and show no warning, so the site looks healthy to whoever is testing it and
  fails on Android, on `curl`, and for anything calling it as an API. It is the shape of fault that
  survives for months because the person investigating cannot reproduce it.

- **The site's Security tab has a TLS certificate panel.** Until now the certificate appeared in the
  interface only when something was wrong with it, as a finding — so "checked this morning and fine"
  and "never checked" looked identical, which is to say they both looked like nothing.

- **A healthy site still costs one connection.** The check verifies first, and a verifying handshake
  that succeeds has already answered trust, the domain and self-signature. The second, non-verifying
  connection is opened only when the first is refused, to describe what was wrong — so the extra work
  is spent on the sites that have something to explain.

- **Nothing is guessed.** Each judgement has three states, not two, and *not determined* is never
  rendered as a pass. A site that has never been swept, a host that did not answer, and a Manager
  installation on a container with no certificate authorities all record no judgement rather than a
  failing one. Without that last case, the first sweep after deploying to a minimal container would
  have opened a high-severity finding against every site in the fleet — each one describing this
  server rather than the site it named.

### Manager now looks at what a site serves to the public

- **Once a day it makes an ordinary request to each site and records what came back.** The security
  headers, whether plain HTTP is redirected, which software the response announces, and whether a
  short fixed list of files — `.env`, `.git/config`, the lockfile, three common database dump names —
  answers over the web.

  **Checked from outside, which is the whole point.** A response header is added or removed by
  whatever serves the response, and PHP on the origin sees none of that: a site whose CDN strips a
  header the application sets looks correct from inside and is not. This is the one class of check
  a plugin running on the site itself cannot get right, and it can be wrong in both directions.

- **A header sent twice is reported.** Two values for the same header is not a stricter site, it is
  an undefined one — browsers disagree about which wins. The usual cause is the application and the
  web server each setting it without knowing about the other, which is invisible from inside the site
  because both halves are doing their job.

- **A site that answers `200` for everything is told nothing.** The sweep also asks for a path that
  certainly does not exist, and if that answers too, the file results are marked inconclusive and no
  exposure finding is raised. Without that control the sites with the loosest routing — a catch-all
  route, a single-page front end — would be the ones accused of publishing their credentials.

- **The file checks are `HEAD`, with no fallback to `GET`.** Discarding a response body still
  transfers it, and a database dump in a webroot is exactly what this looks for, so a fallback would
  pull gigabytes off a customer's server to report that the file should not be there.

- **The site's Security tab has a *Served to the public* panel**, showing what came back whether or
  not anything is wrong with it.

### Four findings from what a site serves, and the ones deliberately left out

Every rule here fires on what a visitor receives rather than on what the site believes, and none of
them takes a capability — the probe reads a public web page, and a grant would be asking a site's
permission to look at what it already serves to everybody.

- **Missing security headers, as one finding rather than three.** HSTS, `X-Content-Type-Options` and
  framing control. A site missing these is almost never missing exactly one of them — the cause is a
  server that was never configured for it — so three rows would describe one afternoon's work three
  times. **Medium** when all three are absent, **low** otherwise.

  A CSP with `frame-ancestors` counts in place of `X-Frame-Options`, because it supersedes it and
  browsers prefer it. Without that, this rule would have flagged the best-configured sites in a fleet
  and told them to go backwards.

- **A Content-Security-Policy that enforces nothing.** Fires on report-only, and **not** on absent —
  which is the opposite of the obvious version and the point of the rule. A site with no CSP has
  usually decided that on purpose. A site with a report-only policy is one where somebody wrote a
  policy, deployed it, meant to come back and enforce it, and a sprint ended: from that day it has
  looked protected to anybody reading its headers while browsers ignored every violation it
  describes. **Low**, because nothing got worse — what changed is that somebody now believes
  otherwise.

- **Plain HTTP served without a redirect.** **High** in production. This sits beside the existing
  *HTTPS is not enforced* rather than replacing it, and the pair is the clearest illustration of why
  the check exists: one is what Craft's `baseUrl` says, the other is what happens when somebody types
  the domain without a scheme. A site can fail either without failing the other, and only one of them
  is about what happens to people.

- **A disclosed software version.** `Server: nginx` is something an attacker could have guessed;
  `Server: nginx/1.24.0` is a specific build to look up in an advisory database. **The version is the
  finding, not the header** — flagging the header would fire on nearly every site on the web and be
  muted within a week, and the sites that then stopped being read are the ones publishing a patch
  level. **Low**, and it earns its row because the fix is one configuration line that lasts forever.

**`Referrer-Policy` and `Permissions-Policy` are shown and never flagged.** Every current browser
defaults `Referrer-Policy` sensibly, and almost nobody sets `Permissions-Policy` or needs to. Both
would fire across most of a fleet to say very little, which is how a findings list stops being read.

- **A file that should not be public, answering over the web, is now the most serious finding
  Manager raises.** `.env`, `.git/config` and three common database dump names are **critical**;
  `composer.json` and `composer.lock` are **high**. The distinction is whether the file *is* the harm
  or helps somebody find it — `.env` is the database password, the mail credentials and every API key
  the site holds, in one request, with no exploit to write, whereas a lockfile is the exact version of
  every dependency and therefore a shopping list of published vulnerabilities.

  The finding says to rotate the credentials rather than only to move the file, which is the part
  that gets skipped.

  **It cannot fire on a site whose answers do not support it.** The gate lives on the report model
  rather than in the rule, so the raw list is unreadable from a rule at all: a site that answered the
  control path is inconclusive, and this stays silent. Getting that wrong would send the loudest
  finding in the product — naming a customer's site and telling somebody their credentials are
  public — to sites whose only fault is a catch-all route.

The path list is a constant in the application — not configuration, and nothing a site sends can add
to it. `OutboundUrlGuard` gained a second entry point for the plain-HTTP redirect check, because that
question cannot be asked over HTTPS; it shares every address check with the existing one, and the
HTTPS-only rule on notification destinations is untouched.

### Two PHP limits that were being collected and never read

- **A site whose queue runs over HTTP is now told when its execution limit is short enough to kill a
  backup.** Craft runs its queue inside a web request unless somebody has arranged otherwise, which
  means taking a dump, encrypting it and uploading it all happen under `max_execution_time` — so on
  the PHP default of thirty seconds a backup of anything but a small database fails at a different
  point each night, having already done the expensive part, with nothing in the log that reads as a
  timeout.

  What makes this a finding rather than a preference is that the site says which it is. The runtime
  report is built by the connector's scheduler, which runs in the queue, so the SAPI in the report is
  the SAPI the queue ran under. A site reporting `cli` has its queue on cron and hears nothing; one
  reporting `fpm-fcgi` is telling us its backups happen inside a web request. **Medium**, and only
  below sixty seconds — ninety and a hundred and twenty are everywhere, and a rule firing on those
  would be amber across half a fleet on day one, which is how a findings list stops being read.

- **`max_input_vars` appears on the Health screen.** The connector has collected it since the first
  runtime report and every schema version has carried it; nothing ever rendered it. It is worth
  seeing because Craft silently truncates a large form on save when it is too low, which presents as
  content mysteriously failing to save rather than as anything resembling a PHP limit.

- **An execution limit of zero now reads as "Unlimited" rather than "0s".** Read as a number, zero is
  the shortest limit there is, so the screen was showing the sites with no limit at all as the worst
  configured ones in the fleet.

### Asking for something no longer reloads the screen

- **"Back up now", "Refresh", "Refresh all", "Check again" and "Cancel" answer where you pressed
  them.** None of these was ever synchronous — each writes a row and the request is over, because
  nothing here reaches out to a site — but each one ended in a redirect, so the screen reloaded and
  came back looking identical. A backup waits for the site to check in, which is up to five minutes
  away and longer on a site whose scheduler runs off web traffic, so for that whole time the page was
  correct and looked broken. The observable consequence was people pressing the button again, which
  is another full dump of a production database.

  The sentence that used to appear as a band across the top now appears as a message in the corner,
  and the page stays where it was.

- **A refusal is visible on screens where it was not.** Asking an unpaired site to refresh flashes
  *"Nothing to refresh: this site has no active connector"*, and five of the seven site tabs carrying
  that button had nowhere to render it — so the sentence was written and thrown away. It is now said
  out loud.

- **With JavaScript blocked, nothing changed at all.** Every one of these is still an ordinary form
  with an ordinary submit button, and still redirects and renders the band it always did. The
  enhancement is opt-in per route, and only routes it is harmless to perform twice may carry it —
  each of these is either keyed so a second press returns the job already queued, or converges on the
  same state. There is an invariant test asserting the list.

### A finished backup no longer takes the page out from under you

- **The in-progress panel updates in place, and stops reloading the document.** When a backup landed,
  the screen reloaded itself — correct, because six things change at once, and jarring, because it
  threw away the scroll position at a moment nobody chose. The panel now updates on its own, and a
  backup that stores, fails, expires or is cancelled says so in the corner with a **Reload** link
  beside it. The artifact table and the summary tiles are honestly stale until that is pressed, which
  is better than a screen that quietly looks current and is not.

- **The elapsed line stops going stale.** The panel used to be patched one attribute at a time and
  only the phase was written, so a card could read "2m at this phase" twenty minutes in. The whole
  card is now re-rendered by the server, so the elapsed time, the "No change" badge and the
  explanatory sentence all move together.

- **The sidebar count keeps up.** It was only ever correct because every action ended in a
  navigation.

## 1.5.2 — 2026-08-09

A failed-backup alert that reads like a message rather than a stack trace, and a round of corrections
to what several screens say.

Nothing to do on upgrade. No migration, no configuration, and the webhook payload is unchanged.

### Alert emails

- **The reason no longer arrives wearing an exception class.** A live alert read *"RuntimeException:
  The platform rejected the request (HTTP 422). This organisation has no room left for another
  backup. Correlation ID: 01KZK…"* — four things in one line, three of which mean nothing to the
  person whose backup did not run. What a connector reports is the message of the exception it
  caught, which is exact and is what support needs; it is not a sentence. The class name and the
  "the platform said no" framing are dropped, and the reason row now reads *"This organisation has
  no room left for another backup."*

  Anything unrecognised is passed through whole. A message trimmed into something shorter risks
  being trimmed into something misleading, and the reader has no way to tell — so this only removes
  framing it can identify.

- **The correlation identifier has a row of its own.** Being findable is the entire reason somebody
  forwarding an alert to support needs it, and the end of a sentence is the worst place for a
  reference number. An identifier the connector could not determine is dropped rather than shown as
  a row reading "unknown".

- **An alert about a storage limit now says so, and says what to do.** A backup refused for space is
  not a fault — the backup was fine, there was nowhere to put it — and the remedy is a setting:
  shorten retention on the site, or increase the storage available to the organisation. The alert
  reported the failure and left the reader to work that out. It says it now, in the HTML and in the
  plain-text part, which has to stand as a complete message on its own. No other failure gains
  advice: a guessed remedy teaches people the advice is worth ignoring.

### On screen, not only in the inbox

- **A failed backup now reads the same on the screen as it does in the alert.** The tidying above was
  applied where the email is rendered and stopped there, so the backups screen kept showing
  *"RuntimeException: The platform rejected the request (HTTP 422)…"* — which is the wrong way round:
  the screen is where somebody is standing when they go and fix it. The reason is a sentence
  everywhere it appears now, including the artifact rows, the activity log and the site's own audit.

- **The remedy list had drifted into two.** The screen kept its own list of four recognised failures
  and the email kept a separate one, so a backup refused for storage was given advice in the inbox
  and none on the screen. One list now, in `FailureReason`, and both read from it.

- **A site name on the backups screen goes to that site's backups.** It went to the site's overview,
  which meant finding the Backups tab and clicking again — on a screen whose entire subject is
  backups.

- **"The site is being asked to start it now" is now "It will start shortly."** The distinction 1.5.0
  drew is kept and still matters: the knock is queued rather than delivered, so a message saying the
  backup *has started* would claim something that has not happened, and a site that cannot be reached
  still gets the sentence it has always had. What changed is the English — the old wording described
  this platform's internal mechanics in the passive voice to somebody who pressed a button and wanted
  to know when it would happen.

**The webhook payload is unchanged.** `context.reason` still carries the failure verbatim, exactly as
1.5.1 promised — a consumer may be matching on the whole string. The tidying happens where the email
is rendered, and there is a test pinning that distinction.

### Wording

Five corrections found by reading the screens rather than the code. Nothing behind any of them
changed, and no setting, migration or payload is involved.

- **A queued test notification pointed at a delivery log that does not exist.** It said *"Check the
  delivery log below in a moment"*. There is no delivery log: a destination's last three deliveries
  are listed inside its own row, only once it has any, and nothing on the page refreshes itself. A
  first-time tester was told to watch a part of the screen that was not going to change and was not
  there. It now says the test sends in the background and names the reload.

- **The activity log explained itself in cryptography.** *"Append-only. Entries cannot be edited or
  deleted, and each one commits to the entry before it, so any alteration is detectable"* is exactly
  true and describes a hash chain to somebody who opened the page to find out who deleted a site.
  It now says that everything Manager does is recorded, that entries cannot be edited or deleted by
  anyone, and that any later attempt to change one would be visible. The guarantee is unchanged, the
  chain is unchanged, and `manager:audit:verify` still proves it.

- **The account screen said an email address cannot be changed and stopped there.** The only route it
  offered was abandoning the account: invite a new one and revoke this one. It now names the person
  to ask first - an administrator on a self-hosted installation, support on a hosted one - with the
  invite route kept as the answer that always works.

- **The single-recovery-key warning is gone.** One active key is what an organisation has the moment
  it finishes the setup instructions, so the warning fired on the ordinary case, permanently, on the
  recovery keys screen and on every backups screen behind it. A warning that is always showing is one
  people stop reading, and it was crowding the blockers beside it, which are the ones that mean a
  backup will not be taken. Nothing about the risk changed and nothing hides it: the instructions for
  making a key still say what losing the secret half costs.

- **Em dashes are gone from the interface and the documentation.** A spaced hyphen everywhere
  instead, including the dash that stands in for a value a site has not reported. Text only.

## 1.5.1 — 2026-08-08

An alert you can read at a glance on a phone, and a round of hardening around outbound destinations
and account enrolment.

No migration, no configuration, no change to what a connector sends or to what a webhook receives.
Nothing to do on upgrade unless a webhook destination of yours is reached through a NAT64 translator,
which is now refused - see **Security**.

### Alert emails

- **The facts now sit in a bordered box, tinted to match how serious the event is** — red for a
  failed backup or a serious finding, amber for a site that has gone quiet or a revoked connector,
  neutral for a permission that was confirmed. The three are the same colours the interface uses for
  the same three states, so moving from an inbox to a screen does not mean learning a second colour
  language.
- **The reason a backup failed is a labelled row of its own, in red**, rather than prose in the
  middle of a sentence. It was the one fact somebody needed to act on and the only one without a
  label, while "Environment: production" had one.
- **The opening sentence of a backup-failure alert is now the same every time** — *"A backup of X did
  not complete, so nothing new was stored. Earlier backups are unaffected."* A sentence that does not
  vary is read once and skipped afterwards, which is what lets the box carry everything that does
  vary. It also answers the first question a failed backup raises, which is what it did to the
  backups already stored: nothing.

The webhook payload is unchanged. `context.reason` still carries the failure verbatim; it is the
`summary` prose around it that moved.

### Security

- **An outbound destination is now judged by the address it names, not by how that address was
  written.** One address can be spelled more than one way, and the private-and-reserved list was
  matching the spelling: an IPv4 address presented in its IPv6 form was tested against the IPv6
  entries, which do not describe it, and never against the IPv4 entries, which do. Those forms are
  reduced to the address they carry before the list is consulted, so one list covers every notation
  rather than needing a second copy of itself. An ordinary destination is unaffected - a public
  address stays reachable however it is written.

  The NAT64 prefix is refused outright instead of being unwrapped. It is less an address than a
  doorway: what reaches the IPv4 address in its low bits is a translator this platform does not run,
  and judging that address would be trusting somebody else's routing to agree. **If a webhook
  destination of yours is reached that way it will now be refused** - give it a name that resolves to
  an address Manager can reach directly.

- **An IPv6 address written straight into a webhook URL is now checked against that list at all.**
  `parse_url` hands back `[::1]` with the brackets still on, which the address parser does not accept,
  so these were refused for failing to resolve rather than for being loopback. Refused either way,
  which is why nothing looked wrong - but every IPv6 entry in the list was unreachable on that path,
  and a rejection that happens by accident is one a later change can remove without noticing.

- **The enrolment secret for a second factor is no longer held in the session in the clear.** The
  confirmed secret is encrypted on the user record, so that column never holds a readable one. Between
  generating the secret and confirming it, though, it sat in the session - and the default session
  driver writes to the database. Anyone who could read that table, or a dump or a replica of it, had a
  working second factor for an account mid-enrolment, which is exactly what the encrypted column
  exists to deny them. It is encrypted there now, and enrolment that cannot be read back is treated as
  no enrolment rather than as an error.

- **Password reset is limited by source, not only by the address asked for.** The password broker
  bounds how often one mailbox can be sent a link, which counts addresses rather than requests: one
  source asking for a different address each time was making a first request every time, passing that
  check every time, and sending mail every time. Both submitting halves of the flow now carry a
  per-source limit as well. Login and the two-factor challenge were already limited in their
  controllers, where they can key on the account being attacked; a reset request deliberately does not
  know whether the address exists, so the source is all there is to count.

- **An oversized connector request is refused on its declared length, before its body is read.** The
  limit was enforced by measuring the body, and measuring a body means already holding it. The
  declared length is checked first now. The measurement stays, because a declared length is a claim
  and can be absent or wrong - this only refuses, for free, the callers that admit it.

- **A check now pins the set of routes that hand back a file to the one route that does.** Anything
  deciding a session may read but not write starts from the HTTP method, because that is all there is
  before dispatch, and then has to name the exceptions by hand. Both ways such a list rots are
  invisible from where it is written: an exception recorded as a route name quietly stops matching if
  the route is renamed, and a download added later was never on it. Adding a second one now fails the
  build until somebody decides what it means for callers that were told reads are safe.

### Fixed

- **`nanoid` moved to 3.3.18**, clearing a high-severity advisory
  ([GHSA-2v37-7h3g-55p8](https://github.com/advisories/GHSA-2v37-7h3g-55p8)) that arrived under
  Vite's copy of PostCSS. It is a lockfile change and nothing else; the compiled assets are
  byte-identical.

  **Nothing shipped with it and nothing needs doing.** This is a build-time dependency: the
  Dockerfile removes the whole Node toolchain and `public/build` is committed, so no running
  installation has ever had `nanoid` on it. The advisory was published after 1.5.0 was tagged, which
  is why a release that was green went red without anybody touching it — the point of running
  `npm audit` on every build rather than at release time.
- **The environment reference was missing eleven settings**, on a page that opens by saying it lists
  everything Manager reads. The most consequential was `MANAGER_NUDGE_ENABLED` — the one 1.5.0 tells
  you to set if your installation must make no outbound request to a managed site. Somebody following
  that instruction would have arrived at the reference and not found it. Also added: the enrolment
  rate limits, the heartbeat interval and grace, telemetry retention (and the older
  `MANAGER_HEARTBEAT_RETENTION_DAYS` name it still honours), `MANAGER_HSTS_SECONDS`,
  `MANAGER_SETUP_TTL`, `MANAGER_FETCH_CHANGELOGS`, `MANAGER_DIAGNOSTICS_ENDPOINT`, and the four S3
  credential variables that were named only in passing inside another row. There is now a check that
  fails the build when a variable is read but not documented.
- **The site and settings tabs scrolled up and down as well as sideways on a phone.** A thumb aiming
  along the strip nudged it out of line instead. CSS resolves an `overflow` of `visible` to `auto` as
  soon as the other axis is not, so `overflow-x-auto` alone means "scrollable both ways" — and the
  tab list deliberately puts one pixel outside the box so the active tab's underline sits on the
  container's border rather than below it. One pixel is enough to scroll. There is now a check that
  fails the build on a navigation strip that can scroll vertically.

## 1.5.0 — 2026-08-07

Seeing the state of a fleet's backups without reading every row, and seeing how far behind each site
is without opening it.

**Before you upgrade:** there is a migration, a new configuration key, and Manager now makes one
outbound request to a managed site that it did not make before. All three are described under
*Requested work starts in seconds* below. Nothing needs doing to take this - the defaults are safe and
the behaviour degrades to exactly what it did before - but an installation that must make no outbound
request to a customer's site should set `MANAGER_NUDGE_ENABLED=false` before deploying.

### Requested work starts in seconds, instead of waiting for the site to notice

Pressing **Request backup** wrote a queued row and nothing else. The site collected it on its next
check-in - up to five minutes away with cron, and on a site whose connector schedule runs off ordinary
web traffic, however long it took for somebody to visit. The screen was correct for that whole time and
looked broken. It even said so in its own source: *"For that whole time the screen is correct and looks
broken."*

Manager now knocks on the site and asks it to check in.

**The knock carries no instruction, and that is the entire design.** There is no field in it for a job,
a capability, a path or a destination. A site that receives one makes the ordinary signed claim it would
have made on its own a few minutes later, and everything that decides anything happens there: the
capability is re-checked at claim time, the recipients are the ones the site has pinned, the artifact
goes where the site derives rather than where anybody says. **So the worst outcome of a forged, replayed
or misdirected knock is one early poll.**

**The address is composed, never accepted.** The host is the site's expected domain - the one an
operator typed, and the one pairing is bound to. Only the *path* comes from the wire, and only after it
has been understood in full: anything carrying a scheme, a host, credentials, a port, a fragment, a
traversal or a control character is refused outright rather than repaired. That is deliberately the
mirror of the rule the connector applies to itself, where an upload host is derived rather than taken
from a response. Both directions now pin to something a person stated.

The request is guarded exactly as an outbound notification webhook is, because the same reasoning
applies: HTTPS only, redirects not followed, the connection pinned to the address the guard validated so
DNS cannot move underneath, a five-second timeout, and the response body discarded.

**Nothing depends on it.** A site behind NAT, an IP allowlist, a WAF, HTTP basic auth, or simply one
running a connector too old to say where to knock, is never knocked on and never told it was missed - it
finds out on its own schedule, exactly as before. After five consecutive failures Manager stops trying
until the site itself says otherwise on a claim, so an unreachable site does not collect a doomed
outbound request every time somebody presses a button.

**What the screen says changes with it, and only when it is true.** A site Manager can reach reads
*"The site is being asked to start it now"*; one it cannot keeps the sentence it has always had. Bulk
requests say how many of each. *Being asked*, not *will start* - the knock is queued, not delivered, and
whether a site answers is not something Manager knows yet.

**This is time-to-start, not time-to-finish.** A large database is still a dump, an encryption pass and
an upload, which is minutes to hours. What changes is that the row goes from *queued* to *running* while
the person who pressed the button is still looking at it.

Requires a connector on 1.14.0 or later to have any effect. Anything older never says where to knock, is
never knocked on, and behaves exactly as it does today. Requires `manager-protocol` 1.8.0.

### Backups

- **A summary above the backups list, and filters under it.** How many backups are stored, arriving
  and failed, and how many bytes storage is holding. Each count is a link that filters the table to
  it, and the filters live in the query string, so a view filtered to failures can be sent to
  whoever owns the site.
- **A status column.** The state of an artifact was previously implied by which of the other columns
  were filled in.
- **When the next backup is due, on the site's own Backups tab.** The next three runs, as dates, with
  the zone named. The tab has always said *how often* a site is backed up and never *when next*, so
  the fleet screen could answer the question and the page somebody actually opens to check could not.
  Three rather than one because three is a pattern: somebody who set "weekly on Tuesday" and meant
  Thursday sees it immediately rather than a month later. Members see it too — a member cannot change
  a schedule, but when a site is backed up is not privileged.
- **Scheduled runs.** When each schedule will next fire, and what the last few produced -
  successes as well as failures. The two panels above it are each scoped to what still needs
  somebody, so a fleet whose schedule had quietly stopped firing looked identical to a fleet with
  nothing to do. The upcoming times are projected by the same code the scheduler runs on, so the
  screen cannot promise a run the scheduler will not make.
- **The list is paginated**, fifty to a page, and the filter stays attached when you page.
- **Fixed: the "In storage" figure was measured from one page of artifacts**, not from all of them.
  Any organisation holding more than a hundred was told it was storing less than it was, on the one
  screen whose job is to say how much is being stored.

### Fleet

- **An Updates column**, showing how far behind each site is and whether any of it is a security
  release. The number was already collected and was only ever shown as an organisation-wide total in
  the sidebar, so the fleet screen could tell you there were nine updates and not which site had
  them. Sorting by it puts a security release above a larger ordinary backlog.
- **A backup that succeeded now reads as a badge**, like a backup that failed. The column carried a
  red badge for a failure and plain text for a success, which made the rows that were fine look like
  an absence of information rather than the answer.
- **A count beside Backups in the sidebar** - failures in red, work in progress quietly, nothing at
  all when there is nothing outstanding.

### Fixed

- **Two status badges have been grey since they were written.** Both asked for a tone spelled
  `amber`, which is not one the component has, so both fell back to grey while their own comments
  explained why amber was the right choice. They are the "Did not complete" and "No change" badges on
  the backup panels. There is now a check that fails the build on a tone the component cannot render.
- **Adding a column to the fleet table left every group heading one cell short.** The `colspan` was a
  literal written twenty lines from the list it had to agree with; it is derived from that list now.

## 1.4.0 — 2026-08-07

Email that looks like it came from Manager, and says what actually happened. A password gate that
asks only when it matters.

### Before you upgrade

**Read this one before you upgrade if you have written anything down about who can do what.** The
recent-authentication gate has been narrowed, and a set of actions that used to demand a password
confirmation no longer do: adding a site, issuing an enrolment code, asking for a backup (one site or
several), setting a backup schedule, acknowledging or reopening a finding, asking a site to re-check
for updates, and **downloading a backup artifact**.

Nothing changed about *who* may do any of these. Every one is still restricted by role and still
audited; what changed is only whether the password has to have been proved in the last fifteen
minutes. If your internal documentation describes the password prompt as part of one of these flows,
it is now wrong.

**The download is the one to weigh.** It hands over a complete copy of a site's database. The bytes
are ciphertext this platform cannot decrypt, sealed to recovery keys that exist only where you put
them, and the caller is still an administrator — but a session left open on an unlocked machine can
now take every artifact the organisation holds without being asked for a password. That was weighed
against the moment the button is actually pressed, which is when a site is already broken. If you
would rather it stayed gated, `MANAGER_RECENT_AUTH_MINUTES` does not help — the route is out of the
group entirely, and re-gating it is a change to `routes/web.php`.

**Nothing is required, and one thing is worth knowing.** Notification emails now arrive as HTML with
a plain-text alternative attached, where before they were plain text only. If you have a mail rule,
a helpdesk integration or a script that matches on the old shape — the aligned `Site:` / `Domain:` /
`Environment:` block, or the `Open Manager:` line — check it. The plain-text part still carries all
of the same facts in the same order, so a rule reading the text alternative keeps working; one
parsing the body of a message that is now `multipart/alternative` may not.

The subject line is unchanged, including its `[Manager]` prefix, on purpose: it is what most filters
actually match on.

### Added

- **Delete several backups at once.** Tick the boxes on the Backups screen and press **Delete
  selected**, with a tick-box in the header for all of them. Owners only, like the per-row button,
  and behind the password confirmation for the same reason — a hundred destroyed encryption keys is
  not a smaller act than one.

  It keeps the distinction the two per-row buttons make rather than flattening it. A backup that
  stored bytes is deleted and tombstoned with its key destroyed; a row for a backup that never stored
  anything is removed outright. A selection may hold both, and the summary reports the two counts
  separately instead of adding them together. Anything that had already gone by the time the button
  was pressed is named in the amber band rather than counted as done.

  The reason recorded against a bulk deletion says that it was one, so the audit log can still tell a
  backup that was chosen from one swept up with thirty-nine others.

  The per-row Delete and Remove buttons are unchanged and stay where they are. Deleting the one bad
  backup in a list should not become tick-then-press.

### Changed

- **The password gate is for changing what Manager is, not for using it.** It sat in front of one
  flat list of forty-five routes, from revoking a team member to pressing "Back up now", and the
  justification written above that list had been composed for the first kind of action and inherited
  by the second. The effect was a password prompt on the first thing anybody does with the product
  and on the thing they do most often, which is not security so much as training people to type their
  password without reading why.

  What kept the gate: settings, people, credentials, capabilities — including granting
  `backups:create`, which still also wants the site's name typed and a reason — deleting a site,
  deleting a backup, and shortening retention. The line inside backups is that creating them is
  routine and destroying them is not, so the schedule is open and retention is not.

  `tests/Invariants/RecentAuthenticationTest.php` now asserts this in both directions. It only ever
  checked that the gate had not been lost; it now also checks it has not been quietly reapplied,
  because re-gating a route is a one-word change that reads, in a diff, like somebody being careful.

- **Notification emails are branded, and this reverses a decision.** The monitoring and backup alerts
  were sent as plain text through `Mail::raw`, deliberately, on the argument that an HTML mail about
  a security finding is a phishing template somebody has been trained to click. That argument was
  real. What it missed is that the alerts were then the only mail Manager sent that did not look like
  Manager — password resets, invitations and every hosted subscription email already rendered through
  the product's own theme — so the most important message was the least recognisable one, and
  "doesn't look like the other mail from this product" is a phishing signal pointing the wrong way.

  What the old decision was really protecting is kept: an alert links to a screen and never carries a
  credential. Every link is a named route to a page that requires signing in, there are no signed or
  tokenised URLs in either part of the message, and `tests/Invariants/MailBrandingTest.php` now
  asserts all of that rather than asserting the absence of HTML.

- **An alert links to the screen the event is about.** Every alert used to end with the same URL —
  `/findings` — whatever had happened, so a backup failure pointed at a list of security findings
  that had nothing to say about it. A backup failure now opens that site's Backups tab, a silent site
  opens the site, a revoked connector opens its settings, and a confirmed permission opens the
  permissions section.

- **The password reset is written in Manager's words.** It was the framework's notification, opening
  "You are receiving this email because we received a password reset request for your account" — the
  same generic voice that invitations were moved away from, arriving at the moment somebody is
  already slightly worried. The mechanism is untouched: Laravel's broker still issues the token,
  single-use, expiring and stored hashed. Its wording is now editable wherever a hosting layer offers
  a screen for that, which the invitation's already was.

- **Emails open with a name where there is one.** "Hello Tim" rather than "Hello!", falling back to
  the old wording when the account has no name. No first name is guessed from an address.

### Fixed

- **A backup failure no longer prints its reason twice.** The reason appeared once as prose and again
  as a labelled row directly beneath it, because it is deliberately in both the event's summary and
  its context — the summary leads with it, and the webhook payload keeps it under `context.reason`.
  The email now skips any detail its own summary has already said. The webhook body is unchanged.

- **Long labels no longer run into their values** in the plain-text part, which padded every label to
  a fixed twelve characters.

## 1.3.1 — 2026-08-07

One race in 1.3.0, found within hours of it reaching a live console, which threw away a backup that
had uploaded correctly.

### Fixed

- **A job reported as failed no longer settles an artifact the platform is still assembling.**
  `POST .../assembled` hashes the whole reassembled artifact before it answers, which on a large
  database takes longer than a connector was willing to wait. The connector gave up, reported the job
  failed, and this platform then marked the artifact failed and deleted its staged parts — while its
  own assembly was still running and about to store them. Both sides agreed to discard an upload that
  had succeeded.

  Connector 1.13.1 waits, which removes the common cause. This removes the class: any client
  disconnect, at any point, can report a failure for work this platform is mid-way through finishing.
  Assembly now holds the same per-artifact lock the parts take, and the job-result path leaves an
  artifact alone while that lock is held — the assembly settles it either way, as stored if it
  completes and as failed if it does not. The job is still recorded as failed, because it was.

  The guard is deliberately narrow. An artifact nobody is assembling is settled exactly as before,
  which is what puts a reason on the backups screen instead of leaving it reading "Uploading".

- **An artifact whose parts vanish mid-assembly is refused rather than throwing.** The same race
  produced `fopen(...): No such file or directory` in the log and a 500 on the wire. It is now a
  stated rejection, the artifact is left pending rather than failed by a technicality, and the stat
  cache is cleared before the check that decides — because the file can be removed by a different
  worker, and a cached answer would let the check pass on a file that is already gone.

## 1.3.0 — 2026-08-06

Backups no longer arrive as one enormous request, which is the difference between a large database
being backed up and not.

Nothing about the artifact changes. Same encryption, same manifest, same signature, same file
`manager-restore` opens. What changes is the number of requests it takes to get here.

### Before you upgrade

**Nothing is required, and one thing is worth knowing.** If you route by path in a reverse proxy —
a `location` block for the upload route, or a rule excluding it from a body limit — it will name
`/api/connector/v1/backups/{id}/content` and will not match the two new URLs beside it. Widen it:

```nginx
# was: ^/api/connector/v1/backups/[^/]+/content$
location ~ ^/api/connector/v1/backups/[^/]+/(content(/[0-9]+)?|assembled)$ {
```

The shipped Docker image is already correct. A rule that is not widened does not break anything
immediately - parts fall through to your general handler, and if that has nginx's default 1 MB body
limit they are refused with a 413 - but it does mean you keep the failure this release exists to
remove.

### Added

- **A backup artifact is uploaded in parts.** A connector now sends bounded pieces of a few megabytes
  each and then asks the platform to assemble them, rather than holding one request open for as long
  as a customer's database takes to travel. The whole file is hashed once, on this side, against the
  checksum inside the signed manifest, and nothing reaches storage until it matches — so the
  guarantee is the one it always was, made after reassembly instead of during a single stream.

  This exists because of a specific failure. A backup was refused with an HTTP **502** carrying no
  correlation ID and no JSON, which means a web server in front of the platform ended the request
  before it reached PHP. That is a timeout, not a size limit, and it is not fixable from inside the
  application: PHP-FPM's `request_terminate_timeout` ends the process from outside, and
  `set_time_limit(0)` — which both upload routes have always called — cannot raise it. Every runbook
  in these repositories was written for a 413. Nothing anywhere mentioned a 502.

  A request that carries eight megabytes does not run into any of that, whatever size the database
  is. So the fix is not a longer request; it is not needing one.

  A part that fails is retried on its own, and a connector that loses its place is told which part to
  resume from — so a connection dropped near the end of a twenty-gigabyte artifact costs that part
  rather than the whole upload. Nothing has to be upgraded in step: the platform offers parts and a
  connector too old to know about them keeps sending the whole file exactly as before, while a newer
  connector talking to an older platform does the same.

- **`MANAGER_BACKUP_INGEST_PART_BYTES`**, 8 MB by default, and deliberately not the existing
  `MANAGER_BACKUP_PART_BYTES`. That one is 256 MB because an object store refuses a single PUT over
  5 GB; this one is small because a request must finish inside a timeout nobody here can see. The two
  numbers move in opposite directions and sharing a key would give you the failure above with extra
  steps.

- **`MANAGER_RATE_LIMIT_INGEST_SITE` and `MANAGER_RATE_LIMIT_INGEST_IP`**, 600 and 1200 a minute.
  Artifact bytes are counted against their own allowance, because a two-gigabyte backup in parts is
  two hundred and fifty requests and the ordinary limit of 60 is sized for a connector that reports
  rather than one that uploads. Separate keys rather than a larger shared number, so an upload in
  progress cannot exhaust the budget heartbeats and job claims depend on.

- **An "Upload path timeout" check in `manager:doctor`.** It reports how long one part takes on a slow
  uplink and states plainly that it cannot read PHP-FPM's `request_terminate_timeout` or nginx's
  `fastcgi_read_timeout` — the two limits that actually decide this. A check that implied otherwise
  would be worse than not having one.

### Changed

- **"Upload path ceiling" now measures against the part size, and a smaller number is enough.** The
  largest body this platform receives is one part, so `post_max_size` no longer has to be sized
  against your largest customer's database. Below the part size the check fails, because then nothing
  can be uploaded at any size by anybody. Between the part size and your configured ceiling it now
  warns instead of failing, and says who is affected: sites on a connector too old to send parts,
  which still upload the whole artifact in one request.

- **"Backup size ceiling" stopped warning about 5 GB on every self-hosted installation.** It said an
  artifact above five gigabytes could not be accepted without presigned uploads. That is no longer
  true — parts make it perfectly possible with no object store involved — so the warning now names
  the sites it is really about, which are the ones on an older connector.

- **The sweep for declarations whose bytes never arrived measures from the last part received.**
  Against the declaration alone it would write off an upload that is working, which is exactly the
  failure that had the window raised from one hour to six. Uploading in parts makes an upload longer
  than six hours genuinely possible for the first time, so this closes the same trap in its new form.

- **One failed backup no longer reads as two.** A failure writes two audit rows — the job's and the
  artifact's — because it settles two objects, and both rows stay. They were reading as unrelated
  events at the same second, because one named the connector's version and the other did not. They
  now carry the same actor, and the Activity log links a backup row through to that backup.

### Fixed

- **Six advisories in `league/commonmark`**, one of them high, all denial-of-service. It arrives
  through `laravel/framework` rather than being asked for directly, and moves 2.8.3 → 2.9.0 inside
  the constraint the framework already declares — so this is a lockfile change and nothing else.

  Unrelated to everything above, and included here because a tag cannot be changed afterwards. The
  advisories predate this release and were failing CI's *Supply chain* job on `main`; shipping a
  version of a backup product with a known high advisory in it, when the fix is a lockfile bump,
  is not a trade worth making for tidier release notes.

- **The shipped nginx configuration excluded every part of a chunked upload.** Its `location` pattern
  ended at `content$`, which does not match `content/3`, so parts would have fallen through to the
  general handler at `client_max_body_size 2m` and failed in exactly the way the single request they
  replace already did. Nothing in the test suite would have caught it, because the suite exercises
  the route and not the image.

## 1.2.0 — 2026-08-06

Four things about the console, and one of them makes an alert work that never has.

**Read *Before you upgrade* first.** Nothing here refuses to start, but the first hour after
deploying will be noisier than usual, and the reason is a fix rather than a fault.

### Before you upgrade

**Expect a burst of notifications shortly after upgrading, and read it as a backlog rather than an
incident.** Findings are now swept hourly (below), so the first run raises `site_not_reporting` for
every site that has already gone quiet and notifies every destination subscribed to it. Those sites
have been unmonitored the whole time; what changes today is that you are told. If you have sites you
know are decommissioned, archive or pause them before deploying and they are left out. A certificate
that crossed its thirty-day threshold while nobody opened the screen will surface in the same run.

**Security findings have moved off the Findings screen.** They are on a new **Security** screen in
the sidebar, listed by site rather than by rule. Nothing is hidden and nothing is deleted -
Findings states how many are outstanding and links to them - but anyone who has bookmarked Findings
as "the list of what is wrong" should know there are now two, and which is which. Acknowledgements
are untouched; they are keyed on the rule, and no rule key changed.

### Added

- **A Security screen, and a Findings screen that is no longer two things at once.** Every rule now
  declares a category, and the category decides the screen: the nine security rules are on
  **Security**, grouped by site and worst first; the nine maintenance and operational ones stay on
  **Findings**, grouped by rule. Both questions were being asked of one list ordered by severity,
  which interleaved "this client is exposed" with "this one needs a plugin update" and answered
  neither well. Nothing disappears in the split - Findings states how many security findings are
  outstanding and links to them, the two sidebar badges are disjoint by construction, and a rule key
  belonging to no category still appears on Findings rather than on nothing. Sites with no security
  findings are listed too, saying whether every rule ran or some were skipped for want of a
  capability, because an empty list is not automatically a clean bill of health. An invariant test
  fails the build if a rule is ever added without a category.
- **Back up several sites at once.** Tick-boxes on the fleet screen, with a select-all in the header
  and one per group, and a **Back up selected** button. Each site is asked separately through the
  same readiness check and the same idempotency key as the single-site button, so one site refusing
  changes nothing about the others - and what was skipped is named, with the reason, rather than
  folded into a count that says "requested". The selection survives the recent-authentication gate.
  Administrators only, like every other way of asking for a backup.
- **The site's domain is a link.** On every tab of a site, `expected_domain` now opens the actual
  site in a new tab, over HTTPS and with `rel="noopener noreferrer"`. It was inert text everywhere in
  the interface, so getting from a finding to the site it is about meant copying a hostname.
  Deliberately not on the fleet table: forty outbound links in a column people scan is forty chances
  to leave the screen by accident.
- **Notification destinations can be scoped to particular sites.** "All sites" stays the default and
  includes anything added later, so every existing destination behaves exactly as it did. Narrowing
  one is what makes "this client's alerts go to this client" possible without telling them about
  anybody else's fleet. Scope is a different question from subscription and does not narrow it: a
  scoped destination still hears about everything it asked for, and still hears anything that is
  about the installation rather than about a site. Re-checked at delivery time as well as at
  dispatch, so narrowing a destination also stops whatever was already queued. A scope that resolves
  to no recognisable site is refused rather than written, because no rows means every site and a
  scope that silently widened is the one failure worth being loud about.
- **`finding.opened` payloads carry the rule's category** alongside its severity, so a webhook
  receiver can act on exposures and file the rest without a second subscription. No new event type:
  the event strings are wire contract for receivers already deployed.
- **Findings are now evaluated on a schedule, which is what makes the "a site stops reporting" alert
  work at all.** It has been subscribable since notifications shipped and could not fire. Findings
  were only ever evaluated when a site sent a report or when somebody opened a screen, and a site
  that has stopped reporting does neither - so the one alert whose entire subject is silence was
  raised only by code paths that silence prevents from running. A destination could be correctly
  configured for it for a year and receive nothing. `manager:findings:sweep` runs hourly over every
  active site, well inside the rule's own six-hour threshold. It also gives every other
  time-dependent rule a clock to move against, and lets a fixed problem close itself without waiting
  for somebody to open a page. See *Before you upgrade*.

### Migrations

One, additive, with no backfill: `notification_destination_site`. It records which sites a
notification destination is scoped to, and **no rows means every site** - so every destination that
exists today keeps behaving exactly as it does, and there is no data step to run.

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
