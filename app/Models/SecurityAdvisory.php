<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One published advisory for a package, as synced from the advisory feed.
 *
 * @property int $id
 * @property string $package
 * @property string $source_id
 * @property string|null $advisory_id
 * @property string|null $cve
 * @property string|null $severity
 * @property string $affected_versions
 * @property string|null $link
 * @property Carbon|null $reported_at
 * @property Carbon $synced_at
 */
class SecurityAdvisory extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
