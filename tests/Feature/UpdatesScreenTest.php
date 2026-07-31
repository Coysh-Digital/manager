<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Capability\UnknownCapabilityException;
use App\Domain\Updates\UpdatesIngestService;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\InventoryReport;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\UpdateReport;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use Database\Factories\UpdateReportFactory;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();
});

it('shows a security release prominently', function (): void {
    UpdateReport::factory()->for($this->site)->create();
    $this->site->forceFill(['has_security_release' => true, 'available_updates' => 2])->save();

    $this->actingAs($this->user)
        ->get('/updates')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertSee('Security release')
        ->assertSee('5.6.2')
        ->assertSee('5.6.4');
});

it('separates sites that have not reported from sites that are up to date', function (): void {
    UpdateReport::factory()->for($this->site)->upToDate()->create();

    $silent = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Silent Site']);
    Connector::factory()->for($silent)->create();

    $this->actingAs($this->user)
        ->get('/updates')
        ->assertOk()
        // "No updates available" and "we do not know" are different answers, and conflating them is
        // how a stale site gets ignored.
        ->assertSee('Not reporting updates')
        ->assertSee('Silent Site')
        ->assertSee('Not granted');
});

it('never shows release notes or download URLs', function (): void {
    UpdateReport::factory()->for($this->site)->create();

    $html = $this->actingAs($this->user)->get('/updates')->getContent();

    // Not merely absent from the view: absent from the schema, so there is nothing to render.
    expect($html)->not->toContain('release_notes')
        ->and($html)->not->toContain('download_url');
});

it('queues a job rather than reaching into the site', function (): void {
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $this->site))
        ->assertRedirect();

    $job = RemoteJob::query()->firstOrFail();

    // The platform cannot contact a connector, so this waits for the site to come and ask. The
    // message says so rather than implying an immediate result.
    expect($job->type)->toBe(Jobs::UPDATES_CHECK)
        ->and($job->state)->toBe(Jobs::STATE_QUEUED)
        ->and(session('status'))->toContain('next time the site checks in');
});

it('does not queue two checks when the button is pressed twice', function (): void {
    $acting = $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $acting->post(route('updates.refresh', $this->site));
    $acting->post(route('updates.refresh', $this->site));

    expect(RemoteJob::query()->count())->toBe(1);
});

it('explains itself when the capability is missing rather than failing silently', function (): void {
    $bare = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Bare Site']);
    Connector::factory()->for($bare)->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $bare))
        ->assertRedirect();

    expect(session('warning'))->toContain('Grant updates:read')
        ->and(RemoteJob::query()->count())->toBe(0);
});

it('requires recent authentication to request a check', function (): void {
    $this->actingAs($this->user)
        ->post(route('updates.refresh', $this->site))
        ->assertRedirect(route('password.confirm'));

    expect(RemoteJob::query()->count())->toBe(0);
});

it('hides another organisation site from the refresh action', function (): void {
    $other = Site::factory()->for(Organisation::factory())->connected()->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $other))
        ->assertNotFound();
});

it('grants a read capability from the interface', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.capabilities.grant', $site), ['capability' => 'updates:read'])
        ->assertRedirect();

    expect($site->fresh()->hasCapability('updates:read'))->toBeTrue();
});

it('refuses to grant a capability that modifies the site', function (): void {
    // backups:create reads the full database including user records. A toggle beside the read
    // switches would understate that considerably, so it needs its own confirmation flow.
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.capabilities.grant', $this->site), ['capability' => 'backups:create'])
        ->assertSessionHasErrors('capability');

    expect($this->site->fresh()->hasCapability('backups:create'))->toBeFalse();
});

it('refuses at the service layer too, however it is called', function (): void {
    // The route validates against the grantable list; this is the check behind it, so a caller that
    // bypasses the route still cannot grant something without a confirmation flow.
    expect(fn () => app(CapabilityService::class)
        ->grant($this->site, 'backups:create', $this->user))
        ->toThrow(UnknownCapabilityException::class);
});

it('shows the capability section with unavailable capabilities explained', function (): void {
    // Capabilities became a section of Settings rather than a screen of its own. The old route still
    // resolves and redirects, which is what the following test asserts.
    $this->actingAs($this->user)
        ->get(route('sites.settings', $this->site))
        ->assertOk()
        ->assertSee('Take a database backup')
        // Says why it is not on offer rather than just showing it greyed out.
        ->assertSee('Needs separate confirmation');
});

it('keeps the old capabilities link working', function (): void {
    // Links to it exist on the fleet screens and in people's bookmarks. A moved section should not
    // produce a 404 for either.
    $this->actingAs($this->user)
        ->get(route('sites.capabilities', $this->site))
        ->assertRedirect(route('sites.settings', $this->site).'#capabilities');
});

it('counts fleet updates in the sidebar', function (): void {
    $this->site->forceFill(['available_updates' => 3, 'has_security_release' => true])->save();

    $this->actingAs($this->user)
        ->get('/sites')
        ->assertOk()
        // Amber, because a security release anywhere is the one number that should interrupt.
        ->assertSee('border-amber-line', false);
});

/*
 | Craft's release notes, read in place
 |-------------------------------------------------------------------------------------------------
 */

it('offers the notes panel only when there is a newer version to read about', function (): void {
    UpdateReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get("/sites/{$this->site->external_id}/updates")
        ->assertOk()
        ->assertSee('What changed between these versions');

    // Nothing to read about a site that is already current, and no panel offering to find out.
    UpdateReport::query()->delete();
    UpdateReport::factory()->for($this->site)->upToDate()->create();

    $this->actingAs($this->user)
        ->get("/sites/{$this->site->external_id}/updates")
        ->assertOk()
        ->assertDontSee('What changed between these versions');
});

it('renders only the versions between the one installed and the one available', function (): void {
    UpdateReport::factory()->for($this->site)->create();

    // Stands in for the fetch. What is asserted is the cut: a site on 5.6.2 must not be shown what
    // 5.6.1 fixed, and must not be shown a version it cannot yet install.
    Cache::put('updates.changelog.craft', <<<'MARKDOWN'
        ## 5.6.5 - 2026-08-01
        - Not offered to this site yet.

        ## 5.6.4 - 2026-07-20
        - Fixed the thing this site is missing.

        ## 5.6.2 - 2026-07-01
        - Already installed here.

        ## 5.6.1 - 2026-06-11
        - Long gone.
        MARKDOWN, now()->addHour());

    $html = $this->actingAs($this->user)
        ->get("/sites/{$this->site->external_id}/updates/changelog")
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Fixed the thing this site is missing')
        ->and($html)->not->toContain('Already installed here')
        ->and($html)->not->toContain('Long gone')
        ->and($html)->not->toContain('Not offered to this site yet');
});

it('falls back to the link rather than an error when the notes cannot be read', function (): void {
    UpdateReport::factory()->for($this->site)->create();
    config()->set('manager.updates.fetch_changelogs', false);

    $this->actingAs($this->user)
        ->get("/sites/{$this->site->external_id}/updates/changelog")
        ->assertOk()
        ->assertSee('could not be read')
        ->assertSee('github.com/craftcms/cms', false);
});

it('will not read another organisation’s notes', function (): void {
    $stranger = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($stranger)->for(Organisation::factory()->create())->owner()->create();

    $this->actingAs($stranger)
        ->get("/sites/{$this->site->external_id}/updates/changelog")
        ->assertNotFound();
});

/*
 | Plugin release notes
 |-------------------------------------------------------------------------------------------------
 |
 | The same panel as Craft's, fed from a different place. Craft's notes are fetched from GitHub; a
 | plugin's were forwarded by connectors and are read back out of this database, so these tests never
 | touch the network or the cache.
 */

it('offers the notes panel only for a plugin that has some', function (): void {
    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::sampleV2Payload());
    InventoryReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get(route('sites.updates', $this->site))
        ->assertOk()
        ->assertSee('What changed between these versions')
        ->assertSee(route('sites.updates.changelog.plugin', ['site' => $this->site, 'handle' => 'formie']), false);
});

it('shows no panel for a plugin nobody has reported notes for', function (): void {
    // The v1 payload carries the same plugin with no releases. A summary that expands to nothing is
    // worse than the Plugin Store link that was always there.
    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::samplePayload());
    InventoryReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get(route('sites.updates', $this->site))
        ->assertOk()
        ->assertDontSee(route('sites.updates.changelog.plugin', ['site' => $this->site, 'handle' => 'formie']), false);
});

it('renders only the plugin releases between the one installed and the one available', function (): void {
    // Same cut as Craft's panel, asserted the same way: a site on 3.0.11 must not be shown what
    // 3.0.10 fixed, nor a version it cannot yet install.
    app(UpdatesIngestService::class)->store($this->site, [
        ...UpdateReportFactory::sampleV2Payload(),
        'plugins' => [[
            'handle' => 'formie',
            'name' => 'Formie',
            'current' => '3.0.11',
            'latest' => '3.0.14',
            'update_available' => true,
            'releases' => [
                ['version' => '3.0.15', 'notes' => 'Not offered to this site yet.'],
                ['version' => '3.0.14', 'notes' => 'Fixed the thing this site is missing.'],
                ['version' => '3.0.11', 'notes' => 'Already installed here.'],
                ['version' => '3.0.9', 'notes' => 'Long gone.'],
            ],
        ]],
    ]);

    InventoryReport::factory()->for($this->site)->create();

    $html = $this->actingAs($this->user)
        ->get(route('sites.updates.changelog.plugin', ['site' => $this->site, 'handle' => 'formie']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Fixed the thing this site is missing')
        ->and($html)->not->toContain('Already installed here')
        ->and($html)->not->toContain('Long gone')
        ->and($html)->not->toContain('Not offered to this site yet');
});

it('strips HTML out of a release note before putting it on the page', function (): void {
    // Third-party text, rendered inside an authenticated session. Whoever publishes the plugin
    // writes this, and they are not trusted with markup here.
    app(UpdatesIngestService::class)->store($this->site, [
        ...UpdateReportFactory::sampleV2Payload(),
        'plugins' => [[
            'handle' => 'formie',
            'current' => '3.0.11',
            'latest' => '3.0.14',
            'update_available' => true,
            'releases' => [[
                'version' => '3.0.14',
                // Separate lines on purpose. CommonMark treats a <script> opener as an HTML block
                // running to the end of its line, so stripping it takes the rest of that line with
                // it — safe, but it would make this test pass for the wrong reason.
                'notes' => "Fixed a thing.\n\n<script>alert(1)</script>\n\n[click](javascript:alert(2))",
            ]],
        ]],
    ]);

    InventoryReport::factory()->for($this->site)->create();

    $html = $this->actingAs($this->user)
        ->get(route('sites.updates.changelog.plugin', ['site' => $this->site, 'handle' => 'formie']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Fixed a thing')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('href="javascript:');
});

it('will not read another organisation’s plugin notes', function (): void {
    app(UpdatesIngestService::class)->store($this->site, UpdateReportFactory::sampleV2Payload());

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($stranger)->for(Organisation::factory()->create())->owner()->create();

    $this->actingAs($stranger)
        ->get(route('sites.updates.changelog.plugin', ['site' => $this->site, 'handle' => 'formie']))
        ->assertNotFound();
});
