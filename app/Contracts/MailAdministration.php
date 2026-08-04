<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Whether this installation's mail relay belongs to whoever is reading Settings.
 *
 * Self-hosted, it does. The operator holds the relay, can change it, and can prove it works — which
 * is what Settings → Mail and the test send are for, because "a transport is configured" and "mail
 * leaves this server" are genuinely different claims and only the second one matters when somebody
 * is waiting for a password reset.
 *
 * On a hosted edition it does not. The relay belongs to whoever runs the service, an administrator
 * cannot change it, and a form for editing somebody else's infrastructure is worse than no form. So
 * the tab is not offered and every mail route answers 404.
 *
 * This used to be a narrower claim — that mail was `MAIL_*` in a `.env` file and therefore nothing
 * about it belonged in the interface at all. The reasoning behind that was right and is kept: whoever
 * can reach Settings is not necessarily whoever holds the relay's credentials. The conclusion was
 * wrong, because the only alternative it left was a shell on the server, and on an installation whose
 * mail has never worked the one thing that cannot be used to tell somebody how to fix it is email. So
 * the rule became a permission instead of an absence: the screen exists, it is owner-only as well as
 * edition-gated, and the credential it holds is write-only.
 *
 * A seam rather than a config key, for the same reason as the other seams: an installation should
 * behave the way it does because of what is wired into it. {@see ProductLabel} is display only and
 * explicitly may not be branched on, so this is its own contract rather than a second use of that
 * one.
 */
interface MailAdministration
{
    /**
     * Whether this installation's operator holds the mail configuration themselves.
     *
     * False on a hosted edition, where delivery is somebody else's responsibility and there is
     * nothing here an administrator could usefully change or test.
     */
    public function operatorManaged(): bool;
}
