<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns an artifact larger than five gigabytes needs.
 *
 * Both nullable, and both stay null for every artifact that already exists. That is not a
 * transitional state to be tidied later - a v2 artifact never declared a CRC, and an artifact small
 * enough to upload in one request never has an upload to reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            /*
             | The whole-file CRC-32C, as eight lowercase hex characters.
             |
             | Declared only under backup.v3. An object store can confirm a whole-object checksum
             | across a multipart assembly when the algorithm linearises, which CRC-32C does and
             | SHA-256 does not - so this is what the store is given to check when an artifact arrives
             | in parts. It never replaces artifact_sha256, which is the signed one and the one a
             | customer verifies offline.
             */
            $table->string('artifact_crc32c', 8)->nullable()->after('artifact_sha256');

            /*
             | The store's own handle for a multipart upload in progress.
             |
             | Recorded when the grant is issued so the confirmation step can complete the upload —
             | and completing it is where the store validates the assembled whole and refuses it if
             | the parts do not add up to what was promised. The platform completes it, never the
             | connector, so nothing about the finished object is taken on a site's word.
             |
             | Not a bearer credential: it names an upload rather than authorising one, and it is
             | useless without credentials for the bucket. Kept out of audit rows and logs regardless,
             | on the same rule that keeps a presigned query string out of them.
             */
            $table->string('upload_reference')->nullable()->after('storage_disk');
        });
    }

    public function down(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table): void {
            $table->dropColumn(['artifact_crc32c', 'upload_reference']);
        });
    }
};
