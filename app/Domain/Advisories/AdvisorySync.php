<?php

declare(strict_types=1);

namespace App\Domain\Advisories;

use App\Models\SecurityAdvisory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brings the stored advisories in line with the feed, and never makes them worse.
 *
 * Three guarantees, each of which exists because the alternative reads as "no vulnerabilities":
 *
 *  - **A failed fetch changes nothing.** The feed throws and this does not catch it, so the table is
 *    exactly as it was and the command exits non-zero.
 *  - **An empty answer for a package is not a clean bill of health.** Craft has had well over a
 *    hundred advisories; a response that lists none for it is a malfunction at the other end, and
 *    removing everything stored because of it would silence every site at once. Nothing is removed
 *    for a package the feed returned nothing for.
 *  - **Only what the feed stopped listing is removed**, and only for a package it did list. An
 *    advisory that was withdrawn should stop being raised; one the feed merely failed to return this
 *    time should not.
 */
final class AdvisorySync
{
    public function __construct(private readonly AdvisoryFeed $feed) {}

    /**
     * The packages Manager checks sites against.
     *
     * Craft core only. Plugins are a different problem: the inventory reports a plugin's handle, and
     * the feed is keyed by Composer package name, and nothing here maps one to the other.
     *
     * @return list<string>
     */
    public static function packages(): array
    {
        return ['craftcms/cms'];
    }

    /**
     * @return array{stored: int, removed: int, skipped: int}
     */
    public function sync(): array
    {
        $fetched = $this->feed->fetch(self::packages());
        $now = Carbon::now();

        $byPackage = [];

        foreach ($fetched['advisories'] as $row) {
            $byPackage[$row['package']][] = $row['source_id'];
        }

        $removed = 0;

        DB::transaction(function () use ($fetched, $byPackage, $now, &$removed): void {
            foreach ($fetched['advisories'] as $row) {
                SecurityAdvisory::query()->updateOrCreate(
                    ['source_id' => $row['source_id']],
                    $row + ['synced_at' => $now],
                );
            }

            foreach ($byPackage as $package => $kept) {
                $removed += SecurityAdvisory::query()
                    ->where('package', $package)
                    ->whereNotIn('source_id', $kept)
                    ->delete();
            }
        });

        return [
            'stored' => count($fetched['advisories']),
            'removed' => $removed,
            'skipped' => $fetched['skipped'],
        ];
    }
}
