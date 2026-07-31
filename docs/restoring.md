# Restoring a backup

Manager for Craft will not restore a backup into a site, and the Craft plugin has no code that
could. You do it, with a tool that runs on your machine and never contacts anything.

This page is how.

## Why Manager for Craft doesn't do it for you

The obvious feature is a "restore" button. It is missing on purpose.

Restoring a production database is destructive and irreversible. Doing it safely needs a threat
model for a compromised Manager for Craft issuing a restore, a confirmation flow that makes the
target unmistakable, a defined behaviour when it fails half way through, and a tested path back from
*that* state. None of that follows from being able to take a backup.

There is also a simpler reason. Manager for Craft cannot read your backups. A restore button would
require it to hold a key that opens them, and holding that key is exactly what we spent the rest of
the design avoiding.

So you get a `.sql` file, and you load it on a host you chose, with a command you typed.

## The tool

```bash
composer global require coysh-digital/manager-restore
```

Or download the phar from the releases page and verify it before running it:

```bash
gpg --verify manager-restore.phar.asc manager-restore.phar
```

Verifying matters more here than usual. This is the one program that will ever hold your recovery
private key, so getting it from somewhere other than the party you are protecting yourself against
is the point.

It needs PHP 8.2+ and the `sodium` extension, which is what your Craft server already runs.

It **works entirely offline** - no sockets, no config, no Manager for Craft. If Manager has been
gone for a year and all you have left is the file and the key, this still works.

## Getting the file

Download the artifact from the site's Backups tab in Manager for Craft, or from your own storage if
you are using your own bucket.

What you get is ciphertext. Manager for Craft cannot open it and neither can anything else without
your key.

## Look at it first

```bash
manager-restore inspect ./01JZX9K2Q4M8N7P6T5V3W2Y1B0.artifact
```

No key needed - everything here is in the file's own header:

```
Artifact        01JZX9K2Q4M8N7P6T5V3W2Y1B0
Site            01JRZX9K2Q4M8N7P6T5V3W2Y1B
Signed by       MGRS-4GNX-3FDQ-K2MR-0B0S-ES6F-NRP1
Taken           2026-07-29 15:46:40 UTC
Database        mysql 8.0.36
Backup size     184.2 MiB (193,152,000 bytes)
Backup SHA-256  520d2673df2ffac78e3ddbce6b2746bce26671e4901cdc65a62fe10f089f45fc

Readable by:
  MGRK-VTWG-MAB4-AMWS-RE3Z-KMH7-AD5C  Ops laptop
  MGRK-139V-DYMJ-0TMZ-3M8B-7E0Q-NE1F  Safe
```

That last section is the one to read. It is the answer to "who, other than me, can open this
backup", and it comes from the file rather than from anything Manager for Craft says.

## Check your key opens it

```bash
manager-restore verify --key=~/keys/acme-recovery.secret ./01JZX….artifact
```

A second or two. It confirms the file names your key, that your key opens the sealed copy, and that
the result decrypts the first chunk.

Be clear about what that does and does not establish. It proves your key works and the backup was
not sealed to somebody else - the failure worth catching cheaply. It does **not** prove the rest of
the file is intact. Only `decrypt` reads all of it.

## Decrypt it

```bash
manager-restore decrypt --key=~/keys/acme-recovery.secret \
                        --out=./restore.sql \
                        ./01JZX….artifact
```

This reads the whole file, checks every chunk, and verifies the result against the checksum taken
when the backup was made. If any of that fails, nothing is written - half a database dump that looks
like a whole one is the artifact most likely to be restored by mistake.

The output file is written mode 0600. It is a complete copy of a production database and now the
least protected copy in existence. Delete it when you are done.

If the dump was compressed, the tool tells you:

```bash
gunzip -c restore.sql | mysql -u root -p target_database
```

## Load it

```bash
mysql -u root -p target_database < restore.sql
# or
psql -U postgres -d target_database -f restore.sql
```

On a host you chose. Not, by reflex, the production one.

## Test restores, properly

**A backup you have not restored is a hypothesis.**

Everything up to this point proves the file is intact, encrypted to you, and decrypts to the bytes
that were taken. None of it proves the SQL loads into a working database. Nothing Manager for Craft
can do establishes that, and nothing this tool can do either.

The only thing that does is loading it into a database and looking. So:

- Pick a scratch host. A local Docker container is fine.
- Restore a real backup into it, quarterly at least.
- Actually look - does the site boot, are the entries there, is the user table populated.
- Write down when you last did it.

That last one matters more than it sounds. "We take nightly backups" and "we restored one in March
and it worked" are very different statements to be able to make when somebody asks.

## Checking a whole folder

For a monthly cron:

```bash
manager-restore audit --key=~/keys/acme-recovery.secret \
                      --also=MGRK-139V-DYMJ-0TMZ-3M8B-7E0Q-NE1F \
                      ~/backups
```

It walks every `.artifact` file and exits non-zero if anything is wrong:

| Result | Meaning |
|---|---|
| `ALIEN` | Sealed to a key you did not authorise. Treat as a compromise, not a warning. |
| `LOCKED` | Not sealed to your key at all. |
| `EDITED` | A recipient's fingerprint does not match its key. The manifest was altered. |
| `FAILED` | Your key did not open it. |
| `BAD` | Not a readable artifact. |

`--also` lists other fingerprints you authorised. Pass them explicitly - asking Manager for Craft
what to expect would be circular, since Manager is the party being audited.

This is the after-the-fact check for the one thing pinning cannot cover: a Manager for Craft
installation that changed the rules on a site that was never pinned, or was pinned late.

## Old backups

Backups taken before recovery keys existed were encrypted to Manager for Craft's own key.
`manager-restore` will tell you so rather than failing confusingly:

```
… is not a v2 Manager artifact. Artifacts taken before recovery keys existed
were encrypted to the platform rather than to you, and are retrieved with
`php artisan manager:backups:fetch` on the Manager installation itself.
```

Those are readable by Manager for Craft, which is precisely why recovery keys exist. They are
labelled on the Backups screen so you know which is which.

## If you have lost your key

Then the backups encrypted to it cannot be opened. Not by you, not by us, not by anyone.

Manager for Craft holds ciphertext and a wrapped key it cannot open. There is no escrow copy and no
key we could be compelled to produce, because we never had one.

That is the cost of the guarantee. If you are in that position now: generate a new recovery key,
register it, take fresh backups, and delete the old artifacts - they are storage cost and nothing
else.

If you are *not* in that position: keep two keys, stored differently. The secret file is 44
characters of printable text, so a copy on paper in a safe fails in a completely different way from
a laptop.
