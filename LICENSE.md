# Licence

**Manager Self-Hosted is free software, licensed under the GNU Affero General Public License,
version 3 or later.** The full text is in [LICENSE](LICENSE).

Copyright (c) Coysh Digital.

## What that means in practice

You may read it, run it, modify it and redistribute it. You may run it for your own websites, for
your clients' websites, and commercially. You may offer it to other people as a hosted service.

The one obligation the AGPL adds over an ordinary GPL is section 13: if you modify Manager and let
other people use your modified version **over a network**, you have to offer those users the source
of your modified version. Running an unmodified copy triggers nothing. Running a modified copy for
yourself triggers nothing. It applies when you host your changes for other people.

That is a deliberate choice rather than a default. Manager is a control plane holding the keys to
other people's backups, and the case for publishing it is that its security properties can be
verified rather than asserted. A fork that carried the trust of this one while hiding what it
actually does would undo the only reason for publishing in the first place.

## The other repositories

| Repository | Licence | Why |
|---|---|---|
| [manager](https://github.com/Coysh-Digital/manager) | AGPL-3.0-or-later | This one. The control plane. |
| [craft-manager-connector](https://github.com/Coysh-Digital/craft-manager-connector) | MIT | It runs inside somebody else's Craft installation. A copyleft licence on a plugin that sits in a customer's own codebase would raise a question they should never have to ask their lawyer. |
| [manager-protocol](https://github.com/Coysh-Digital/manager-protocol) | MIT | The signed contract between the two. Anyone should be able to read, verify or reimplement it without asking us. |

Manager Cloud is a separate, private package that runs on top of this one. It is not covered by this
licence and is not published. It contains no capability the self-hosted edition lacks: the difference
is who holds the keys, the storage and the on-call rota.

## No warranty

Section 15 of the AGPL disclaims all warranties, and that disclaimer is not boilerplate to skim past.

Manager takes backups of production databases and holds the keys that open them. **Test your
restores.** See [docs/backup.md](docs/backup.md).

## Questions

Anything this does not answer: **support@managerforcraft.com**.
