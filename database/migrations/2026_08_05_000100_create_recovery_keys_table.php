<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation recovery keys.
 *
 * These are the keys backups are encrypted to. Under the older format an artifact's key was sealed to
 * *this platform*, which meant anybody holding the platform's backup secret key and its object storage
 * could read every backup it held. These rows replace that arrangement: a backup is sealed to keys the
 * organisation generated on its own machines, and the platform never sees the other half of one.
 *
 * So note what this table cannot hold. There is a `public_key` column and there is deliberately no
 * counterpart to it. Not an encrypted one, not an optional escrow copy, not a column named something
 * else. An escrow column would be a permanent invitation to be compelled, and its existence would make
 * every claim on the security page conditional; an organisation that wants an escrow arrangement adds
 * the escrow holder's public key as a second recovery key, which is the same outcome done explicitly,
 * visible in every artifact's manifest, and revocable by them rather than by us.
 *
 * Two design points worth having here rather than in a pull request nobody can find later.
 *
 * **Rows are never deleted.** `revoked` is terminal. Deleting a key would erase the record of what an
 * artifact was bound to, which is precisely what somebody who had added a key of their own would want,
 * and it would leave historical artifacts naming a fingerprint nothing explains.
 *
 * **A key is not usable until it has been proven.** Almost nothing can be checked about a submitted
 * X25519 public key - any 32 bytes is a valid one - so the only meaningful test is whether the
 * submitter can decrypt something sealed to it. The challenge columns exist for that ceremony, and only
 * the hash of the expected answer is stored, so the row itself never contains anything that would let
 * somebody else complete it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_keys', function (Blueprint $table): void {
            $table->id();
            $table->ulid('external_id')->unique();

            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            /*
             | pending_proof - submitted, not yet demonstrated to be usable. Never a recipient.
             | active        - proven. Every new backup is sealed to it.
             | revoked       - excluded from new backups. Terminal, and historical artifacts keep theirs.
             */
            $table->string('state', 24)->default('pending_proof');

            // Base64 raw X25519, 44 characters. The same form as everywhere else in this system: the
            // protocol carries the key, not a description of it.
            $table->string('public_key', 64);

            // Derived from the key, stored so it can be indexed and compared without decoding on every
            // read. Recomputed and checked before the key is ever served to a site - a fingerprint in a
            // row is a value somebody could have edited, and this is the one field a site pins against.
            $table->string('fingerprint', 40);

            $table->string('label', 120)->nullable();

            // Proof of possession. Only the hash of the expected answer, so a stolen database does not
            // let somebody activate a key they cannot open.
            $table->text('challenge')->nullable();
            $table->char('challenge_response_hash', 64)->nullable();
            $table->timestamp('challenge_expires_at')->nullable();
            $table->unsignedSmallInteger('challenge_attempts')->default(0);

            $table->timestamp('activated_at')->nullable();

            // A key nobody has touched in a year is a key nobody can find, so this drives a prompt to
            // demonstrate it again rather than expiring anything on its own.
            $table->timestamp('last_proved_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            // Who did each thing. Not foreign keys, matching audit_events: the record of who enrolled a
            // key must survive that person's account being removed.
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_label')->nullable();
            $table->unsignedBigInteger('revoked_by_id')->nullable();
            $table->string('revoked_by_label')->nullable();

            $table->timestamps();

            // One organisation cannot enrol the same key twice. Scoped to the organisation rather than
            // global on purpose: a global unique index would let one tenant discover whether another had
            // registered a given key by watching an insert fail.
            $table->unique(['organisation_id', 'fingerprint']);

            $table->index(['organisation_id', 'state']);
        });

        Schema::table('organisations', function (Blueprint $table): void {
            /*
             | Which artifact format this organisation's sites may produce.
             |
             | A ratchet, not a setting. It moves from v1 to v2 the first time a recovery key is
             | activated and there is no path back through the interface, because going back means
             | going back to backups this platform can read, and that should not be one click away
             | from somebody who does not realise what it means.
             |
             | It is not the control that stops a downgrade - a compromised platform is the same party
             | that would be enforcing it. The control that works lives on the Craft server, where a
             | connector refuses to seal to anything but its pinned fingerprints. This defends against
             | the likelier failure: an operator rolling a connector fleet back a version.
             */
            $table->string('backup_format_floor', 8)->default('v1');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn('backup_format_floor');
        });

        Schema::dropIfExists('recovery_keys');
    }
};
