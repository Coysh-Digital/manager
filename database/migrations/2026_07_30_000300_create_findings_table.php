<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security and operational findings.
 *
 * Findings are *derived*, not stored facts: the platform holds the rules, and each report is
 * re-evaluated. So this table is a reconciliation target rather than a log - a finding opens when a
 * rule first matches, updates while it keeps matching, and resolves itself when it stops.
 *
 * The unique key on (site_id, rule) is what makes that work. Without it, every report would insert a
 * duplicate and the screen would fill with the same finding over and over.
 *
 * Acknowledgement is the one piece of state a human owns, and it deliberately survives
 * re-evaluation: acknowledging "dev mode is on, we know, it is deliberate on staging" must not be
 * undone by the next report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Stable rule identifier, e.g. "dev_mode_in_production". Never renamed once released:
            // an acknowledgement is keyed on it.
            $table->string('rule');

            // critical | high | medium | low
            $table->string('severity');

            $table->string('title');
            $table->text('detail');

            // What the rule saw. Booleans, versions and counts only - the same discipline as the
            // reports it is derived from.
            $table->jsonb('evidence')->nullable();

            // open | acknowledged | resolved
            $table->string('state')->default('open');

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();

            // Survives re-evaluation. Cleared only if the finding resolves and later returns, which
            // is a new occurrence and deserves a fresh decision.
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->string('acknowledged_label')->nullable();
            $table->string('acknowledgement_reason')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'rule']);
            $table->index(['site_id', 'state']);
            $table->index(['state', 'severity']);
        });

        Schema::table('sites', function (Blueprint $table): void {
            // Mirrored so the fleet table can rank by worst outstanding finding without a join.
            $table->unsignedSmallInteger('open_findings')->default(0);
            $table->string('worst_finding_severity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['open_findings', 'worst_finding_severity']);
        });

        Schema::dropIfExists('findings');
    }
};
