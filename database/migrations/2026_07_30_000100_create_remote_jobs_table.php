<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remote jobs.
 *
 * A job is a named operation from a closed registry, with parameters validated against that
 * registry's schema before the row is written. There is no column here for a command, a script, a
 * path or a URL, and that is deliberate: invariant 8 forbids arbitrary execution, and the surest way
 * to forbid it is to have nowhere to put it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_jobs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Matched against the registry on the way in. A row can only exist for a type the
            // platform defines.
            $table->string('type');
            $table->string('schema_version');

            // Validated against the definition's parameter schema, which sets additionalProperties
            // false — so an unknown parameter never reaches here.
            $table->jsonb('parameters');

            // queued | claimed | succeeded | failed | cancelled | expired
            $table->string('state')->default('queued');

            // What makes a retry safe. Two enqueue attempts carrying the same key produce one job,
            // which is how invariant 16 holds for jobs as well as for requests.
            $table->string('idempotency_key', 64)->nullable();

            $table->foreignId('claimed_by_connector_id')->nullable()->constrained('connectors')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            // Set from the definition's maximum runtime when the job is claimed. A job past this
            // without a result is expired, not still running.
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('finished_at')->nullable();

            // Validated against the definition's result schema. A connector cannot report a shape
            // the platform did not ask for.
            $table->jsonb('result')->nullable();
            $table->string('failure_reason')->nullable();

            $table->unsignedSmallInteger('claim_count')->default(0);

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('requested_by_label')->nullable();
            $table->ulid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'state']);
            $table->index(['state', 'expires_at']);
        });

        // One outstanding job per idempotency key per site. Partial, so a completed job does not
        // block a later request that legitimately reuses the key.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX remote_jobs_outstanding_idempotency
                ON remote_jobs (site_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL
               AND state IN ('queued', 'claimed')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_jobs');
    }
};
