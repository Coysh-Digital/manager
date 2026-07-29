<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * The outcome of verifying one audit chain.
 */
final class AuditChainResult
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly ?int $organisationId,
        public readonly int $eventsChecked,
        public readonly array $problems,
    ) {}

    public function isIntact(): bool
    {
        return $this->problems === [];
    }
}
