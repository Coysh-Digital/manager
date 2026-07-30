<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * What a rule found.
 *
 * Named RuleMatch rather than Match because the latter is reserved.
 *
 * The detail is written for whoever has to act on it: what is wrong, and what to do about it. A
 * finding that says only "dev mode enabled" makes the reader go and look up why that matters, which
 * is work the rule should have done for them.
 */
final class RuleMatch
{
    /**
     * @param  array<string, mixed>  $evidence  what the rule saw; booleans, versions and counts only
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $evidence = [],
    ) {}
}
