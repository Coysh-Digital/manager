<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention and the schedule's time zone become the site's, not the organisation's.
 *
 * Both were organisation-wide, and both are decisions about a particular site. A busy shop and a
 * brochure site do not warrant the same history, and "03:00 where the site is" means nothing when
 * one customer's sites are in London and another's are in Sydney - a fleet cannot share one quiet
 * hour. The schedule itself has already moved to the site's own Backups screen; these are the two
 * settings that decide what it does, and they were still a screen away.
 *
 * **Copied before they are dropped**, so no site changes behaviour on upgrade. Every site inherits
 * whatever its organisation had, which is by definition what was being applied to it a moment ago.
 * A fleet that had never touched the defaults gets the defaults; one that had tuned them keeps the
 * tuning, per site, and can now diverge.
 *
 * The organisation columns go, rather than staying as a fallback. A default nobody can see is a
 * setting people discover by being surprised, and there is nowhere left to show it: the block on
 * Settings that held these is what this removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // Same defaults as the organisation columns carried, so a site created between this
            // migration and the backfill below is not left at zero - which would mean "keep
            // nothing" rather than "not set".
            $table->unsignedSmallInteger('backup_retention_days')->default(30)->after('backup_schedule_day');
            $table->unsignedSmallInteger('backup_retention_weeks')->default(4)->after('backup_retention_days');
            $table->unsignedSmallInteger('backup_retention_months')->default(12)->after('backup_retention_weeks');

            // IANA identifier rather than an offset, for the reason the organisation column gave:
            // an offset stops being true twice a year.
            $table->string('timezone', 64)->default('UTC')->after('backup_retention_months');
        });

        /*
         | Carry the values across before the source of them disappears.
         |
         | One UPDATE ... FROM rather than a loop: a fleet is small, but a migration that iterates
         | models is a migration that fails differently on a big installation than on a small one,
         | and this is the moment nobody wants a surprise.
        */
        DB::statement(<<<'SQL'
            UPDATE sites
               SET backup_retention_days = organisations.backup_retention_days,
                   backup_retention_weeks = organisations.backup_retention_weeks,
                   backup_retention_months = organisations.backup_retention_months,
                   timezone = organisations.timezone
              FROM organisations
             WHERE organisations.id = sites.organisation_id
        SQL);

        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn([
                'backup_retention_days',
                'backup_retention_weeks',
                'backup_retention_months',
                'timezone',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('backup_retention_days')->default(30);
            $table->unsignedSmallInteger('backup_retention_weeks')->default(4);
            $table->unsignedSmallInteger('backup_retention_months')->default(12);
            $table->string('timezone', 64)->default('UTC');
        });

        /*
         | Rolling back takes the *longest* retention any of the organisation's sites had, and the
         | zone of whichever site is alphabetically first.
         |
         | Neither is reversible in any real sense - several values are becoming one - and the
         | choice is deliberate on the retention side: taking the longest means a rollback never
         | shortens anybody's history and so never deletes a backup that was being kept. The zone is
         | a display and scheduling detail and simply cannot be reconciled, which is worth knowing
         | before rolling this back on a fleet that has diverged.
        */
        DB::statement(<<<'SQL'
            UPDATE organisations
               SET backup_retention_days = COALESCE(longest.days, 30),
                   backup_retention_weeks = COALESCE(longest.weeks, 4),
                   backup_retention_months = COALESCE(longest.months, 12),
                   timezone = COALESCE(longest.zone, 'UTC')
              FROM (
                    SELECT organisation_id,
                           MAX(backup_retention_days) AS days,
                           MAX(backup_retention_weeks) AS weeks,
                           MAX(backup_retention_months) AS months,
                           MIN(timezone) AS zone
                      FROM sites
                  GROUP BY organisation_id
                   ) AS longest
             WHERE longest.organisation_id = organisations.id
        SQL);

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn([
                'backup_retention_days',
                'backup_retention_weeks',
                'backup_retention_months',
                'timezone',
            ]);
        });
    }
};
