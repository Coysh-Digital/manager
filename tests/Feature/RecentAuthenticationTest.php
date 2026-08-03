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
 * did was throw the form away. Somebody filled in "Add a site", pressed the button, was asked for
 * their password, proved it — and arrived back at an empty, collapsed panel with no sign that
 * anything had been typed. The reported behaviour was "I have to add the site again".
 *
 * Nothing is replayed. The input comes back and the person presses the button; see ResumableInput
 * for why that is the design rather than a limitation of it.
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
     | asking it to survive — and by the POST below there was nothing left to restore. Three
     | requests, not two.
    */
    $this->confirm = function () {
        $this->actingAs($this->owner)->get(route('password.confirm'))->assertOk();

        return $this->actingAs($this->owner)
            ->post(route('password.confirm.store'), ['password' => 'correct-horse-battery-staple']);
    };
});

it('gives the add-site form back after a password confirmation', function (): void {
    $this->actingAs($this->owner)
        ->post('/sites', [
            'name' => 'Example Client',
            'expected_domain' => 'example.org',
            'environment' => 'staging',
            'capabilities' => ['inventory:read'],
        ])
        ->assertRedirect(route('password.confirm'));

    // Nothing was created: the gate still refuses the action itself.
    expect(Site::query()->count())->toBe(0);

    ($this->confirm)()->assertRedirect(route('sites.index'));

    $this->actingAs($this->owner)
        ->get('/sites')
        ->assertOk()
        ->assertSee('value="Example Client"', false)
        ->assertSee('value="example.org"', false)
        // The panel is a <details>. Restoring the fields into a collapsed panel would look exactly
        // like losing them.
        ->assertSee('<details class="group relative z-20" open', false);
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
     | window into a three-hour one — a weakened control with nothing on screen to show for it.
    */
    $window = (int) config('auth.password_timeout');

    expect($window)->toBe(15 * 60);

    $this->actingAs($this->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subSeconds($window - 60)->timestamp])
        ->post('/sites', [
            'name' => 'Inside the window',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ])
        // Straight through to the site it created, rather than to the gate.
        ->assertRedirect(route('sites.show', Site::query()->sole()));

    $this->actingAs($this->owner)
        ->withSession(['auth.password_confirmed_at' => now()->subSeconds($window + 60)->timestamp])
        ->post('/sites', [
            'name' => 'Outside the window',
            'expected_domain' => 'other.example',
            'environment' => 'production',
        ])
        ->assertRedirect(route('password.confirm'));

    expect(Site::query()->pluck('name')->all())->toBe(['Inside the window']);
});

it('answers a JSON caller with 423 rather than a redirect', function (): void {
    // The API branch is delegated to the framework untouched: no redirect, no captured input, and
    // nothing for a machine caller to misread as success.
    $this->actingAs($this->owner)
        ->postJson('/sites', [
            'name' => 'Machine',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ])
        ->assertStatus(423);

    expect(session()->has('manager.resumable_input'))->toBeFalse();
});

it('says what was interrupted, rather than that something was', function (): void {
    // "You are about to do something that changes what Manager may do" is true of every
    // interruption and helps with none of them. Reported as frustrating, and the frustration is not
    // the gate — it is not knowing whether what you typed still exists.
    $this->actingAs($this->owner)
        ->post('/sites', ['name' => 'Example Client', 'expected_domain' => 'example.org', 'environment' => 'production'])
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->owner)->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('You were about to')
        ->assertSee('add a site')
        ->assertSee('what you had typed is kept');
});

it('says on arrival that nothing was done, and what to press', function (): void {
    $this->actingAs($this->owner)
        ->post('/sites', ['name' => 'Example Client', 'expected_domain' => 'example.org', 'environment' => 'production'])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)();

    $this->actingAs($this->owner)->get('/sites')
        ->assertOk()
        ->assertSee('nothing was done yet')
        ->assertSee('press the button again to add a site');
});

it('gives back the environment as well, which it used to drop in silence', function (): void {
    /*
     | The worst shape this bug takes. `environment` was missing from the allowlist, and the field
     | falls back to old('environment', $site->environment) — so a change to it came back looking
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

it('gives back a backup schedule it interrupted', function (): void {
    // The schedule moved to the Backups screen and kept its gate, so it had to be given a way back
    // as well — a control that loses what you set it to is one people stop trusting.
    $site = Site::factory()->for($this->organisation)->connected()->create(['backup_schedule' => 'off']);

    $this->actingAs($this->owner)
        ->post(route('sites.backups.schedule', $site), [
            'backup_schedule' => 'weekly',
            'backup_schedule_hour' => 4,
            'backup_schedule_day' => 3,
        ])
        ->assertRedirect(route('password.confirm'));

    ($this->confirm)()->assertRedirect(route('sites.backups', $site));

    expect(session('_old_input.backup_schedule'))->toBe('weekly')
        ->and((int) session('_old_input.backup_schedule_hour'))->toBe(4)
        ->and((int) session('_old_input.backup_schedule_day'))->toBe(3)
        // The gate still refused the act itself. Restoring is not replaying.
        ->and($site->fresh()->backup_schedule)->toBe('off');
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
