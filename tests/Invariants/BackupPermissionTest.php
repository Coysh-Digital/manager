<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Capability\UnknownCapabilityException;
use App\Models\AuditEvent;
use App\Models\CapabilityEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Protocol;

/**
 * Invariant 7: backup access must require explicit permission.
 *
 * "Explicit" is doing real work in that sentence, and this file is where it is pinned down. A
 * checkbox among the read-only switches would satisfy a careless reading of the invariant and miss
 * the point entirely: granting this authorises a copy of every user record, password hash and session
 * on a production website.
 *
 * So the tests here assert four separate things, because a single one of them failing would be enough
 * to make the permission accidental rather than explicit - it cannot arrive at pairing, it cannot
 * arrive through the ordinary grant path, it cannot arrive without an acknowledgement and the site's
 * name, and it cannot arrive without leaving a record of who did it and what they were told.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->grantBackups = fn (array $overrides = []) => $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post("/sites/{$this->site->external_id}/capabilities/grant-confirmed", array_merge([
            'capability' => 'backups:create',
            'confirm_site' => 'Example Site',
            'acknowledge' => '1',
            'reason' => 'Nightly backups before the platform migration',
        ], $overrides));
});

// --------------------------------------------------------------------------------------------------
// It cannot arrive by default
// --------------------------------------------------------------------------------------------------

it('is never granted when a site is paired', function (): void {
    $fresh = Site::factory()->for($this->organisation)->create();

    app(CapabilityService::class)->grantDefaultsForPairing($fresh);

    expect($fresh->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('is not auto-grantable in the protocol at all', function (): void {
    // Asserted at the protocol level as well as the service level. The two are independent lists, and
    // an invariant that holds in only one of them holds by coincidence.
    expect(Protocol::autoGrantableCapabilities())->not->toContain('backups:create')
        ->and(Protocol::isReadOnlyCapability('backups:create'))->toBeFalse()
        ->and(CapabilityService::pairingDefaults())->not->toContain('backups:create');
});

it('is never offered as an ordinary switch', function (): void {
    expect(CapabilityService::grantableFromInterface())->not->toContain('backups:create')
        ->and(CapabilityService::confirmable())->toBe(['backups:create']);

    // The two lists must not overlap, or a capability could be reached by whichever path validates
    // less.
    expect(array_intersect(
        CapabilityService::grantableFromInterface(),
        CapabilityService::confirmable(),
    ))->toBe([]);
});

// --------------------------------------------------------------------------------------------------
// It cannot arrive through the ordinary path
// --------------------------------------------------------------------------------------------------

it('refuses to grant through the read-only service method', function (): void {
    expect(fn () => app(CapabilityService::class)->grant($this->site, 'backups:create', $this->owner))
        ->toThrow(UnknownCapabilityException::class);

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('refuses to grant a read-only capability through the confirmation method', function (): void {
    // The mirror of the test above, and the reason there are two methods rather than one with a flag.
    // Neither path will accept the other's capabilities, so a bug in a route cannot turn a read-only
    // grant into a backup permission.
    expect(fn () => app(CapabilityService::class)
        ->grantConfirmed($this->site, 'inventory:read', $this->owner, 'because'))
        ->toThrow(UnknownCapabilityException::class);
});

it('refuses to grant through the ordinary route', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/sites/{$this->site->external_id}/capabilities/grant", [
            'capability' => 'backups:create',
        ])
        ->assertSessionHasErrors('capability');

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

// --------------------------------------------------------------------------------------------------
// It cannot arrive without confirmation
// --------------------------------------------------------------------------------------------------

it('refuses without the acknowledgement', function (): void {
    ($this->grantBackups)(['acknowledge' => '0'])->assertSessionHasErrors('acknowledge');

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('refuses without the site name typed correctly', function (string $typed): void {
    ($this->grantBackups)(['confirm_site' => $typed])->assertSessionHasErrors('confirm_site');

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
})->with([
    'another site' => 'Different Site',
    'partial' => 'Example',
    'empty-ish' => ' ',
]);

it('refuses without a reason', function (): void {
    ($this->grantBackups)(['reason' => ''])->assertSessionHasErrors('reason');
});

it('refuses without recent authentication', function (): void {
    // A session left open on an unlocked machine must not be enough. This is the same gate the rest of
    // the sensitive actions sit behind, asserted here because this is the most sensitive of them.
    $this->actingAs($this->owner)
        ->post("/sites/{$this->site->external_id}/capabilities/grant-confirmed", [
            'capability' => 'backups:create',
            'confirm_site' => 'Example Site',
            'acknowledge' => '1',
            'reason' => 'Nightly backups',
        ])
        ->assertRedirect(route('password.confirm'));

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('refuses a member who is not an administrator', function (): void {
    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->post("/sites/{$this->site->external_id}/capabilities/grant-confirmed", [
            'capability' => 'backups:create',
            'confirm_site' => 'Example Site',
            'acknowledge' => '1',
            'reason' => 'Nightly backups',
        ])
        ->assertForbidden();

    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('refuses to reach another organisation\'s site', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create(['name' => 'Their Site']);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/sites/{$theirs->external_id}/capabilities/grant-confirmed", [
            'capability' => 'backups:create',
            'confirm_site' => 'Their Site',
            'acknowledge' => '1',
            'reason' => 'Nightly backups',
        ])
        ->assertNotFound();

    expect($theirs->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

// --------------------------------------------------------------------------------------------------
// When it does arrive, it is on the record
// --------------------------------------------------------------------------------------------------

it('grants when every condition is met', function (): void {
    ($this->grantBackups)()->assertRedirect();

    expect($this->site->fresh()->grantedCapabilities())->toContain('backups:create');
});

it('records the acknowledgement verbatim in the audit log', function (): void {
    ($this->grantBackups)();

    $event = AuditEvent::query()
        ->where('action', 'capability.granted')
        ->where('target_id', 'backups:create')
        ->sole();

    // The wording, not a boolean. A log entry saying "acknowledged: true" cannot answer what somebody
    // was actually told when they agreed.
    expect($event->after['acknowledged'])->toBe(CapabilityService::acknowledgementFor('backups:create'))
        ->and($event->after['acknowledged'])->toContain('password hashes')
        ->and($event->after['reason'])->toBe('Nightly backups before the platform migration')
        ->and($event->actor_id)->toBe($this->owner->id);
});

it('records who granted it and why in the capability history', function (): void {
    ($this->grantBackups)();

    $event = CapabilityEvent::query()->where('capability', 'backups:create')->sole();

    expect($event->new_state)->toBe(CapabilityGrant::STATE_GRANTED)
        ->and($event->previous_state)->toBeNull()
        ->and($event->actor_id)->toBe($this->owner->id)
        ->and($event->actor_label)->toBe('Tim Coysh')
        ->and($event->reason)->toBe('Nightly backups before the platform migration');
});

it('can be revoked like anything else', function (): void {
    ($this->grantBackups)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/sites/{$this->site->external_id}/capabilities/revoke", [
            'capability' => 'backups:create',
            'reason' => 'Migration finished',
        ])
        ->assertRedirect();

    // Revocation must never need the confirmation the grant did. Making it harder to withdraw a
    // permission than to give it would be exactly the wrong way round.
    expect($this->site->fresh()->grantedCapabilities())->not->toContain('backups:create');
});

it('states plainly what granting it means, next to the switch', function (): void {
    $html = $this->actingAs($this->owner)
        ->get("/sites/{$this->site->external_id}/settings")
        ->assertOk()
        ->getContent();

    // The acknowledgement is on the screen where the decision is made, not in documentation somebody
    // reads afterwards.
    expect($html)->toContain('password hashes')
        ->and($html)->toContain('personal information');
});
