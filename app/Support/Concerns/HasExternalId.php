<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model an unguessable public identifier, distinct from its primary key.
 *
 * Everything that leaves the application - URLs, API responses, audit records, log lines - uses the
 * ULID. Sequential primary keys stay internal, because exposing them would let anyone holding one
 * resource enumerate the rest and infer how many exist.
 */
trait HasExternalId
{
    public static function bootHasExternalId(): void
    {
        static::creating(function (self $model): void {
            if (! $model->getAttribute('external_id')) {
                $model->setAttribute('external_id', (string) Str::ulid());
            }
        });
    }

    /**
     * Bind routes on the external identifier, never the primary key.
     */
    public function getRouteKeyName(): string
    {
        return 'external_id';
    }
}
