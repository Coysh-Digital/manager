# Getting started

This is the whole thing end to end: a server running, a Craft site connected, and a backup taken
that only you can open. Set aside an hour or so. Most of it is waiting for Docker.

If you would rather read about the design before installing anything, [What it does, and
does not](/what-it-does) is the page for that.

## What you'll need

- A server with Docker and Docker Compose. Two cores and 2 GB of RAM is plenty for a few dozen
  sites.
- A domain pointing at it, with TLS. Manager for Craft refuses to talk to a site over plain HTTP and
  the connector refuses to talk back, so this is not optional.
- Somewhere to put backups. A local volume is fine to start with; S3 or anything S3-compatible works
  too.
- A Craft 4.4+ or Craft 5 site you can install a plugin on.

You do **not** need admin credentials for the sites you are going to monitor. Manager for Craft has
nowhere to put them.

## 1. Get it running

Get the source and start the stack. Every command in this section runs from `deploy/docker`, which is
where the compose file lives — there is none at the top of the repository.

```bash
git clone --branch v1.2.0 https://github.com/Coysh-Digital/manager.git /opt/manager
cd /opt/manager/deploy/docker
cp ../../.env.example .env
```

Open `.env` and set at minimum:

```dotenv
APP_URL=https://manager.example.com
DB_PASSWORD=            # something long and random
MANAGER_VERSION=1.2.0   # which release this is; the interface says "unreleased build" without it
```

The container will refuse to start if `APP_KEY` is empty, if `APP_DEBUG` is on, if `DB_PASSWORD` is
one of the obvious ones, or if `APP_ENV` is set to anything but `production`. That is deliberate —
those are the ones that turn up in every post-mortem, and since 1.1.0 none of them is conditional on
`APP_ENV`. They used to be, which meant copying a `.env.example` that shipped `APP_ENV=local` skipped
every one of them; [install.md](install.md) has the detail.

Now generate the three secrets. Do this once, and keep the output somewhere that is **not** your
database backup:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
docker compose run --rm --no-deps app php artisan manager:keys:generate --show
docker compose run --rm --no-deps app php artisan manager:backups:keygen --show
```

**Copy every line of that output into `.env` yourself.** The container's root filesystem is
read-only and its environment arrives from that file, so nothing can write them for you — which is
why they are printed rather than saved. `--no-deps` keeps the database and Redis from starting just
to generate a key.

- `APP_KEY` encrypts things at rest.
- The **signing keypair** is how Manager for Craft proves to your sites that instructions really
  came from it. Lose it and every site needs re-pairing. Not the end of the world, but an afternoon
  you would rather not have.
- The **backup keypair** is the platform's own encryption identity. `manager:doctor` fails once any
  site has permission to back up and this is missing, because a connector with no key to encrypt to
  refuses rather than uploading a database in the clear.

::: warning Back these up separately
Put them in a password manager, not in the database dump. A backup that contains both the encrypted
data and the key to it is not a backup, it is a copy.
:::

Then bring it up:

```bash
docker compose up -d
docker compose exec app php artisan manager:doctor
```

`manager:doctor` is the thing to trust. It checks the database, Redis, the keys, the queue, whether
the audit log's tamper protection is actually installed, and where backups are going. If it is
happy, you are up.

Full detail lives in [Installing](/install) and [Environment variables](/env). If you are putting
this behind nginx or Cloudflare, read [Behind a reverse proxy](/reverse-proxy) first - getting the
trusted proxy setting wrong is the classic way to end up with every request appearing to come from
127.0.0.1.

## 2. Create your account

Visit your `APP_URL`. The first visit offers a one-time setup screen where you create the first
account and your organisation. That screen closes itself permanently once used, so if you see it
later than you expect, something is wrong.

There is no public registration on self-hosted, and no environment variable to turn it on. You add
colleagues from Settings, which sends them a single-use link to set their own password. No password
ever passes through you - an admin who can set a colleague's password can sign in as them, and one
who can only send a link cannot.

While you are in Settings, turn on **two-factor** for the organisation. This is a control plane for
every site you look after; a stolen session here is worth more than a stolen session almost anywhere
else.

## 3. Set up your recovery key

Do this **before** connecting sites. It takes five minutes and it decides whether your backups are
genuinely private or merely encrypted-in-transit.

Manager for Craft encrypts each backup to a key you hold. It never sees the other half and has
nowhere to put one, which means we cannot read your backups - and also that we cannot help if you
lose the key.

Install the tool and make a key:

```bash
composer global require coysh-digital/manager-restore

manager-restore keygen --label="Ops laptop" --out=~/keys/acme-recovery
```

That writes two files. The `.pub` half goes into Manager for Craft; the `.secret` half is yours and
does not leave your machine.

In Manager for Craft, go to **Settings → Recovery keys**, paste the contents of the `.pub` file, and
register it. Manager will hand you back a challenge. Answer it:

```bash
manager-restore prove --key=~/keys/acme-recovery.secret --challenge=<paste the challenge>
```

Paste the short code it prints back into Manager for Craft and the key goes active.

That challenge is not ceremony for its own sake. Any 32 bytes is a valid X25519 public key, so
without it you could register a key from a laptop you wiped last month, take backups happily for a
year, and find out on the one night it mattered. Answering it proves you can actually decrypt -
which means you have the tool, you can find the file, and it works.

[Recovery keys](/recovery-keys) covers rotation, second keys and what happens when one is lost.

## 4. Connect a site

In Manager for Craft, **Sites → Add site**. Give it a name and the domain the site is served from.
Manager generates a single-use enrolment code, valid for fifteen minutes.

On the Craft site:

```bash
composer require coysh-digital/craft-manager-connector
php craft plugin/install manager-connector
```

Installing the plugin does not create a config file, and there is no config file to find until you
write one. That is not an oversight - it is optional, and the connector works without it.

Create `config/manager-connector.php` anyway. It is the file the fingerprint pin lives in at step 5,
and creating it now saves coming back:

```php
<?php

return [
    'platformUrl' => 'https://manager.example.com',
];
```

`vendor/coysh-digital/craft-manager-connector/src/config.php` is a commented template you can copy
if you want to see every option.

Setting `platformUrl` here rather than typing it on the pairing screen also locks the address: with
it set, the pairing screen shows it read-only, so a hijacked control panel session cannot repoint
the site at somebody else's Manager. Without it, you type the address when you pair - that works,
and the site is paired either way.

Then pair:

```bash
php craft manager-connector/pair mgr_enrol_xxxxxxxxxxxx
```

Within a few minutes the site appears in Manager for Craft with its Craft version, PHP version,
plugins and available updates.

The private signing key for that site was generated on the Craft server during pairing and never
leaves it. Manager for Craft only ever sees the public half. See [Pairing a site](/pairing) if
anything goes sideways - the most common cause is a domain mismatch, which holds the pairing for
confirmation rather than accepting it.

## 5. Pin your recovery key on the site

This is the step people skip, and it is the one that carries the most weight.

Add the fingerprint to the same `config/manager-connector.php` - creating it now if you skipped it
at step 4, because this is the one thing that has nowhere else to live:

```php
<?php

return [
    'platformUrl' => 'https://manager.example.com',

    'recoveryKeyFingerprints' => [
        'MGRK-4F3A-9C2B-7D18-E605-2A9F-33C1',
    ],
];
```

Get the fingerprint from your own file, not from Manager for Craft's screen:

```bash
manager-restore fingerprint ~/keys/acme-recovery.pub --quiet
```

Here is why it matters. Manager for Craft tells the site which keys to encrypt to, because the site
has no other way to learn them. A Manager installation that had been compromised - or that was
handed a court order - could name a key of its own, and the site would encrypt to it. No error. No
missing backup. Just a backup somebody else can read.

The pinned list lives on **your** server, in **your** version control. Manager for Craft cannot
reach it. If Manager offers a key that is not on the list, the site refuses the whole backup, before
it dumps anything.

Once your fleet is pinned, add `'requirePinnedRecoveryKeys' => true` so an unpinned site refuses
outright rather than falling back.

## 6. Turn on backups

Backups need their own permission, granted per site, and it is not a checkbox. Go to the site's
**Capabilities** tab and grant `backups:create`. You will be asked to confirm your password, type
the site's name, and give a reason, which is recorded.

That friction is on purpose. Every other permission lets Manager for Craft read version numbers;
this one lets it ask for a copy of the database.

If the **Back up now** button is not offered, the screen says which condition is unmet rather than
leaving you to guess: no connector yet, no active recovery key, or a backup already outstanding for
that site. The same checks run before a nightly backup is queued, so what the screen tells you is
what the scheduler would decide.

Then take one, from the site's **Backups** tab. What happens next:

1. The site checks the offered keys against your pinned list. Anything unexpected and it stops here.
2. Craft dumps its own database, using its own connection.
3. The site encrypts it, generating a fresh key for this backup and no other.
4. That key is sealed to each of your recovery keys.
5. The encrypted file is uploaded.
6. Manager for Craft checks the bytes arrived intact and records the metadata.
7. Both temporary files on the Craft server are deleted, whatever happened.

Manager for Craft now holds a file it cannot open, and knows how big it is, when it was taken and
what it should hash to.

Set a schedule from the site's **Backups** tab - daily or weekly, at an hour you choose, in that
site's own time zone so "03:00" means the quiet hour where the site is rather than where your
server is.

## 7. Prove you can actually restore

Not optional, and not something to leave for later.

```bash
manager-restore verify --key=~/keys/acme-recovery.secret ./01JZX….artifact
```

That takes a second and tells you your key opens the file. Do it monthly across everything you have
downloaded:

```bash
manager-restore audit --key=~/keys/acme-recovery.secret ~/backups
```

`audit` exits non-zero if any backup is sealed to a key you did not authorise, which is the check
that catches a Manager for Craft installation that changed the rules after you stopped looking.

But verifying is not restoring. A file that decrypts is a file that decrypts; whether the SQL inside
loads into a working database is a different question, and the only way to answer it is to load it
into one:

```bash
manager-restore decrypt --key=~/keys/acme-recovery.secret \
                        --out=./restore.sql ./01JZX….artifact

mysql -u root -p scratch_database < restore.sql
```

Do that on a scratch host, on a schedule, and write down when you last did it. Until then you have a
file that decrypts, which is not the same thing as a backup that works.

## What now

- [What it watches](/monitoring) - findings, updates, certificates, uptime.
- [Backups](/backups) - the full picture, including retention and what the states mean.
- [The Craft plugin](/craft-plugin) - every setting, and what the site does and does not send.
- [Security](/security) - the threat model, stated plainly, including what it does not cover.

And set up notifications. A monitoring system nobody looks at is decoration; one that emails you
when a site goes quiet is a monitoring system.
