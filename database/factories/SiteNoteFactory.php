<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteNote>
 */
class SiteNoteFactory extends Factory
{
    protected $model = SiteNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'author_id' => User::factory(),
            'author_label' => 'Tim Coysh',
            'body' => 'PHP stays on 8.2 until the client replaces their payment gateway. '
                .'Do not upgrade it without asking, however loudly the findings list complains.',
            'pinned' => false,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['pinned' => true]);
    }
}
