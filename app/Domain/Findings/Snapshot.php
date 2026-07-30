<?php

declare(strict_types=1);

namespace App\Domain\Findings;

use App\Models\InventoryReport;
use App\Models\LoginReport;
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
}
