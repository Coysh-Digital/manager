# What Manager for Craft does, and what it deliberately does not

The short answer: Manager for Craft **watches** a fleet of Craft installations and tells you what it
sees. It does not change them.

That is a design decision rather than a missing feature, and this page explains where the line is
and why it is drawn there.

## What it does

**Reports what each site is running.** Craft version and edition, PHP version, database engine and
version, every plugin and Composer package with its version. So "what is that site on?" is a glance
rather than an SSH session.

**Tells you what needs patching.** With `updates:read`, each site checks its installed versions
against published releases and reports what is available - including whether anything in between is
flagged as a security release. That last part is the one that matters: it is the difference between
"twelve sites have updates" and "two sites have a security release outstanding".

**Reports licence state.** Whether Craft and each plugin licence is valid, on trial, or expiring.
Calculated on the site; the keys are never transmitted.

**Notices configuration you would not choose.** Dev mode left on in production, HTTPS not enforced,
schema changes permitted in production, the updater enabled in production. Reported as findings with
a severity, and they resolve themselves when the site is fixed.

**Reports system state.** Queue depth, failed jobs, pending migrations, environment classification.
Counts only - never the contents of a queued job, which can carry anything.

**Takes database backups**, if you grant that separately per site. Encrypted on the site, to keys
you hold, before they leave it - Manager for Craft stores something it cannot read. See
[Backups](/backups).

**Tells somebody.** Findings can go to an email address or a webhook, so a security release on a
client site reaches you without anybody opening a dashboard.

## What it does not do

**It does not install updates.** Not Craft, not plugins, not Composer packages.

**It does not change site configuration.** It reports settings; it cannot alter them.

**It does not run console commands, evaluate PHP, run queries, read files or write files.** There is
no capability for any of these - the code to do them is not in the connector, and a script in that
repository fails the build if it appears.

**It does not restore backups.** See [Restoring a backup](/restoring) for why that waits for its own
threat model rather than being added quietly.

## Why it does not update anything

This is the question everybody asks, so here is the reasoning rather than a rule.

**An update is a deployment.** On any Craft site worth managing, `composer update` is followed by
migrations, a project-config sync, a cache clear, and someone looking at the front end. Sites have
staging environments, review processes and deploy pipelines for exactly this. A monitoring tool that
reached in and ran Composer would be bypassing all of it, and the first time it broke a client's
checkout at 5pm on a Friday you would turn it off for good.

**It would need the one thing Manager for Craft refuses to hold.** Installing anything means write
access to the filesystem and the ability to run commands. That means SSH or an equivalent, and once
Manager for Craft holds that for forty sites, Manager becomes the most attractive target in your
infrastructure. The whole security model here - outbound only, no credentials, nothing executable -
exists because it holds nothing worth stealing. Auto-updating would trade all of it for convenience.

**A compromised platform would become a supply chain.** If Manager for Craft could install packages
on every site it manages, then whoever compromised Manager could install whatever they liked,
everywhere, at once. Reporting cannot be weaponised that way.

So Manager for Craft tells you a security release is outstanding on three named sites, and you
update them the way you already update sites. The value is knowing, promptly, without checking
twelve control panels.

## If `allowUpdates` is off

Nothing changes. `allowUpdates` controls whether Craft's control panel will *install* an update; it
does not affect *checking*. Craft's update service still answers, so Manager for Craft still reports
what is available.

In fact Manager for Craft prefers it off: with `security:read` granted, "the updater is enabled in
production" is reported as a low-severity finding. A production site that can install its own
packages from a browser session is a wider attack surface than one that cannot.

## If `allowAdminChanges` is off

Nothing changes here either, and this is the configuration Manager for Craft expects of a well-run
production site. "Schema changes are permitted in production" is reported as a medium-severity
finding when `allowAdminChanges` is **on**.

Two practical notes for locked-down sites:

- The connector's screen is a **Craft utility**, not a plugin settings page. Utilities stay in the
  navigation when admin changes are off; a plugin's settings page is not linked at all, which would
  make enrolment undiscoverable exactly where it matters most.
- The Plugin Store is unavailable, so install the connector with Composer.

Neither setting needs relaxing to use Manager for Craft. If either of them had to be turned on, that
would be a reason not to use it.

## What a connector can and cannot be asked to do

The complete list of remote jobs. There are three, and adding a fourth means editing a registry
where every entry has to declare its required capability, its parameter schema, its maximum runtime
and its retry behaviour:

| Job | Requires | What it does |
|---|---|---|
| `inventory.refresh` | `inventory:read` | Gather and send operational metadata now, rather than at the next scheduled run |
| `updates.check` | `updates:read` | Check for available updates and report what is found |
| `backup.create` | `backups:create` | Dump the database, encrypt it locally, upload it |

Note what none of them takes: a command, a path, a query, a URL. A job names an operation the
connector already implements, or the connector refuses it - independently of the platform's own
refusal to issue one it does not know.

## Self-hosted or Cloud

Everything on this page is true of both editions. Self-hosted Manager for Craft is feature-complete;
Cloud adds no monitoring capability that is missing here.

What Cloud adds is that somebody else runs the server, holds the keys, watches the storage and takes
the call when Postgres fills its disk. If that appeals more than doing it yourself, see [Manager
Cloud](https://managerforcraft.com). The connector is identical, so moving between them means
re-pairing sites rather than rebuilding anything.
