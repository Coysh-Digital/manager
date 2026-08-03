<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\ServerAccess;

/**
 * The self-hosted answer: it is your server, so of course you can reach it.
 *
 * Nothing to decide. Somebody running Manager on their own infrastructure has a shell on the
 * machine serving the page they are reading, and a command is often the better instruction — it can
 * stream a multi-gigabyte artifact without a request timing out underneath it.
 */
final class OwnServerAccess implements ServerAccess
{
    public function reachable(): bool
    {
        return true;
    }
}
