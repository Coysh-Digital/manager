<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationEvent;
use App\Jobs\DeliverNotification;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/*
 * The scheduled findings sweep.
 *
 * This exists for one rule and one alert. `site_not_reporting` is about a site that has stopped
 * talking to us, and until this command existed findings were only ever evaluated when a site sent a
 * report or when somebody opened a screen. A silent site does neither, so the alert whose entire
 * subject is silence was raised only by code paths that silence prevents from running. A destination
 * could be subscribed to "a site stops reporting", correctly configured, and never receive anything.
 *
 * Every test here goes through the command with no request and no ingest, because that is the
 * condition the whole thing is for.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();
});

it('opens a finding for a site that has gone quiet, with nothing having reported', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Gone Quiet',
        'last_seen_at' => now()->subHours(8),
    ]);
    Connector::factory()->for($site)->create();

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    expect(Finding::query()->where('site_id', $site->id)->where('rule', 'site_not_reporting')->exists())
        ->toBeTrue();
});

it('queues the site.silent notification the schedule was the only way to reach', function (): void {
    Queue::fake();

    $site = Site::factory()->for($this->organisation)->connected()->create([
        'last_seen_at' => now()->subHours(8),
    ]);
    Connector::factory()->for($site)->create();

    NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::SITE_SILENT],
    ]);

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    Queue::assertPushed(DeliverNotification::class, 1);
});

it('leaves a site that is still reporting alone', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'last_seen_at' => now()->subMinutes(10),
    ]);
    Connector::factory()->for($site)->create();

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    expect(Finding::query()->where('site_id', $site->id)->where('rule', 'site_not_reporting')->exists())
        ->toBeFalse();
});

it('resolves a finding once the site comes back, without waiting for a screen', function (): void {
    // The other half of the same gap. A finding that can only close when somebody opens a page is a
    // list that grows for reasons unrelated to the fleet.
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'last_seen_at' => now()->subMinutes(5),
    ]);
    Connector::factory()->for($site)->create();

    Finding::factory()->for($site)->rule('site_not_reporting')->create();

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    expect(Finding::query()->where('site_id', $site->id)->where('rule', 'site_not_reporting')->sole()->state)
        ->toBe(Finding::STATE_RESOLVED);
});

it('skips an archived site', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'last_seen_at' => now()->subHours(8),
        'archived_at' => now(),
    ]);

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    expect(Finding::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('writes nothing on a dry run', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'last_seen_at' => now()->subHours(8),
    ]);
    Connector::factory()->for($site)->create();

    $this->artisan('manager:findings:sweep', ['--dry-run' => true])->assertSuccessful();

    expect(Finding::query()->count())->toBe(0);
});

it('respects the scope of a destination when the sweep raises the alert', function (): void {
    /*
     | The two halves of this change meeting. A destination scoped to one client must not learn that
     | somebody else's site went quiet - and the sweep is the code path that will now be raising most
     | of these, so it is the one worth asserting against rather than the ingest path.
     */
    Queue::fake();

    $mine = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Mine',
        'last_seen_at' => now()->subHours(8),
    ]);
    Connector::factory()->for($mine)->create();

    $theirs = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Theirs',
        'last_seen_at' => now()->subHours(8),
    ]);
    Connector::factory()->for($theirs)->create();

    $destination = NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::SITE_SILENT],
    ]);
    $destination->sites()->sync([$mine->id]);

    $this->artisan('manager:findings:sweep')->assertSuccessful();

    // Both sites went quiet; only one of them is this destination's business.
    Queue::assertPushed(DeliverNotification::class, 1);
});

it('is registered on the schedule', function (): void {
    // A command nobody runs is the state this replaced. The schedule is the whole feature.
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $command): bool => str_contains($command, 'manager:findings:sweep')))
        ->toBeTrue();
});
