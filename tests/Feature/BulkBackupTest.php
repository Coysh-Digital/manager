<?php

declare(strict_types=1);

use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use App\Support\ResumableInput;
use coyshdigital\managerprotocol\Jobs;

/*
 * Backing up several sites from the fleet screen.
 *
 * The behaviour worth defending is not that it queues jobs - the single-site button already proved
 * that - but that it never claims to have queued one it did not. A screen that reports "requested"
 * over a fleet where half the sites refused is a half-truth discovered at the worst possible moment.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    RecoveryKey::factory()->for($this->organisation)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->readySite = function (string $name): Site {
        $site = Site::factory()->for($this->organisation)->connected()->create(['name' => $name]);
        Connector::factory()->for($site)->create();
        CapabilityGrant::factory()->for($site)->capability('backups:create')->create();

        return $site;
    };
});

it('queues one backup per selected site', function (): void {
    $one = ($this->readySite)('One');
    $two = ($this->readySite)('Two');

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->from('/sites')
        ->post('/backups/sites', ['sites' => [$one->external_id, $two->external_id]])
        ->assertRedirect('/sites')
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 sites'));

    expect(RemoteJob::query()->where('type', Jobs::BACKUP_CREATE)->count())->toBe(2);
});

it('leaves an unselected site alone', function (): void {
    $selected = ($this->readySite)('Selected');
    $untouched = ($this->readySite)('Untouched');

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => [$selected->external_id]]);

    expect(RemoteJob::query()->where('site_id', $selected->id)->count())->toBe(1)
        ->and(RemoteJob::query()->where('site_id', $untouched->id)->count())->toBe(0);
});

it('reports what it skipped rather than counting it as done', function (): void {
    $ready = ($this->readySite)('Ready');

    // No connector and no capability, so readiness refuses it. The point of the test is the
    // sentence, not the refusal: the refusal already had a home on the single-site button.
    $blocked = Site::factory()->for($this->organisation)->create(['name' => 'Blocked']);

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => [$ready->external_id, $blocked->external_id]])
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 site'))
        ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, '1 site was skipped'));

    expect(RemoteJob::query()->where('type', Jobs::BACKUP_CREATE)->count())->toBe(1);
});

it('groups several sites refused for the same reason', function (): void {
    $ready = ($this->readySite)('Ready');
    Site::factory()->for($this->organisation)->count(3)->create();

    $ids = Site::query()->where('organisation_id', $this->organisation->id)->pluck('external_id')->all();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => $ids])
        ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, '3 sites:'));

    expect(RemoteJob::query()->where('site_id', $ready->id)->count())->toBe(1);
});

it('errors rather than flashing success when nothing could be queued', function (): void {
    $blocked = Site::factory()->for($this->organisation)->create(['name' => 'Blocked']);

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => [$blocked->external_id]])
        ->assertSessionHasErrors('sites')
        ->assertSessionMissing('status');

    expect(RemoteJob::query()->count())->toBe(0);
});

it('ignores a site belonging to another organisation without abandoning the rest', function (): void {
    $mine = ($this->readySite)('Mine');

    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create(['name' => 'Theirs']);

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => [$mine->external_id, $theirs->external_id]])
        ->assertSessionHas('status');

    // One stale or hostile identifier must not cancel the good ones, and must not reach the other
    // organisation's site either.
    expect(RemoteJob::query()->where('site_id', $mine->id)->count())->toBe(1)
        ->and(RemoteJob::query()->where('site_id', $theirs->id)->count())->toBe(0);
});

it('does not queue a second backup for a site that already owes one', function (): void {
    $site = ($this->readySite)('Twice');

    foreach ([1, 2] as $ignored) {
        $this->actingAs($this->owner)
            ->withSession($this->recentAuth)
            ->post('/backups/sites', ['sites' => [$site->external_id]]);
    }

    // A second full dump of a production database is not a free no-op, which is why the single-site
    // button carries an idempotency key and this one passes the same one.
    expect(RemoteJob::query()->where('site_id', $site->id)->where('type', Jobs::BACKUP_CREATE)->count())->toBe(1);
});

it('refuses a member who cannot administer', function (): void {
    $site = ($this->readySite)('Ready');

    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create(['role' => Membership::ROLE_MEMBER]);

    $this->actingAs($member)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => [$site->external_id]])
        ->assertForbidden();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('asks for several backups without asking for the password again', function (): void {
    /*
     | Was the opposite, and so was a second test beside it that asserted the selection came back
     | across the confirm-password screen through ResumableInput. That second test is gone rather
     | than inverted: there is no gate here to carry anything across any more, so it was asserting
     | the behaviour of a mechanism that no longer runs on this route.
     |
     | The selection still survives a validation error through old('sites'), which is what the fleet
     | screen's checkboxes read - that is a different mechanism and it is exercised by the screen
     | test below rather than here.
     */
    $site = ($this->readySite)('Ready');

    $this->actingAs($this->owner)
        ->post('/backups/sites', ['sites' => [$site->external_id]])
        ->assertRedirect();

    expect(RemoteJob::query()->count())->toBe(1);
});

it('needs at least one site', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/backups/sites', ['sites' => []])
        ->assertSessionHasErrors('sites');
});

it('offers the checkboxes to an administrator and nobody else', function (): void {
    $site = ($this->readySite)('Ready');

    $this->actingAs($this->owner)
        ->get('/sites')
        ->assertOk()
        ->assertSee('Back up selected')
        ->assertSee($site->external_id);

    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create(['role' => Membership::ROLE_MEMBER]);

    $this->actingAs($member)
        ->get('/sites')
        ->assertOk()
        ->assertDontSee('Back up selected');
});
