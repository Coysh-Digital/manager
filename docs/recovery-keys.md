# Recovery keys

A recovery key is what your backups are encrypted to. You generate it, you hold it, and Manager for
Craft never sees the other half.

This page is the one to read properly. Everything else about backups is plumbing; this is the part
that decides whether "we cannot read your backups" is true.

## How it works, briefly

When a site takes a backup it makes up a fresh encryption key for that one file. It encrypts the
database with it, then seals a copy of that key to each of your recovery keys - using the public
half, which is all it has. The sealed copies travel inside the backup file.

Manager for Craft stores the file, checks it arrived intact, and can tell you how big it is and when
it was taken. It cannot open it. There is no column in its database for a recovery private key, no
escrow copy, and no support process that could produce one.

If you want somebody else to be able to open your backups - an agency partner, a client - you add
their public key as a second recovery key. That is the same outcome as escrow, done explicitly, and
you can see it in every backup file and revoke it whenever you like.

## Making one

```bash
composer global require coysh-digital/manager-restore

manager-restore keygen --label="Ops laptop" --out=~/keys/acme-recovery
```

Two files come out:

| File | What it is |
|---|---|
| `acme-recovery.pub` | Safe to share. This is what goes into Manager for Craft. |
| `acme-recovery.secret` | The one that matters. Mode 0600, never leaves your machine. |

Both carry a fingerprint like `MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1`. That is how you refer to a key
everywhere - on screen, in your config file, in a backup's manifest. It uses an alphabet with no I,
L, O or U in it, so there is no "is that a one or an ell" when you read one over the phone.

### Why there is no button for this in Manager for Craft

Because a private key generated on our server would exist, however briefly, in a response body and
possibly in a proxy buffer. At that point "we cannot read your backups" stops being a property of
the system and becomes a promise about our own discipline. Those are different things and only one
of them survives a subpoena.

The obvious counter-argument is that a browser could generate the key locally and only send the
public half. Two problems. The browser can't actually use the result - the cryptography Manager for
Craft uses needs primitives WebCrypto doesn't have, so without shipping a large WASM bundle the
browser would produce a key it could never open a backup with. And more fundamentally: **Manager for
Craft serves that JavaScript**. Against a compromised Manager - the exact thing this is protecting
you from - a page promising to keep your private key is worth nothing.

A tool you installed separately and can inspect is worth something.

## Registering it

**Settings → Recovery keys** in Manager for Craft. Paste the whole contents of the `.pub` file,
label it, and register.

Manager for Craft gives you back a challenge. Answer it from where the secret key is:

```bash
manager-restore prove --key=~/keys/acme-recovery.secret --challenge=<paste>
```

Paste the short `MGRP-…` code it prints back into Manager for Craft and the key is active.

### Why bother with the challenge

Because almost nothing about a public key can be checked. Any 32 bytes is a valid one. Manager for
Craft can confirm it is the right length and not one of a handful of degenerate values, and that is
genuinely the end of the list.

So without a challenge you could register a key from a laptop you wiped last month, or a key whose
secret half was never written to disk, or 32 bytes of something else entirely - and Manager for
Craft would cheerfully encrypt a year of backups to it. You would find out the night you needed one.

Answering the challenge proves the private key exists, that you can find it, and that the tool runs.
It turns enrolling a key into a restore rehearsal, which is the only check that reliably catches a
key nobody can actually use.

The answer is safe to send, by the way. It is a hash of the challenge rather than the challenge
itself, so it tells us nothing about your key.

## Pinning the fingerprint on your sites

**This is the step that carries the most weight.** Everything above is undone without it.

Manager for Craft tells each site which keys to encrypt to. It has to - the site has no other way to
learn them. Which means a compromised Manager for Craft could name a key of its own, and the site
would use it. Nothing would look wrong: no error, no warning, no missing backup. Just a backup
readable by somebody else.

Pinning closes that:

```php
// config/manager-connector.php, on the Craft server.
// Create it if the site does not have one - installing the plugin does not.
return [
    'platformUrl' => 'https://manager.example.com',

    'recoveryKeyFingerprints' => [
        'MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1',
    ],

    // Once your whole fleet is pinned:
    'requirePinnedRecoveryKeys' => true,
];
```

The site recomputes the fingerprint of every key Manager for Craft offers, from the key itself
rather than from the label attached to it, and refuses the whole backup if any of them is not on
your list. It refuses rather than filtering - a response containing a key you did not authorise is
evidence of something, and quietly dropping it would hide that. And it checks before dumping, so a
refused backup never writes a copy of your database to disk.

That file is on your server, in your version control. Manager for Craft cannot change it. That is
the whole idea.

::: tip Get the fingerprint from your own file
```bash
manager-restore fingerprint ~/keys/acme-recovery.pub --quiet
```
Comparing Manager for Craft's screen against its own claim proves nothing. It can display whatever
it likes. The file on your laptop is the reference.
:::

The setting is deliberately not editable from the Craft control panel. Somebody who hijacked a CP
session and could re-pin the site would undo the only control that actually works.

## Keep two

Manager for Craft seals every backup to every active key, so a second one costs nothing and removes
a single point of failure that has no recovery path.

Store them differently. The secret file is 44 characters of printable text, so a copy on paper in a
safe fails in a completely different way from a laptop - which is exactly what you want from a
second copy.

Manager for Craft will nag you while you only have one. It is right to.

## Rotating

Two deliberate steps, never one button:

1. Generate and prove a new key.
2. Revoke the old one.

Doing it in that order means there is never a moment with nothing active. A single "rotate" button
would make it far too easy to revoke the only key you can still find.

### What revoking does and does not do

Revoking removes a key from **future** backups. That is all.

Backups already taken keep the sealed copy that was made for that key, and remain openable by it
forever. There is no way to reach into a stored artifact and change who can read it, and pretending
otherwise would be worse than useless - somebody would rely on it.

So if a recovery key is genuinely compromised, rotating is necessary but not sufficient. The backups
taken while it was active are readable by whoever has it. Judge whether those need deleting on their
own merits.

Manager for Craft will not let you revoke your last active key without an explicit written
confirmation, because that stops every backup in the organisation and doing it by accident is easy.

Keys are never deleted, only revoked. An old backup's manifest names the fingerprints it was sealed
to, and the revoked row is the only thing that still explains one a year later. It also means
somebody who managed to add a key of their own cannot tidy up after themselves.

## Re-proving

After six months Manager for Craft will ask you to demonstrate a key again. Same ceremony as
enrolment.

Nothing stops working if you ignore it - a key that quietly expired because nobody logged in for six
months would be a worse failure than the one being guarded against. But a key nobody has touched in
a year is very often a key nobody can find, and the prompt is there to make you check while it is
still a five-minute job.

## If you lose it

Then the backups encrypted to it are gone. Not "recoverable with effort" - gone.

Manager for Craft holds ciphertext, a wrapped key it cannot open, and metadata. There is no escrow
copy and no key we could be compelled to hand over, because we never had one.

This is the honest cost of the guarantee, and it is worth being clear-eyed about it before switching
backups on. Two things reduce the chance of ending up there:

- **Two keys, stored differently.** Losing one then costs nothing.
- **Re-prove them.** The prompt exists for exactly this.

If you have lost every key for an organisation, nothing in this documentation will help. Generate a
new one, register it, and take fresh backups. The old artifacts are storage cost and nothing else -
delete them.

## Audit trail

Every key decision is written to the audit log, which is hash-chained and append-only at the
database level:

- `backup.recovery_key.submitted`
- `backup.recovery_key.activated`
- `backup.recovery_key.proof_failed`
- `backup.recovery_key.revoked`
- `backup.recovery_key.reproved`

Fingerprints and labels are recorded. Challenges, answers and key material are not - and there is a
test that looks for them in the audit trail to keep it that way.

Repeated `proof_failed` entries against one key are worth looking at. Usually it is somebody using
the wrong file; occasionally it is not.
