<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Whether the person reading a screen can reach the machine it is served from.
 *
 * Self-hosted they can, and it is most of the point: Manager runs on their infrastructure, and a
 * command is often the honest answer to "how do I get this file". `manager:backups:fetch` streams
 * and verifies an artifact in one pass with no timeout to lose, which no web request can promise,
 * and `manager:audit:verify` checks the audit chain against itself.
 *
 * On a hosted edition they cannot. The console is a service, the shell belongs to whoever runs it,
 * and a paragraph that ends "run this on the server" is instructions for a machine the reader has
 * no account on — worse than saying nothing, because it reads as the answer and there is no way to
 * follow it. {@see MailAdministration} exists because exactly this happened once already: paying
 * customers were told to edit `MAIL_*` in a `.env` file they cannot reach.
 *
 * A seam rather than a config key, like the others: an installation behaves the way it does because
 * of what is wired into it, not because of a value somebody could set by accident. {@see
 * ProductLabel} is display only and explicitly may not be branched on, so this is its own contract
 * rather than a second reading of that one.
 *
 * It says nothing about the *managed sites*. A hosted customer still owns those, still has a shell
 * on them, and `php craft manager-connector/pair` is still the right instruction — this is only
 * about the machine Manager itself runs on.
 */
interface ServerAccess
{
    /**
     * Whether the reader can run a command on the machine serving this page.
     *
     * False on a hosted edition, where a command line is not an instruction anybody can follow.
     */
    public function reachable(): bool;
}
