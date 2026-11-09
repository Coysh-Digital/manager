<?php

declare(strict_types=1);

namespace App\Domain\Advisories;

use App\Domain\Findings\Severity;
use App\Models\SecurityAdvisory;
use InvalidArgumentException;

/**
 * Which published advisories a Craft version is inside the affected range of.
 *
 * The stored feed plus {@see CuratedAdvisories}, deduplicated on the advisory id. Ranges the matcher
 * cannot read are skipped, not treated as matching: they were already filtered at sync, so reaching
 * one here means the table was written by something else, and it is safer to say nothing about one
 * advisory than to raise a critical finding from a range nobody could parse.
 *
 * **A version is affected on the number alone.** Whether a particular site is exploitable depends on
 * how it is configured, and the connector does not report that, so the finding says what the number
 * proves and where to read the rest.
 *
 * @phpstan-type Affecting array{
 *     id: string,
 *     cve: string|null,
 *     severity: string,
 *     fixed_in: string|null,
 *     link: string|null
 * }
 */
final class CraftAdvisories
{
    public const PACKAGE = 'craftcms/cms';

    /**
     * Worst first, then by identifier, so the first few are the ones worth naming.
     *
     * @return list<Affecting>
     */
    public static function affecting(string $version): array
    {
        if (! VersionConstraint::isComparable($version)) {
            return [];
        }

        $affecting = [];

        foreach (self::all() as $advisory) {
            try {
                if (! VersionConstraint::matches($version, $advisory['affected_versions'])) {
                    continue;
                }

                $fixed = VersionConstraint::fixedIn($version, $advisory['affected_versions']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $affecting[] = [
                'id' => $advisory['advisory_id'] ?? $advisory['source_id'],
                'cve' => $advisory['cve'],
                // A severity the feed leaves out, or spells some other way, is medium: serious enough to be
                // raised, not so serious as to claim something the feed did not.
                'severity' => in_array($advisory['severity'], Severity::ordered(), true) ? $advisory['severity'] : Severity::MEDIUM,
                'fixed_in' => $fixed,
                'link' => $advisory['link'],
            ];
        }

        usort($affecting, static function (array $a, array $b): int {
            return [Severity::rank($a['severity']), $a['id']] <=> [Severity::rank($b['severity']), $b['id']];
        });

        return $affecting;
    }

    /**
     * Whether any advisory has ever been loaded, from the feed or otherwise.
     *
     * Lets the rule's documentation and the doctor say "the feed has not run" instead of leaving an
     * empty table to look like a clean fleet.
     */
    public static function hasFeedData(): bool
    {
        return SecurityAdvisory::query()->where('package', self::PACKAGE)->exists();
    }

    /**
     * @return list<array{advisory_id: string|null, source_id: string, cve: string|null, severity: string|null, affected_versions: string, link: string|null}>
     */
    private static function all(): array
    {
        $stored = SecurityAdvisory::query()
            ->where('package', self::PACKAGE)
            ->get()
            ->map(static fn (SecurityAdvisory $advisory): array => [
                'advisory_id' => $advisory->advisory_id,
                'source_id' => $advisory->source_id,
                'cve' => $advisory->cve,
                'severity' => $advisory->severity,
                'affected_versions' => $advisory->affected_versions,
                'link' => $advisory->link,
            ])
            ->all();

        $known = array_filter(array_column($stored, 'advisory_id'));

        foreach (CuratedAdvisories::ENTRIES as $entry) {
            if (in_array($entry['advisory_id'], $known, true)) {
                continue;
            }

            $stored[] = [
                'advisory_id' => $entry['advisory_id'],
                'source_id' => 'curated:'.$entry['advisory_id'],
                'cve' => $entry['cve'],
                'severity' => $entry['severity'],
                'affected_versions' => $entry['affected_versions'],
                'link' => $entry['link'],
            ];
        }

        return $stored;
    }
}
