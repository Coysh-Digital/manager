<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\SiteNote;
use App\Models\User;

/**
 * The palette, the fleet sort, and site notes.
 *
 * The scoping assertions carry the weight here as usual: the palette hands the browser a list of
 * every site in the organisation, and notes are the first free text this product stores.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Tim Coysh']);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Acme Ltd']);
});

/*
 | Command palette
 |-------------------------------------------------------------------------------------------------
 */

it('offers every screen and every site, with a direct link to each tab', function (): void {
    $payload = $this->actingAs($this->user)->getJson(route('palette'))->assertOk()->json();

    expect($payload['sites'])->toHaveCount(1)
        ->and($payload['sites'][0]['name'])->toBe('Acme Ltd')
        // The whole reason a palette beats a search box: "acme health" goes straight there.
        ->and($payload['sites'][0]['tabs'])->toHaveKeys([
            'overview', 'health', 'updates', 'security', 'backups', 'settings', 'audit',
        ])
        ->and($payload['sites'][0]['tabs']['health'])->toBe(route('sites.health', $this->site));

    expect(collect($payload['screens'])->pluck('name'))->toContain('Findings', 'People');
});

it('never offers another organisation\'s site', function (): void {
    Site::factory()->for(Organisation::factory())->create(['name' => 'Somebody Else']);

    $payload = $this->actingAs($this->user)->getJson(route('palette'))->assertOk()->json();

    // The palette cannot offer a destination the person could not already reach.
    expect(collect($payload['sites'])->pluck('name'))->not->toContain('Somebody Else');
});

it('keeps the palette behind authentication', function (): void {
    // A web route, so a guest is redirected to sign in rather than given a 401.
    $this->get(route('palette'))->assertRedirect(route('login'));
});

it('puts a visible trigger on the page as well as the shortcut', function (): void {
    // A keyboard shortcut nobody is told about is a feature for the person who wrote it - and on a
    // phone there is no shortcut at all.
    $this->actingAs($this->user)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertSee('data-palette-open', escape: false)
        ->assertSee('Search sites and screens');
});

/*
 | Fleet sorting
 |-------------------------------------------------------------------------------------------------
 */

it('sorts the fleet by disk without dissolving the groups', function (): void {
    $roomy = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Roomy']);
    $full = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Full']);

    RuntimeReport::factory()->for($roomy)->create([
        'disk_free_bytes' => 90_000_000_000, 'disk_total_bytes' => 100_000_000_000,
    ]);
    RuntimeReport::factory()->for($full)->create([
        'disk_free_bytes' => 2_000_000_000, 'disk_total_bytes' => 100_000_000_000,
    ]);

    $html = $this->actingAs($this->user)
        ->get(route('sites.index', ['sort' => 'disk']))
        ->assertOk()
        ->assertSee('98%')
        ->assertSee('10%')
        ->getContent();

    // A fleet sorted by disk that buried a silent site under a healthy one would have forgotten what
    // the screen is for, so the grouping survives the sort - these three are all steady, and the
    // heading is still there.
    expect($html)->toContain('Steady')
        ->and(strpos($html, 'Full'))->toBeLessThan(strpos($html, 'Roomy'));
});

it('sinks sites with nothing to sort on rather than treating them as zero', function (): void {
    $known = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Measured']);
    Site::factory()->for($this->organisation)->connected()->create(['name' => 'Unmeasured']);

    RuntimeReport::factory()->for($known)->create([
        'disk_free_bytes' => 50_000_000_000, 'disk_total_bytes' => 100_000_000_000,
    ]);

    $html = $this->actingAs($this->user)
        ->get(route('sites.index', ['sort' => 'disk']))
        ->assertOk()
        ->getContent();

    // A site that has never reported its disk is not the emptiest disk in the fleet.
    expect(strpos($html, 'Measured'))->toBeLessThan(strpos($html, 'Unmeasured'));
});

it('ignores a sort column that is not on the list', function (): void {
    // The value arrives in a query string; ordering by an arbitrary column name from a URL is how a
    // sort control becomes a way to probe the schema.
    $this->actingAs($this->user)
        ->get(route('sites.index', ['sort' => 'password']))
        ->assertOk();
});

it('carries the sort through the filter form', function (): void {
    $this->actingAs($this->user)
        ->get(route('sites.index', ['sort' => 'seen']))
        ->assertOk()
        ->assertSee('<input type="hidden" name="sort" value="seen">', escape: false);
});

/*
 | Site notes
 |-------------------------------------------------------------------------------------------------
 */

it('records a note and audits that one was written, not what it said', function (): void {
    $this->actingAs($this->user)
        ->post(route('sites.notes.store', $this->site), [
            'body' => 'PHP stays on 8.2 until the payment gateway is replaced.',
            'pinned' => '1',
        ])
        ->assertRedirect();

    $note = SiteNote::query()->where('site_id', $this->site->id)->first();

    expect($note?->pinned)->toBeTrue()
        ->and($note?->authorName())->toBe('Tim Coysh');

    // The log is append-only and hash-chained, so anything put in it is permanent and cannot be
    // corrected. Free text somebody typed in a hurry is the last thing that should be.
    $event = AuditEvent::query()->where('action', 'site.note.added')->latest('seq')->first();

    expect($event)->not->toBeNull()
        ->and(json_encode($event->after))->not->toContain('payment gateway')
        ->and($event->after['length'])->toBeGreaterThan(0);
});

it('shows a pinned note above the findings that would contradict it', function (): void {
    SiteNote::factory()->for($this->site)->pinned()->create([
        'body' => 'Dev mode is on deliberately, this is a staging clone.',
    ]);

    $html = $this->actingAs($this->user)
        ->get(route('sites.show', $this->site))
        ->assertOk()
        ->assertSee('staging clone')
        ->getContent();

    // Above "What this site is running": a caveat somebody must read before acting is no use
    // underneath the thing it is a caveat about.
    expect(strpos($html, 'staging clone'))->toBeLessThan(strpos($html, 'What this site is running'));
});

it('lets any member write a note but only the author or an owner delete one', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    // Writing changes nothing about the site, and the value comes from it being easy enough that
    // people actually do it.
    $this->actingAs($member)
        ->post(route('sites.notes.store', $this->site), ['body' => 'Client asked us not to touch the theme.'])
        ->assertRedirect();

    $mine = SiteNote::query()->where('site_id', $this->site->id)->first();

    $someoneElse = SiteNote::factory()->for($this->site)->create([
        'author_id' => $this->user->id,
        'author_label' => 'Tim Coysh',
    ]);

    // Their own: fine.
    $this->actingAs($member)->delete(route('sites.notes.destroy', [$this->site, $mine]))->assertRedirect();

    // Somebody else's, as a plain member: refused. Quietly removable institutional memory is worse
    // than none.
    $this->actingAs($member)
        ->delete(route('sites.notes.destroy', [$this->site, $someoneElse]))
        ->assertForbidden();

    expect(SiteNote::query()->whereKey($someoneElse->id)->exists())->toBeTrue();
});

it('refuses a note belonging to a different site', function (): void {
    $other = Site::factory()->for($this->organisation)->create();
    $note = SiteNote::factory()->for($other)->create();

    // Route binding resolves the note on its own identifier, so without the check a note from one
    // site could be acted on through another site's URL.
    $this->actingAs($this->user)
        ->post(route('sites.notes.pin', [$this->site, $note]))
        ->assertNotFound();
});

it('bounds a note rather than accepting a document', function (): void {
    $this->actingAs($this->user)
        ->post(route('sites.notes.store', $this->site), [
            'body' => str_repeat('x', SiteNote::MAX_LENGTH + 1),
        ])
        ->assertSessionHasErrors('body');
});

it('keeps notes out of another organisation\'s reach', function (): void {
    $elsewhere = Site::factory()->for(Organisation::factory())->create();

    $this->actingAs($this->user)
        ->post(route('sites.notes.store', $elsewhere), ['body' => 'Should not land.'])
        ->assertNotFound();

    expect(SiteNote::query()->where('site_id', $elsewhere->id)->exists())->toBeFalse();
});
