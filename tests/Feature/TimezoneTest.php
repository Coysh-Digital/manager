<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\ViewerTimezone;
use Illuminate\Support\Carbon;

/**
 * What time it says, and to whom.
 *
 * Every absolute time on every screen was printed in the server's zone, which is UTC unless an
 * operator changed it.
 *
 * The first version of this fell back to the organisation's zone for anybody who had not chosen
 * their own. That was wrong twice over, and reported as such: the organisation zone was never
 * presented as a setting anybody would recognise — it lived inside a block about backup retention —
 * so a time rendered in it was a time in a zone the reader had never chosen and could not find. And
 * "where your sites are" is not one place, which is why scheduling reads the *site's* zone now and
 * the organisation column is gone.
 *
 * Two answers, then: the reader's, or the application's.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'timezone' => 'Europe/London',
    ]);
    Connector::factory()->for($this->site)->create();

    // The site's Backups tab shows nothing but an explanation until the capability is granted.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    // 22:30 UTC on a fixed date: the same instant is the following day in Sydney, so this catches a
    // conversion that changes only the hour and leaves the date behind.
    $this->takenAt = Carbon::parse('2026-03-04 22:30:00', 'UTC');

    $this->artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'taken_at' => $this->takenAt,
    ]);
});

it('prints a time in the zone the reader chose', function (): void {
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    // 22:30 UTC on 4 March is 09:30 on 5 March in Sydney. Both halves are asserted: an hour that
    // moves while the date does not is the bug this is most likely to be replaced by.
    $this->actingAs($this->user)->get("/sites/{$this->site->external_id}/backups")
        ->assertOk()
        ->assertSee('5 Mar 2026, 09:30');
});

it('falls back to the application for somebody who has not chosen one', function (): void {
    // Not the organisation, and not the site being looked at. A reader who has expressed no
    // preference gets the installation's own clock, which is at least a zone somebody configured
    // deliberately — the organisation's was one nobody could find.
    expect($this->user->timezone)->toBeNull()
        ->and(ViewerTimezone::for($this->user))->toBe(config('app.timezone'));
});

it('prefers the reader over everything else', function (): void {
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    expect(ViewerTimezone::for($this->user))->toBe('Australia/Sydney');
});

it('does not read the site being looked at, however tempting', function (): void {
    /*
     | The site has a zone now — it is what its backup schedule reads — and using it here would be
     | the same mistake as the organisation, one level down: a Sydney reader opening a London site
     | would see London times on one screen and their own on the next, with nothing saying which
     | was which.
    */
    expect($this->site->timezone)->toBe('Europe/London')
        ->and(ViewerTimezone::for($this->user))->toBe(config('app.timezone'));
});

it('lets somebody set and then unset their own zone', function (): void {
    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => 'Australia/Sydney'])
        ->assertRedirect();

    expect($this->user->fresh()->timezone)->toBe('Australia/Sydney');

    // Blank is a real answer and means "use this installation's own clock". Without this, choosing
    // a zone once would be irreversible from the screen.
    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => ''])
        ->assertRedirect();

    expect($this->user->fresh()->timezone)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'user.timezone.updated')->count())->toBe(2);
});

it('refuses something that is not a time zone', function (): void {
    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => 'Middle-Earth/Shire'])
        ->assertSessionHasErrors('timezone');

    expect($this->user->fresh()->timezone)->toBeNull();
});

it('changes nothing for anybody else', function (): void {
    // A personal preference, and the audit log is shared. Two people reading the same row should see
    // it in their own zone, which is only true because nothing here writes anywhere shared.
    $colleague = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($colleague)->for($this->organisation)->create();

    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => 'Australia/Sydney']);

    expect($colleague->fresh()->timezone)->toBeNull()
        ->and($this->site->fresh()->timezone)->toBe('Europe/London');
});

it('does not move when the backup schedule runs', function (): void {
    /*
     * The distinction the copy makes, asserted. A display preference must not reach the scheduler:
     * somebody in Sydney setting their own zone and thereby moving a customer's backup window to
     * the middle of a working day would be a genuinely bad outcome from a convenience feature.
     */
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    $this->actingAs($this->user)->get('/settings')->assertOk();

    expect($this->site->fresh()->timezone)->toBe('Europe/London');
});

it('leaves the model attribute alone when a view renders it', function (): void {
    // apply() copies rather than mutating. Carbon::setTimezone() changes the instance in place, and
    // these are model attributes — shifting one would leave the model holding a time in a zone that
    // is nobody's business but the view's, and the next thing to read it would be wrong.
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    ViewerTimezone::apply($this->artifact->taken_at, $this->user);

    expect($this->artifact->taken_at->getTimezone()->getName())->toBe('UTC');
});
