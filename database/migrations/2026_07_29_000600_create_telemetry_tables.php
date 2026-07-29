<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What connectors report.
 *
 * The payload column is jsonb, but it is not a free-for-all: every report is validated against the
 * inventory.v1 schema in the protocol package before it reaches here, and unknown keys are
 * rejected rather than stripped. Nothing in this table may contain entries, assets, user records,
 * credentials, licence keys or environment values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('connector_version')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->ulid('correlation_id');

            $table->timestamp('received_at');

            // Heartbeats arrive every few minutes per site and are pruned on a schedule, so the
            // index is on the retrieval path rather than on every column.
            $table->index(['site_id', 'received_at']);
        });

        Schema::create('inventory_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Which version of the allowlist this payload was validated against. Reports are kept
            // across schema versions, so readers must not assume the current shape.
            $table->string('schema_version');

            $table->jsonb('payload');

            // When the connector gathered the data, as distinct from when it arrived. A queued
            // report from an hour ago should not read as current.
            $table->timestamp('collected_at');
            $table->timestamp('received_at');

            $table->ulid('correlation_id');

            $table->index(['site_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reports');
        Schema::dropIfExists('heartbeats');
    }
};
