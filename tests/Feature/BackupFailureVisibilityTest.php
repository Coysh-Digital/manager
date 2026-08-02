<?php

declare(strict_types=1);

use App\Contracts\BackupSizeLimit;
use App\Domain\Job\JobService;
use App\Domain\Notifications\NotificationEvent;
use App\Jobs\DeliverNotification;
use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
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

it('settles a declared artifact when the backup job fails', function (): void {
    /*
     * Reported live: four backups sitting at "Uploading" hours apart, with nothing saying why.
     *
     * The declaration had succeeded, so an artifact existed in `pending` — which the screen renders
     * as "Uploading" — and then the upload failed and the job failed with it. The artifact stayed
     * pending, so it went on claiming to be uploading, and FailedBackupJobs excluded the job because
     * an artifact existed. The reason was recorded twice and visible neither time until the nightly
     * prune swept it up.
     */
    $job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'claimed_by_connector_id' => $this->connector->id,
    ]);

    $artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'remote_job_id' => $job->id,
        'state' => BackupArtifact::STATE_PENDING,
    ]);

    app(JobService::class)->report(
        site: $this->site,
        connector: $this->connector,
        jobExternalId: $job->external_id,
        succeeded: false,
        failureReason: 'the upload did not complete',
    );

    $artifact->refresh();

    expect($artifact->state)->toBe(BackupArtifact::STATE_FAILED)
        ->and($artifact->failure_reason)->toBe('the upload did not complete');
});

it('sends no size limit on a self-hosted installation', function (): void {
    // The site's own maxBackupMegabytes stands. Whoever runs this installation runs the machines
    // being backed up, and the limit bounds a dump on a disk they own.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $job = app(JobService::class)->enqueue($this->site, Jobs::BACKUP_CREATE);

    expect($job->parameters)->not->toHaveKey('max_megabytes');
});

it('passes the platform limit through when an edition sets one', function (): void {
    /*
     * Hosted, maxBackupMegabytes is the wrong control in the wrong place: the customer's plugin
     * config is on their own server, most sites have no config file, and the 2 GB default becomes a
     * ceiling nobody chose on storage that is already metered and billed. Zero means no limit.
     */
    app()->bind(BackupSizeLimit::class, fn () => new class implements BackupSizeLimit
    {
        public function megabytes(): ?int
        {
            return 0;
        }
    });

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $job = app(JobService::class)->enqueue($this->site, Jobs::BACKUP_CREATE);

    expect($job->parameters['max_megabytes'])->toBe(0);
});
