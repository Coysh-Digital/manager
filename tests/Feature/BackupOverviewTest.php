<?php

declare(strict_types=1);

use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;

/*
 * Seeing the state of a fleet's backups without reading every row.
 *
 * Three things here, and they share one property: each is a claim the screen makes *about* data it
 * is not showing you, so each can be wrong without looking wrong.
 *
 *  - The summary tiles count the organisation, not the page. They were previously derived from the
 *    loaded collection, which was capped, so an organisation with more artifacts than one page was
 *    told it was storing less than it was.
 *  - The filters have to compose and survive paging, or a filtered view cannot be sent to anybody -
 *    which is the only reason to have them in a query string.
 *  - The upcoming runs are a promise about what a scheduled command will do.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    RecoveryKey::factory()->for($this->organisation)->create();
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function artifact(array $attributes = []): BackupArtifact
{
    return BackupArtifact::factory()->for(test()->site)->create([
        'organisation_id' => test()->organisation->id,
        ...$attributes,
    ]);
}

/*
 | The summary
 |-------------------------------------------------------------------------------------------------
 */

it('counts every artifact the organisation holds, not the ones on this page', function (): void {
    // Sixty is above the fifty-per-page ceiling. The old strip summed the loaded collection, so this
    // is the case where it reported the page and called it the organisation.
    for ($i = 0; $i < 60; $i++) {
        artifact([
            'state' => BackupArtifact::STATE_STORED,
            'taken_at' => now()->subHours($i + 1),
        ]);
    }

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    expect($html)->toContain('>60<');
});

it('adds up the bytes storage actually holds', function (): void {
    // The same rule the quota enforces - artifact_bytes for a v2 artifact, ciphertext otherwise -
    // because a meter that measured something else would disagree with the thing refusing backups.
    artifact([
        'state' => BackupArtifact::STATE_STORED,
        'format_version' => BackupArtifact::FORMAT_V2,
        'artifact_bytes' => 3 * 1048576,
        'ciphertext_bytes' => 1,
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('3.0 MB');
});

it('counts each state separately, under the word the table uses for it', function (): void {
    artifact(['state' => BackupArtifact::STATE_STORED]);
    artifact(['state' => BackupArtifact::STATE_PENDING]);
    artifact(['state' => BackupArtifact::STATE_FAILED]);

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    // "Arriving" rather than "In progress": that heading already belongs to the panel above the
    // table, which counts jobs rather than artifacts, and two numbers under one word on one screen
    // is how somebody comes to distrust both.
    expect($html)->toContain('Arriving')
        ->and($html)->toContain('Stored')
        ->and($html)->toContain('Failed');
});

/*
 | The filters
 |-------------------------------------------------------------------------------------------------
 */

/*
 * Rows are identified here by their short checksum rather than their external id, and that is not
 * arbitrary. The external id is only rendered inside the bulk checkbox, which is drawn only for a
 * row that has something to delete - so a failed artifact that still holds a storage key carries no
 * external id on the page at all, and an assertion on one would be testing the checkbox rather than
 * the filter. The checksum is on every row.
 */
it('filters the table to one state from the query string', function (): void {
    $stored = artifact(['state' => BackupArtifact::STATE_STORED]);
    $failed = artifact(['state' => BackupArtifact::STATE_FAILED]);

    $this->actingAs($this->owner)->get('/backups?state=failed')
        ->assertOk()
        ->assertSee($failed->shortChecksum())
        ->assertDontSee($stored->shortChecksum());
});

it('filters to one site by its external identifier', function (): void {
    $other = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Other Site']);

    $mine = artifact(['state' => BackupArtifact::STATE_STORED]);
    $theirs = BackupArtifact::factory()->for($other)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
    ]);

    $this->actingAs($this->owner)->get('/backups?site='.$other->external_id)
        ->assertOk()
        ->assertSee($theirs->shortChecksum())
        ->assertDontSee($mine->shortChecksum());
});

it('composes the two filters rather than letting one replace the other', function (): void {
    $other = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Other Site']);

    $wanted = BackupArtifact::factory()->for($other)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_FAILED,
    ]);

    // Right site, wrong state.
    $wrongState = BackupArtifact::factory()->for($other)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
    ]);

    // Right state, wrong site.
    $wrongSite = artifact(['state' => BackupArtifact::STATE_FAILED]);

    $this->actingAs($this->owner)->get('/backups?state=failed&site='.$other->external_id)
        ->assertOk()
        ->assertSee($wanted->shortChecksum())
        ->assertDontSee($wrongState->shortChecksum())
        ->assertDontSee($wrongSite->shortChecksum());
});

it('ignores a state that is not one of the three it lists', function (): void {
    // The value reaches a where() on a column. `deleted` is a real state and deliberately not
    // listable - a tombstone is not a backup somebody has - so asking for it must fall back to
    // everything rather than quietly showing tombstones.
    $stored = artifact(['state' => BackupArtifact::STATE_STORED]);
    $tombstone = artifact(['state' => BackupArtifact::STATE_DELETED]);

    $this->actingAs($this->owner)->get('/backups?state=deleted')
        ->assertOk()
        ->assertSee($stored->shortChecksum())
        ->assertDontSee($tombstone->shortChecksum());
});

it('keeps the filter attached to the second page', function (): void {
    for ($i = 0; $i < 60; $i++) {
        artifact([
            'state' => BackupArtifact::STATE_STORED,
            'taken_at' => now()->subHours($i + 1),
        ]);
    }

    $html = $this->actingAs($this->owner)->get('/backups?state=stored')->assertOk()->getContent();

    // Without withQueryString() on the paginator the pager links drop the filter, so page two of a
    // filtered view silently becomes page two of everything.
    expect($html)->toContain('state=stored');
});

it('leaves a way back when a filter matches nothing', function (): void {
    artifact(['state' => BackupArtifact::STATE_STORED]);

    $this->actingAs($this->owner)->get('/backups?state=failed')
        ->assertOk()
        // The card used to be drawn only when the page had rows, so filtering to nothing took the
        // filter bar and its Clear link with it.
        ->assertSee('No backup matches that filter')
        ->assertSee('Show every backup');
});

it('shows another organisation nothing, whatever it asks for', function (): void {
    $stranger = Organisation::factory()->create();
    $strangerSite = Site::factory()->for($stranger)->connected()->create(['name' => 'Their Site']);
    $theirs = BackupArtifact::factory()->for($strangerSite)->create([
        'organisation_id' => $stranger->id,
        'state' => BackupArtifact::STATE_STORED,
    ]);

    $this->actingAs($this->owner)->get('/backups?site='.$strangerSite->external_id)
        ->assertOk()
        ->assertDontSee($theirs->external_id)
        ->assertDontSee('Their Site');
});

/*
 | Scheduled runs
 |-------------------------------------------------------------------------------------------------
 */

it('says when the schedule will next fire', function (): void {
    $this->site->forceFill([
        'backup_schedule' => 'daily',
        'backup_schedule_hour' => 3,
        'timezone' => 'Europe/London',
    ])->save();

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Scheduled runs')
        ->assertSee('Upcoming')
        ->assertSee('03:00');
});

it('says so plainly when no site has a schedule', function (): void {
    // A site with a finished job, so the panel is drawn at all and the empty half is the one under
    // test rather than the whole panel being absent.
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_SUCCEEDED,
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('No site has a backup schedule');
});

it('lists what the schedule has produced, successes as well as failures', function (): void {
    /*
     * The point of the past half. The two panels above it are each scoped to what still needs
     * somebody - work in flight, and undismissed failures - so a fleet whose schedule had quietly
     * stopped firing looked exactly like a fleet with nothing to do.
     */
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_SUCCEEDED,
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Past')
        ->assertSee('Succeeded');
});

it('does not count a backup still in flight as a past run', function (): void {
    // A schedule, so the panel is drawn and the half under test is the empty one rather than the
    // whole panel being absent.
    $this->site->forceFill([
        'backup_schedule' => 'daily',
        'backup_schedule_hour' => 3,
        'timezone' => 'Europe/London',
    ])->save();

    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_QUEUED,
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('No backup has finished yet');
});

/*
 | The sidebar
 |-------------------------------------------------------------------------------------------------
 */

it('counts failed backups in the sidebar, in red', function (): void {
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'destination_unreachable',
    ]);

    $this->actingAs($this->owner)->get('/sites')
        ->assertOk()
        ->assertSee('border-danger-line', false);
});

it('shows work in progress quietly, and lets a failure take the slot instead', function (): void {
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_QUEUED,
    ]);

    $running = $this->actingAs($this->owner)->get('/sites')->assertOk()->getContent();

    // Nothing needs acting on, so nothing shouts.
    expect($running)->not->toContain('border-danger-line');

    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'destination_unreachable',
    ]);

    $failed = $this->actingAs($this->owner)->get('/sites')->assertOk()->getContent();

    expect($failed)->toContain('border-danger-line');
});

it('says nothing at all when there is nothing outstanding', function (): void {
    $html = $this->actingAs($this->owner)->get('/sites')->assertOk()->getContent();

    // A nav entry carrying a permanent zero teaches people to stop reading it.
    expect($html)->toMatch('/>Backups<\/span>\s*+<\/a>/');
});
