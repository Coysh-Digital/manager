<?php

declare(strict_types=1);

namespace App\Domain\Advisories;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Packagist's public security-advisories API.
 *
 * Packagist aggregates the GitHub advisory database and FriendsOfPHP's, and serves them keyed by
 * Composer package with the affected range already written as a Composer constraint. One request
 * covers every package asked for, it needs no account or token, and it is the same source
 * `composer audit` reads.
 *
 * **The request says which packages, and nothing else.** No installation identifier, no site, no
 * version: Manager asks for the whole list and works out what applies locally, so the feed learns
 * nothing about what anybody runs. The user agent is generic for the same reason.
 *
 * Anything unexpected throws, and the caller leaves what it already holds alone. An empty or
 * malformed answer must never be read as "there are no advisories".
 */
final class AdvisoryFeed
{
    public const ENDPOINT = 'https://packagist.org/api/security-advisories/';

    /**
     * @param  list<string>  $packages
     * @return array{advisories: list<array<string, mixed>>, skipped: int}
     *
     * @throws RuntimeException
     */
    public function fetch(array $packages): array
    {
        $query = implode('&', array_map(
            static fn (string $package): string => 'packages[]='.rawurlencode($package),
            $packages,
        ));

        $response = Http::timeout(20)
            ->withUserAgent('Manager advisory sync')
            ->acceptJson()
            ->get(self::ENDPOINT.'?'.$query);

        if (! $response->successful()) {
            throw new RuntimeException('The advisory feed answered HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['advisories'] ?? null)) {
            throw new RuntimeException('The advisory feed did not answer in the expected shape.');
        }

        $advisories = [];
        $skipped = 0;

        foreach ($packages as $package) {
            $listed = $body['advisories'][$package] ?? [];

            if (! is_array($listed)) {
                throw new RuntimeException("The advisory feed's list for {$package} was not a list.");
            }

            foreach ($listed as $entry) {
                $row = $this->normalise($package, $entry);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $advisories[] = $row;
            }
        }

        return ['advisories' => $advisories, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>|null null for an entry that cannot be used
     */
    private function normalise(string $package, mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $sourceId = $entry['advisoryId'] ?? null;
        $range = $entry['affectedVersions'] ?? null;

        // Without an identifier it cannot be matched on the next sync, and without a readable range
        // it cannot be applied to anything. Dropped and counted, never guessed at.
        if (! is_string($sourceId) || $sourceId === '' || ! is_string($range) || ! VersionConstraint::isReadable($range)) {
            return null;
        }

        return [
            'package' => $package,
            'source_id' => $sourceId,
            'advisory_id' => is_string($entry['remoteId'] ?? null) ? $entry['remoteId'] : null,
            'cve' => is_string($entry['cve'] ?? null) ? $entry['cve'] : null,
            'severity' => is_string($entry['severity'] ?? null) ? strtolower($entry['severity']) : null,
            'affected_versions' => $range,
            'link' => is_string($entry['link'] ?? null) ? mb_substr($entry['link'], 0, 255) : null,
            'reported_at' => is_string($entry['reportedAt'] ?? null) ? $entry['reportedAt'] : null,
        ];
    }
}
