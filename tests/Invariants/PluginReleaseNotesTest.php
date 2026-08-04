<?php

declare(strict_types=1);

use App\Domain\Updates\ChangelogFetcher;
use App\Domain\Updates\UpdatesIngestService;
use App\Models\Organisation;
use App\Models\PluginReleaseNote;
use App\Models\Site;
use App\Models\UpdateReport;
use Database\Factories\UpdateReportFactory;
use Illuminate\Support\Facades\Schema;

/**
 * The bargain that made plugin release notes acceptable to store at all.
 *
 * `updates.v1` refused them outright, and the reasoning is worth restating rather than paraphrasing:
 * release notes describe in detail what a version fixes, and a database that knows *this named site*
 * is three versions behind *these fixes* is a map of an exploitable installation. Somebody who gets a
 * read on this database should not thereby get a target list.
 *
 * `updates.v2` accepts them because the danger was located slightly wrong. The text is public - the
 * Craft Plugin Store serves it to anyone, and every site running the plugin already downloaded it,
 * which is why forwarding it costs no outbound request. What was never safe was the *association*,
 * and that is a property of where the receiver puts things. So:
 *
 *  - notes live in `plugin_release_notes`, keyed on a plugin and a version, with no site column and
 *    no organisation column - the table cannot express the association even if asked;
 *  - `update_reports.payload` keeps the whole body a site sent, so the notes are stripped out of it
 *    before it is written;
 *  - and no new outbound destination was introduced to make any of this work.
 *
 * Each of those is one line of code away from being wrong, so each is a test. If this file goes red,
 * the feature has become the thing v1 refused, and the fix is the code.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create();
});

it('keeps release notes out of the report it stores against a site', function (): void {
    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::sampleV2Payload());

    $stored = UpdateReport::query()->where('site_id', $this->site->id)->sole();
    $encoded = json_encode($stored->payload, JSON_THROW_ON_ERROR);

    // Written against the stored bytes rather than the array shape: a refactor that starts keeping
    // notes under a different key fails here rather than passing on the strength of a comment. The
    // keys are matched with their JSON punctuation because `releases_behind` is a legitimate field
    // and a bare "releases" would match it.
    expect($encoded)->not->toContain('authentication bypass')
        ->and($encoded)->not->toContain('"releases":')
        ->and($encoded)->not->toContain('"notes":');

    // And the report is otherwise intact - stripping must not cost the fields the screen reads.
    expect($stored->payload['plugins'][0]['handle'])->toBe('formie')
        ->and($stored->payload['plugins'][0]['latest'])->toBe('3.0.14')
        ->and($stored->craft_latest)->toBe('5.6.4');
});

it('stores the notes themselves against the release rather than the site', function (): void {
    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::sampleV2Payload());

    $note = PluginReleaseNote::query()->where('handle', 'formie')->where('version', '3.0.14')->sole();

    expect($note->notes)->toContain('authentication bypass')
        ->and($note->critical)->toBeTrue()
        ->and($note->released_on)->toBe('2026-07-14');
});

it('has nowhere to record which site a release note applies to', function (): void {
    // The whole argument, as a schema property. A well-meaning "site_id" added here two years from
    // now to make a query easier would reintroduce exactly what v1 refused.
    foreach (Schema::getColumnListing('plugin_release_notes') as $column) {
        expect($column)->not->toMatch('/site|organisation|organization|tenant|customer/i');
    }
});

it('does not let two sites reporting the same plugin become two rows', function (): void {
    $other = Site::factory()->for($this->organisation)->connected()->create();

    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::sampleV2Payload());
    app(UpdatesIngestService::class)->store($other, UpdateReportFactory::sampleV2Payload());

    // A fleet running one plugin on forty sites stores one copy of each note. Pleasant, but the
    // reason it holds is that there is nothing site-shaped in the key.
    expect(PluginReleaseNote::query()->where('handle', 'formie')->count())->toBe(2);
});

it('still accepts a report from a connector that has not been upgraded', function (): void {
    // updates.v1 remains valid. A fleet is upgraded a site at a time, and a platform that refused
    // the old report would take every not-yet-upgraded site's updates screen down.
    $problems = app(UpdatesIngestService::class)->validate(UpdateReportFactory::samplePayload());

    expect($problems)->toBe([]);

    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::samplePayload());

    expect(UpdateReport::query()->where('site_id', $this->site->id)->sole()->schema_version)
        ->toBe('updates.v1')
        ->and(PluginReleaseNote::query()->count())->toBe(0);
});

it('refuses a report that puts release notes where the schema does not have them', function (): void {
    // The allowlist still bites. Notes belong to a release, under a field with a shape; a free-text
    // blob hung off the craft object is rejected rather than stripped and stored anyway.
    $problems = app(UpdatesIngestService::class)->validate([
        ...UpdateReportFactory::sampleV2Payload(),
        'craft' => [
            'current' => '5.6.2',
            'latest' => '5.6.4',
            'update_available' => true,
            'release_notes' => 'Fixes an authentication bypass...',
        ],
    ]);

    expect($problems)->not->toBeEmpty();
});

it('adds no outbound destination to serve plugin notes', function (): void {
    /*
     | The alternative design, and the reason it was not taken.
     |
     | Resolving a plugin handle against the Plugin Store would have been far less code. It would
     | also have told that store which plugins every site in a fleet runs and which of them are
     | behind - the fleet inventory leaking one request at a time. Connectors forward what their own
     | Craft install already downloaded instead, so the platform asks nobody anything.
     |
     | ChangelogFetchTest guards the same constant from the other side; this asserts the plugin route
     | did not quietly add a second entry to it.
     */
    expect(ChangelogFetcher::SOURCES)->toBe([
        'craft' => 'https://raw.githubusercontent.com/craftcms/cms/5.x/CHANGELOG.md',
    ]);
});
