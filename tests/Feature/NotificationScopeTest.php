<?php

declare(strict_types=1);

use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\WebhookTransport;
use App\Jobs\DeliverNotification;
use App\Models\Membership;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use Illuminate\Support\Facades\Queue;

/*
 * Scoping a notification destination to particular sites.
 *
 * Two failure directions, and they are not symmetrical. Sending too little is an alert somebody
 * misses; sending too much is one client being told about another's unpatched site. The tests are
 * weighted accordingly - most of what is below is about the second.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->alpha = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Alpha']);
    $this->beta = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Beta']);

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->destination = function (array $sites = []): NotificationDestination {
        $destination = NotificationDestination::factory()->for($this->organisation)->create([
            'events' => [NotificationEvent::FINDING_OPENED, NotificationEvent::BACKUP_FAILED],
        ]);

        if ($sites !== []) {
            $destination->sites()->sync(collect($sites)->pluck('id')->all());
        }

        return $destination;
    };

    $this->raise = function (?Site $site): int {
        return app(Notifier::class)->dispatch(new NotificationEvent(
            type: NotificationEvent::FINDING_OPENED,
            subject: 'Something happened',
            summary: 'A finding opened.',
            site: $site,
        ), $this->organisation);
    };
});

it('treats a destination with no scope as covering every site', function (): void {
    // The default, and what every destination created before scoping existed has. It must not have
    // changed behaviour on the day the pivot table appeared.
    $destination = ($this->destination)();

    expect($destination->covers($this->alpha))->toBeTrue()
        ->and($destination->covers($this->beta))->toBeTrue()
        ->and($destination->covers(null))->toBeTrue();
});

it('covers a site it is scoped to and not one it is not', function (): void {
    $destination = ($this->destination)([$this->alpha]);

    expect($destination->covers($this->alpha))->toBeTrue()
        ->and($destination->covers($this->beta))->toBeFalse();
});

it('still hears about a fleet-wide event when it is scoped to one site', function (): void {
    // Somebody narrowing a destination answered "which sites", not "stop telling me about the
    // installation". An event with no site attached is not about a site at all.
    $destination = ($this->destination)([$this->alpha]);

    expect($destination->covers(null))->toBeTrue();
});

it('queues a delivery only for the destination responsible for that site', function (): void {
    Queue::fake();

    ($this->destination)([$this->alpha]);
    ($this->destination)([$this->beta]);
    ($this->destination)();

    expect(($this->raise)($this->alpha))->toBe(2);

    // The Alpha-scoped one and the unscoped one. Not Beta's.
    Queue::assertPushed(DeliverNotification::class, 2);
});

it('tells nobody about a site outside every scope', function (): void {
    Queue::fake();

    ($this->destination)([$this->alpha]);

    expect(($this->raise)($this->beta))->toBe(0);

    Queue::assertNothingPushed();
});

it('re-checks the scope at delivery time', function (): void {
    /*
     | A destination narrowed between queueing and running would otherwise still deliver whatever was
     | already in flight. That arrives minutes later and reads as a bug in the scope rather than in
     | the queue, which is the worst kind to diagnose.
     */
    $destination = ($this->destination)();

    $job = new DeliverNotification($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Something happened',
        summary: 'A finding opened.',
        site: $this->beta,
    ));

    $destination->sites()->sync([$this->alpha->id]);

    $job->handle(
        app(WebhookTransport::class),
        app(EmailTransport::class),
        app(CorrelationId::class),
    );

    expect($destination->deliveries()->count())->toBe(0);
});

it('still sends a test delivery to a scoped destination', function (): void {
    // The test button asks "is this reachable", not "is this subscribed" - and now not "is this in
    // scope" either. A destination narrowed to one site must still be testable.
    Queue::fake();

    $destination = ($this->destination)([$this->alpha]);

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post("/settings/notifications/{$destination->getRouteKey()}/test")
        ->assertRedirect();

    Queue::assertPushed(DeliverNotification::class, 1);
});

it('creates a destination scoped to the sites an owner ticked', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/settings/notifications', [
            'transport' => 'email',
            'label' => 'Alpha only',
            'target' => 'alpha@example.org',
            'events' => [NotificationEvent::BACKUP_FAILED],
            'scope' => 'some',
            'sites' => [$this->alpha->external_id],
        ])
        ->assertRedirect();

    $destination = NotificationDestination::query()->where('label', 'Alpha only')->sole();

    expect($destination->sites()->pluck('sites.id')->all())->toBe([$this->alpha->id]);
});

it('creates an unscoped destination by default', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/settings/notifications', [
            'transport' => 'email',
            'label' => 'Everything',
            'target' => 'ops@example.org',
            'events' => [NotificationEvent::BACKUP_FAILED],
        ])
        ->assertRedirect();

    $destination = NotificationDestination::query()->where('label', 'Everything')->sole();

    expect($destination->sites()->count())->toBe(0)
        ->and($destination->covers($this->beta))->toBeTrue();
});

it('will not scope a destination to another organisation\'s site', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create(['name' => 'Theirs']);

    // Every identifier unrecognised, so the scope would resolve to nothing - and no pivot rows means
    // every site. Refused rather than quietly widened into the one mistake that tells a customer
    // about somebody else's fleet.
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/settings/notifications', [
            'transport' => 'email',
            'label' => 'Sneaky',
            'target' => 'ops@example.org',
            'events' => [NotificationEvent::BACKUP_FAILED],
            'scope' => 'some',
            'sites' => [$theirs->external_id],
        ])
        ->assertSessionHasErrors('sites');

    expect(NotificationDestination::query()->where('label', 'Sneaky')->exists())->toBeFalse();
});

it('keeps the sites it recognises and drops the ones it does not', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/settings/notifications', [
            'transport' => 'email',
            'label' => 'Mixed',
            'target' => 'ops@example.org',
            'events' => [NotificationEvent::BACKUP_FAILED],
            'scope' => 'some',
            'sites' => [$this->alpha->external_id, $theirs->external_id],
        ])
        ->assertRedirect();

    $destination = NotificationDestination::query()->where('label', 'Mixed')->sole();

    expect($destination->sites()->pluck('sites.id')->all())->toBe([$this->alpha->id]);
});

it('requires at least one site when scoping to some', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/settings/notifications', [
            'transport' => 'email',
            'label' => 'Empty scope',
            'target' => 'ops@example.org',
            'events' => [NotificationEvent::BACKUP_FAILED],
            'scope' => 'some',
        ])
        ->assertSessionHasErrors('sites');
});

it('says on the screen which sites a destination covers', function (): void {
    ($this->destination)([$this->alpha]);
    ($this->destination)();

    $this->actingAs($this->owner)
        ->get('/settings/notifications')
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('All sites, including any added later');
});

it('forgets the scope when a site is removed', function (): void {
    // A scope that still named a deleted site would quietly widen: the rows left behind would become
    // the whole of it.
    $destination = ($this->destination)([$this->alpha, $this->beta]);

    $this->alpha->delete();

    expect($destination->fresh()->sites()->pluck('sites.id')->all())->toBe([$this->beta->id]);
});
