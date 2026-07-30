<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Illuminate\Support\Carbon;

/**
 * What a site's check-in record looks like over one period.
 *
 * A value object rather than an array because the Health screen reads eight things off it and a
 * misspelled key in a Blade template fails silently.
 */
final class UptimeWindow
{
    /**
     * @param  list<Outage>  $outages
     * @param  list<array{label: string, starts_at: Carbon, percentage: float, received: int, expected: int}>  $buckets
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly int $received,
        public readonly int $expected,
        public readonly float $availability,
        public readonly array $outages,
        public readonly ?Outage $longest,
        public readonly array $buckets,
        public readonly ?Carbon $lastSeenAt,
    ) {}

    /**
     * Whether there is enough here to say anything at all.
     *
     * One heartbeat is not a record. Rendering "100% uptime" off a single check-in would be a claim
     * the data cannot support, and the screen says so instead.
     */
    public function hasEvidence(): bool
    {
        return $this->received > 1;
    }

    public function isCurrentlySilent(): bool
    {
        // Indexed rather than end(), which moves an internal pointer and so takes its argument by
        // reference — which a readonly property cannot be.
        return $this->outages !== [] && $this->outages[array_key_last($this->outages)]->isOngoing;
    }

    /**
     * Availability rounded the way it is displayed, so the number and the badge cannot disagree.
     */
    public function availabilityLabel(): string
    {
        return number_format($this->availability, $this->availability >= 99.95 ? 0 : 1).'%';
    }

    public function tone(): string
    {
        return match (true) {
            ! $this->hasEvidence() => 'grey',
            $this->isCurrentlySilent() => 'bad',
            $this->availability >= 99 => 'ok',
            $this->availability >= 95 => 'warn',
            default => 'bad',
        };
    }
}
