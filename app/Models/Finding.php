<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Findings\Severity;
use App\Support\Concerns\HasExternalId;
use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A derived finding.
 *
 * Not a log entry: there is one row per rule per site, reconciled on each report. It opens when a rule
 * first matches, updates while it keeps matching, and resolves when it stops.
 *
 * @property int $id
 * @property string $external_id
 * @property int $site_id
 * @property string $rule
 * @property string $severity
 * @property string $title
 * @property string $detail
 * @property array<string, mixed>|null $evidence
 * @property string $state
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_label
 * @property string|null $acknowledgement_reason
 */
class Finding extends Model
{
    use HasExternalId;

    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    public const STATE_OPEN = 'open';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    public const STATE_RESOLVED = 'resolved';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
     * Outstanding, whether or not somebody has acknowledged it.
     *
     * Acknowledgement is not resolution. An acknowledged finding is still true - somebody has just
     * said they know. Counting it as closed would let a fleet look clean while every problem in it
     * has merely been read.
     */
    public function isOutstanding(): bool
    {
        return in_array($this->state, [self::STATE_OPEN, self::STATE_ACKNOWLEDGED], true);
    }

    public function isAcknowledged(): bool
    {
        return $this->state === self::STATE_ACKNOWLEDGED;
    }

    public function tone(): string
    {
        return Severity::tone($this->severity);
    }

    /**
     * How long this has been true.
     */
    public function age(): string
    {
        return $this->first_seen_at->diffForHumans(short: true, syntax: Carbon::DIFF_ABSOLUTE);
    }
}
