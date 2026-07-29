<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Models\Site;
use App\Models\UpdateReport;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts an update report, or refuses it.
 *
 * Same allowlist discipline as inventory: unknown keys are rejected rather than stripped. The
 * schema permits whether an update exists and whether it is a security release, and not release notes
 * or download URLs — an advisory body pasted into a dashboard is a description of an unpatched
 * vulnerability on a named site.
 */
final class UpdatesIngestService
{
    public const SCHEMA = 'updates.v1';

    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        return SchemaValidator::forSchema(self::SCHEMA)->validate($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(Site $site, array $payload): UpdateReport
    {
        return DB::transaction(function () use ($site, $payload): UpdateReport {
            $now = Carbon::now();

            $plugins = $payload['plugins'] ?? [];

            $pluginUpdates = 0;
            $pluginSecurity = 0;
            $abandoned = 0;

            foreach ($plugins as $plugin) {
                if ((bool) ($plugin['update_available'] ?? false)) {
                    $pluginUpdates++;
                }

                if ((bool) ($plugin['security_release_available'] ?? false)) {
                    $pluginSecurity++;
                }

                if ((bool) ($plugin['abandoned'] ?? false)) {
                    $abandoned++;
                }
            }

            $craftUpdate = (bool) ($payload['craft']['update_available'] ?? false);
            $craftSecurity = (bool) ($payload['craft']['security_release_available'] ?? false);

            $report = UpdateReport::query()->create([
                'site_id' => $site->id,
                'schema_version' => self::SCHEMA,
                'payload' => $payload,
                'craft_update_available' => $craftUpdate,
                'craft_security_release' => $craftSecurity,
                'craft_current' => $payload['craft']['current'] ?? null,
                'craft_latest' => $payload['craft']['latest'] ?? null,
                'plugin_updates' => $pluginUpdates,
                'plugin_security_releases' => $pluginSecurity,
                'abandoned_plugins' => $abandoned,
                'checked_at' => Carbon::createFromTimestamp($payload['checked_at']),
                'received_at' => $now,
                'correlation_id' => $this->correlationId->get(),
            ]);

            // Mirrored so the fleet query stays a single table scan, and so "needs attention" can be
            // decided without joining to the latest report per site.
            $site->forceFill([
                'last_update_check_at' => $now,
                'has_security_release' => $craftSecurity || $pluginSecurity > 0,
                'available_updates' => ($craftUpdate ? 1 : 0) + $pluginUpdates,
                'last_seen_at' => $now,
            ])->save();

            return $report;
        });
    }
}
