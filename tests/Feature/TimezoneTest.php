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
 * operator changed it. The organisation already had a timezone and exactly one thing read it — the
 * backup scheduler — so "03:00" meant the quiet hour where the sites are, while the audit log three
 * screens away reported an event at an hour nobody recognised.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['timezone' => 'Europe/London']);

    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create();
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

it('falls back to the organisation for somebody who has not chosen one', function (): void {
    // Europe/London in March is still UTC, so this asserts the fallback resolves rather than that a
    // conversion happened — which is the point: a colleague who never opens their account screen
    // reads the same clock as the schedule they are looking at.
    expect($this->user->timezone)->toBeNull()
        ->and(ViewerTimezone::for($this->user))->toBe('Europe/London');
});

it('prefers the reader over the organisation, not the other way round', function (): void {
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    expect(ViewerTimezone::for($this->user))->toBe('Australia/Sydney');
});

it('falls back to the application when an organisation has none', function (): void {
    // Not reachable through the interface — the column defaults to UTC and the form requires a
    // value — but a third answer has to exist or the accessor returns an empty string to a
    // formatter, and PHP reads that as UTC while saying nothing.
    $this->organisation->forceFill(['timezone' => ''])->save();

    expect(ViewerTimezone::for($this->user))->toBe(config('app.timezone'));
});

it('lets somebody set and then unset their own zone', function (): void {
    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => 'Australia/Sydney'])
        ->assertRedirect();

    expect($this->user->fresh()->timezone)->toBe('Australia/Sydney');

    // Blank is a real answer and means "use the organisation's". Without this, choosing a zone once
    // would be irreversible from the screen.
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
    // it in their own zone, which is only true because nothing here writes to the organisation.
    $colleague = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($colleague)->for($this->organisation)->create();

    $this->actingAs($this->user)->post('/account/timezone', ['timezone' => 'Australia/Sydney']);

    expect($colleague->fresh()->timezone)->toBeNull()
        ->and($this->organisation->fresh()->timezone)->toBe('Europe/London');
});

it('does not move when the backup schedule runs', function (): void {
    /*
     * The distinction the copy makes, asserted. A display preference must not reach the scheduler:
     * somebody in Sydney setting their own zone and thereby moving every customer's backup window
     * to the middle of a working day would be a genuinely bad outcome from a convenience feature.
     */
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    $this->actingAs($this->user)->get('/settings')->assertOk();

    expect($this->organisation->fresh()->timezone)->toBe('Europe/London');
});

it('leaves the model attribute alone when a view renders it', function (): void {
    // apply() copies rather than mutating. Carbon::setTimezone() changes the instance in place, and
    // these are model attributes — shifting one would leave the model holding a time in a zone that
    // is nobody's business but the view's, and the next thing to read it would be wrong.
    $this->user->forceFill(['timezone' => 'Australia/Sydney'])->save();

    ViewerTimezone::apply($this->artifact->taken_at, $this->user);

    expect($this->artifact->taken_at->getTimezone()->getName())->toBe('UTC');
});
