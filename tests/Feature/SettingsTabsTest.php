<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;

/*
 | Settings as several screens rather than one.
 |
 | It was a 762-line page with `#people` and `#recovery-keys` anchors doing the work of navigation,
 | beside a second destination called "Account and security". Every visit ran every query on it,
 | whichever section somebody had come for.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->admin()->create();
});

it('renders every tab', function (string $route): void {
    $this->actingAs($this->owner)->get(route($route))->assertOk()->assertSee('Settings sections', false);
})->with([
    'settings.show',
    'settings.account',
    'settings.security',
    'settings.people',
    'settings.notifications',
    'settings.emails',
    'settings.recovery-keys',
]);

it('marks exactly one tab as the current screen', function (string $route): void {
    // Two would mean a route pattern in <x-settings-tabs> matches more than one tab, which reads as
    // the navigation being broken rather than as an overlap.
    //
    // Counted within the tab strip alone. The breadcrumb's last segment carries the same attribute,
    // correctly, and counting the whole document would be counting two different navigations.
    $html = (string) $this->actingAs($this->owner)->get(route($route))->assertOk()->getContent();

    $start = strpos($html, '<nav aria-label="Settings sections"');
    expect($start)->not->toBeFalse();

    $strip = substr($html, $start, strpos($html, '</nav>', $start) - $start);

    expect(substr_count($strip, 'aria-current="page"'))->toBe(1);
})->with([
    'settings.show',
    'settings.account',
    'settings.security',
    'settings.people',
    'settings.notifications',
    'settings.emails',
    'settings.recovery-keys',
]);

it('has no account page any more', function (): void {
    // Moved rather than redirected. A redirect would keep the second settings destination alive, and
    // with it the idea that the account is somewhere else.
    $this->actingAs($this->owner)->get('/account')->assertNotFound();
});

it('lights the Settings entry in the sidebar from every tab', function (string $route): void {
    /*
     | The entry used to match on settings.* alone, so the screens whose routes are named for what
     | they act on - team.*, recovery-keys.*, notifications.*, account.*, passkeys.* - never lit it.
     | Mostly invisible, because those are POSTs that redirect; visible at the confirm-password
     | interstitial, which is exactly when somebody wants to know where they still are.
     */
    $html = (string) $this->actingAs($this->owner)->get(route($route))->assertOk()->getContent();

    expect($html)->toContain('bg-pale text-primary');
})->with(['settings.show', 'settings.account', 'settings.security', 'settings.people']);

it('loads only what the tab it is on shows', function (): void {
    // The reason for the split. General used to carry the member list, the destinations and the keys
    // whether or not anybody had come for them.
    $general = (string) $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    expect($general)->not->toContain('Invite somebody')
        ->and($general)->not->toContain('Register key')
        ->and($general)->not->toContain('Add destination');
});

it('keeps the settings screens readable by a member who cannot change them', function (string $route): void {
    // What each of these was as a section of the one screen: readable by any member, with every
    // control inside gated on ownership. Moving a section to a route is not a reason to change that.
    $this->actingAs($this->member)->get(route($route))->assertOk();
})->with(['settings.show', 'settings.people', 'settings.notifications', 'settings.recovery-keys', 'settings.emails']);

it('sends somebody who has not enrolled a second factor to the screen that enrols one', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();

    $this->actingAs($this->member)
        ->get(route('settings.people'))
        ->assertRedirect(route('settings.security'));

    // And says so on arrival, rather than leaving them to discover it one tab at a time.
    $this->actingAs($this->member)
        ->get(route('settings.security'))
        ->assertOk()
        ->assertSee('the other tabs will send you back here');
});

/*
|--------------------------------------------------------------------------------------------------
| The Mail tab
|--------------------------------------------------------------------------------------------------
|
| Two gates, and the tab is offered only when both pass. See App\Contracts\MailAdministration.
*/

it('offers the Mail tab to an owner of an installation that holds its own relay', function (): void {
    $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->assertSee('settings/mail', false);
});

it('offers no Mail tab to somebody who is not an owner', function (): void {
    // The screen holds a relay's host and login, and whoever administers sites is not necessarily
    // whoever holds those.
    $this->actingAs($this->member)->get(route('settings.show'))->assertOk()->assertDontSee('settings/mail', false);

    // And the route refuses rather than merely not being linked to.
    $this->actingAs($this->member)->get(route('settings.mail'))->assertForbidden();
});
