<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managed Craft installations.
 *
 * Note what is absent, and must stay absent: there is no administrator password, no SSH
 * credential, and no site database password anywhere in this table or any other. Invariants 1 to 3
 * are enforced by the schema simply having nowhere to put them, and there is a test that asserts
 * this stays true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The domain this site is expected to pair from. A connector presenting a different
            // host does not silently succeed: pairing is held for confirmation and the user is
            // shown both values.
            $table->string('expected_domain');

            $table->string('environment')->default('production');

            // connected | never_connected | not_connected | paused
            $table->string('status')->default('never_connected');

            // Denormalised from the most recent inventory report so the fleet table can sort and
            // filter without reaching into jsonb on every row. The report remains the record of
            // what was actually received.
            $table->string('craft_version')->nullable();
            $table->string('craft_edition')->nullable();
            $table->string('php_version')->nullable();
            $table->string('connector_version')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_inventory_at')->nullable();

            // Archiving keeps history intact. Deleting a site is a separate, audited action that
            // revokes its connector in the same transaction.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['organisation_id', 'archived_at']);
            $table->index(['organisation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
