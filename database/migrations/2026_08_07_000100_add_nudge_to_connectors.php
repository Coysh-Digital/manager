<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to knock so a site checks in now, instead of on its own schedule.
 *
 * Work queued here is collected when the site next claims - up to five minutes with cron, and until
 * somebody visits the site if its scheduler runs off ordinary web traffic. A nudge asks it to claim
 * sooner. It is an optimisation and never a dependency: every site that cannot be reached keeps
 * working exactly as it did.
 *
 * **Note what this is not.** There is no column here for a URL, a host, a port or a scheme, and there
 * must never be one. The address is composed from `sites.expected_domain` - which an operator typed and
 * pairing is bound to - and only the *path* comes from the wire. A host the site chose is a host a
 * compromised site chose, and it would turn this platform into something that can be pointed at an
 * internal address. That is the mirror of the rule the connector applies to upload hosts, and
 * `tests/Invariants/NoRemoteExecutionTest.php` asserts both halves.
 *
 * On `connectors` rather than `sites` for three reasons. Reachability is a property of the installed
 * plugin, not of the domain. It dies with the connector, so superseding or revoking one - or removing
 * the site - drops it with no extra code and no way to forget. And `sites` keeps its property of having
 * no address in it at all, which is a simpler thing to check than an exception to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connectors', function (Blueprint $table): void {
            // A path, never a URL. Validated by App\Domain\Connector\NudgePath before it is stored, and
            // again in composition, because a value that was safe when written is not self-evidently
            // safe when read.
            $table->string('nudge_path', 255)->nullable();

            // When one last landed, and how many have failed since. The counter is what stops this
            // platform making a doomed outbound request every time somebody presses a button on a site
            // it cannot reach - which, left alone, is forever.
            $table->timestamp('nudge_succeeded_at')->nullable();
            $table->unsignedSmallInteger('nudge_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table): void {
            $table->dropColumn(['nudge_path', 'nudge_succeeded_at', 'nudge_failures']);
        });
    }
};
