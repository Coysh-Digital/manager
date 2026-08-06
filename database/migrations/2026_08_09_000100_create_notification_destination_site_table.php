<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sites a notification destination is for.
 *
 * **No rows means every site**, which is what makes this migration safe to run on a live
 * installation with no data step behind it: every destination that exists today has no rows here and
 * keeps behaving exactly as it did. There is deliberately no `scope` column recording "all" or
 * "some" - a second place to state the same thing is a second place for it to disagree with this
 * table, and the empty case is the one that must never be got wrong.
 *
 * The unique pair is not decoration. A destination listed against the same site twice would receive
 * two of every notification about it, and the delivery log would show a duplicate that looks like a
 * retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_destination_site', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();

            // Cascade rather than restrict: removing a site should not be blocked by a notification
            // rule about it, and a scope that still named a deleted site would quietly widen - the
            // remaining rows would be the whole of the scope.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->unique(['notification_destination_id', 'site_id']);

            // The read is always "which sites is this destination scoped to", asked once per
            // dispatch, so the index follows the destination.
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_destination_site');
    }
};
