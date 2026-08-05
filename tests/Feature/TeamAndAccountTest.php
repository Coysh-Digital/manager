<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\TeamInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Access to the installation itself, and to one's own account.
 *
 * Both are new: before this an installation was either one account or a shell command away from a
 * second, and the only ways to change a password were the forgotten-password email or the console.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-horse-battery']);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->confirmed = ['auth.password_confirmed_at' => now()->timestamp];
});

/*
 | Password
 |-------------------------------------------------------------------------------------------------
 */

it('changes a password and ends every other session', function (): void {
    DB::table('sessions')->insert([
        ['id' => 'other-session', 'user_id' => $this->owner->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('account.password'), [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-much-longer-passphrase-1',
            'password_confirmation' => 'a-much-longer-passphrase-1',
        ])
        ->assertRedirect();

    expect(Hash::check('a-much-longer-passphrase-1', $this->owner->fresh()->password))->toBeTrue()
        // If the change is happening because something felt wrong, leaving the others alive undoes it.
        ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse();

    expect(AuditEvent::query()->where('action', 'user.password.changed')->exists())->toBeTrue();
});

it('refuses a password change without the current password', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('account.password'), [
            'current_password' => 'not-it',
            'password' => 'a-much-longer-passphrase-1',
            'password_confirmation' => 'a-much-longer-passphrase-1',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('correct-horse-battery', $this->owner->fresh()->password))->toBeTrue();

    // Somebody guessing from inside a live session is what an audit log is for.
    $event = AuditEvent::query()->where('action', 'user.password.changed')->latest('seq')->first();
    expect($event?->outcome)->toBe(AuditEvent::OUTCOME_FAILURE);
});

it('applies the same strength rule as a reset', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('account.password'), [
            'current_password' => 'correct-horse-battery',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors('password');
});

it('will not change a password from a stale session', function (): void {
    $this->actingAs($this->owner)
        ->post(route('account.password'), [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-much-longer-passphrase-1',
            'password_confirmation' => 'a-much-longer-passphrase-1',
        ])
        ->assertRedirect(route('password.confirm'));
});

/*
 | People
 |-------------------------------------------------------------------------------------------------
 */

it('invites somebody without ever handling their password', function (): void {
    Notification::fake();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('team.invite'), [
            'name' => 'Sam Reader',
            'email' => 'Sam@Example.ORG',
            'role' => Membership::ROLE_MEMBER,
        ])
        ->assertRedirect();

    $invited = User::query()->where('email', 'sam@example.org')->first();

    expect($invited)->not->toBeNull()
        ->and($invited->memberships()->where('organisation_id', $this->organisation->id)->value('role'))
        ->toBe(Membership::ROLE_MEMBER);

    /*
     | They set their own, through the ordinary single-use expiring link.
     |
     | The token still comes from the framework's password broker, which is the part that was always
     | right. What changed is the message carrying it: this used to send ResetPassword, so somebody
     | who had never had an account was told "we received a password reset request for your account"
     | by a product they had never used. The correct response to that email is to delete it as
     | phishing.
     */
    Notification::assertSentTo($invited, TeamInvitation::class);
    Notification::assertNotSentTo($invited, ResetPassword::class);

    expect(AuditEvent::query()->where('action', 'member.invited')->exists())->toBeTrue();
});

it('reinstates a revoked member rather than creating a second membership', function (): void {
    Notification::fake();

    $returning = User::factory()->create(['email' => 'back@example.org']);
    Membership::factory()->for($returning)->for($this->organisation)->create(['revoked_at' => now()]);

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('team.invite'), [
            'name' => 'Back Again',
            'email' => 'back@example.org',
            'role' => Membership::ROLE_ADMIN,
        ])
        ->assertRedirect();

    $memberships = Membership::query()
        ->where('organisation_id', $this->organisation->id)
        ->where('user_id', $returning->id)
        ->get();

    // Two rows for one person in one organisation is a state every access check would need an
    // opinion about.
    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->revoked_at)->toBeNull()
        ->and($memberships->first()->role)->toBe(Membership::ROLE_ADMIN);
});

it('refuses to invite an address that already has access', function (): void {
    Notification::fake();

    $existing = User::factory()->create(['email' => 'here@example.org']);
    Membership::factory()->for($existing)->for($this->organisation)->create();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('team.invite'), [
            'name' => 'Already Here',
            'email' => 'here@example.org',
            'role' => Membership::ROLE_ADMIN,
        ])
        ->assertSessionHasErrors('email');
});

it('revokes access and signs the person out immediately', function (): void {
    $member = User::factory()->create();
    $membership = Membership::factory()->for($member)->for($this->organisation)->create();

    DB::table('sessions')->insert([
        ['id' => 'their-session', 'user_id' => $member->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('team.revoke', $membership))
        ->assertRedirect();

    expect($membership->fresh()->revoked_at)->not->toBeNull()
        // "Immediate removal of access" has to mean immediate.
        ->and(DB::table('sessions')->where('id', 'their-session')->exists())->toBeFalse();
});

it('will not leave the installation without an owner', function (): void {
    $onlyOwner = Membership::query()
        ->where('organisation_id', $this->organisation->id)
        ->where('user_id', $this->owner->id)
        ->first();

    // Demoting the last owner locks the installation: nobody left who can grant access, add a site
    // or invite anybody.
    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('team.role', $onlyOwner), ['role' => Membership::ROLE_MEMBER])
        ->assertSessionHasErrors('role');

    expect($onlyOwner->fresh()->role)->toBe(Membership::ROLE_OWNER);
});

it('will not let somebody revoke their own access', function (): void {
    $second = User::factory()->create();
    Membership::factory()->for($second)->for($this->organisation)->owner()->create();

    $mine = Membership::query()
        ->where('organisation_id', $this->organisation->id)
        ->where('user_id', $this->owner->id)
        ->first();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('team.revoke', $mine))
        ->assertSessionHasErrors('membership');

    expect($mine->fresh()->revoked_at)->toBeNull();
});

it('keeps people management to owners', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    // An administrator manages sites. Deciding who can reach the control plane at all is a level up.
    $this->actingAs($admin)
        ->withSession($this->confirmed)
        ->post(route('team.invite'), [
            'name' => 'Nope',
            'email' => 'nope@example.org',
            'role' => Membership::ROLE_MEMBER,
        ])
        ->assertForbidden();

    expect(User::query()->where('email', 'nope@example.org')->exists())->toBeFalse();
});

it('refuses a membership belonging to another organisation', function (): void {
    $elsewhere = Membership::factory()->for(Organisation::factory())->for(User::factory())->create();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->post(route('team.role', $elsewhere), ['role' => Membership::ROLE_MEMBER])
        ->assertNotFound();
});

/*
 | Your own details
 |-------------------------------------------------------------------------------------------------
 |
 | The name appears beside every audit event the account produces, so a stale one makes the log harder
 | to read for exactly the person trying to read it. Until now the only way to change it was to edit
 | the database.
 */

it('lets somebody change the name their account is shown under', function (): void {
    $this->actingAs($this->owner)->withSession($this->confirmed)
        ->post(route('account.profile'), ['name' => 'Tim Coysh'])
        ->assertRedirect();

    expect($this->owner->fresh()->name)->toBe('Tim Coysh');

    $event = AuditEvent::query()->where('action', 'user.profile.updated')->sole();

    expect($event->after['name'])->toBe('Tim Coysh')
        ->and($event->actor_id)->toBe($this->owner->id);
});

it('records nothing when the name did not change', function (): void {
    $this->actingAs($this->owner)->withSession($this->confirmed)
        ->post(route('account.profile'), ['name' => $this->owner->name])
        ->assertRedirect()
        ->assertSessionHas('status', 'Nothing to change.');

    expect(AuditEvent::query()->where('action', 'user.profile.updated')->exists())->toBeFalse();
});

it('will not change an email address', function (): void {
    /*
     | Not an oversight. The address identifies the account for sign-in, password resets, invitations
     | and every audit row already written, so changing it is an account-recovery flow - proving the
     | new address, handling the window where neither is confirmed - and none of that exists. A field
     | that quietly moved sign-in to an unverified address would be worse than no field.
    */
    $was = $this->owner->email;

    $this->actingAs($this->owner)->withSession($this->confirmed)
        ->post(route('account.profile'), ['name' => 'Tim Coysh', 'email' => 'someone-else@example.org'])
        ->assertRedirect();

    expect($this->owner->fresh()->email)->toBe($was);

    // And the screen says why rather than leaving somebody hunting for the field.
    $this->actingAs($this->owner)
        ->get(route('settings.account'))
        ->assertOk()
        ->assertSee('cannot be changed here');
});

it('refuses an empty name', function (): void {
    $this->actingAs($this->owner)->withSession($this->confirmed)
        ->post(route('account.profile'), ['name' => '  '])
        ->assertSessionHasErrors('name');
});

it('needs recent authentication to change your details', function (): void {
    $this->actingAs($this->owner)
        ->post(route('account.profile'), ['name' => 'Tim Coysh'])
        ->assertRedirect(route('password.confirm'));
});
