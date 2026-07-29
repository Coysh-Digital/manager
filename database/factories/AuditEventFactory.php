<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 *
 * Real audit writes go through the recorder, which owns sequence numbers and chain hashes. This
 * factory exists for tests that need a row to look at, not for producing valid chains.
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'seq' => 1,
            'actor_type' => AuditEvent::ACTOR_SYSTEM,
            'action' => 'test.event',
            'outcome' => AuditEvent::OUTCOME_SUCCESS,
            'correlation_id' => (string) Str::ulid(),
            'prev_hash' => AuditEvent::GENESIS_HASH,
            'hash' => hash('sha256', (string) Str::ulid()),
            'created_at' => now(),
        ];
    }
}
