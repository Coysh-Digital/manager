<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup artifacts.
 *
 * Note what these columns are and are not. There is metadata about an artifact — how big, which
 * checksums, when taken, which key opened it — and there is nowhere at all to put anything from inside
 * one. No table names, no row counts, no schema dump, no sample. The bytes live in object storage and
 * the database knows only how to find them and how to verify them.
 *
 * There is likewise no database credential anywhere in this schema, which is invariant 3. The connector
 * uses the site's own Craft connection to take a dump; nothing about that connection is ever sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Denormalised so retention and the organisation's own listing do not have to join through
            // sites, and so an artifact remains attributable if a site row is being torn down.
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            // The job that produced it. Unique, which is what makes a retried report harmless: the
            // second declaration finds the first artifact instead of creating another.
            $table->foreignId('remote_job_id')->nullable()->unique()->constrained()->nullOnDelete();

            /*
             | pending  — declared, bytes not yet uploaded
             | stored   — bytes present and verified against the declared checksum
             | failed   — the upload did not complete or did not verify
             | deleted  — removed by retention or by hand; the row survives for the audit trail
             */
            $table->string('state')->default('pending');

            // Where the bytes are. Built by the platform from the artifact's own identifier — never
            // from anything a connector sent.
            $table->string('storage_key', 512)->nullable();
            $table->string('storage_disk')->nullable();

            $table->string('scheme');
            $table->text('stream_header');

            // The per-artifact key, sealed by the connector to the platform's encryption key and then
            // re-wrapped for storage. Encrypted again at the model boundary, so a database dump alone
            // does not open an artifact even if the object storage was taken at the same time.
            $table->text('wrapped_key')->nullable();
            $table->string('wrapping_key_id')->nullable();

            $table->char('ciphertext_sha256', 64);
            $table->char('plaintext_sha256', 64);
            $table->unsignedBigInteger('ciphertext_bytes');
            $table->unsignedBigInteger('plaintext_bytes');
            $table->unsignedInteger('chunk_bytes');

            $table->string('engine')->nullable();
            $table->string('engine_version')->nullable();
            $table->boolean('compressed')->default(false);

            $table->timestamp('taken_at');
            $table->timestamp('stored_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // When retention will remove it. Computed at storage time from the organisation's policy,
            // so changing the policy later does not silently re-date artifacts already taken.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_reason')->nullable();

            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['organisation_id', 'state']);
            $table->index(['site_id', 'state']);

            // Retention sweeps by expiry among artifacts that are actually present.
            $table->index(['state', 'expires_at']);
        });

        Schema::table('organisations', function (Blueprint $table): void {
            // Retention is a policy, and a policy needs a default that is not "forever". A backup kept
            // indefinitely is personal data kept indefinitely.
            $table->unsignedSmallInteger('backup_retention_days')->default(30);

            // A floor, so a short retention window cannot leave an organisation with nothing. Whichever
            // of the two keeps an artifact alive wins.
            $table->unsignedSmallInteger('backup_keep_count')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['backup_retention_days', 'backup_keep_count']);
        });

        Schema::dropIfExists('backup_artifacts');
    }
};
