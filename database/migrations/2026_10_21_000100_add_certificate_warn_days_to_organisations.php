<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many days before a certificate expires it becomes a finding.
 *
 * Null means "use the installation's default", which is `manager.findings.certificate_warn_days` and
 * thirty unless an operator has said otherwise. Nullable rather than defaulted to 30 so that an
 * organisation which never touched the setting follows the installation when it changes, instead of
 * carrying a copy of whatever the default was on the day this ran.
 *
 * A certificate renewed automatically every sixty days is always inside a thirty-day window for the
 * second half of its life, and a finding that opens every cycle is one that people learn to
 * acknowledge without reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('certificate_warn_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('certificate_warn_days');
        });
    }
};
