<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more things sites may report, each behind its own capability.
 *
 * Separate tables rather than columns on inventory_reports, for the same reason updates got its own:
 * they answer different questions, arrive on different schedules, and one failing must not stop the
 * others. A six-hourly directory walk timing out should not cost an operator their hourly version
 * report.
 *
 * The payload columns are jsonb but they are not free-for-alls: every report is validated against
 * system.v1 or logins.v1 in the protocol package before it reaches here, and unknown keys are
 * rejected rather than stripped.
 *
 * Note what neither table can hold. `runtime_reports` has byte counts and numeric limits, and
 * nowhere to put a path or a file name. `login_reports` has four integers and a timestamp, and
 * nowhere to put a username, an email address or a source address - a log of who tried to sign in to
 * somebody else's website is not a thing this platform collects, and the schema is where that is
 * enforced rather than the intention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('schema_version');
            $table->jsonb('payload');

            // Denormalised so the fleet can be sorted and filtered without reaching into jsonb per
            // row. The payload remains the record of what was actually received.
            $table->unsignedBigInteger('storage_bytes')->nullable();
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();
            $table->unsignedInteger('response_p95_ms')->nullable();
            $table->unsignedInteger('response_mean_ms')->nullable();

            $table->timestamp('collected_at');
            $table->timestamp('received_at');
            $table->ulid('correlation_id');

            $table->index(['site_id', 'received_at']);
        });

        Schema::create('login_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('schema_version');
            $table->jsonb('payload');

            $table->unsignedInteger('window_hours');
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->unsignedInteger('accounts_with_failures')->default(0);
            $table->unsignedInteger('accounts_locked')->default(0);
            $table->unsignedInteger('admin_accounts_affected')->default(0);
            $table->timestamp('last_failure_at')->nullable();

            $table->timestamp('collected_at');
            $table->timestamp('received_at');
            $table->ulid('correlation_id');

            $table->index(['site_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_reports');
        Schema::dropIfExists('runtime_reports');
    }
};
