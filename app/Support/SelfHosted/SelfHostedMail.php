<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\MailAdministration;

/**
 * The self-hosted answer: you run this, so you configure the mail.
 *
 * There is nothing to decide here. An installation on somebody's own infrastructure has exactly one
 * person who can set `MAIL_*`, and they are the person looking at Settings.
 */
final class SelfHostedMail implements MailAdministration
{
    public function operatorManaged(): bool
    {
        return true;
    }
}
