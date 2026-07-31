# Permissions

What a site may report is decided per site, in Manager for Craft, and defaults to almost nothing.

A freshly paired site can tell you its Craft version, PHP version and database engine. Everything
else is granted deliberately, and taking a backup has a confirmation flow of its own.

## The list

| Capability | Granted at pairing | What it permits |
|---|---|---|
| `inventory:read` | Yes | Plugins and Composer packages, with versions |
| `updates:read` | No | Which updates exist, and whether any are security releases |
| `licences:read` | No | Craft and plugin licence state. Never the keys themselves |
| `security:read` | No | Configuration flags: dev mode, admin changes, HTTPS enforcement |
| `system:read` | No | Queue depth and pending migrations |
| `runtime:read` | No | Disk usage, PHP limits, sampled response timings |
| `logins:read` | No | Counts of failed control-panel sign-ins |
| `backups:create` | **Never** | Take an encrypted database backup |

Core fields need no capability: connector version, Craft version and edition, PHP version,
database engine, environment. They are what makes a site identifiable at all.

## Granting and revoking

Site page → **Capabilities**. Administrators can grant anything except `backups:create`. Recent
authentication is required, and every change is recorded in the audit log with who did it and when.

Revoking takes effect on the site's next signed exchange, within five minutes. The site adopts its
new permissions from a signature-verified response, so revocation genuinely stops collection rather
than just hiding it in the interface.

## Why `runtime:read` is separate from `system:read`

They look similar and are not.

`system:read` reads numbers Craft already has to hand - queue depth, pending migrations. It costs
nothing.

`runtime:read` means walking asset volume directories to measure them, and timing every request the
site serves. Those are activities rather than lookups, and folding them into an existing grant would
make sites start doing both without anybody deciding to.

## `backups:create`

This one is different in kind and the interface treats it that way.

Granting it requires an administrator, recent password confirmation, typing the site's name, and a
reason that is stored verbatim. It is never granted at pairing and there is no bulk action for it.

That is because every other capability lets Manager for Craft read facts *about* a site. This one
lets it ask for the site's data.

Backups also need an active recovery key on the organisation before anything will run. See
[Backups](/backups).

## What no capability permits

Worth stating, because it is the more useful half of the list. There is no capability - granted,
ungranted or hypothetical - that permits:

- running a console command, PHP or SQL;
- reading or writing files;
- installing, updating or disabling anything;
- changing any Craft setting;
- reading entries, users, assets or any content;
- restoring a backup.

Those are not permissions that default to off. There is no code in the plugin that could do them,
and a build check fails the release if somebody adds one.

## What a granted capability actually gets you

Even with everything granted, reports are checked against a strict allowlist on both sides. A report
carrying a field the schema does not name is **rejected**, not stripped.

That distinction matters. Silently dropping unrecognised fields would let a connector start
collecting more than it should - through a bad change or a bad actor - without anybody noticing.
Failing loudly makes it visible immediately.

To see exactly what a site would send under its current grants:

```bash
php craft manager-connector/preview
```

## Related

- [What it watches](/monitoring) - what each report turns into on screen
- [The Craft plugin](/craft-plugin) - the field-by-field list
- [Backups](/backups) - the extra conditions on `backups:create`
