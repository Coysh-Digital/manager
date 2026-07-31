<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Findings\FindingsEvaluator;
use App\Models\Site;
use App\Models\UpdateReport;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts an update report, or refuses it.
 *
 * Same allowlist discipline as inventory: unknown keys are rejected rather than stripped, and neither
 * version of the schema has a field for a download URL or a licence key.
 *
 * Two versions are accepted, dispatched on the report's own `schema_version`. A connector older than
 * 1.8 sends `updates.v1` and keeps working unchanged; there is no cutover and no window in which a
 * fleet has to be upgraded in step.
 *
 * `updates.v2` adds release notes per plugin, which v1 refused outright. The refusal was aimed at a
 * real problem — a row saying "this named site is three versions behind these fixes" is a map of an
 * exploitable installation — but the problem was the association rather than the text, and the text
 * is public. So the notes are taken out of the report here and written to `plugin_release_notes`,
 * which is keyed on a plugin and a version and has no idea what a site is, and what gets stored in
 * `update_reports.payload` is the report as it would have been under v1. That last part is not a
 * detail: `payload` is the column that would otherwise hold exactly the association this is avoiding,
 * and `tests/Invariants/PluginReleaseNotesTest.php` is what keeps it out.
 */
final class UpdatesIngestService
{
    public const SCHEMA = 'updates.v2';

    /**
     * Every schema version a connector may still be sending.
     *
     * Oldest last is not significant; what matters is that dropping one is a decision to stop
     * accepting reports from connectors in the field, not a tidy-up.
     */
    public const ACCEPTED_SCHEMAS = ['updates.v2', 'updates.v1'];

    public function __construct(
        private readonly CorrelationId $correlationId,
        private readonly FindingsEvaluator $findings,
        private readonly PluginChangelog $changelog,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        return SchemaValidator::forSchema($this->schemaFor($payload))->validate($payload);
    }

    /**
     * Which schema to hold a payload to.
     *
     * The declared version, when it is one this platform knows. Anything else is validated against
     * the current schema, which rejects it and names the field that disagreed — better than a bare
     * "unknown schema", and it means a connector from the future is refused rather than half-read.
     *
     * @param  array<string, mixed>  $payload
     */
    private function schemaFor(array $payload): string
    {
        $declared = $payload['schema_version'] ?? null;

        return is_string($declared) && in_array($declared, self::ACCEPTED_SCHEMAS, true)
            ? $declared
            : self::SCHEMA;
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

            // Notes out of the report and into a table that cannot name a site, before the report
            // itself is written. Order matters: `payload` below keeps whatever it is handed.
            $this->changelog->remember(is_array($plugins) ? array_values(array_filter($plugins, is_array(...))) : []);

            $report = UpdateReport::query()->create([
                'site_id' => $site->id,
                'schema_version' => $this->schemaFor($payload),
                'payload' => $this->changelog->withoutReleases($payload),
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

            $this->findings->evaluate($site->refresh());

            return $report;
        });
    }
}
