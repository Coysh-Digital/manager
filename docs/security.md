# Security

## Reporting a vulnerability

Email **security@coysh.digital**. Please do not open a public issue.

We aim to acknowledge within two working days, and to publish an advisory once a fix is available or
after 90 days, whichever comes first. If something is being actively exploited we move faster and
say so.

## What Manager can and cannot do

The point of the design is that these are properties of the code rather than promises.

| | |
|---|---|
| No administrator password | There is nowhere in the schema to store one. A test walks the live schema for anything resembling a stored site credential, so a new table is checked the moment it is created. |
| No SSH or database credentials | Same. |
| No inbound endpoint on managed sites | The connector registers no route that accepts management input. Every exchange is started by the connector, outbound. |
| No remote execution | No console-command runner, no PHP evaluation, no SQL, no shell, no arbitrary file access. |
| Read-only by default | A newly paired site receives read capabilities and nothing else. Anything that modifies a site needs separate, explicit confirmation. |
| Public keys only | Connectors generate their keypair locally and send only the public half. A stolen copy of the platform database confers no ability to sign as any site. |

## How a connector authenticates

Every request carries the site identifier, connector version, timestamp, a random nonce, the method,
the canonical path and a hash of the body — all of it covered by an Ed25519 signature. Change any of
those and the signature stops verifying.

On receipt the platform enforces, in order: a payload size cap before parsing, required headers, a
clock tolerance of ±120 seconds, a site and connector lookup, signature verification, per-site rate
limiting, and finally a replay check.

The replay check comes last, only once a request has proved authentic, so that nobody who can merely
reach the endpoint can fill the replay store. It **fails closed**: if the store is unreachable the
request is rejected with a 503, because accepting it would mean accepting replays of it.

An unknown site and a bad signature return an identical response through the same code path, and an
unknown site is verified against a decoy key so the timing matches too. Otherwise the endpoint would
be a way to enumerate which sites exist.

Responses carrying commands or security-sensitive configuration are signed by the platform over a
string that includes the request nonce, which binds a response to the one request that asked for it.

## What a connector may report

An allowlist, enforced by a shared schema. Unknown fields are **rejected**, not stripped: silently
discarding them would let a connector widen what it collects without anyone noticing.

Permitted: Craft version and edition, PHP version, database engine and version, plugin handles and
versions, Composer package names and versions, connector version, a handful of safe configuration
booleans, queue and migration counts, locally-computed licence state, and an environment
classification.

Never: entries, assets, user records, password hashes, sessions, complete logs,
environment-variable values, security keys, licence keys, API credentials, database credentials,
complete configuration files, or arbitrary file contents.

A rejected payload is never stored, not even to help debugging — a report that failed validation is
precisely where forbidden data would be. Only the field paths are recorded.

To see what a given site would send:

```bash
php craft manager-connector/preview
```

## The audit log

Append-only, enforced two ways. A database trigger rejects `UPDATE`, `DELETE` and `TRUNCATE`, which
holds even for the table owner; and deployments are documented to connect as a role without those
privileges. `manager:doctor` warns if Manager connects as a superuser, because a superuser bypasses
privilege checks entirely.

Events are hash-chained per organisation, so anyone who does get past both still cannot alter history
without leaving a detectable break:

```bash
php artisan manager:audit:verify
```

Worth running on a schedule and after every restore: restoring an older backup is otherwise
indistinguishable from deliberate truncation.

Secrets never reach the log. A guard screens every payload and **throws** rather than redacting,
because a silent redaction would let a call site keep passing a password indefinitely with nobody
noticing.

## Account security

Password plus TOTP, with hashed single-use recovery codes. A user with a second factor is not signed
in by the password step: their identifier is parked in the session and the session is only upgraded
once the factor is satisfied, so no window exists in which one factor was enough.

Sensitive actions need the password to have been confirmed within the last fifteen minutes —
changing capabilities, revoking a connector, disabling a second factor, reading out fresh recovery
codes. A session left open on an unlocked machine is not enough.

Resetting a password does not bypass the second factor, and it ends every other session.

Passkeys are planned for Phase 2. They are a specification requirement and are not yet implemented.

## Runbooks

### A connector's key may have leaked

On the site: `php craft manager-connector/disconnect` — this deletes the key locally. Then revoke the
connector in Manager, which stops the platform accepting anything signed with it. Both steps matter:
the first stops the key being used from that server, the second stops it being used from anywhere.

Re-pair with a fresh enrolment code. The old key can never be reinstated.

### A managed site may be compromised

Revoke the connector first — that stops the site reporting and removes its capabilities in the same
transaction. Read the site's entries in the activity log for what it did while trusted. Since
capabilities are read-only by default, a compromised site could not have used Manager to change
anything.

### A platform account may be compromised

Reset the password, which ends every other session. Review that account's activity-log entries.
Check whether recovery codes were used, and regenerate them. If capabilities were changed, the
permission history on each affected site shows exactly what and when.

### The audit chain fails to verify

Treat it as an incident. Establish first whether a restore happened — that produces an intact but
shorter chain, which is expected. A chain reporting *altered* events with no restore means somebody
had enough database access to drop a trigger, and the appropriate response is a full compromise
assessment, not a repair.

### The platform signing key may have leaked

Generate a new one with `manager:keys:generate --force`. Every connector then rejects platform
responses until it learns the new public key, which means re-pairing each site. Disruptive by design:
the alternative is a platform whose instructions cannot be trusted.

## Supported versions

The current minor release receives security fixes. The previous minor receives them for 90 days
after being superseded.
