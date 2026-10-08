<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a finding last sent a notification.
 *
 * A finding that resolves and reopens is recorded as a fresh occurrence, which is right for the
 * audit trail and wrong for a mailbox: a devMode flag that flips with every report sent one email per
 * flip. This is what lets the evaluator tell "newly opened" from "reopened an hour after we last
 * said so", and it survives the reopen because the reopen does not reset it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
