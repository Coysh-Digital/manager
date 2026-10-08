<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many notifications a destination may be sent in a rolling day.
 *
 * Null means "use the installation default" and zero means no limit, which are different answers: an
 * owner who wants a mailbox uncapped has to be able to say so without the default quietly applying
 * to it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_destinations', function (Blueprint $table): void {
            $table->dropColumn('daily_limit');
        });
    }
};
