<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paired connectors and the single-use codes that pair them.
 *
 * Only public keys are stored. A connector generates its keypair locally and the private half
 * never leaves the Craft installation, so a full compromise of this database yields no ability to
 * impersonate any site — which is what makes invariant 11 hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // base64 Ed25519 public key, 32 raw bytes.
            $table->string('public_key', 64);

            $table->string('connector_version')->nullable();

            // pending | pending_confirmation | active | superseded | revoked
            $table->string('state')->default('pending');

            // Recorded when the host a connector paired from differs from the site's expected
            // domain. Kept so the confirmation screen can show the user both values.
            $table->string('submitted_domain')->nullable();
            $table->string('pending_reason')->nullable();

            $table->timestamp('paired_at')->nullable();
            $table->timestamp('key_rotated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'state']);
        });

        // A site has at most one active connector. Enforced by the database rather than by
        // application code, because "two connectors both believed they were live" is exactly the
        // state a compromised site would try to reach.
        DB::statement('CREATE UNIQUE INDEX connectors_one_active_per_site ON connectors (site_id) WHERE state = \'active\'');

        Schema::create('enrolment_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Only the SHA-256 of the code is stored; the plaintext is shown once and never again.
            // Unique so that consuming a code is a single indexed statement, which is what keeps
            // it atomic under concurrent pairing attempts.
            $table->char('code_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();

            // Counted so repeated failures against a known-good code are visible, not just
            // rate-limited away.
            $table->unsignedInteger('attempts')->default(0);

            // Replacing a live connector requires a user to authorise it explicitly, having
            // recently proved their password. Without this, a compromised site could silently
            // re-pair itself and lock the real one out.
            $table->foreignId('replace_authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replace_authorised_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolment_codes');
        Schema::dropIfExists('connectors');
    }
};
