<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Models\Heartbeat;
use App\Models\Site;
use App\Support\ViewerTimezone;
use Illuminate\Support\Carbon;

/**
 * How reliably a site has been checking in.
 *
 * Read entirely from heartbeats that arrived. The platform never calls out to a site — that is what
 * lets a connector work from behind NAT with no inbound port — so there is no probe to read and
 * nothing here is a measurement of whether the website answered a visitor. It measures whether the
 * connector spoke to us, which is a different claim, and the screen makes that claim rather than the
 * one people expect from the word "uptime".
 *
 * The two are correlated but not the same. A site whose scheduler is driven by ordinary traffic
 * rather than cron reports only while it has visitors, so a quiet night reads as a gap; and a site
 * whose web server is down but whose cron still runs would keep reporting. Both are stated on the
 * screen rather than smoothed over here.
 *
 * Not distinct from {@see Diagnostics}, which answers "can this Manager instance serve a request".
 * This answers "has that site been talking to it".
 */
final class SiteUptime
{
    /**
     * The windows the screen offers, in hours.
     */
    public const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];

    public function for(Site $site, int $windowHours): UptimeWindow
    {
        $to = Carbon::now();
        $from = $to->copy()->subHours($windowHours);

        $interval = $this->interval();
        $tolerated = $interval * $this->graceMultiplier();

        // Only the timestamps: a month of heartbeats is thousands of rows, and none of the rest of
        // the record is read here.
        /** @var list<Carbon> $beats */
        $beats = Heartbeat::query()
            ->where('site_id', $site->id)
            ->where('received_at', '>=', $from)
            ->orderBy('received_at')
            ->get(['received_at'])
            ->map(static fn (Heartbeat $heartbeat): Carbon => $heartbeat->received_at)
            ->all();

        $from = $this->effectiveStart($site, $from, $beats);

        $outages = $this->outages($beats, $from, $to, $tolerated, $site);

        // Availability is time covered, not check-ins received. A site that reports twice as often as
        // expected is not 200% available, and one that misses a single beat in a quiet hour has not
        // had an outage — the tolerance above is what decides that.
        $silent = array_sum(array_map(static fn (Outage $outage): int => $outage->seconds(), $outages));
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());
        $availability = max(0.0, min(100.0, (1 - $silent / $span) * 100));

        $longest = null;

        foreach ($outages as $outage) {
            if ($longest === null || $outage->seconds() > $longest->seconds()) {
                $longest = $outage;
            }
        }

        /*
         | Both ends of the window count, and forgetting that is what made a healthy site read
         | "8 of ~7".
         |
         | Beats one interval apart across a span of S seconds number floor(S / interval) + 1, not
         | floor(S / interval): a site paired 35 minutes ago that has reported every five minutes
         | since has checked in at 0, 5, 10, 15, 20, 25, 30 and 35 — eight times over seven
         | intervals. Reporting the interval count as the expectation meant every well-behaved site
         | showed one more than it should have, permanently, which reads as a bug in the counter
         | rather than as a site doing exactly what it was asked.
         |
         | $span is measured from the same $from the beats were counted from, so the two figures
         | describe the same window rather than two overlapping ones.
         |
         | What this still cannot do is predict the count exactly, and the tilde is load-bearing.
         | `expected` models a single producer beating on a fixed interval, where a connector has two
         | that throttle independently — the cron task and the web trigger — so a site running both
         | reports somewhat more often than asked. The screen says so rather than clamping the count,
         | because the raw figure is the one worth having when a week reads thin.
         */
        return new UptimeWindow(
            from: $from,
            to: $to,
            received: count($beats),
            expected: max(1, (int) floor($span / $interval) + 1),
            availability: $availability,
            outages: $outages,
            longest: $longest,
            buckets: $this->buckets($beats, $from, $to, $windowHours, $interval),
            lastSeenAt: $beats === [] ? $site->last_seen_at : end($beats),
            interval: $interval,
        );
    }

    /**
     * Where the window really starts.
     *
     * A site cannot have been unavailable before it existed. Without this, a site added an hour ago
     * reads "97.8% over 7 days": the numerator counts only the gaps since it was added, while the
     * denominator covers the whole window, so the six days before anybody had heard of it silently
     * count as uptime. The figure was flattering, stable and meaningless.
     *
     * Never later than the first heartbeat on record, though. A report that arrived before the row's
     * `created_at` — a restored database, a corrected clock, a site seeded by a fixture — is evidence
     * the site was alive, and evidence beats a timestamp.
     *
     * @param  list<Carbon>  $beats
     */
    private function effectiveStart(Site $site, Carbon $windowStart, array $beats): Carbon
    {
        $createdAt = $site->created_at;

        if ($createdAt === null || $createdAt->lte($windowStart)) {
            return $windowStart;
        }

        $firstBeat = $beats[0] ?? null;

        if ($firstBeat !== null && $firstBeat->lt($createdAt)) {
            return $firstBeat->copy();
        }

        // Through instance() rather than copy(): the model's timestamp is a base Carbon, and the rest
        // of this class works in Illuminate's subclass.
        return Carbon::instance($createdAt);
    }

    /**
     * Gaps longer than the tolerated interval, plus the two edges.
     *
     * The leading edge matters: a site that fell silent yesterday and is still silent has no gap
     * *between* two heartbeats, and counting only the gaps between them would report it as perfect.
     *
     * @param  list<Carbon>  $beats
     * @return list<Outage>
     */
    private function outages(array $beats, Carbon $from, Carbon $to, int $tolerated, Site $site): array
    {
        // Never heard from at all inside the window. Whether that is an outage or a site that was
        // added this morning is decided by whether it has ever reported.
        if ($beats === []) {
            return $site->last_seen_at === null
                ? []
                : [new Outage(from: $from, to: $to, isOngoing: true)];
        }

        $outages = [];

        // Silence between the start of the window and the first heartbeat in it. Only counted when
        // the site had already been reporting before the window opened, so a site added midway
        // through does not appear to have been down for the half before it existed.
        $previous = $beats[0];

        // The window now starts no earlier than the site itself, so a gap between the window opening
        // and the first heartbeat is real silence rather than the period before the site existed.
        if ($site->last_seen_at !== null
            && $previous->getTimestamp() - $from->getTimestamp() > $tolerated) {
            $outages[] = new Outage(from: $from, to: $previous, isOngoing: false);
        }

        foreach (array_slice($beats, 1) as $beat) {
            if ($beat->getTimestamp() - $previous->getTimestamp() > $tolerated) {
                $outages[] = new Outage(from: $previous, to: $beat, isOngoing: false);
            }

            $previous = $beat;
        }

        // Still silent now.
        if ($to->getTimestamp() - $previous->getTimestamp() > $tolerated) {
            $outages[] = new Outage(from: $previous, to: $to, isOngoing: true);
        }

        return $outages;
    }

    /**
     * Check-ins per bucket as a percentage of what was expected in it.
     *
     * Hourly over a day, daily over a week or a month: twenty-four bars or thirty, either of which
     * fits across a screen without becoming a texture.
     *
     * No off-by-one correction here, unlike the window total above, and the difference is real
     * rather than an oversight: buckets tile. A beat landing exactly on a boundary is counted in the
     * bucket it opens, so each one holds its own length divided by the interval and nothing is
     * counted twice.
     *
     * @param  list<Carbon>  $beats
     * @return list<array{label: string, starts_at: Carbon, percentage: float, received: int, expected: int}>
     */
    private function buckets(array $beats, Carbon $from, Carbon $to, int $windowHours, int $interval): array
    {
        $bucketHours = $windowHours <= 24 ? 1 : 24;
        $count = max(1, (int) ceil($windowHours / $bucketHours));
        $bucketSeconds = $bucketHours * 3600;

        $received = array_fill(0, $count, 0);

        foreach ($beats as $beat) {
            $index = intdiv($beat->getTimestamp() - $from->getTimestamp(), $bucketSeconds);

            if ($index >= 0 && $index < $count) {
                $received[$index]++;
            }
        }

        $buckets = [];

        foreach ($received as $index => $seen) {
            $startsAt = $from->copy()->addSeconds($index * $bucketSeconds);

            // A bucket still in progress would otherwise always render short. Scale what is expected
            // of it by how much of it has actually elapsed.
            $elapsed = max(1, min($bucketSeconds, $to->getTimestamp() - $startsAt->getTimestamp()));
            $due = max(1, intdiv($elapsed, $interval));

            $buckets[] = [
                // In the reader's zone. An hourly axis is the one place a timezone is most obviously
                // wrong: "the site went quiet at 03:00" means nothing if 03:00 is somebody else's.
                'label' => ViewerTimezone::apply($startsAt)->format($bucketHours === 1 ? 'H:i' : 'j M'),
                'starts_at' => $startsAt,
                'percentage' => min(100.0, $seen / $due * 100),
                'received' => $seen,

                // The same figure the bar is drawn from. It used to be a whole bucket's worth
                // regardless, so the hour in progress drew correctly and then described itself as
                // "3 of 12" — or, once it filled up, "13 of 12".
                'expected' => $due,
            ];
        }

        return $buckets;
    }

    /**
     * How often a connector is expected to check in.
     *
     * Mirrors the connector's own schedule (Tasks::HEARTBEAT, 300 seconds). Configurable because an
     * operator who has changed the cron on their fleet should be able to tell Manager so, rather
     * than watching every site report a permanent 50% because it beats every ten minutes.
     */
    private function interval(): int
    {
        return max(60, (int) config('manager.health.heartbeat_interval', 300));
    }

    /**
     * How many missed beats before it counts as an outage.
     *
     * Three, by default. One missed heartbeat is a queue that was busy; fifteen minutes of silence
     * is something worth a row in a table.
     */
    private function graceMultiplier(): int
    {
        return max(1, (int) config('manager.health.heartbeat_grace_multiplier', 3));
    }
}
