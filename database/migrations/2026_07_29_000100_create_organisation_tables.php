<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisations and membership.
 *
 * Organisation scoping is in the schema from the first migration rather than retrofitted later.
 * The self-hosted edition usually has exactly one organisation, but building the boundary now is
 * what makes the hosted edition's tenant isolation a policy question rather than a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void {
            $table->id();

            // Every identifier that appears in a URL, an API response or a log is a ULID.
            // Sequential integers would let anyone holding one resource guess at the existence and
            // volume of others.
            $table->ulid('external_id')->unique();

            $table->string('name');

            // Organisation-wide multi-factor enforcement. The column ships now so that turning it
            // on later is a policy change rather than a migration.
            $table->boolean('mfa_required')->default(false);

            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // owner: may transfer ownership and delete the organisation.
            // admin: may manage sites, capabilities and members.
            // member: read-only across the organisation's sites.
            $table->string('role')->default('member');

            // Revoking membership is a timestamp rather than a delete, so the audit log keeps
            // pointing at something real. Access checks treat any non-null value as no access,
            // and revocation takes effect on the next request rather than at session expiry.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organisations');
    }
};
