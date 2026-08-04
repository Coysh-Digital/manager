<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\RecoveryKey;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\RecoveryProof;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RecoveryKey>
 */
class RecoveryKeyFactory extends Factory
{
    protected $model = RecoveryKey::class;

    /**
     * The secret halves of the keys this factory generated, by fingerprint.
     *
     * A test that wants to prove possession needs the other half, and a real one is the only way to
     * exercise the ceremony end to end - a stubbed challenge would pass whether or not the sealing
     * actually worked.
     *
     * @var array<string, string>
     */
    private static array $secrets = [];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keypair = Sealing::generateBoxKeypair();
        $fingerprint = KeyFingerprint::forRecoveryKey($keypair['public']);

        self::$secrets[$fingerprint] = $keypair['secret'];

        return [
            'organisation_id' => Organisation::factory(),
            'state' => RecoveryKey::STATE_ACTIVE,
            'public_key' => $keypair['public'],
            'fingerprint' => $fingerprint,
            'label' => fake()->randomElement(['Ops laptop', 'Safe', 'Offsite copy', 'Backup admin']),
            'activated_at' => Carbon::now()->subDays(3),
            'last_proved_at' => Carbon::now()->subDays(3),
        ];
    }

    /**
     * The secret half of a key this factory made.
     */
    public static function secretFor(string $fingerprint): string
    {
        return self::$secrets[$fingerprint] ?? throw new \RuntimeException(
            'No secret was recorded for '.$fingerprint.'. It was not made by this factory.'
        );
    }

    /**
     * Awaiting proof, with a live challenge sealed to its own key.
     */
    public function awaitingProof(): static
    {
        return $this->state(function (array $attributes): array {
            $plaintext = (string) base64_decode(RecoveryProof::generateChallenge(), true);

            return [
                'state' => RecoveryKey::STATE_PENDING_PROOF,
                'activated_at' => null,
                'last_proved_at' => null,
                'challenge' => Sealing::seal($plaintext, (string) $attributes['public_key']),
                'challenge_response_hash' => hash(
                    'sha256',
                    KeyFingerprint::normalise(RecoveryProof::responseFor($plaintext)),
                ),
                'challenge_expires_at' => Carbon::now()->addMinutes(RecoveryKey::CHALLENGE_TTL_MINUTES),
                'challenge_attempts' => 0,
            ];
        });
    }

    public function revoked(string $reason = 'Rotated'): static
    {
        return $this->state([
            'state' => RecoveryKey::STATE_REVOKED,
            'revoked_at' => Carbon::now()->subDay(),
            'revoked_reason' => $reason,
        ]);
    }

    /**
     * Long enough since it was last demonstrated that the interface should ask again.
     */
    public function dueReproof(): static
    {
        return $this->state([
            'activated_at' => Carbon::now()->subDays(RecoveryKey::REPROVE_AFTER_DAYS + 30),
            'last_proved_at' => Carbon::now()->subDays(RecoveryKey::REPROVE_AFTER_DAYS + 30),
        ]);
    }
}
