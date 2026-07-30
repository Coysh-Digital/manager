<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use coyshdigital\managerprotocol\Jobs;
use Database\Factories\RemoteJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One instance of a job from the registry.
 *
 * The row can only exist for a type the registry defines, with parameters that passed that type's
 * schema. Nothing here is interpreted as an instruction: the type names an operation the connector
 * already implements, or the connector refuses it.
 *
 * @property int $id
 * @property string $external_id
 * @property int $site_id
 * @property string $type
 * @property string $schema_version
 * @property array<string, mixed> $parameters
 * @property string $state
 * @property string|null $idempotency_key
 * @property int|null $claimed_by_connector_id
 * @property Carbon|null $claimed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $result
 * @property string|null $failure_reason
 * @property int $claim_count
 */
class RemoteJob extends Model
{
    use HasExternalId;

    /** @use HasFactory<RemoteJobFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'type',
        'schema_version',
        'parameters',
        'state',
        'idempotency_key',
        'requested_by',
        'requested_by_label',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Recorded when a backup job is handed out, and compared against the manifest at declare
            // time. Never read from the connector's payload.
            'backup_recipient_fingerprints' => 'array',
            'parameters' => 'array',
            'result' => 'array',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<Connector, $this>
     */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'claimed_by_connector_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->state, Jobs::terminalStates(), true);
    }

    public function isClaimed(): bool
    {
        return $this->state === Jobs::STATE_CLAIMED;
    }

    /**
     * Whether this job's maximum runtime has passed without a result.
     *
     * Expiry is a decision, not a state that happens on its own: the sweep command is what writes
     * it. This only answers whether it is due.
     */
    public function hasOutrunItsRuntime(): bool
    {
        return $this->isClaimed()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
