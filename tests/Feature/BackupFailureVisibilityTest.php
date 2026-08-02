<?php

declare(strict_types=1);

use App\Domain\Job\JobService;
use App\Domain\Notifications\NotificationEvent;
use App\Jobs\DeliverNotification;
use App\Models\BackupArtifact;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Support\Facades\Queue;

/*
 | A backup that fails has to reach a person.
 |
 | Every case here is one that happened in production. A site whose database had outgrown its
 | connector's limit failed every night and told nobody: the connector refuses before it declares an
 | artifact, so there was no row on the backups screen, and the job had left the queue, so there was
 | no in-progress row either. The request vanished and the screen went on showing the previous
 | backup, which had worked.
 |
 | The pricing page has promised "a notification when a run does not complete" since launch. Nothing
 | dispatched anything.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Oscar']);
    $this->connector = Connector::factory()->for($this->site)->create();
    RecoveryKey::factory()->for($this->organisation)->create();
});

it('shows a backup the site refused, which left no artifact behind', function (): void {
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'the database is larger than this connector is configured to back up',
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Did not complete')
        ->assertSee('larger than this connector is configured')
        // The reason alone is a diagnosis. This is the setting that changes it.
        ->assertSee('maxBackupMegabytes');
});

it('does not report the same failure twice when an artifact exists', function (): void {
    // A declared artifact that later failed is already on the screen with a reason of its own.
    // Listing the job as well would describe one failure twice, in two different sentences.
    $job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'the database is larger than this connector is configured to back up',
    ]);

    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'remote_job_id' => $job->id,
        'state' => BackupArtifact::STATE_FAILED,
        'failure_reason' => 'The artifact was declared but never uploaded',
    ]);

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    expect(substr_count($html, 'larger than this connector is configured'))->toBe(0);
});

it('stops shouting about a failure once it is old', function (): void {
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'the database is larger than this connector is configured to back up',
        'updated_at' => now()->subDays(30),
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertDontSee('larger than this connector is configured');
});

it('tells somebody when a site reports the backup failed', function (): void {
    Queue::fake();

    NotificationDestination::factory()->for($this->organisation)->create([
        'enabled' => true,
        'events' => [NotificationEvent::BACKUP_FAILED],
    ]);

    $job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'claimed_by_connector_id' => $this->connector->id,
    ]);

    app(JobService::class)->report(
        site: $this->site,
        connector: $this->connector,
        jobExternalId: $job->external_id,
        succeeded: false,
        failureReason: 'the database is larger than this connector is configured to back up',
    );

    Queue::assertPushed(DeliverNotification::class);
});

it('does not notify anybody about an ordinary job failing', function (): void {
    // The catalogue is short on purpose. A channel that fires on every failed inventory refresh is
    // one people filter away, and then the backup message goes with it.
    Queue::fake();

    NotificationDestination::factory()->for($this->organisation)->create([
        'enabled' => true,
        'events' => [NotificationEvent::BACKUP_FAILED],
    ]);

    $job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::INVENTORY_REFRESH,
        'state' => Jobs::STATE_CLAIMED,
        'claimed_by_connector_id' => $this->connector->id,
    ]);

    app(JobService::class)->report(
        site: $this->site,
        connector: $this->connector,
        jobExternalId: $job->external_id,
        succeeded: false,
        failureReason: 'something went wrong',
    );

    Queue::assertNotPushed(DeliverNotification::class);
});

it('offers backup failures as something to subscribe to', function (): void {
    expect(NotificationEvent::catalogue())->toHaveKey(NotificationEvent::BACKUP_FAILED)
        ->and(NotificationEvent::isKnown(NotificationEvent::BACKUP_FAILED))->toBeTrue();
});
