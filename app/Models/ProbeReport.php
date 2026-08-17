<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProbeReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One look at a site from outside it.
 *
 * Unlike the inventory, runtime, update and sign-in reports, nothing here was sent by the site - the
 * platform went and looked, because a response header is decided by whatever serves the response and
 * PHP on the origin cannot see what a CDN did to it on the way out.
 *
 * The payload holds an allowlisted set of response headers, a status, the paths that answered, and
 * two booleans. No response body is read by the check that fills this, so there is nowhere here for
 * one to be.
 *
 * @property int $id
 * @property int $site_id
 * @property array<string, mixed> $payload
 * @property int|null $status
 * @property bool|null $redirects_to_https
 * @property bool|null $answers_everything
 * @property int|null $exposed_count
 * @property string|null $error
 * @property Carbon $probed_at
 */
class ProbeReport extends Model
{
    /** @use HasFactory<ProbeReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'redirects_to_https' => 'boolean',
            'answers_everything' => 'boolean',
            'probed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function value(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }

    /**
     * One response header, or null if the site did not send it.
     */
    public function header(string $name): ?string
    {
        $value = $this->value('headers.'.strtolower($name));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function succeeded(): bool
    {
        return $this->error === null && $this->status !== null;
    }

    /**
     * Whether the exposure results from this probe mean anything.
     *
     * False on a site that answers 200 for any path at all, where "your `.env` returned 200" is a
     * fact about the routing rather than about the file. Every reader of `exposed` has to ask this
     * first, which is why it is a method here rather than a comparison repeated in each of them.
     */
    public function exposureIsConclusive(): bool
    {
        return $this->succeeded() && $this->answers_everything === false;
    }

    /**
     * Paths that answered, when that answer can be trusted.
     *
     * @return list<array{path: string, status: int, bytes: int|null}>
     */
    public function exposedPaths(): array
    {
        if (! $this->exposureIsConclusive()) {
            return [];
        }

        $exposed = $this->value('exposed', []);

        return is_array($exposed) ? array_values($exposed) : [];
    }

    /**
     * Header names the site sent more than once.
     *
     * @return list<string>
     */
    public function duplicatedHeaders(): array
    {
        $names = $this->value('duplicated', []);

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }
}
