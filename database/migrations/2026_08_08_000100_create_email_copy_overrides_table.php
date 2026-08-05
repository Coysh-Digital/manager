<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Wording an operator has changed, for the emails whose wording is theirs to change.
 *
 * Installation-scoped, with no organisation column, for the reason mail_settings gives: what an
 * invitation says is a property of the installation sending it, not of each tenant inside it. A
 * per-organisation override would also mean an email whose text depends on which tenant triggered
 * it, which is a support problem nobody has asked for.
 *
 * Absence is the default. A missing row means the code's own wording stands, so reverting is a
 * delete and there is no third state to reason about — no "enabled" flag, no row that exists but
 * does not apply. `subject` and `body` are separately nullable so an operator can change one
 * without restating the other.
 *
 * Only the entries the catalogue marks editable can appear here. The monitoring alerts cannot: they
 * are sent with Mail::raw and are deliberately plain text, because an HTML mail about a security
 * finding is a phishing template somebody has been trained to click.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_copy_overrides', function (Blueprint $table): void {
            $table->id();

            // The catalogue key. Unique because an email has one wording — a second row would be a
            // second truth with no rule for deciding which of them sends.
            $table->string('key', 64)->unique();

            // Null means the code's wording stands. Distinct from an empty string, which is
            // somebody deliberately removing a paragraph.
            $table->string('subject')->nullable();
            $table->text('body')->nullable();

            // Who last changed it. nullOnDelete rather than cascade: losing the operator's account
            // must not silently revert customer-facing wording back to the default.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_copy_overrides');
    }
};
