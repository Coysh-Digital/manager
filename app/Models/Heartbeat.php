<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HeartbeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evidence that a site checked in.
 *
 * Carries no site data at all - the point of a heartbeat is liveness, and anything more would be
 * collection without a stated purpose.
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $connector_version
 * @property string|null $source_ip
 * @property string $correlation_id
 * @property Carbon $received_at
 */
class Heartbeat extends Model
{
    /** @use HasFactory<HeartbeatFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'connector_version',
        'source_ip',
        'correlation_id',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
