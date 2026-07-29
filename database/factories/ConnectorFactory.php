<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Connector;
use App\Models\Site;
use coyshdigital\managerprotocol\Keys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connector>
 */
class ConnectorFactory extends Factory
{
    protected $model = Connector::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),

            // A real, throwaway public key. Tests that need to sign requests generate their own
            // keypair and pass it through withKeypair(), because the factory cannot hand a secret
            // back through itself: states are cloned, so anything stashed on $this would be lost.
            'public_key' => Keys::generateKeypair()['public'],

            'connector_version' => '1.0.0',
            'state' => Connector::STATE_ACTIVE,
            'paired_at' => now(),
        ];
    }

    /**
     * Pair this connector to a keypair the caller already holds.
     *
     * @param  array{public: string, secret: string}  $keypair
     */
    public function withKeypair(array $keypair): static
    {
        return $this->state(fn (): array => ['public_key' => $keypair['public']]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => Connector::STATE_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function awaitingConfirmation(string $submittedDomain): static
    {
        return $this->state(fn (): array => [
            'state' => Connector::STATE_PENDING_CONFIRMATION,
            'submitted_domain' => $submittedDomain,
            'pending_reason' => Connector::REASON_DOMAIN_MISMATCH,
            'paired_at' => null,
        ]);
    }
}
