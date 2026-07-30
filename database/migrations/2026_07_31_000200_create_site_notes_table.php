<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator notes on a site.
 *
 * The one kind of information this platform holds that no connector can report: why a site is the
 * way it is. "PHP stays on 8.2 until the client's payment gateway is replaced." "Do not take backups
 * before 9am, the host throttles." "Dev mode is on deliberately, this is a staging clone."
 *
 * Without somewhere to put that, it lives in a chat thread and leaves with whoever wrote it, and the
 * next person acknowledges the same finding for the third time.
 *
 * Free text, so unlike everything else in this database it is content rather than metadata. Three
 * consequences, all deliberate:
 *
 *  - **It is written by people here, never by a site.** No connector endpoint touches this table, and
 *    no report can reach it. It is not telemetry and cannot become telemetry.
 *  - **It is audited like anything else.** Adding and deleting one is recorded, because a note is a
 *    decision and decisions on a control plane are logged.
 *  - **It is bounded.** A text column with a validated ceiling, not a document store. Somebody who
 *    needs to attach a specification is looking for a wiki, and this should stay obviously not that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_notes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Nullable, and stays that way when the author's account is removed. A note whose author
            // has left is still worth reading, and deleting the record of why a site is configured
            // the way it is because somebody changed jobs would be the wrong trade.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_label')->nullable();

            $table->text('body');

            // Pinned notes lead the Overview. The rest are history, behind a disclosure — an
            // operational caveat somebody must read before touching the site is a different thing
            // from a record of what happened in March.
            $table->boolean('pinned')->default(false);

            $table->timestamps();

            $table->index(['site_id', 'pinned', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_notes');
    }
};
