<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A PHP extension Craft requires and this site does not have.
 *
 * Rare, and specific when it happens. The usual cause is a host that rebuilt a container from a
 * slimmer base image, or a PHP minor upgrade where one extension was not reinstalled - so it appears
 * on a site that has been running happily for a year, without anybody changing the site.
 *
 * What makes it worth reporting rather than waiting for is that the symptom is almost never the
 * cause. Missing `intl` breaks date formatting in one language. Missing `fileinfo` breaks asset
 * uploads for some file types and not others. Missing `zip` breaks updates and plugin installs but
 * nothing a visitor sees. Each of those gets investigated as an application bug for a while first.
 *
 * The list this reads is fixed in the wire schema, so it can only ever name one of Craft's published
 * requirements. A site running something unusual is not described here in either direction.
 */
final class RequiredExtensionMissing implements Rule
{
    public function key(): string
    {
        return 'required_extension_missing';
    }

    public function category(): string
    {
        return RuleCategory::OPERATIONAL;
    }

    public function requiresCapability(): string
    {
        return 'runtime:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentRuntime()) {
            return null;
        }

        // Null is a connector too old to have looked; an empty array is one that looked and found
        // none. Only the second is an all-clear, and neither is a finding.
        $missing = $snapshot->runtime?->missingExtensions();

        if ($missing === null || $missing === []) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::HIGH,
            title: count($missing) === 1
                ? 'The '.$missing[0].' PHP extension is missing'
                : count($missing).' PHP extensions Craft requires are missing',
            detail: sprintf(
                'Craft requires %s, and %s not loaded. This usually appears without anybody '
                .'changing the site - a container rebuilt from a slimmer base image, or a PHP minor '
                .'upgrade where an extension was not reinstalled. It is worth fixing before somebody '
                .'meets the symptom, because the symptom rarely looks like the cause: missing intl '
                .'presents as a date formatting bug, missing fileinfo as uploads failing for some '
                .'file types, missing zip as updates that will not apply.',
                implode(', ', $missing),
                count($missing) === 1 ? 'it is' : 'they are',
            ),
            evidence: ['missing' => $missing],
        );
    }
}
