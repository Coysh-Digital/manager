<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\ProductLabel;

/**
 * This repository is the self-hosted product, so this is the honest answer with nothing installed.
 */
final class SelfHostedLabel implements ProductLabel
{
    public function label(): string
    {
        return 'Self-hosted';
    }
}
