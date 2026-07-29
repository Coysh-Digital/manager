<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Manager is permitted to do on each site.
 *
 * Capabilities are independently revocable and default to nothing. Phase 1 registers
 * `inventory:read` only, but the model is complete, so the remaining capabilities slot in without
 * a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('capability');

            // granted | revoked. A row that has never been granted simply does not exist, so the
            // absence of a row is the same as a denial.
            $table->string('state')->default('revoked');

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'capability']);
        });

        // Every transition, kept forever. The spec requires user, timestamp, previous state, new
        // state, reason, source IP and correlation ID for each capability change, and this is
        // where that lives. The grants table only knows the current position.
        Schema::create('capability_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('capability');
            $table->string('previous_state')->nullable();
            $table->string('new_state');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Kept as text alongside the foreign key so the record still reads correctly after the
            // user row is gone.
            $table->string('actor_label')->nullable();

            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->ulid('correlation_id')->nullable();

            $table->timestamp('created_at');

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_events');
        Schema::dropIfExists('capability_grants');
    }
};
