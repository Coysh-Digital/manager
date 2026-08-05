# What Manager for Craft is

One screen for every Craft site you look after: versions, updates, findings and encrypted backups,
without holding a single administrator password, SSH key or database credential.

## Is this for you?

Manager for Craft is built for the person looking after ten to forty Craft sites who wants to answer
"what changed since last week?" before the client call, without logging into ten control panels.

It watches. It does not deploy, it does not run updates, and it cannot execute anything on a site.
That is a design decision rather than a roadmap gap, and [What it does, and does
not](/what-it-does) explains where the line sits and why.

## What makes it different

**It holds nothing worth stealing.** There is no administrator password, no SSH key and no database
credential anywhere in the schema. Not encrypted, not hidden: there is no column. A test walks the
live database on every run to keep it that way.

**Backups only you can open.** A site encrypts its own database to keys you generated on your own
machine, then uploads it. What gets stored is ciphertext, and so is what anyone who steals the
server gets. See [Recovery keys](/recovery-keys).

**Sites come to it, not the other way round.** Every exchange starts at the Craft site and goes
outbound. Nothing listens, nothing gets pushed, and a site behind NAT works with no inbound firewall
rule at all.

**You decide what each site may do.** A newly paired site can report its own version numbers and
nothing else. Everything past that is granted per site, and taking a backup needs its own deliberate
confirmation. See [Permissions](/capabilities).

## Nothing is held back

This repository is the whole product. Every monitoring, findings, jobs and backup feature is here,
free to run for your own and your clients' sites — there is no reduced edition and nothing reserved
for a paid tier.

A hosted option exists at [managerforcraft.com](https://managerforcraft.com) for people who would
rather not run a security-sensitive service themselves. It is the same code, and the connector is
identical, so moving between the two means re-pairing sites rather than rebuilding anything.

These docs are for self-hosting.

## Where to start

If you are installing for the first time, [Getting started](/getting-started) walks the whole thing
end to end, from the server coming up to the first site connected and the first backup taken, in
about an hour.

If you already have it running and want to switch on encrypted backups, go straight to [Recovery
keys](/recovery-keys). That is the part with a step people skip, and skipping it undoes most of the
point.
