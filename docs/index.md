---
layout: home

hero:
  name: Manager
  text: One screen for every Craft site you look after
  tagline: Versions, updates, findings and encrypted backups across the whole fleet — without holding a single admin password, SSH key or database credential.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: What it does, and does not
      link: /what-it-does

features:
  - title: It holds nothing worth stealing
    details: There is no admin password, no SSH key and no database credential anywhere in the schema. Not encrypted, not hidden — there is no column. A test walks the live database on every run to keep it that way.
  - title: Backups only you can open
    details: A site encrypts its own database to keys you generated on your own machine, then uploads it. We store the ciphertext and genuinely cannot read it. Neither can anyone who steals the server.
  - title: Sites come to it, not the other way round
    details: Every exchange starts at the Craft site and goes outbound. Nothing listens, nothing gets pushed, and a site behind NAT works with no inbound firewall rule at all.
  - title: You decide what each site may do
    details: A newly paired site can report its own version numbers and nothing else. Everything past that is granted per site, and taking a backup needs its own deliberate confirmation.
---

## Is this for you?

Manager is built for the person looking after ten to forty Craft sites who wants to answer "what
changed since last week?" before the client call, without logging into ten control panels.

It watches. It does not deploy, it does not run updates, and it cannot execute anything on a site.
That is a design decision rather than a roadmap gap, and [What it does, and does
not](/what-it-does) explains where the line sits and why.

## Two editions, same code

**Manager Self-Hosted** is this repository. Free, complete, and yours to run. Everything is here —
there is no reduced edition and nothing held back for a paid tier.

**[Manager Cloud](https://coysh.digital/manager)** is the same core, run by us. Same connector, same
protocol, same security boundaries. What you are paying for is that the server, the storage and the
on-call rota are somebody else's problem.

You can move between them. The connector is identical, so migrating means re-pairing your sites
rather than rebuilding anything.

These docs are for self-hosting.

## Where to start

If you are installing for the first time, [Getting started](/getting-started) walks the whole thing
end to end — server up, first site connected, first backup taken — in about an hour.

If you already have it running and want to switch on encrypted backups, go straight to [Recovery
keys](/recovery-keys). That is the part with a step people skip, and skipping it undoes most of the
point.
