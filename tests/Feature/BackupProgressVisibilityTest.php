<?php

declare(strict_types=1);

use App\Domain\Backup\BackupTimeline;
use App\Domain\Backup\InFlightBackup;
use App\Domain\Backup\InFlightBackups;
use App\Models\BackupEvent;
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
 | What a person watching a backup can actually tell.
 |
 | Reported live: backups sat at "Collected by the site" for the best part of an hour with nothing
 | else on the screen. The stepper had no way to advance — the connector reports a phase once, when
 | the dump starts, and the only other events the platform read were ones nothing ever wrote. So a
 | backup that was busy uploading looked exactly like one that had stopped dead, and neither said
 | how long it had been that way.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    RecoveryKey::factory()->for($this->organisation)->create();

    $this->job = fn (array $attributes = []): RemoteJob => RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'expires_at' => now()->addHour(),
        ...$attributes,
    ]);
});

it('advances to uploading on a declaration, which the platform records itself', function (): void {
    // The connector never reports an upload stage and it is right not to: a signed request with a
    // nonce and a rate-limit slot, to say something the declaration already proves. A site cannot
    // declare checksums for bytes it has not produced, so a declaration means dumped and encrypted.
    $job = ($this->job)();

    app(BackupTimeline::class)->platform(event: BackupEvent::DECLARED, site: $this->site, job: $job);

    $backup = app(InFlightBackups::class)->forSite($this->site)->first();

    expect($backup->phase)->toBe(InFlightBackup::PHASE_UPLOADING)
        // Observed here, not claimed by the site, and the screen distinguishes the two.
        ->and($backup->reportedBySite)->toBeFalse();
});

it('says how long a backup has been at its phase', function (): void {
    $job = ($this->job)(['created_at' => now()->subMinutes(50)]);

    $this->travelTo(now()->subMinutes(45), function () use ($job): void {
        app(BackupTimeline::class)->connector(event: BackupEvent::DUMP_STARTED, site: $this->site, job: $job);
    });

    $backup = app(InFlightBackups::class)->forSite($this->site)->first();

    // The phase changed 45 minutes ago, not when the job was requested 50 minutes ago. Those answer
    // different questions and only the first one tells somebody to go and look.
    expect($backup->since()->diffInMinutes(now()))->toBeGreaterThanOrEqual(44)
        ->and($backup->since()->diffInMinutes(now()))->toBeLessThan(46);
});

it('flags a backup that has gone quiet for half its allowed runtime', function (): void {
    $job = ($this->job)([
        'created_at' => now()->subMinutes(50),
        'expires_at' => now()->addMinutes(10),
    ]);

    $backup = app(InFlightBackups::class)->forSite($this->site)->first();

    // No phase report at all, 50 minutes into a 60-minute window. Measured against the job's own
    // expiry rather than a number chosen in a view, so the screen and the sweep that eventually
    // gives up agree with each other.
    expect($backup->looksStalled())->toBeTrue();
});

it('does not flag a backup that has only just started', function (): void {
    ($this->job)(['created_at' => now()->subMinute()]);

    expect(app(InFlightBackups::class)->forSite($this->site)->first()->looksStalled())->toBeFalse();
});

it('shows the phase age and the point it will be given up on', function (): void {
    $job = ($this->job)(['created_at' => now()->subMinutes(50), 'expires_at' => now()->addMinutes(10)]);

    $this->actingAs($this->owner)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('at this phase')
        ->assertSee('Given up on at')
        ->assertSee('No change');
});
