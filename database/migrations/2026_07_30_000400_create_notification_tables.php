<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where notifications go, and what happened when they were sent.
 *
 * The delivery log exists because an unnoticed notification failure is worse than no notifications at
 * all: the operator believes they are covered. Consecutive failures are counted on the destination so
 * a dead endpoint can be disabled rather than retried forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_destinations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            // email | webhook
            $table->string('transport');

            $table->string('label');

            // An address or an HTTPS URL. Validated per transport before it is written — a webhook
            // URL has passed the outbound guard by the time it reaches here.
            $table->string('target', 512);

            // Which events this destination wants. An empty list means none, not all: defaulting to
            // everything would make an accidentally-created destination noisy rather than silent.
            $table->jsonb('events');

            // Shared secret for signing webhook bodies, so a receiver can tell a genuine notification
            // from anything else that finds its URL. Encrypted at rest.
            $table->text('signing_secret')->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_failure_reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_label')->nullable();

            $table->timestamps();

            $table->index(['organisation_id', 'enabled']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();

            $table->string('event');
            $table->string('subject');

            // sent | failed
            $table->string('outcome');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->ulid('correlation_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['notification_destination_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_destinations');
    }
};
