<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * How an email opens.
 *
 * `MailMessage::greeting('Hello')` renders as a bare "Hello!", which is what every email this
 * installation sends currently says - including the ones telling somebody their payment failed or
 * their backups are about to be deleted. It reads like a form letter at exactly the moments a person
 * is most likely to be deciding whether this is a real message from a real company.
 *
 * One function rather than the same three lines in fourteen notification classes, and it is in the
 * core rather than beside any one of them because the hosting layer's subscription emails need the
 * same answer as the invitation does.
 *
 * **No first name is guessed from an address.** `tim@timcoysh.co.uk` does not reliably yield "Tim",
 * and "Hello Info" or "Hello Accounts" is worse than no name at all. Where there is no name on the
 * account, this is exactly what it was before.
 */
final class Greeting
{
    public static function for(?object $notifiable): string
    {
        $name = trim((string) ($notifiable->name ?? ''));

        if ($name === '') {
            return 'Hello';
        }

        // The first word, so "Tim Coysh" opens "Hello Tim". A single-word name is already that.
        return 'Hello '.explode(' ', $name)[0];
    }
}
