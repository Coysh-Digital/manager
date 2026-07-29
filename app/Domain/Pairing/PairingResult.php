<?php

declare(strict_types=1);

namespace App\Domain\Pairing;

use App\Models\Connector;
use App\Models\Site;

/**
 * What a successful pairing produced.
 */
final class PairingResult
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public readonly Site $site,
        public readonly Connector $connector,
        public readonly array $capabilities,
        public readonly bool $isLive,
    ) {}
}
