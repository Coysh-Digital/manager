<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationEvent;
use App\Jobs\DeliverNotification;
use App\Mail\AlertMail;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * How much one destination can be sent in a day.
 *
 * What matters is what the limit does not do: it must not read as a fault, count against the
 * destination, or swallow a test - and it must be explained where somebody would look.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->destination = NotificationDestination::factory()->for($this->organisation)->create([
        'events' => [NotificationEvent::FINDING_OPENED],
        'daily_limit' => 2,
    ]);
});

function deliverFinding(NotificationDestination $destination, bool $test = false): void
{
    dispatch_sync(new DeliverNotification($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Something is wrong',
        summary: 'A finding.',
        context: $test ? ['test' => true] : [],
    )));
}

it('sends up to the limit and records the rest as suppressed', function (): void {
    foreach (range(1, 4) as $_) {
        deliverFinding($this->destination);
    }

    Mail::assertSent(AlertMail::class, 2);

    expect(NotificationDelivery::query()->where('outcome', NotificationDelivery::OUTCOME_SENT)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('outcome', NotificationDelivery::OUTCOME_SUPPRESSED)->count())->toBe(2)
        ->and(NotificationDelivery::query()->where('outcome', NotificationDelivery::OUTCOME_SUPPRESSED)->first()->failure_reason)
        ->toBe('Daily limit of 2 reached');
});

it('does not count being at the limit as a failure', function (): void {
    foreach (range(1, 5) as $_) {
        deliverFinding($this->destination);
    }

    // A busy destination must not be disabled for being busy, which is what ten consecutive
    // failures would do to it.
    $destination = $this->destination->fresh();

    expect($destination->consecutive_failures)->toBe(0)
        ->and($destination->isDeliverable())->toBeTrue();
});

it('starts counting again once the oldest deliveries are more than a day old', function (): void {
    deliverFinding($this->destination);
    deliverFinding($this->destination);
    deliverFinding($this->destination);

    NotificationDelivery::query()
        ->where('outcome', NotificationDelivery::OUTCOME_SENT)
        ->update(['created_at' => now()->subHours(25)]);

    deliverFinding($this->destination);

    Mail::assertSent(AlertMail::class, 3);
});

it('does not count failed attempts towards the limit', function (): void {
    NotificationDelivery::factory()->count(5)->create([
        'notification_destination_id' => $this->destination->id,
        'outcome' => NotificationDelivery::OUTCOME_FAILED,
        'created_at' => now(),
    ]);

    // Nothing was delivered by those, so none of the allowance was used.
    expect($this->destination->sentInLastDay())->toBe(0);

    deliverFinding($this->destination);

    Mail::assertSent(AlertMail::class, 1);
});

it('applies no limit when the destination says zero', function (): void {
    $this->destination->forceFill(['daily_limit' => 0])->save();
    config(['manager.notifications.daily_limit' => 1]);

    foreach (range(1, 5) as $_) {
        deliverFinding($this->destination);
    }

    // Zero is a decision, and the installation default must not quietly apply to it again.
    Mail::assertSent(AlertMail::class, 5);
});

it('falls back to the installation default when the destination has none', function (): void {
    $this->destination->forceFill(['daily_limit' => null])->save();
    config(['manager.notifications.daily_limit' => 1]);

    deliverFinding($this->destination);
    deliverFinding($this->destination);

    Mail::assertSent(AlertMail::class, 1);
});

it('applies no limit when neither the destination nor the installation sets one', function (): void {
    $this->destination->forceFill(['daily_limit' => null])->save();
    config(['manager.notifications.daily_limit' => 0]);

    expect($this->destination->effectiveDailyLimit())->toBeNull();
});

it('still sends a test when the destination is at its limit', function (): void {
    deliverFinding($this->destination);
    deliverFinding($this->destination);
    deliverFinding($this->destination, test: true);

    // Somebody testing a destination wants to know whether it is reachable, not whether it has
    // already been busy today.
    Mail::assertSent(AlertMail::class, 3);
});

it('shows a suppressed delivery as not sent rather than as a fault', function (): void {
    foreach (range(1, 3) as $_) {
        deliverFinding($this->destination);
    }

    $this->actingAs($this->owner)->get('/settings/notifications')
        ->assertOk()
        ->assertSee('not sent - Daily limit of 2 reached')
        ->assertSee('Up to 2 a day');
});

it('says so when a destination has no limit', function (): void {
    $this->destination->forceFill(['daily_limit' => 0])->save();

    $this->actingAs($this->owner)->get('/settings/notifications')
        ->assertOk()
        ->assertSee('No daily limit');
});

it('sets a limit when a destination is added', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Another mailbox',
        'target' => 'other@example.org',
        'events' => [NotificationEvent::FINDING_OPENED],
        'daily_limit' => '7',
    ])->assertRedirect();

    expect(NotificationDestination::query()->where('label', 'Another mailbox')->sole()->daily_limit)->toBe(7);
});

it('stores a blank limit as the installation default rather than as zero', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/settings/notifications', [
        'transport' => 'email',
        'label' => 'Another mailbox',
        'target' => 'other@example.org',
        'events' => [NotificationEvent::FINDING_OPENED],
        'daily_limit' => '',
    ])->assertRedirect();

    expect(NotificationDestination::query()->where('label', 'Another mailbox')->sole()->daily_limit)->toBeNull();
});

it('changes the limit on an existing destination and audits it', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->patch("/settings/notifications/{$this->destination->external_id}", ['daily_limit' => '9'])
        ->assertRedirect();

    expect($this->destination->fresh()->daily_limit)->toBe(9);

    $this->assertDatabaseHas('audit_events', ['action' => 'notification_destination.updated']);
});

it('refuses a limit that is negative, fractional or absurd', function (string $value): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->patch("/settings/notifications/{$this->destination->external_id}", ['daily_limit' => $value])
        ->assertSessionHasErrors('daily_limit');

    expect($this->destination->fresh()->daily_limit)->toBe(2);
})->with(['-1', '1.5', '5000', 'lots']);

it('lets only an owner change a limit', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    $this->actingAs($member)->withSession($this->recentAuth)
        ->patch("/settings/notifications/{$this->destination->external_id}", ['daily_limit' => '9'])
        ->assertForbidden();

    expect($this->destination->fresh()->daily_limit)->toBe(2);
});

it('will not change another organisation\'s destination', function (): void {
    $other = NotificationDestination::factory()->for(Organisation::factory()->create())->create(['daily_limit' => 3]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->patch("/settings/notifications/{$other->external_id}", ['daily_limit' => '9'])
        ->assertNotFound();

    expect($other->fresh()->daily_limit)->toBe(3);
});
