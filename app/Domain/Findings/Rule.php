<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * One rule that turns reported metadata into a finding.
 *
 * Rules live on the platform rather than in the connector deliberately. There is one place to fix a
 * rule, adding one needs no connector release, and - the part that matters - a compromised site
 * cannot decide what its own findings are. A site reports facts; the platform draws conclusions.
 *
 * A rule returns null when it does not apply. That is what makes findings self-resolving: the
 * evaluator compares what matched this time against what is open, and closes the difference.
 */
interface Rule
{
    /**
     * Stable identifier, never renamed once released - acknowledgements are keyed on it.
     */
    public function key(): string;

    /**
     * Which screen this rule's findings belong on.
     *
     * Declared by the rule rather than inferred from its severity or its capability. Both were tried
     * as proxies and both are wrong: a critical disk is not a security problem, and
     * `certificate_expiring` needs no capability at all because the platform observes it directly.
     *
     * One of the {@see RuleCategory} constants. `RuleCategoryTest` asserts every rule answers with a
     * known one, so a new rule cannot quietly appear on no screen.
     */
    public function category(): string;

    /**
     * Evaluate against a site's most recent reports.
     */
    public function evaluate(Snapshot $snapshot): ?RuleMatch;

    /**
     * Which capability this rule needs to say anything useful.
     *
     * A rule whose capability is not granted is skipped rather than reported as clean. "We do not
     * know" and "there is nothing wrong" are different answers, and conflating them is how a real
     * problem gets a clean bill of health.
     */
    public function requiresCapability(): ?string;
}
