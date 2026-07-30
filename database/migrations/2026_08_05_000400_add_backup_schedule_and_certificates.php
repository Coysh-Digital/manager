<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled backups, and the TLS certificate a site's visitors actually see.
 *
 * **Scheduling** is per site rather than per organisation, because "back this one up nightly and that
 * one weekly" is the shape of a real fleet — a busy shop and a brochure site do not warrant the same
 * cadence, and forcing one policy across an organisation means picking the more expensive one.
 *
 * The hour is stored separately from the frequency, and in the organisation's own time zone, because
 * "nightly" that fires at 03:00 UTC is the middle of the afternoon somewhere and the point of a
 * nightly backup is that it happens when the site is quiet.
 *
 * Note what is absent: nothing here says *where* a backup goes or *who* can read it. A schedule
 * decides when to ask, and every other property of the backup is decided elsewhere — the recipient
 * list by the organisation's recovery keys, the destination by the site's own configuration. A column
 * here naming either would be the beginning of a way to reconfigure a backup by editing a schedule.
 *
 * **Certificates** are checked by this platform reaching the site's own hostname over TLS, which is a
 * departure worth naming. Everything else about this product is reported by the connector, and there
 * is a good reason for that — a platform that can reach into sites is a platform worth attacking.
 *
 * A certificate is the exception because the connector genuinely cannot see it. TLS is terminated at
 * the edge: a CDN, a load balancer, a reverse proxy. PHP running on the origin sees whatever the proxy
 * chose to tell it, which is not the certificate a visitor's browser validates, and reporting the
 * origin's view would produce a number that is confidently wrong. The only way to know what visitors
 * see is to be one.
 *
 * That connection is read-only, made to a hostname the operator entered, and guarded against
 * loopback, private and metadata addresses like every other outbound request this application makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // off | daily | weekly. A string rather than a boolean plus an interval, because the set
            // is closed and an unrecognised value should be visible rather than arithmetic.
            $table->string('backup_schedule', 16)->default('off');

            // Hour of the day, in the organisation's time zone.
            $table->unsignedTinyInteger('backup_schedule_hour')->default(3);

            // Day of the week for a weekly schedule, 1 (Monday) to 7. Ignored when daily.
            $table->unsignedTinyInteger('backup_schedule_day')->default(7);

            // When the scheduler last enqueued for this site. The guard against enqueuing twice in one
            // window is a comparison against this, not a count of jobs — a job that failed and was
            // cleaned up would otherwise let the same window fire again.
            $table->timestamp('backup_scheduled_at')->nullable();

            $table->index(['backup_schedule', 'backup_scheduled_at']);

            // The certificate a visitor sees. Nullable throughout: a site that has never been checked
            // and a site whose certificate could not be read are different facts, and only one of them
            // is alarming.
            $table->timestamp('certificate_checked_at')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->string('certificate_issuer')->nullable();
            $table->string('certificate_subject')->nullable();

            // Why the last check did not produce an answer, in language safe to store. Never a
            // response body, never a stack trace.
            $table->string('certificate_error')->nullable();
        });

        Schema::table('organisations', function (Blueprint $table): void {
            // What "03:00" means. IANA identifier rather than an offset, so a schedule keeps its
            // meaning across a daylight saving change instead of drifting by an hour twice a year.
            $table->string('timezone', 64)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['backup_schedule', 'backup_scheduled_at']);
            $table->dropColumn([
                'backup_schedule',
                'backup_schedule_hour',
                'backup_schedule_day',
                'backup_scheduled_at',
                'certificate_checked_at',
                'certificate_expires_at',
                'certificate_issuer',
                'certificate_subject',
                'certificate_error',
            ]);
        });
    }
};
