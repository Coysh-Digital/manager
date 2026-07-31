<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plugin release changed — held against the release, and against nothing else.
 *
 * The shape of this table is the whole of the privacy argument for the feature it serves, so it is
 * worth stating plainly rather than leaving to be inferred from the columns.
 *
 * Release notes describe, in detail, what a version fixes. A row saying "this named site is three
 * versions behind these fixes" is a map of an exploitable installation, and that is worth refusing
 * whether the notes were fetched from a third party or forwarded by the site itself. It is why
 * `updates.v1` had no field for them at all.
 *
 * What was actually dangerous, though, was the association rather than the text. The text is public:
 * the Craft Plugin Store serves it to anyone, and every site running the plugin already holds a copy,
 * which is how a connector can send it without anybody making an outbound request. So the notes are
 * kept here, keyed on the plugin and the version they describe, and **there is deliberately no
 * site_id and no organisation_id**. This table cannot answer "which of my sites is exposed to this",
 * because it does not know what a site is. The screen makes that connection at render time, from the
 * handle and versions the update report already carries, and then forgets it again.
 *
 * A fleet running the same plugin on forty sites stores one copy of each note, which is a pleasant
 * side effect rather than the reason.
 *
 * `tests/Invariants/PluginReleaseNotesTest.php` asserts the absent columns stay absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_release_notes', function (Blueprint $table): void {
            $table->id();

            // The protocol's own handle pattern is at most 128 characters, and a version at most 32.
            $table->string('handle', 128);
            $table->string('version', 32);

            $table->text('notes')->nullable();
            $table->boolean('critical')->default(false);

            // A string rather than a date: it is shown and never compared, and plugin stores are not
            // consistent about the format they publish.
            $table->string('released_on', 32)->nullable();

            $table->timestamps();

            // One row per release. Reports arrive repeatedly saying the same thing, and an upsert on
            // this pair is what makes that cheap rather than duplicating.
            $table->unique(['handle', 'version']);

            // Pruning walks this, and so does every read for one plugin's notes.
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_release_notes');
    }
};
