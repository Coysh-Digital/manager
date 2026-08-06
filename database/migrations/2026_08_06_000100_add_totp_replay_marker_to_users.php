<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last TOTP step this account accepted, so a code cannot be accepted twice.
 *
 * A TOTP code is valid for its 30-second step, and the verifier allows one step either side to
 * absorb clock drift between a phone and the server - so any given code is accepted for roughly 90
 * seconds. Nothing recorded that a code had been used, so within that window it could be replayed:
 * shoulder-surfed, read off a notification on a lock screen, or captured by anything sitting in
 * front of the login form.
 *
 * Storing the step rather than a datetime because that is the unit the verifier compares. It is
 * `floor(unix_time / 30)` - a counter, not a timestamp - and converting it to and from a datetime
 * here would be inventing a translation for the sake of the column looking familiar.
 *
 * Nullable, and null means "no code has been accepted yet", which is every existing account. The
 * first successful challenge after this ships sets it, and replay protection begins there. There is
 * nothing to backfill: a step from before this column existed was never recorded, so the honest
 * starting position is that the next code is the first one counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('totp_last_used_step')->nullable()->after('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('totp_last_used_step');
        });
    }
};
