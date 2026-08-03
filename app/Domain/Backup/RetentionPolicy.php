<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupArtifact;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Which backups to keep.
 *
 * The obvious policy — keep the most recent N — is the one to avoid, and it is worth being explicit
 * about why, because it is what this replaces.
 *
 * A site that starts producing bad backups produces them on a schedule. Keep-the-latest-N answers that
 * by discarding the last known-good backup first: seven bad nights and the only usable copy is gone,
 * having been pushed out by seven copies of the problem. The count never dropped below N and nothing
 * looked wrong. That failure mode is not hypothetical, it is the ordinary consequence of a policy that
 * treats recency as the only thing that matters.
 *
 * So retention is by *period*, in the grandfather-father-son shape backup systems have used for
 * decades: some number of days, then one per week, then one per month. The last backup of each period
 * survives, which means the oldest surviving copy is genuinely old — from before whatever started
 * going wrong — rather than merely being the oldest of a recent batch.
 *
 * Two rules make it safe:
 *
 *  - **Nothing is deleted for being surplus within a period.** A day with five backups keeps the last
 *    one once that day falls out of the daily window; until then all five are inside it and all five
 *    are kept. Retention answers "how far back", not "how many".
 *
 *  - **A site is never left with nothing.** If every period is empty — a site that stopped reporting
 *    six months ago — the single newest artifact is kept regardless. Somebody who has not taken a
 *    backup in a long time is precisely the person who will need the one they have.
 */
final class RetentionPolicy
{
    /**
     * @param  int  $days  keep everything from this many days back
     * @param  int  $weeks  then the last backup of each week, this many weeks back
     * @param  int  $months  then the last backup of each month, this many months back
     */
    public function __construct(
        public readonly int $days,
        public readonly int $weeks,
        public readonly int $months,
    ) {}

    /**
     * The policy a particular site is kept under.
     *
     * Per site rather than per organisation, because how far back a shop needs to reach is not how
     * far back a brochure site does, and one policy across a fleet means picking the more expensive
     * one for everybody.
     */
    public static function forSite(Site $site): self
    {
        return new self(
            days: max(0, $site->backup_retention_days),
            weeks: max(0, $site->backup_retention_weeks),
            months: max(0, $site->backup_retention_months),
        );
    }

    /**
     * The identifiers of the artifacts that survive.
     *
     * Takes everything an organisation holds and returns what to keep, rather than deciding artifact
     * by artifact. It has to: whether a backup survives depends on whether there is a newer one in the
     * same week, which is not a question a single row can answer about itself.
     *
     * @param  Collection<int, BackupArtifact>  $artifacts
     * @return array<int, true> keyed by artifact id, so a caller can test membership cheaply
     */
    public function keep(Collection $artifacts): array
    {
        // Oldest first, so that walking forward leaves the *last* artifact of each period as the one
        // recorded against it. Keeping the first of each week would mean a Monday backup outliving the
        // Sunday one taken six days later, which is the wrong way round.
        $ordered = $artifacts->sortBy(fn (BackupArtifact $a): int => $a->taken_at->getTimestamp())->values();

        if ($ordered->isEmpty()) {
            return [];
        }

        $keep = [];
        $now = now();

        $weekly = [];
        $monthly = [];

        foreach ($ordered as $artifact) {
            $takenAt = $artifact->taken_at;

            // Inside the daily window, everything survives. This is the part people actually restore
            // from, and thinning it to save space would be saving the wrong thing.
            if ($this->days > 0 && $takenAt->greaterThanOrEqualTo($now->copy()->subDays($this->days))) {
                $keep[$artifact->id] = true;

                continue;
            }

            if ($this->weeks > 0 && $takenAt->greaterThanOrEqualTo($now->copy()->subWeeks($this->weeks))) {
                // ISO year and week, so the last days of December do not share a bucket with the first
                // days of January under a naive week number.
                $weekly[$takenAt->format('o-W')] = $artifact->id;

                continue;
            }

            if ($this->months > 0 && $takenAt->greaterThanOrEqualTo($now->copy()->subMonths($this->months))) {
                $monthly[$takenAt->format('Y-m')] = $artifact->id;
            }
        }

        foreach ([...array_values($weekly), ...array_values($monthly)] as $id) {
            $keep[$id] = true;
        }

        if ($keep === []) {
            /*
             | Every period empty, which means nothing has been backed up in months.
             |
             | Deleting the last copy an organisation holds because the schedule stopped is the policy
             | doing precise harm: the person whose backups stopped six months ago is exactly the
             | person who is going to need the one they still have. Whatever retention says, the newest
             | artifact stays.
             */
            $keep[(int) $ordered->last()->id] = true;
        }

        return $keep;
    }

    /**
     * A description an operator can check against what they meant.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->days > 0) {
            $parts[] = $this->days === 1 ? 'every backup from the last day' : "every backup from the last {$this->days} days";
        }

        if ($this->weeks > 0) {
            $parts[] = "one a week for {$this->weeks} weeks";
        }

        if ($this->months > 0) {
            $parts[] = "one a month for {$this->months} months";
        }

        return $parts === []
            ? 'nothing beyond the most recent backup'
            : implode(', then ', $parts);
    }
}
