<?php

declare(strict_types=1);

use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Carbon;

/**
 * Backups that happen without anybody pressing a button.
 *
 * A schedule is where a security property quietly stops holding, because it removes the person who
 * would have noticed. Somebody clicking "back up now" sees the error; a nightly job that has been
 * failing for six weeks is discovered when a restore is needed. So most of this file is about the
 * conditions the scheduler refuses to proceed under, and about the one thing it must never do —
 * decide anything about the *content* of a backup.
 *
 * A scheduled backup is identical to a requested one in every respect that matters. Same job type,
 * same empty parameter list, same recipients from the organisation's recovery keys, same destination
 * from the site's own configuration. The schedule decides *when* to ask and nothing else.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->key = RecoveryKey::factory()->for($this->organisation)->create();
    $this->organisation->forceFill(['backup_format_floor' => Protocol::BACKUP_FORMAT_V2])->save();

    $this->makeSite = function (array $attributes = []): Site {
        $site = Site::factory()->for($this->organisation)->connected()->create(array_merge([
            'backup_schedule' => 'daily',
            'backup_schedule_hour' => 3,

            // The site's own zone. A fleet split between London and Sydney has no single quiet
            // hour, which is why this stopped being the organisation's.
            'timezone' => 'Europe/London',
        ], $attributes));

        Connector::factory()->for($site)->create();
        CapabilityGrant::factory()->for($site)->capability('backups:create')->create();

        return $site;
    };

    // 03:00 in the site's own time zone, which is the whole reason the column exists.
    $this->atScheduledHour = fn () => Carbon::setTestNow(
        Carbon::parse('2026-08-05 03:30', 'Europe/London')->utc(),
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------------------------------
| When it fires
|--------------------------------------------------------------------------------------------------
*/

it('asks for a backup at the hour the site was set to', function (): void {
    $site = ($this->makeSite)();
    ($this->atScheduledHour)();

    $this->artisan('manager:backups:schedule')->assertSuccessful();

    $job = RemoteJob::query()->where('site_id', $site->id)->first();

    expect($job)->not->toBeNull()
        ->and($job->type)->toBe(Jobs::BACKUP_CREATE)
        // No parameters at all. A backup job carries nothing naming a destination, a recipient or a
        // format, and a schedule is not a way to smuggle one in.
        ->and($job->parameters)->toBe([]);
});

it('does not fire at other hours', function (): void {
    ($this->makeSite)();
    Carbon::setTestNow(Carbon::parse('2026-08-05 09:30', 'Europe/London')->utc());

    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('reads the hour in the organisation\'s time zone, not the server\'s', function (): void {
    // The reason the column is not just an hour. 03:00 in London is 02:00 UTC in summer, and a
    // scheduler that used server time would back this site up in the middle of its afternoon.
    ($this->makeSite)();

    Carbon::setTestNow(Carbon::parse('2026-08-05 03:30', 'UTC'));
    $this->artisan('manager:backups:schedule')->assertSuccessful();
    expect(RemoteJob::query()->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-08-05 02:30', 'UTC'));
    $this->artisan('manager:backups:schedule')->assertSuccessful();
    expect(RemoteJob::query()->count())->toBe(1);
});

it('fires a weekly schedule only on its day', function (): void {
    // 2026-08-05 is a Wednesday.
    ($this->makeSite)(['backup_schedule' => 'weekly', 'backup_schedule_day' => 3]);
    $other = ($this->makeSite)(['backup_schedule' => 'weekly', 'backup_schedule_day' => 7]);

    ($this->atScheduledHour)();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(1)
        ->and(RemoteJob::query()->where('site_id', $other->id)->exists())->toBeFalse();
});

it('leaves a site alone when its schedule is off', function (): void {
    ($this->makeSite)(['backup_schedule' => 'off']);
    ($this->atScheduledHour)();

    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('does not ask twice in the same window', function (): void {
    ($this->makeSite)();
    ($this->atScheduledHour)();

    $this->artisan('manager:backups:schedule')->assertSuccessful();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    // Two scheduler containers, or a run that overlapped its predecessor, resolve to one job.
    expect(RemoteJob::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------------------------------
| When it refuses
|--------------------------------------------------------------------------------------------------
*/

it('will not schedule a backup an organisation has no key to encrypt', function (): void {
    $this->key->forceFill(['state' => RecoveryKey::STATE_REVOKED, 'revoked_at' => now()])->save();

    ($this->makeSite)();
    ($this->atScheduledHour)();

    // The connector would refuse before dumping, so queuing this would turn a missing setting into a
    // nightly failed job whose real cause is somewhere else entirely. It is a refusal that says so.
    $this->artisan('manager:backups:schedule')
        ->expectsOutputToContain('no active recovery key')
        ->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('will not schedule a backup a site has not been given permission for', function (): void {
    $site = ($this->makeSite)();
    CapabilityGrant::query()->where('site_id', $site->id)->delete();

    ($this->atScheduledHour)();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('will not schedule a backup for a site with no live connector', function (): void {
    $site = ($this->makeSite)();
    Connector::query()->where('site_id', $site->id)->delete();

    ($this->atScheduledHour)();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    // Otherwise the job sits queued until it expires and the fleet looks busy rather than disconnected.
    expect(RemoteJob::query()->count())->toBe(0);
});

it('will not queue one backup behind another', function (): void {
    $site = ($this->makeSite)();

    RemoteJob::factory()->for($site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
    ]);

    ($this->atScheduledHour)();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    // A backup taking longer than an hour must not have another queued behind it. Two concurrent
    // dumps of the same database is how a backup schedule becomes an outage.
    expect(RemoteJob::query()->where('type', Jobs::BACKUP_CREATE)->count())->toBe(1);
});

it('leaves an archived site alone', function (): void {
    ($this->makeSite)(['archived_at' => now()->subDay()]);
    ($this->atScheduledHour)();

    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('changes nothing when asked not to', function (): void {
    ($this->makeSite)();
    ($this->atScheduledHour)();

    $this->artisan('manager:backups:schedule', ['--dry-run' => true])->assertSuccessful();

    expect(RemoteJob::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------------------------------
*/

it('judges each organisation by its own keys and its own clock', function (): void {
    // One organisation with a key, one without. The scheduler must not let the first one's readiness
    // stand in for the second's.
    $ready = ($this->makeSite)();

    $other = Organisation::factory()->create();
    $otherSite = Site::factory()->for($other)->connected()->create([
        'backup_schedule' => 'daily',
        'backup_schedule_hour' => 3,
        'timezone' => 'Europe/London',
    ]);
    Connector::factory()->for($otherSite)->create();
    CapabilityGrant::factory()->for($otherSite)->capability('backups:create')->create();

    ($this->atScheduledHour)();
    $this->artisan('manager:backups:schedule')->assertSuccessful();

    expect(RemoteJob::query()->where('site_id', $ready->id)->exists())->toBeTrue()
        ->and(RemoteJob::query()->where('site_id', $otherSite->id)->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------------------------------
| Setting a schedule
|--------------------------------------------------------------------------------------------------
*/

it('lets an administrator set a schedule, and records the change', function (): void {
    $site = ($this->makeSite)(['backup_schedule' => 'off']);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$site->external_id}/backups/schedule", [
            'backup_schedule' => 'weekly',
            'backup_schedule_hour' => 2,
            'backup_schedule_day' => 6,
            'timezone' => 'Europe/London',
        ])->assertRedirect();

    $site->refresh();

    expect($site->backup_schedule)->toBe('weekly')
        ->and($site->backup_schedule_hour)->toBe(2)
        ->and($site->backup_schedule_day)->toBe(6);

    // Changing when a site is backed up is a change to the site, and the audit trail shows what it
    // was before - the same treatment the expected domain gets. Its own action now that it has its
    // own form: "site.updated" covering both meant an audit reader could not filter for the one
    // that decides a production database is dumped.
    $event = AuditEvent::query()->where('action', 'site.backup_schedule.updated')->latest('id')->first();

    expect($event->before['backup_schedule'])->toBe('off')
        ->and($event->after['backup_schedule'])->toBe('weekly');
});

it('leaves the schedule alone when the site settings form is saved', function (): void {
    // The settings form no longer carries these fields at all, which is a stronger version of the
    // guarantee this replaced: renaming a site cannot disable its backups, because the form that
    // renames it has nothing to say about them.
    $site = ($this->makeSite)(['backup_schedule' => 'daily']);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$site->external_id}/settings", [
            'name' => 'Renamed',
            'expected_domain' => $site->expected_domain,
            'environment' => $site->environment,
            // Sent, and ignored: the controller does not validate or read them.
            'backup_schedule' => 'off',
        ])->assertRedirect();

    expect($site->fresh()->backup_schedule)->toBe('daily');
});

it('will not let a schedule be set before there is a key to encrypt to', function (): void {
    /*
     | The quietest failure this area had.
     |
     | The form saved, the audit log recorded it, the settings screen read "Every day" indefinitely,
     | and ScheduleBackupsCommand skipped the site every hour with its reason going to cron's stdout
     | and nowhere else. Somebody could believe a fleet had been backed up nightly for months on the
     | evidence of a screen that agreed with them.
    */
    $this->key->forceFill(['state' => RecoveryKey::STATE_REVOKED, 'revoked_at' => now()])->save();

    $site = ($this->makeSite)(['backup_schedule' => 'off']);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$site->external_id}/backups/schedule", [
            'backup_schedule' => 'daily',
            'backup_schedule_hour' => 2,
            'backup_schedule_day' => 1,
            'timezone' => 'Europe/London',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('backup_schedule');

    expect($site->fresh()->backup_schedule)->toBe('off');
});

it('always lets a schedule be turned off', function (): void {
    // Needing a recovery key in order to stop asking for backups would be absurd, and would strand
    // an organisation whose key was revoked with a schedule it could not switch off.
    $site = ($this->makeSite)(['backup_schedule' => 'daily']);

    $this->key->forceFill(['state' => RecoveryKey::STATE_REVOKED, 'revoked_at' => now()])->save();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$site->external_id}/backups/schedule", [
            'backup_schedule' => 'off',
            'backup_schedule_hour' => 3,
            'backup_schedule_day' => 1,
            'timezone' => 'Europe/London',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($site->fresh()->backup_schedule)->toBe('off');
});

it('refuses to queue a backup for an organisation with no key, whoever asks', function (): void {
    // The rule lives in JobService as well as in BackupReadiness, so a caller that never consults
    // readiness - a command, a future screen - cannot create a job that can only be cancelled later.
    $this->key->forceFill(['state' => RecoveryKey::STATE_REVOKED, 'revoked_at' => now()])->save();

    $site = ($this->makeSite)();

    expect(fn () => app(JobService::class)->enqueue($site, Jobs::BACKUP_CREATE))
        ->toThrow(JobRejectedException::class);

    expect(RemoteJob::query()->where('type', Jobs::BACKUP_CREATE)->count())->toBe(0);
});

it('sets the schedule on the screen that shows what it produced', function (): void {
    /*
     | Reported from use: the schedule was on the site's Settings form, sharing a Save button with
     | the site's name and its expected domain - so the answer to "why has this site not been backed
     | up" lived on a different screen from the evidence that it had not.
     */
    $site = ($this->makeSite)(['backup_schedule' => 'off']);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)->get("/sites/{$site->external_id}/backups")
        ->assertOk()
        // On or off, said plainly, because "no backups yet" and "no backups ever" look identical in
        // an empty list.
        ->assertSee('Only when asked')
        ->assertSee('No schedule')
        ->assertSee(route('sites.backups.schedule', $site));

    // And gone from where it was.
    $this->actingAs($admin)->get("/sites/{$site->external_id}/settings")
        ->assertOk()
        ->assertDontSee('name="backup_schedule"', false);
});

it('names the zone the schedule is in, which is the site\'s and not the reader\'s', function (): void {
    // An hour with no zone beside it is a number somebody guesses at, and the guess is their own
    // zone rather than the site's - which is the one ScheduleBackupsCommand reads.
    $site = ($this->makeSite)([
        'backup_schedule' => 'daily',
        'backup_schedule_hour' => 3,
        'timezone' => 'Europe/London',
    ]);

    expect($site->fresh()->backupScheduleSentence())->toBe('Every day at 03:00 (Europe/London).');

    $site->forceFill(['backup_schedule' => 'weekly', 'backup_schedule_day' => 3])->save();

    expect($site->fresh()->backupScheduleSentence())->toBe('Every Wednesday at 03:00 (Europe/London).');

    $site->forceFill(['backup_schedule' => 'off'])->save();

    expect($site->fresh()->hasBackupSchedule())->toBeFalse()
        ->and($site->fresh()->backupScheduleSentence())->toContain('only when somebody asks');
});

it('refuses a schedule change from somebody who is not an administrator', function (): void {
    $site = ($this->makeSite)(['backup_schedule' => 'off']);

    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$site->external_id}/backups/schedule", [
            'backup_schedule' => 'daily',
            'backup_schedule_hour' => 3,
            'backup_schedule_day' => 1,
            'timezone' => 'Europe/London',
        ])->assertForbidden();

    expect($site->fresh()->backup_schedule)->toBe('off');
});

it('keeps the recent-authentication gate the schedule had before it moved', function (): void {
    // Moving a control must not quietly drop a gate it was behind. This decides that a production
    // database is dumped on a repeating schedule, which is the same act "Back up now" performs.
    $site = ($this->makeSite)(['backup_schedule' => 'off']);

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->post("/sites/{$site->external_id}/backups/schedule", [
            'backup_schedule' => 'daily',
            'backup_schedule_hour' => 3,
            'backup_schedule_day' => 1,
            'timezone' => 'Europe/London',
        ])->assertRedirect(route('password.confirm'));

    expect($site->fresh()->backup_schedule)->toBe('off');
});
