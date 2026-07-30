<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention by period, replacing retention by count.
 *
 * `backup_keep_count` said "always keep the most recent N", and it is the rule worth removing rather
 * than tuning. A site that starts producing bad backups produces them on a schedule, and a count-based
 * floor answers that by discarding the last known-good copy first: seven bad nights and the only
 * usable backup has been pushed out by seven copies of the problem, with the count never dropping
 * below N and nothing looking wrong.
 *
 * The replacement is the grandfather-father-son shape: everything for some days, then one a week, then
 * one a month. The oldest surviving copy is then genuinely old — from before whatever started going
 * wrong — instead of merely being the oldest of a recent batch.
 *
 * `backup_keep_count` is left in place and unread. Dropping a column in the same deployment that stops
 * writing it means a rollback loses the value, and this table is small enough that a dead column costs
 * nothing. It goes in a later release, once nobody needs to roll back past this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            // Four weeks of weeklies and twelve months of monthlies, on top of the existing thirty
            // days. Generous enough that the default is a real policy rather than a placeholder, and
            // bounded enough that it is still a policy.
            $table->unsignedSmallInteger('backup_retention_weeks')->default(4);
            $table->unsignedSmallInteger('backup_retention_months')->default(12);
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['backup_retention_weeks', 'backup_retention_months']);
        });
    }
};
