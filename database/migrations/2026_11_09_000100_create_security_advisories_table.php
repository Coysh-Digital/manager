<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published security advisories for software Manager checks sites against.
 *
 * Global reference data, not anybody's: nothing here is scoped to an organisation, and nothing here
 * says which site is affected. That is worked out when a site's findings are evaluated, from the
 * version it reported, so this table is the same on every installation that syncs it.
 *
 * What is stored is deliberately what a finding needs to name an advisory and no more: an
 * identifier, a severity, the range of versions it affects and a link to read the rest. The
 * advisory's description is not kept, for the same reason release notes are not attached to a named
 * site - see CraftSecurityRelease.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_advisories', function (Blueprint $table): void {
            $table->id();

            // The package as Composer names it, which is how the feed is keyed.
            $table->string('package')->index();

            // The feed's own identifier for the advisory. Stable, and what a re-sync matches on.
            $table->string('source_id')->unique();

            // What people recognise it by. Either can be absent: a CVE is not always assigned.
            $table->string('advisory_id')->nullable();
            $table->string('cve')->nullable();

            $table->string('severity')->nullable();

            // A Composer version constraint, e.g. ">=5.0.0-RC1,<5.10.6|>=4.0.0-RC1,<4.18.2".
            $table->text('affected_versions');
            $table->string('link')->nullable();

            $table->timestamp('reported_at')->nullable();

            // Last time the feed still listed it. An advisory the feed stops listing was withdrawn,
            // and is removed rather than left to be raised against sites.
            $table->timestamp('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_advisories');
    }
};
