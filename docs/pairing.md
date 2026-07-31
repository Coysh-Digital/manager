# Pairing a site

Pairing is how a Craft site and Manager for Craft establish that they know each other. It happens
once per site and takes about a minute.

## The short version

In Manager for Craft, **Sites → Add site**. Name it, and give the domain it is served from. You get
a single-use enrolment code, good for fifteen minutes.

Then, on the Craft site, go to **Utilities → Manager Connector** in the control panel and paste the
code in. That is usually the easier way round: you are already signed into Craft, there is nothing
to SSH into, and the form reports a domain mismatch on the spot rather than in a command's exit
status. It needs the `utility:manager-connector` permission.

Done. The site appears in Manager for Craft within a few minutes.

If you would rather use the command line, or you are scripting a rollout across several sites, the
same thing works from a shell on the Craft server:

```bash
php craft manager-connector/pair mgr_enrol_xxxxxxxxxxxx
```

Both routes do exactly the same work, in the same order, with the same checks. Neither is a
degraded version of the other.

## What actually happens

1. **The site generates a keypair.** Ed25519, on the Craft server. The private half is encrypted
   with Craft's own security key and stored in the site's database.
2. **It sends the public half**, the enrolment code, and its own hostname. Over HTTPS, which is
   enforced - there is no setting to allow plain HTTP, and the plugin refuses before sending
   anything.
3. **Manager for Craft consumes the code** in a single atomic step, so two simultaneous attempts
   cannot both succeed, and records the public key.
4. **Manager for Craft signs its reply**, including its own public key and the nonce the site sent.
5. **The site verifies that signature** against the key it was just handed, bound to the nonce it
   just generated. Trust on first use, but verified: the reply has to be signed by the key it is
   presenting, for this exact request.

After that, every request the site makes carries an Ed25519 signature over the method, path,
timestamp, nonce and a hash of the body. Manager for Craft only ever holds public keys, so a Manager
database compromise does not let anybody speak for your sites.

## Enrolment codes

- **Single use.** Consumed the moment pairing succeeds.
- **Short-lived.** Fifteen minutes by default.
- **256 bits of randomness**, prefixed `mgr_enrol_` so a leaked one is recognisable in a log or a
  paste.
- **Only the hash is stored.** Manager for Craft cannot show you a code again; issue a new one
  instead, which invalidates the previous.
- **Bound to one site and one organisation.** A code for one site cannot pair another.

## Domain mismatch

If the hostname the site reports does not match what you entered, pairing is **held for
confirmation** rather than accepted or rejected.

This is normal, not an error. It happens when a site sits behind a CDN under a different name, or
where the primary site URL in Craft differs from the public domain. The console prints the domain
Manager for Craft expected.

The site sits in `pending_confirmation` and reports nothing at all until somebody approves it in
Manager for Craft. That is the point: a mismatch means the request did not come from where you
thought, and a human should look before it starts reporting.

Approve it on the site's page in Manager for Craft, or fix the expected domain in the site's
settings and re-pair.

## Re-pairing

If a site is already paired, pairing again is refused unless you have explicitly authorised a
replacement when issuing the code. Otherwise anybody with a fresh code could quietly take over an
existing site's identity.

Re-pair when:

- the site's keypair was lost - a restored database from before pairing, for instance;
- you rotated connector keys fleet-wide from Settings;
- you moved the site to different hosting and its stored keypair did not come with it.

The old connector is superseded rather than deleted, so the audit trail still shows it existed.

## Rotating every key at once

**Settings → Rotate all connectors**, owner only, with recent authentication. Every active connector
is revoked immediately and every site needs a fresh enrolment code.

This is the break-glass action for "we think Manager for Craft was compromised". It is disruptive on
purpose.

## Disconnecting

On the Craft site:

```bash
php craft manager-connector/disconnect
```

That deletes the keypair from the site's database. It does **not** tell Manager for Craft,
deliberately - a compromised site should not be able to remove itself from your dashboard. You would
want to see it sitting there having gone quiet.

So do both: disconnect on the site, then revoke it in Manager for Craft. Two steps, two different
purposes.

If a site is compromised, revoke it in Manager for Craft **first**. That stops it being trusted
immediately, whatever is happening on the server.

## When it goes wrong

**"the enrolment code was not accepted"** - expired, already used, or superseded by a newer one.
Issue a fresh one.

**"this site is already paired"** - disconnect on the site first, or issue a code with replacement
authorised.

**"the platform URL must use https"** - exactly what it says. There is no override, because pairing
sends the enrolment code and doing that in the clear would hand it to anybody watching.

**"could not verify the platform's response"** - something between the site and Manager for Craft
altered the reply. Check for a proxy that rewrites bodies. This is fatal by design; the site will
not pair with something it cannot verify.

**Paired but nothing appears.** Run `php craft manager-connector/status`. If it says
`pending_confirmation`, see the domain mismatch section above. If it says `active`, the schedule is
not running - check the queue with `php craft queue/run`, or your cron.
