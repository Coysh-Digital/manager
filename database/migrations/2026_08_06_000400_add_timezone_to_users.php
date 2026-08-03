<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What time it is where the reader is.
 *
 * Every absolute time on every screen was rendered in the server's zone, which is UTC unless
 * somebody changed it. An organisation timezone already existed and was read by exactly one thing —
 * the backup scheduler — so "03:00" meant the quiet hour where the sites are, while the audit log
 * three screens away still reported an event at an hour nobody recognised.
 *
 * Per user rather than only per organisation, because a team is not necessarily in one place. Null
 * is the normal state and means "use the organisation's", which in turn falls back to the
 * application default: a preference nobody has expressed should not be a third answer.
 *
 * An IANA identifier rather than an offset, for the same reason `organisations.timezone` is one — an
 * offset stops being true twice a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
