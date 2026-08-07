<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four columns an artifact needs when it arrives in pieces.
 *
 * All nullable, and null for every artifact that already exists as well as for every one that
 * arrives in a single request or goes straight to a store. That is not a transitional state to tidy
 * up later: an artifact uploaded whole has no partial state to describe.
 *
 * Null `ingest_part_bytes` in particular is load-bearing rather than incidental. It means "declared
 * before this platform accepted parts", so an artifact that was in flight across a deploy keeps the
 * upload path it was promised instead of being offered a second one halfway through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            /*
             | How much has arrived, and therefore where the next part goes.
             |
             | A resume point rather than a progress bar. A part is accepted when its offset is at or
             | below this number, so re-sending the last part after a dropped connection is safe, and
             | a connector that has lost track is told which part to continue from rather than
             | starting a twenty-gigabyte upload again.
             */
            $table->unsignedBigInteger('staged_bytes')->nullable()->after('upload_reference');

            /*
             | The part size this artifact was declared under, pinned at declare time.
             |
             | Not read from configuration when each part arrives, which is the obvious implementation
             | and is wrong. An operator changing `MANAGER_BACKUP_INGEST_PART_BYTES` during a
             | multi-hour upload would move every remaining offset, and the only thing that would
             | notice is the whole-file checksum - after the site had finished sending all of it.
             */
            $table->unsignedInteger('ingest_part_bytes')->nullable()->after('staged_bytes');

            /*
             | Which application server is holding the parts.
             |
             | The staging file is local to one machine, because the object store seam has no append
             | and because unverified bytes must not reach a bucket. On a platform behind more than
             | one application server, parts landing on different machines would each write a
             | fragment - and the failure would surface at assembly as a checksum mismatch, which
             | reads as a corrupt backup rather than as a misconfiguration. That is the worst shape a
             | failure can take.
             |
             | Recording the hostname does not fix it and is not meant to. It makes the second part
             | say so, loudly and with a remedy, instead of the whole upload ending in a lie about
             | the customer's data.
             */
            $table->string('staged_node', 64)->nullable()->after('ingest_part_bytes');

            /*
             | When the last part arrived.
             |
             | The sweep for declarations that never turned into artifacts measures against
             | `created_at`, which was right when an upload was one request. It is not right now:
             | chunked ingest makes an upload longer than the six-hour window genuinely possible for
             | the first time, and measuring from the declaration would write off a backup that is
             | still arriving - then fail it, on a site doing everything correctly. That exact
             | failure is already recorded in the `upload_window` config comment as something this
             | platform has fixed once. This column is what stops it coming back in a new form.
             */
            $table->timestamp('staged_at')->nullable()->after('staged_node');
        });
    }

    public function down(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->dropColumn(['staged_bytes', 'ingest_part_bytes', 'staged_node', 'staged_at']);
        });
    }
};
