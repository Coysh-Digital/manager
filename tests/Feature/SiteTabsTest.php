<?php

declare(strict_types=1);

use App\Domain\Health\SiteUptime;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Heartbeat;
use App\Models\InventoryReport;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\UpdateReport;
use App\Models\User;
use Database\Factories\InventoryReportFactory;

/**
 * The seven screens a site has.
 *
 * The tenant-scoping assertions matter more than the rest of this file put together. Route binding
 * resolves a site on its external identifier alone, so every new route is a new way to read another
 * organisation's site if the scoping is ever forgotten.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Example Site',
        'expected_domain' => 'example.org',
    ]);

    Connector::factory()->for($this->site)->create();

    foreach (['inventory:read', 'updates:read', 'security:read', 'system:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }
});

/**
 * @return list<string>
 */
function siteTabRoutes(): array
{
    return [
        'sites.show',
        'sites.health',
        'sites.updates',
        'sites.security',
        'sites.backups',
        'sites.settings',
        'sites.audit',
    ];
}

it('renders every tab', function (string $route): void {
    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertSee('Example Site')
        // The tab bar is on all of them, so any one can reach any other.
        ->assertSee(route('sites.updates', $this->site), escape: false);
})->with(siteTabRoutes());

it('refuses a site belonging to another organisation on every tab', function (string $route): void {
    $other = Site::factory()->for(Organisation::factory())->create();

    // A 404 rather than a 403: telling somebody a site exists but is not theirs is telling them
    // something about another organisation.
    $this->actingAs($this->user)->get(route($route, $other))->assertNotFound();
})->with(siteTabRoutes());

it('redirects the old capabilities route into settings', function (): void {
    $this->actingAs($this->user)
        ->get(route('sites.capabilities', $this->site))
        ->assertRedirect(route('sites.settings', $this->site).'#capabilities');
});

/*
 | Updates
 |-------------------------------------------------------------------------------------------------
 */

it('lists a plugin the update check knows nothing about as not checked', function (): void {
    // The join runs from the installed list, not the update report. A private or VCS-installed
    // plugin appears in the first and never in the second, and losing it would lose exactly the
    // plugins nobody is watching.
    InventoryReport::factory()->for($this->site)->create([
        'payload' => [
            ...InventoryReportFactory::samplePayload(),
            'plugins' => [
                ['handle' => 'formie', 'version' => '3.0.11', 'enabled' => true],
                ['handle' => 'client-bespoke', 'version' => '1.2.0', 'enabled' => true],
            ],
        ],
    ]);

    UpdateReport::factory()->for($this->site)->create();

    $html = $this->actingAs($this->user)
        ->get(route('sites.updates', $this->site))
        ->assertOk()
        ->assertSee('client-bespoke')
        ->assertSee('Formie')
        ->getContent();

    expect($html)->toContain('Not checked');
});

it('shows every installed plugin with its installed and latest version', function (): void {
    InventoryReport::factory()->for($this->site)->create([
        'payload' => [
            ...InventoryReportFactory::samplePayload(),
            'plugins' => [['handle' => 'formie', 'version' => '3.0.11', 'enabled' => true]],
        ],
    ]);

    UpdateReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get(route('sites.updates', $this->site))
        ->assertOk()
        ->assertSee('3.0.11')
        ->assertSee('3.0.14')
        ->assertSee('1 installed');
});

/*
 | Health
 |-------------------------------------------------------------------------------------------------
 */

it('finds a gap in the check-in record', function (): void {
    // Twelve hours of heartbeats every five minutes, with two hours missing in the middle.
    $start = now()->subHours(12);

    for ($minute = 0; $minute <= 720; $minute += 5) {
        if ($minute > 300 && $minute < 420) {
            continue;
        }

        Heartbeat::factory()->for($this->site)->create([
            'received_at' => $start->copy()->addMinutes($minute),
        ]);
    }

    $window = app(SiteUptime::class)->for($this->site, 24);

    expect($window->outages)->toHaveCount(1)
        ->and($window->longest?->duration())->toBe('2h')
        // Not perfect, and not catastrophic. A two-hour gap in a day is a little over 8%.
        ->and($window->availability)->toBeGreaterThan(80.0)
        ->and($window->availability)->toBeLessThan(95.0);
});

it('reports a site that has stopped checking in as ongoing', function (): void {
    Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subHours(6)]);
    Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subHours(6)->addMinutes(5)]);

    $window = app(SiteUptime::class)->for($this->site, 24);

    expect($window->isCurrentlySilent())->toBeTrue()
        ->and($window->tone())->toBe('bad');
});

it('claims nothing from a single check-in', function (): void {
    // "100% uptime" off one heartbeat would be a claim the data cannot support.
    Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes(2)]);

    expect(app(SiteUptime::class)->for($this->site, 24)->hasEvidence())->toBeFalse();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('Not enough check-ins');
});

it('does not count the time before a site existed as uptime', function (): void {
    // A site added an hour ago must not read "99.9% over 30 days". The numerator would only count
    // gaps since it was added while the denominator covered the whole month - a figure that is
    // flattering, stable and meaningless.
    $this->site->forceFill(['created_at' => now()->subHours(4)])->save();

    for ($minute = 0; $minute <= 120; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create([
            'received_at' => now()->subHours(4)->addMinutes($minute),
        ]);
    }

    $window = app(SiteUptime::class)->for($this->site->fresh(), 720);

    // Two hours of heartbeats then two hours of silence, inside a four-hour life - not a rounding
    // error inside a thirty-day window.
    expect($window->from->diffInHours(now()))->toBeLessThan(5)
        ->and($window->availability)->toBeLessThan(60.0)
        ->and($window->isCurrentlySilent())->toBeTrue();
});

it('does not expect fewer check-ins than a well-behaved site sends', function (): void {
    /*
     | The regression that produced "Check-ins 8 of ~7" on screen.
     |
     | A site paired 35 minutes ago, reporting every five minutes, has checked in at 0, 5, 10, 15,
     | 20, 25, 30 and 35 - eight times across seven intervals. Both ends of the window count, so the
     | expectation is eight, and a site doing exactly what was asked of it must never appear to have
     | done more than that.
     */
    $this->site->forceFill(['created_at' => now()->subMinutes(35)])->save();

    for ($minute = 0; $minute <= 35; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create([
            'received_at' => now()->subMinutes(35 - $minute),
        ]);
    }

    $window = app(SiteUptime::class)->for($this->site->fresh(), 24);

    expect($window->received)->toBe(8)
        ->and($window->expected)->toBe(8);
});

it('explains a site that checks in more often than the schedule asks for', function (): void {
    /*
     | The sibling of the case above, and the opposite mistake to make about it.
     |
     | "37 of ~35" is not a counting error. `expected` models one producer beating on a fixed
     | interval; a connector has two that throttle independently - the cron task and the web trigger
     | that fires off ordinary traffic - so a busy site with both enabled sits above the estimate
     | permanently. Clamping the count would hide a figure worth having; the screen explains it
     | instead.
     */
    $this->site->forceFill(['created_at' => now()->subMinutes(30)])->save();

    // Every five minutes as asked, plus a handful of extra beats from the web trigger.
    for ($minute = 0; $minute <= 30; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes(30 - $minute)]);
    }

    foreach ([27, 18, 9] as $minutesAgo) {
        Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes($minutesAgo)]);
    }

    $window = app(SiteUptime::class)->for($this->site->fresh(), 24);

    expect($window->received)->toBe(10)
        ->and($window->expected)->toBe(7)
        ->and($window->reportsMoreOftenThanExpected())->toBeTrue()

        // The extra beats must not flatter the figure the badge is drawn from.
        ->and($window->availability)->toBeLessThanOrEqual(100.0);

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('10 of ~7')
        ->assertSee('checks in more often than the 5-minute schedule', false);
});

it('says nothing about the schedule when a site keeps to it', function (): void {
    $this->site->forceFill(['created_at' => now()->subMinutes(35)])->save();

    for ($minute = 0; $minute <= 35; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes(35 - $minute)]);
    }

    expect(app(SiteUptime::class)->for($this->site->fresh(), 24)->reportsMoreOftenThanExpected())->toBeFalse();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertDontSee('checks in more often than');
});

it('does not describe an hour in progress as though it were over', function (): void {
    // The same off-by-one in the chart: a bucket still filling was measured against a whole hour's
    // worth of check-ins, so it read "3 of 12" while the bar beside it - already scaled by elapsed
    // time - drew as full.
    for ($minute = 0; $minute <= 10; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create([
            'received_at' => now()->subMinutes(10 - $minute),
        ]);
    }

    $buckets = app(SiteUptime::class)->for($this->site, 24)->buckets;
    $current = end($buckets);

    expect($current['received'])->toBeLessThanOrEqual($current['expected']);
});

it('falls back to a sensible window when asked for a nonsense one', function (): void {
    $this->actingAs($this->user)
        ->get(route('sites.health', ['site' => $this->site, 'window' => 'forever']))
        ->assertOk();
});

/*
 | Security
 |-------------------------------------------------------------------------------------------------
 */

it('names the checks that could not run', function (): void {
    // A rule whose capability is not granted is skipped, not passed. An empty findings list must not
    // read as a clean bill of health.
    $this->site->capabilityGrants()->where('capability', 'security:read')->delete();

    $this->actingAs($this->user)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('Some checks did not run')
        ->assertSee('security:read');
});

it('shows this site\'s outstanding findings', function (): void {
    Finding::factory()->for($this->site)->create([
        'rule' => 'dev_mode_in_production',
        'severity' => 'critical',
        'title' => 'Development mode is on in production',
        'state' => Finding::STATE_OPEN,
    ]);

    $this->actingAs($this->user)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('Development mode is on in production')
        ->assertSee('Critical');
});

/*
 | Settings
 |-------------------------------------------------------------------------------------------------
 */

it('offers the day of the week only for a schedule that reads it', function (): void {
    // The scheduler ignores the day unless the schedule is weekly, so showing it for a daily one
    // invites somebody to set a value that is saved, audited, and then never consulted.
    //
    // The form lives on the Backups screen now, which shows nothing but an explanation until the
    // capability is granted - there is no point scheduling what the site has not been permitted to
    // do.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->site->forceFill(['backup_schedule' => 'daily'])->save();

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('data-backup-schedule-field="day" hidden', false)
        ->assertDontSee('data-backup-schedule-field="hour" hidden', false);

    $this->site->forceFill(['backup_schedule' => 'weekly'])->save();

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertDontSee('data-backup-schedule-field="day" hidden', false);

    // Neither applies when backups only happen on request.
    $this->site->forceFill(['backup_schedule' => 'off'])->save();

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('data-backup-schedule-field="day" hidden', false)
        ->assertSee('data-backup-schedule-field="hour" hidden', false);
});

it('renames a site and records the previous value', function (): void {
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.settings.update', $this->site), [
            'name' => 'Renamed Site',
            'expected_domain' => 'https://www.Example.ORG/some/path',
            'environment' => 'staging',
        ])
        ->assertRedirect();

    $this->site->refresh();

    expect($this->site->name)->toBe('Renamed Site')
        // Normalised through the same path a new site's domain goes through.
        ->and($this->site->expected_domain)->toBe('example.org')
        ->and($this->site->environment)->toBe('staging');

    $event = AuditEvent::query()->where('action', 'site.updated')->latest('seq')->first();

    expect($event)->not->toBeNull()
        ->and($event->before['name'])->toBe('Example Site')
        ->and($event->after['environment'])->toBe('staging');
});

it('refuses a domain that is not one', function (): void {
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.settings.update', $this->site), [
            'name' => 'Example Site',
            'expected_domain' => 'localhost',
            'environment' => 'production',
        ])
        ->assertSessionHasErrors('expected_domain');

    expect($this->site->fresh()->expected_domain)->toBe('example.org');
});

it('records nothing when nothing changed', function (): void {
    // An append-only log should not gain a row saying a site was edited when it was not.
    $before = AuditEvent::query()->count();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.settings.update', $this->site), [
            'name' => 'Example Site',
            'expected_domain' => 'example.org',
            'environment' => $this->site->environment,
        ])
        ->assertRedirect();

    expect(AuditEvent::query()->count())->toBe($before);
});

it('refuses to change site details without recent authentication', function (): void {
    $this->actingAs($this->user)
        ->post(route('sites.settings.update', $this->site), [
            'name' => 'Renamed Site',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ])
        ->assertRedirect(route('password.confirm'));

    expect($this->site->fresh()->name)->toBe('Example Site');
});

it('refuses to change site details for a member who cannot administer', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.settings.update', $this->site), [
            'name' => 'Renamed Site',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ])
        ->assertForbidden();
});

/*
 | Backups
 |-------------------------------------------------------------------------------------------------
 */

it('distinguishes a site with no backups from one that is not being backed up', function (): void {
    // Two different problems, and only one of them needs chasing. An empty list that means both is
    // the state somebody stares at wondering which they are looking at.
    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('This site is not being backed up')
        // The acknowledgement is on the screen where the decision would be made.
        ->assertSee('password hashes');

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('Granted, but nothing has arrived yet')
        ->assertDontSee('This site is not being backed up');
});

it('lists this site\'s artifacts and flags a stale one', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    BackupArtifact::factory()->for($this->site)->create([
        'taken_at' => now()->subDays(20),
        'stored_at' => now()->subDays(20),
    ]);

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('Over a week old')
        ->assertSee('Checksum matched')
        // Ciphertext can be downloaded; plaintext still cannot, and there is still no restore button.
        // Both of those absences are deliberate - see BackupScreenTest for the full argument.
        ->assertSee('manager:backups:fetch')
        ->assertSee('manager-restore decrypt')
        ->assertDontSee('Restore</');
});

it('shows another site\'s backups nowhere near this one', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $other = Site::factory()->for($this->organisation)->create(['name' => 'Other Site']);
    BackupArtifact::factory()->for($other)->create(['plaintext_bytes' => 999999999]);

    $this->actingAs($this->user)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('Granted, but nothing has arrived yet');
});

it('reports the last backup on the overview', function (): void {
    $this->actingAs($this->user)
        ->get(route('sites.show', $this->site))
        ->assertOk()
        // Never granted reads differently from granted-but-empty.
        ->assertSee('Not enabled');

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    BackupArtifact::factory()->for($this->site)->create(['taken_at' => now()->subHours(3)]);

    $this->actingAs($this->user)
        ->get(route('sites.show', $this->site))
        ->assertOk()
        ->assertSee('Last backup')
        ->assertDontSee('Not enabled');
});

/*
 | Charts
 |-------------------------------------------------------------------------------------------------
 */

it('hands the uptime chart its figures as data rather than as markup', function (): void {
    for ($minute = 0; $minute <= 180; $minute += 5) {
        Heartbeat::factory()->for($this->site)->create([
            'received_at' => now()->subHours(3)->addMinutes($minute),
        ]);
    }

    $html = $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->getContent();

    // The canvas carries a payload the server rendered; nothing about a site is known to the script.
    expect($html)->toContain('data-chart=')
        ->toContain('&quot;kind&quot;:&quot;uptime&quot;')
        // And the same figures exist as text, for a screen reader, a printout and a blocked script.
        ->toContain('check-ins</td>');
});

/*
 | Audit
 |-------------------------------------------------------------------------------------------------
 */

it('shows only this site\'s audit events', function (): void {
    $other = Site::factory()->for($this->organisation)->create(['name' => 'Other Site']);

    // Sequence numbers are per organisation and unique, so they are set explicitly here - the
    // factory produces rows to look at, not valid chains.
    AuditEvent::factory()->for($this->organisation)->for($this->site)
        ->create(['seq' => 1, 'action' => 'site.refreshed']);
    AuditEvent::factory()->for($this->organisation)->for($other)
        ->create(['seq' => 2, 'action' => 'site.elsewhere']);

    $this->actingAs($this->user)
        ->get(route('sites.audit', $this->site))
        ->assertOk()
        ->assertSee('site.refreshed')
        ->assertDontSee('site.elsewhere');
});

/*
 | The schedule shown on screen
 |-------------------------------------------------------------------------------------------------
 */

it('offers a cron entry for every task the connector runs', function (): void {
    // This drifted once already: two new tasks shipped and the panel kept showing the old four, so a
    // site could be granted a capability and then sit reporting nothing forever with no visible
    // reason. The screen is the instruction, so the instruction has to be complete.
    $html = $this->actingAs($this->user)
        ->get(route('sites.settings', $this->site))
        ->assertOk()
        ->getContent();

    // Scheduled tasks, not the job vocabulary - those are deliberately different things.
    foreach (['heartbeat', 'jobs', 'logins', 'report', 'system', 'updates'] as $task) {
        expect($html)->toContain("php craft manager-connector/{$task}");
    }

    // And the count in the sentence above them matches what is printed. It said "four" against six.
    expect($html)->toContain('these six lines');
});

it('does not tell anybody cron is required', function (): void {
    /*
     | It is not, and has not been since connector 1.5.0: webTrigger is on by default and drives the
     | schedule from ordinary traffic, so a paired site reports without anyone touching crontab. The
     | documentation has said so throughout - "Cron is optional. Everything works out of the box
     | without it." - while two places on screen still said otherwise, which is the wrong way round
     | for somebody on hosting where cron is not available deciding whether this product works.
    */
    $html = $this->actingAs($this->user)
        ->get(route('sites.settings', $this->site))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('required for this site to report');
    expect($html)->toContain('No cron? Nothing to do.');
});

it('does not present a scheduled task as an outstanding step on a silent site', function (): void {
    // The banner fires exactly when somebody has just paired and is waiting, which is the moment they
    // are most likely to go and configure something they did not need. It now says what will happen
    // on its own, and offers cron as an improvement rather than as the missing piece.
    $this->site->forceFill(['craft_version' => null])->save();

    $html = $this->actingAs($this->user)
        ->get(route('sites.show', $this->site))
        ->assertOk()
        ->assertSee('Paired, but nothing reported yet')
        ->getContent();

    expect($html)->not->toContain('one more step');
    expect($html)->toContain('cron is optional');
});
