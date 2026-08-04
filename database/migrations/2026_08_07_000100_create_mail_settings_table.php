<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a self-hosted installation's mail relay is configured.
 *
 * Until now the only answer was the `MAIL_*` variables in `.env`, which meant a shell on the server
 * and a redeploy - and on an installation whose mail has never worked, the one thing that cannot be
 * used to tell somebody how to fix it is email. The environment is still the fallback and still the
 * floor: nothing here is written unless somebody saves this screen, and discarding it puts the
 * environment straight back.
 *
 * One row, enforced by the database rather than by the code that writes it. There is deliberately no
 * organisation column: a relay is a property of the installation, the way APP_KEY and the database
 * URL are, not of a tenant inside it. The edition that has more than one tenant is the edition where
 * App\Contracts\MailAdministration answers false and this table is never read at all.
 *
 * On the `password` column and tests/Invariants/NoStoredCredentialsTest.php: that test refuses any
 * column naming a *managed site's* credential - admin_password, db_password, ssh_password and so on.
 * This is not one of those. It is this installation's own credential for its own relay, held the way
 * it already holds a TOTP secret and a webhook signing secret, and it is why the fragment list there
 * names the specific kinds rather than the bare word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();

            // One row, and the unique index is what says so. A boolean that is always true reads
            // oddly until you notice it is the constraint: two rows cannot both be the truth about
            // where mail goes, and a screen that quietly wrote a second one would be unexplainable.
            $table->boolean('singleton')->default(true);
            $table->unique('singleton');

            $table->string('transport', 32);

            // SMTP only. Null under an API transport rather than left holding the last SMTP host
            // somebody typed - see MailSetting::toConfig(), which writes every key it touches.
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption', 16)->nullable();
            $table->string('username')->nullable();

            /*
             | The relay password, the Postmark server token, the Resend API key or the SES secret,
             | depending on the transport above.
             |
             | Encrypted at rest with the same cast as NotificationDestination::signing_secret and
             | User::totp_secret. A database dump alone must not hand somebody the ability to send
             | mail as this installation - which is the ability to send a convincing password-reset
             | email to every one of its users.
             |
             | text rather than string: a Laravel encrypted payload is base64-encoded JSON and
             | comfortably exceeds 255 characters for a long API token.
             */
            $table->text('password')->nullable();

            // SES only.
            $table->string('region', 32)->nullable();

            $table->string('from_address');
            $table->string('from_name');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Whether this has ever been proved to send, rather than merely accepted as valid. The
            // screen says so standingly while the answer is no, because "configured" and "mail
            // leaves this server" are different claims and only one of them can be tested.
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_outcome', 16)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
