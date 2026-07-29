<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Update availability, as reported by each site.
 *
 * Separate from inventory_reports because it answers a different question and arrives on a different
 * cadence: inventory is what is installed, updates is what could be installed. Keeping them apart
 * means an update check failing does not stop version reporting.
 *
 * The denormalised columns exist so the fleet view can sort by urgency without reaching into jsonb
 * for every row. The payload remains the record of what was actually received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('schema_version');
            $table->jsonb('payload');

            // Summary, for the fleet table.
            $table->boolean('craft_update_available')->default(false);
            $table->boolean('craft_security_release')->default(false);
            $table->string('craft_current')->nullable();
            $table->string('craft_latest')->nullable();
            $table->unsignedSmallInteger('plugin_updates')->default(0);
            $table->unsignedSmallInteger('plugin_security_releases')->default(0);
            $table->unsignedSmallInteger('abandoned_plugins')->default(0);

            $table->timestamp('checked_at');
            $table->timestamp('received_at');
            $table->ulid('correlation_id');

            $table->index(['site_id', 'received_at']);
        });

        Schema::table('sites', function (Blueprint $table): void {
            // Mirrored onto the site so the fleet query stays a single table scan. Refreshed on
            // every accepted report.
            $table->timestamp('last_update_check_at')->nullable();
            $table->boolean('has_security_release')->default(false);
            $table->unsignedSmallInteger('available_updates')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['last_update_check_at', 'has_security_release', 'available_updates']);
        });

        Schema::dropIfExists('update_reports');
    }
};
