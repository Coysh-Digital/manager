<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Findings\Severity;
use App\Models\Finding;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    protected $model = Finding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'rule' => 'dev_mode_in_production',
            'severity' => Severity::HIGH,
            'title' => 'Development mode is on in production',
            'detail' => 'Craft is running with devMode enabled.',
            'evidence' => ['dev_mode' => true],
            'state' => Finding::STATE_OPEN,
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now(),
        ];
    }

    public function severity(string $severity): static
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }

    public function rule(string $rule): static
    {
        return $this->state(fn (): array => ['rule' => $rule]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => [
            'state' => Finding::STATE_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_label' => 'Tim Coysh',
            'acknowledgement_reason' => 'Deliberate on this site',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'state' => Finding::STATE_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}
