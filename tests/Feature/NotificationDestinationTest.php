<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationEvent;
use App\Jobs\DeliverNotification;
use App\Models\AuditEvent;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    // Adding a destination changes where security information is sent, so it sits behind the
    // recent-authentication gate along with everything else that matters.
    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

// A literal address, so nothing in these tests depends on DNS. 203.0.114.1 sits just outside the
// blocked 203.0.113.0/24 documentation range, which makes it a usable stand-in for a public host.
const PUBLIC_HOOK = 'https://203.0.114.1/hooks/manager';

it('shows existing destinations with their subscribed events', function (): void {
    NotificationDestination::factory()->for($this->organisation)->create([
        'label' => 'Operations mailbox',
        'transport' => NotificationDestination::TRANSPORT_EMAIL,
        'target' => 'ops@example.org',
        'events' => [NotificationEvent::FINDING_OPENED],
    ]);

    $this->actingAs($this->owner)->get('/settings/notifications')
        ->assertOk()
        ->assertSee('Operations mailbox')
        ->assertSee('ops@example.org')
        ->assertSee('finding.opened');
});

it('adds an email destination', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Operations mailbox',
        'target' => 'ops@example.org',
        'events' => [NotificationEvent::FINDING_OPENED, NotificationEvent::SITE_SILENT],
    ])->assertRedirect();

    $destination = NotificationDestination::query()->sole();

    expect($destination->transport)->toBe('email')
        ->and($destination->events)->toBe(['finding.opened', 'site.silent'])
        ->and($destination->organisation_id)->toBe($this->organisation->id)
        // No signing secret for email: there is nothing for a recipient to verify a signature with.
        ->and($destination->signing_secret)->toBeNull();
});

it('generates a signing secret for a webhook and shows it once', function (): void {
    $response = $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'webhook',
        'label' => 'Incident channel',
        'target' => PUBLIC_HOOK,
        'events' => [NotificationEvent::FINDING_OPENED],
    ]);

    $destination = NotificationDestination::query()->sole();

    expect($destination->signing_secret)->toBeString()
        ->and(strlen((string) $destination->signing_secret))->toBeGreaterThanOrEqual(40);

    $secret = (string) $destination->signing_secret;

    $response->assertSessionHas('freshSigningSecret', $secret);

    // Once, on the screen the creation redirects to - a receiver needs it in order to verify
    // deliveries...
    $this->actingAs($this->owner)->get('/settings/notifications')->assertOk()->assertSee($secret);

    // ...and not again. It is encrypted at rest and there is no route that will reveal it, so
    // somebody who misses it rotates the destination rather than asking us to look it up.
    $this->actingAs($this->owner)->get('/settings/notifications')->assertOk()->assertDontSee($secret);
});

it('refuses a webhook pointing at a private or reserved address', function (string $url): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'webhook',
        'label' => 'Sneaky',
        'target' => $url,
        'events' => [NotificationEvent::FINDING_OPENED],
    ])->assertSessionHasErrors('target');

    // Refused at the form, not accepted and then quietly failing at send time.
    expect(NotificationDestination::query()->count())->toBe(0);
})->with([
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
    'loopback' => 'https://127.0.0.1/hook',
    'private range' => 'https://10.1.2.3/hook',
    'plain http' => 'http://203.0.114.1/hook',
]);

it('refuses an unknown event type', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Everything',
        'target' => 'ops@example.org',
        'events' => ['site.database_password'],
    ])->assertSessionHasErrors('events.0');

    expect(NotificationDestination::query()->count())->toBe(0);
});

it('requires at least one event', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Silent',
        'target' => 'ops@example.org',
    ])->assertSessionHasErrors('events');
});

it('records the target but never the signing secret in the audit log', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'webhook',
        'label' => 'Incident channel',
        'target' => PUBLIC_HOOK,
        'events' => [NotificationEvent::FINDING_OPENED],
    ]);

    $event = AuditEvent::query()->where('action', 'notification_destination.added')->sole();
    $secret = (string) NotificationDestination::query()->sole()->signing_secret;

    // Where notifications started going is exactly what an operator needs from the audit log.
    expect($event->after['target'])->toBe(PUBLIC_HOOK)
        ->and(json_encode($event->after))->not->toContain($secret);
});

it('only lets an owner manage destinations', function (): void {
    $this->actingAs($this->member)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Mine now',
        'target' => 'member@example.org',
        'events' => [NotificationEvent::FINDING_OPENED],
    ])->assertForbidden();

    $destination = NotificationDestination::factory()->for($this->organisation)->create();

    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->delete("/settings/notifications/{$destination->external_id}")
        ->assertForbidden();

    expect(NotificationDestination::query()->count())->toBe(1);
});

it('cannot reach another organisation\'s destination', function (): void {
    $other = Organisation::factory()->create();
    $theirs = NotificationDestination::factory()->for($other)->create();

    // 404 rather than 403: whether that identifier exists at all is not this organisation's business.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/settings/notifications/{$theirs->external_id}")
        ->assertNotFound();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/settings/notifications/{$theirs->external_id}/test")
        ->assertNotFound();

    expect(NotificationDestination::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('queues a test delivery regardless of what the destination subscribed to', function (): void {
    Queue::fake();

    $destination = NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::CONNECTOR_REVOKED],
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/settings/notifications/{$destination->external_id}/test")
        ->assertRedirect();

    // Somebody testing a destination wants to know whether it is reachable, not whether it happens to
    // be subscribed to the event the test borrows.
    Queue::assertPushed(DeliverNotification::class);
});

it('delivers a test even though its event was not subscribed to', function (): void {
    $destination = NotificationDestination::factory()->for($this->organisation)->create([
        'transport' => NotificationDestination::TRANSPORT_EMAIL,
        'target' => 'ops@example.org',
        'events' => [NotificationEvent::CONNECTOR_REVOKED],
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/settings/notifications/{$destination->external_id}/test");

    $delivery = NotificationDelivery::query()->sole();

    expect($delivery->succeeded())->toBeTrue()
        ->and($delivery->notification_destination_id)->toBe($destination->id);
});

it('still drops an unsubscribed event that is not a test', function (): void {
    $destination = NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::CONNECTOR_REVOKED],
    ]);

    dispatch_sync(new DeliverNotification($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Not asked for',
        summary: 'This destination did not subscribe to findings.',
    )));

    // The test-delivery exemption must not become a way for any event to bypass subscriptions.
    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('removes a destination and says so in the audit log', function (): void {
    $destination = NotificationDestination::factory()->for($this->organisation)->create([
        'label' => 'Old channel',
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/settings/notifications/{$destination->external_id}")
        ->assertRedirect();

    expect(NotificationDestination::query()->count())->toBe(0);

    $event = AuditEvent::query()->where('action', 'notification_destination.removed')->sole();

    expect($event->target_id)->toBe($destination->external_id)
        ->and($event->before['label'])->toBe('Old channel');
});

it('needs recent authentication to add a destination', function (): void {
    // A stolen session alone should not be enough to start sending findings somewhere new.
    $this->actingAs($this->owner)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Opportunistic',
        'target' => 'attacker@example.org',
        'events' => [NotificationEvent::FINDING_OPENED],
    ])->assertRedirect(route('password.confirm'));

    expect(NotificationDestination::query()->count())->toBe(0);
});

it('shows a destination that has failed too often as stopped', function (): void {
    NotificationDestination::factory()->for($this->organisation)->create([
        'label' => 'Broken channel',
        'consecutive_failures' => NotificationDestination::FAILURE_LIMIT,
    ]);

    $this->actingAs($this->owner)->get('/settings/notifications')
        ->assertOk()
        ->assertSee('Stopped after repeated failures');
});

it('notifies when every connector in the organisation is revoked at once', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();

    NotificationDestination::factory()->for($this->organisation)->create([
        'transport' => NotificationDestination::TRANSPORT_EMAIL,
        'target' => 'ops@example.org',
        'events' => [NotificationEvent::CONNECTOR_REVOKED],
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/connectors/rotate', ['confirm_organisation' => 'Coysh Digital'])
        ->assertRedirect();

    // Silencing the whole fleet is exactly the action a compromised account would take, and the
    // notification is the part of it the attacker cannot suppress from inside the interface.
    $delivery = NotificationDelivery::query()->sole();

    expect($delivery->event)->toBe(NotificationEvent::CONNECTOR_REVOKED)
        ->and($delivery->succeeded())->toBeTrue();
});

it('does not announce a rotation that revoked nothing', function (): void {
    NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::CONNECTOR_REVOKED],
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/connectors/rotate', ['confirm_organisation' => 'Coysh Digital'])
        ->assertRedirect();

    expect(NotificationDelivery::query()->count())->toBe(0);
});
