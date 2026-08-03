<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody stopped wanting to be told about a job that failed.
 *
 * The backups screen shows a "Did not complete" panel built from failed, expired and cancelled
 * backup jobs that left no artifact behind. It ages out after seven days on its own, which is right
 * for a problem nobody has looked at yet and wrong for one that has been read, understood and
 * either fixed or accepted — that one goes on being shouted about for a week, and a panel somebody
 * has learned to scroll past is a panel that no longer reports anything.
 *
 * Nullable, and null is the normal state. This does not delete the job, its failure reason or its
 * audit row: those are the record of what happened, and hiding a notice is not the same as denying
 * it. It only decides whether the screen still leads with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_jobs', function (Blueprint $table): void {
            $table->timestamp('notice_dismissed_at')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('remote_jobs', function (Blueprint $table): void {
            $table->dropColumn('notice_dismissed_at');
        });
    }
};
