<?php

declare(strict_types=1);

namespace App\Domain\Findings;

use App\Models\InventoryReport;
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
}
