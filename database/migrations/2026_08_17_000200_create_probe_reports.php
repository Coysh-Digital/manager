<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a site serves to somebody who is not it.
 *
 * The fourth report table, and the second one this platform fills in itself rather than receiving —
 * certificates being the first. The reason is the same in both cases and it is not a preference: a
 * response header is added or removed by whatever serves the response, and PHP on the origin sees
 * none of that. A site whose CDN strips a header the application sets looks correct from inside and
 * is not; a site whose CDN adds one looks broken from inside and is fine. Asking the site produces a
 * confidently wrong answer on precisely the sites where it matters.
 *
 * A table rather than columns on `sites`, matching the other reports, because the useful question is
 * often "when did this change" - a security header that disappeared last Tuesday is a deploy that
 * did it, and a single mutable row cannot say that.
 *
 * **What this table can hold, and what it structurally cannot.** The payload is an allowlisted set
 * of response headers, a status code, a list of paths that answered, and two booleans. No response
 * body is read anywhere in the check that fills it, so there is no column here for one and no way
 * for one to arrive. The paths are a constant in the application rather than configuration, so the
 * `exposed` list can only ever contain members of a fixed set that somebody wrote down on purpose.
 *
 * `answers_everything` is worth naming here because it is the difference between a useful finding
 * and a libellous one. Some sites return 200 for any path at all, and on one of those "your `.env`
 * is readable" is not a conclusion the data supports. The probe asks for a path that certainly does
 * not exist, and this column records what happened - so the sites with the loosest routing are the
 * ones the check goes quiet about rather than the ones it accuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->jsonb('payload');

            // Denormalised out of the payload so the fleet can be read without reaching into jsonb
            // per row. The payload remains the record of what was actually observed.
            $table->unsignedSmallInteger('status')->nullable();
            $table->boolean('redirects_to_https')->nullable();
            $table->boolean('answers_everything')->nullable();
            $table->unsignedSmallInteger('exposed_count')->nullable();

            // Why the last probe produced nothing, in language safe to store. Never a curl message —
            // those name addresses, resolvers and file paths.
            $table->string('error')->nullable();

            $table->timestamp('probed_at');

            $table->index(['site_id', 'probed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_reports');
    }
};
