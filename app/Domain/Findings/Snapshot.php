<?php

declare(strict_types=1);

namespace App\Domain\Findings;

use App\Models\InventoryReport;
use App\Models\LoginReport;
use App\Models\ProbeReport;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\UpdateReport;

/**
 * Everything a rule is allowed to look at.
 *
 * Passing this rather than the Site model keeps rules from reaching into the database and quietly
 * depending on something that is not part of a report. A rule reads facts a site sent; nothing else.
 */
final class Snapshot
{
    public function __construct(
        public readonly Site $site,
        public readonly ?InventoryReport $inventory,
        public readonly ?UpdateReport $updates,
        /** @var list<string> */
        public readonly array $capabilities,
        public readonly ?RuntimeReport $runtime = null,
        public readonly ?LoginReport $logins = null,

        /**
         * The only report here the site did not send.
         *
         * It carries no capability for that reason. Every other report is something a site chose to
         * tell us and can stop telling us; this is what the site serves to anybody who asks, and a
         * grant would be asking permission to look at a public web page.
         */
        public readonly ?ProbeReport $probe = null,
    ) {}

    public function isProduction(): bool
    {
        return $this->site->environment === 'production';
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * A config flag from the latest inventory report.
     *
     * Returns null when the flag was not reported at all, which is different from false: a site
     * without security:read sends no flags, and a rule must not read that as "dev mode is off".
     */
    public function flag(string $name): ?bool
    {
        $value = $this->inventory?->value('config_flags.'.$name);

        return $value === null ? null : (bool) $value;
    }

    public function inventoryValue(string $path, mixed $default = null): mixed
    {
        return $this->inventory?->value($path, $default) ?? $default;
    }

    public function runtimeValue(string $path, mixed $default = null): mixed
    {
        return $this->runtime?->value($path, $default) ?? $default;
    }

    /**
     * How stale the runtime report is.
     *
     * Disk usage and response timings age differently from a version number: a six-hourly figure
     * from last month is not evidence of anything, and a rule firing on it would be reporting the
     * past. Rules that read the runtime report check this first.
     */
    public function hasRecentRuntime(int $maxAgeHours = 48): bool
    {
        return $this->runtime !== null
            && $this->runtime->received_at->gt(now()->subHours($maxAgeHours));
    }

    public function hasRecentLogins(int $maxAgeHours = 6): bool
    {
        return $this->logins !== null
            && $this->logins->received_at->gt(now()->subHours($maxAgeHours));
    }

    /**
     * Whether there is a usable look at what this site serves.
     *
     * Two days rather than the six hours the sign-in reports get, because the sweep behind this runs
     * once a day and a single missed run must not resolve every header finding in the fleet and
     * reopen them the next morning.
     *
     * A probe that failed is not recent evidence of anything - a site nobody could reach has no
     * headers to be missing, and reporting it as though it served none would be a finding about the
     * wrong thing entirely.
     */
    public function hasRecentProbe(int $maxAgeHours = 48): bool
    {
        return $this->probe !== null
            && $this->probe->succeeded()
            && $this->probe->probed_at->gt(now()->subHours($maxAgeHours));
    }

    /**
     * One response header from the most recent probe.
     */
    public function servedHeader(string $name): ?string
    {
        return $this->hasRecentProbe() ? $this->probe?->header($name) : null;
    }
}
