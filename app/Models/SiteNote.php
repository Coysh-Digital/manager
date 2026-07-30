<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\SiteNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a person needed the next person to know about a site.
 *
 * The only free text in this database that describes a managed site, and the only record here no
 * connector can produce. Everything else is a fact a site reported; this is a decision somebody made
 * about it, and the reason.
 *
 * @property int $id
 * @property string $external_id
 * @property int $site_id
 * @property int|null $author_id
 * @property string|null $author_label
 * @property string $body
 * @property bool $pinned
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SiteNote extends Model
{
    use HasExternalId;

    /** @use HasFactory<SiteNoteFactory> */
    use HasFactory;

    /**
     * Long enough for a paragraph of context, short enough to stay obviously not a wiki.
     */
    public const MAX_LENGTH = 2000;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Who wrote it, whether or not they still have an account.
     *
     * The label is denormalised at write time for exactly this: an account removed a year later
     * should not turn a note's authorship into "Unknown".
     */
    public function authorName(): string
    {
        if ($this->author_label !== null) {
            return $this->author_label;
        }

        // Only reached for a note written before the label was denormalised, or one whose author
        // row has gone. Both are legitimate; neither should read as an error.
        return $this->author->name ?? 'Unknown';
    }

    /**
     * @param  Builder<SiteNote>  $query
     * @return Builder<SiteNote>
     */
    public function scopePinned(Builder $query): Builder
    {
        return $query->where('pinned', true);
    }
}
