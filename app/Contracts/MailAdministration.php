<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Whether the person reading Settings is also the person who configures mail delivery.
 *
 * Self-hosted, they are the same person: mail is `MAIL_*` in a `.env` file on a server they own, and
 * the only way to know whether a password reset will actually arrive is to send one. That is what
 * the test-send button is for, and "a transport is configured" and "mail leaves this server" are
 * genuinely different claims.
 *
 * On a hosted edition they are not the same person. The relay belongs to whoever runs the service,
 * an administrator cannot change it, and a button that tests somebody else's infrastructure answers
 * a question they were not asking. The paragraph beside it is worse than the button — it tells the
 * reader to edit a `.env` file they have no access to.
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
     * nothing on this screen an administrator could usefully change or test.
     */
    public function operatorManaged(): bool;
}
