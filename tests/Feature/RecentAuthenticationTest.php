<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\ResumableInput;
use Illuminate\Support\Facades\Hash;

/**
 * The recent-authentication gate, and what survives it.
 *
 * The gate itself was never the problem: it fires when it should and refuses what it should. What it
 * did was throw the form away. Somebody filled a form in, pressed the button, was asked for their
 * password, proved it - and arrived back at a form showing the old values, with no sign that
 * anything had been typed. The reported behaviour was "I have to type it all again".
 *
 * Nothing is replayed. The input comes back and the person presses the button; see ResumableInput
 * for why that is the design rather than a limitation of it.
 *
 * These tests used "Add a site" as their vehicle, because that was the reported case. It is no
 * longer behind the gate at all - the gate was narrowed to actions that change what Manager is
 * rather than actions that use it - so they drive the same mechanism through renaming a site
 * instead. What is under test here is the gate and ResumableInput, not the route; the route is only
 * whatever still goes through them. `tests/Invariants/RecentAuthenticationTest.php` is what pins
 * which routes those are, in both directions.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create([
        'email_verified_at' => now(),
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    /*
     | What a browser actually does: land on the confirm screen, then submit it.
     |
     | This used to skip the GET, and skipping it is what let a broken feature pass. Flash data lives
     | one request, so the payload the gate stashed was read and discarded by the screen nobody was
     | asking it to survive - and by the POST below there was nothing left to restore. Three
     | requests, not two.
    */
    $this->confirm = function () {
        $this->actingAs($this->owner)->get(route('password.confirm'))->assertOk();

        return $this->actingAs($this->owner)
            ->post(route('password.confirm.store'), ['password' => 'correct-horse-battery-staple']);
    };
});

it('gives the form back after a password confirmation', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Example Site',
        'expected_domain' => 'example.org',
    ]);

    $this->actingAs($this->owner)
        ->post(route('sites.settings.update', $site), [
            'name' => 'Renamed',
            'expected_domain' => 'renamed.example',
            'environment' => 'staging',
        ])
        ->assertRedirect(route('password.confirm'));

    // Nothing was changed: the gate still refuses the action itself.
    expect($site->fresh()->name)->toBe('Example Site');

    ($this->confirm)()->assertRedirect(route('sites.settings', $site));

    // What was typed is on the screen, not what was stored. Coming back to the old values is
    // indistinguishable from having lost the new ones, which was the whole complaint.
    $this->actingAs($this->owner)
        ->get(route('sites.settings', $site))
        ->assertOk()
        ->assertSee('value="Renamed"', false)
        ->assertSee('value="renamed.example"', false);
});

it('puts the person back where they were, not on the fleet screen', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);

    // The intended URL Laravel records for a POST is the Referer, and a Referrer-Policy may remove
    // it. The return address is resolved from the route instead, so this lands on the site that was
    // being edited rather than defaulting to the fleet.
    $this->actingAs($this->owner)
        ->post(route('sites.settings.update', $site), [
            'name' => 'Renamed',
            'expected_domain' => 'renamed.example',
        ])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)()->assertRedirect(route('sites.settings', $site));

    expect($site->fresh()->name)->toBe('Example Site');
});

it('honours the configured recent-authentication window', function (): void {
    /*
     | The gate's own duration, pinned.
     |
     | AuthServiceProvider binds RequirePassword *by exact class name*, handing it
     | config('auth.password_timeout'). A subclass is autowired instead, so it never sees that
     | binding and the parent constructor falls back to a hard-coded 10800 seconds. Overriding the
     | alias without reading the config in the constructor would quietly turn a fifteen-minute
     | window into a three-hour one - a weakened control with nothing on screen to show for it.
    */
    $window = (int) config('auth.password_timeout');

    expect($window)->toBe(15 * 60);

    $site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Before']);

    // Straight through, rather than to the gate. The controller answers with back(), so what is
    // asserted is that the gate did not intercept - and then that the change actually landed.
    $this->actingAs($this->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subSeconds($window - 60)->timestamp])
        ->post(route('sites.settings.update', $site), [
            'name' => 'Inside the window',
            'expected_domain' => $site->expected_domain,
            'environment' => $site->environment,
        ])
        ->assertSessionHas('status');

    expect($site->fresh()->name)->toBe('Inside the window');

    $this->actingAs($this->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subSeconds($window + 60)->timestamp])
        ->post(route('sites.settings.update', $site), [
            'name' => 'Outside the window',
            'expected_domain' => $site->expected_domain,
            'environment' => $site->environment,
        ])
        ->assertRedirect(route('password.confirm'));

    expect($site->fresh()->name)->toBe('Inside the window');
});

it('answers a JSON caller with 423 rather than a redirect', function (): void {
    // The API branch is delegated to the framework untouched: no redirect, no captured input, and
    // nothing for a machine caller to misread as success.
    $site = Site::factory()->for($this->organisation)->connected()->create();

    $this->actingAs($this->owner)
        ->postJson(route('sites.settings.update', $site), [
            'name' => 'Machine',
            'expected_domain' => $site->expected_domain,
        ])
        ->assertStatus(423);

    expect(session()->has('manager.resumable_input'))->toBeFalse();
});

it('says what was interrupted, rather than that something was', function (): void {
    // "You are about to do something that changes what Manager may do" is true of every
    // interruption and helps with none of them. Reported as frustrating, and the frustration is not
    // the gate - it is not knowing whether what you typed still exists.
    $site = Site::factory()->for($this->organisation)->connected()->create();

    $this->actingAs($this->owner)
        ->post(route('sites.settings.update', $site), ['name' => 'Renamed', 'expected_domain' => $site->expected_domain])
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->owner)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('You were about to')
        ->assertSee("change a site's details")
        ->assertSee('what you had typed is kept');
});

it('says on arrival that nothing was done, and what to press', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();

    $this->actingAs($this->owner)
        ->post(route('sites.settings.update', $site), ['name' => 'Renamed', 'expected_domain' => $site->expected_domain])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)();

    $this->actingAs($this->owner)->get(route('sites.settings', $site))
        ->assertOk()
        ->assertSee('nothing was done yet')
        ->assertSee("press the button again to change a site's details");
});

it('gives back the environment as well, which it used to drop in silence', function (): void {
    /*
     | The worst shape this bug takes. `environment` was missing from the allowlist, and the field
     | falls back to old('environment', $site->environment) - so a change to it came back looking
     | exactly like the value already saved. Somebody set staging, proved their password, and was
     | shown production with nothing to suggest anything had been lost.
    */
    $site = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Example Site',
        'environment' => 'production',
    ]);

    $this->actingAs($this->owner)
        ->post(route('sites.settings.update', $site), [
            'name' => 'Example Site',
            'expected_domain' => $site->expected_domain,
            'environment' => 'staging',
        ])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)()->assertRedirect(route('sites.settings', $site));

    $this->actingAs($this->owner)->get(route('sites.settings', $site))
        ->assertOk()
        ->assertSee('value="staging" selected', false);

    expect($site->fresh()->environment)->toBe('production');
});

it('gives back a retention policy it interrupted', function (): void {
    /*
     | This was the backup *schedule*, which sits on the same screen and has since been let out of
     | the gate: setting one is the same act "Back up now" performs, said once for every night.
     | Retention is the control beside it that kept its gate, because shortening retention deletes
     | artifacts - and it is the one that needed this most. Three numbers typed into three boxes is
     | exactly the kind of thing a control loses and people stop trusting.
     */
    $site = Site::factory()->for($this->organisation)->connected()->create();

    $this->actingAs($this->owner)
        ->post(route('sites.backups.retention', $site), [
            'backup_retention_days' => 14,
            'backup_retention_weeks' => 6,
            'backup_retention_months' => 3,
        ])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)()->assertRedirect(route('sites.backups', $site));

    expect((int) session('_old_input.backup_retention_days'))->toBe(14)
        ->and((int) session('_old_input.backup_retention_weeks'))->toBe(6)
        ->and((int) session('_old_input.backup_retention_months'))->toBe(3)
        // The gate still refused the act itself. Restoring is not replaying.
        ->and($site->fresh()->backup_retention_days)->not->toBe(14);
});

it('still refuses to carry a typed confirmation across the gate', function (): void {
    // The one thing that must not become easier. sites.destroy asks for the domain precisely so that
    // somebody types it at the moment they do it; handing it back would remove the only thing it is
    // for. Same for the password field on this screen.
    expect(ResumableInput::resumableRoutes())
        ->not->toContain('sites.destroy')
        ->not->toContain('capabilities.grant-confirmed')
        ->not->toContain('settings.connectors.rotate')
        ->not->toContain('recovery-keys.prove');
});
