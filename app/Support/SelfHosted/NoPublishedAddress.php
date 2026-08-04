<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\PairingAddress;

/**
 * The self-hosted answer: nobody published an address, so nothing is claimed.
 *
 * This installation is reached at whatever the operator put in front of it, and only they know what
 * that is. `APP_URL` is not it — that is what this application was told to generate links with, and
 * on a setup behind a reverse proxy, a tunnel or an internal hostname it can be right for the
 * browser and wrong for a Craft site somewhere else entirely.
 *
 * Returning null rather than a best guess is the point. A wrong address on the enrolment screen
 * would be read as instruction, followed, and produce a pairing failure whose cause is a sentence
 * Manager made up about infrastructure it cannot see.
 */
final class NoPublishedAddress implements PairingAddress
{
    public function url(): ?string
    {
        return null;
    }
}
