<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security columns and recovery codes.
 *
 * TOTP ships in Phase 1. Passkeys are Phase 2 and will add their own table rather than change
 * anything here, so this schema does not need to anticipate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('external_id')->nullable()->unique()->after('id');

            // Encrypted at rest by the model cast. It is nullable because a user exists before
            // they enrol a second factor, and confirmation is separate from generation: a secret
            // that was generated but never proved with a valid code must not satisfy an MFA
            // requirement.
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();

            // Set when the user last proved possession of their password. Sensitive actions
            // require this to be recent; see config('manager.auth.recent_auth_minutes').
            $table->timestamp('last_authenticated_at')->nullable();
        });

        Schema::create('recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Hashed, never recoverable. The plaintext is shown once at generation and cannot be
            // shown again — regenerating replaces the whole set.
            $table->string('code_hash', 255);

            // Marked used rather than deleted, so "you have three codes left, one was used on
            // Tuesday" is answerable.
            $table->timestamp('used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['external_id', 'totp_secret', 'totp_confirmed_at', 'last_authenticated_at']);
        });
    }
};
