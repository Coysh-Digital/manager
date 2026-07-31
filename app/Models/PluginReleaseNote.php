<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One plugin release, and what it said it changed.
 *
 * Has no `site()` relation, and must not grow one. The migration explains why at length: the text is
 * public, the association between it and a named site is not, and the absence of a site column is
 * what keeps this table from being able to express that association at all.
 *
 * @property int $id
 * @property string $handle
 * @property string $version
 * @property string|null $notes
 * @property bool $critical
 * @property string|null $released_on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PluginReleaseNote extends Model
{
    protected $fillable = [
        'handle',
        'version',
        'notes',
        'critical',
        'released_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'critical' => 'boolean',
        ];
    }
}
