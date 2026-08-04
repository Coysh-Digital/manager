<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Updates\PluginInventory;
use Database\Factories\UpdateReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accepted update report.
 *
 * The payload passed the updates.v1 allowlist, which permits whether an update exists and whether it
 * is a security release - and not release notes or download URLs.
 *
 * @property int $id
 * @property int $site_id
 * @property array<string, mixed> $payload
 * @property bool $craft_update_available
 * @property bool $craft_security_release
 * @property int $plugin_updates
 * @property int $plugin_security_releases
 * @property int $abandoned_plugins
 * @property Carbon $checked_at
 * @property Carbon $received_at
 */
class UpdateReport extends Model
{
    /** @use HasFactory<UpdateReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'craft_update_available' => 'boolean',
            'craft_security_release' => 'boolean',
            'checked_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Plugins with an update available, newest-urgency first.
     *
     * @return list<array<string, mixed>>
     */
    public function pluginsNeedingUpdates(): array
    {
        $plugins = array_values(array_filter(
            $this->payload['plugins'] ?? [],
            static fn (array $plugin): bool => (bool) ($plugin['update_available'] ?? false),
        ));

        // Security releases first, then abandoned plugins: an abandoned one will never receive a
        // fix, which makes it the more permanent problem even without a current advisory.
        usort($plugins, static function (array $a, array $b): int {
            return [(bool) ($b['security_release_available'] ?? false), (bool) ($b['abandoned'] ?? false)]
                <=> [(bool) ($a['security_release_available'] ?? false), (bool) ($a['abandoned'] ?? false)];
        });

        return $plugins;
    }

    /**
     * Everything the update check knows about, keyed by handle.
     *
     * For joining against the installed list from an inventory report - see
     * {@see PluginInventory}, which explains why the join runs that way round.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pluginsByHandle(): array
    {
        $keyed = [];

        foreach ($this->payload['plugins'] ?? [] as $plugin) {
            $handle = (string) ($plugin['handle'] ?? '');

            if ($handle !== '') {
                $keyed[$handle] = $plugin;
            }
        }

        return $keyed;
    }

    /**
     * Read a value from the payload by dotted path.
     */
    public function value(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }

    public function totalUpdates(): int
    {
        return ($this->craft_update_available ? 1 : 0) + $this->plugin_updates;
    }
}
