<?php

declare(strict_types=1);

namespace App\Domain\Health;

use Illuminate\Support\Carbon;

/**
 * A stretch during which a site stopped checking in.
 *
 * Bounded by the two heartbeats either side of it, so `from` is the last one heard before the
 * silence and `to` the first one after. An ongoing outage has no heartbeat after it, and `to` is
 * the moment the window was read.
 */
final class Outage
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly bool $isOngoing,
    ) {}

    public function seconds(): int
    {
        return max(0, $this->to->getTimestamp() - $this->from->getTimestamp());
    }

    /**
     * "3h 12m", "45m", "2d 4h" — the shape somebody reads off a table rather than a sentence.
     */
    public function duration(): string
    {
        $seconds = $this->seconds();

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
