<?php

declare(strict_types=1);

use App\Domain\Findings\Severity;
use App\Domain\Health\Diagnostics;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    $this->connector = Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

// --------------------------------------------------------------------------------------------------
// Findings
// --------------------------------------------------------------------------------------------------

it('lists outstanding findings worst first', function (): void {
    Finding::factory()->for($this->site)->rule('low_thing')->severity(Severity::LOW)->create(['title' => 'Least urgent']);
    Finding::factory()->for($this->site)->rule('critical_thing')->severity(Severity::CRITICAL)->create(['title' => 'Most urgent']);

    $html = $this->actingAs($this->owner)->get('/findings')->assertOk()->getContent();

    // A critical finding that has been true for a month is worse than one raised this morning, and
    // should read that way.
    expect(strpos($html, 'Most urgent'))->toBeLessThan(strpos($html, 'Least urgent'));
});

it('shows acknowledged findings rather than hiding them', function (): void {
    Finding::factory()->for($this->site)->acknowledged()->create(['title' => 'Known problem']);

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        // Filing an acknowledged finding out of sight turns a decision to wait into a permanent one
        // nobody revisits.
        ->assertSee('Known problem')
        ->assertSee('Acknowledged')
        ->assertSee('Deliberate on this site');
});

it('hides resolved findings unless asked for', function (): void {
    Finding::factory()->for($this->site)->resolved()->create(['title' => 'Fixed already']);

    $this->actingAs($this->owner)->get('/findings')->assertOk()->assertDontSee('Fixed already');
    $this->actingAs($this->owner)->get('/findings?resolved=1')->assertOk()->assertSee('Fixed already');
});

it('gives each site its own numbers rather than the first site\'s', function (): void {
    // Two sites on two different servers. Their disk readings are facts about different machines,
    // and there is no arrangement of them under which one stands for the other. The grouped layout
    // printed $group->first()->detail as the description for the whole rule, so both rows carried
    // the first server's percentage — reported from a live fleet, where it read as one site
    // borrowing another's disk.
    $second = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Second Site']);

    Finding::factory()->for($this->site)->rule('disk_almost_full')->create([
        'title' => 'The disk is filling up',
        'detail' => 'The volume holding this site is 90.5% full, with 5.5 GB free.',
    ]);
    Finding::factory()->for($second)->rule('disk_almost_full')->create([
        'title' => 'The disk is filling up',
        'detail' => 'The volume holding this site is 96.2% full, with 1.2 GB free.',
    ]);

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        ->assertSee('90.5% full, with 5.5 GB free')
        ->assertSee('96.2% full, with 1.2 GB free');
});

it('still states a shared description once', function (): void {
    // The other half of the same rule, and the reason the fix is conditional rather than a blanket
    // "render it per row". Development mode being on is a fact about a config flag: the sentence is
    // identical everywhere, and repeating it per site is the noise the grouping was built to remove.
    $second = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Second Site']);

    $detail = 'Development mode is on, which exposes stack traces to visitors.';

    Finding::factory()->for($this->site)->rule('dev_mode_in_production')->create([
        'title' => 'Development mode is on in production',
        'detail' => $detail,
    ]);
    Finding::factory()->for($second)->rule('dev_mode_in_production')->create([
        'title' => 'Development mode is on in production',
        'detail' => $detail,
    ]);

    $html = $this->actingAs($this->owner)->get('/findings')->assertOk()->getContent();

    expect(substr_count($html, e($detail)))->toBe(1);
});

it('requires a reason to acknowledge', function (): void {
    $finding = Finding::factory()->for($this->site)->create();

    // "Acknowledged three weeks ago" with no explanation leaves the next person unable to tell a
    // decision from a shrug.
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $finding), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($finding->fresh()->state)->toBe(Finding::STATE_OPEN);
});

it('acknowledges with a reason and audits it', function (): void {
    $finding = Finding::factory()->for($this->site)->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $finding), ['reason' => 'Staging box, deliberate'])
        ->assertRedirect();

    $finding->refresh();

    expect($finding->state)->toBe(Finding::STATE_ACKNOWLEDGED)
        ->and($finding->acknowledgement_reason)->toBe('Staging box, deliberate')
        ->and($finding->acknowledged_label)->toBe('Tim Coysh')
        ->and(AuditEvent::query()->where('action', 'finding.acknowledged')->exists())->toBeTrue();
});

it('still counts an acknowledged finding against the site', function (): void {
    $finding = Finding::factory()->for($this->site)->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $finding), ['reason' => 'Later this week']);

    // Acknowledgement is not resolution. A fleet must not look clean because everything in it has
    // merely been read.
    expect($this->site->fresh()->open_findings)->toBeGreaterThan(0);
});

it('withdraws an acknowledgement', function (): void {
    $finding = Finding::factory()->for($this->site)->acknowledged()->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.reopen', $finding))
        ->assertRedirect();

    expect($finding->fresh()->state)->toBe(Finding::STATE_OPEN)
        ->and($finding->fresh()->acknowledgement_reason)->toBeNull();
});

it('requires recent authentication to acknowledge', function (): void {
    $finding = Finding::factory()->for($this->site)->create();

    $this->actingAs($this->owner)
        ->post(route('findings.acknowledge', $finding), ['reason' => 'whatever'])
        ->assertRedirect(route('password.confirm'));

    expect($finding->fresh()->state)->toBe(Finding::STATE_OPEN);
});

it('hides findings belonging to another organisation', function (): void {
    $other = Site::factory()->for(Organisation::factory())->create();
    $theirs = Finding::factory()->for($other)->create(['title' => 'Their problem']);

    $this->actingAs($this->owner)->get('/findings')->assertOk()->assertDontSee('Their problem');

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $theirs), ['reason' => 'not mine'])
        ->assertNotFound();
});

it('says an empty list is only as complete as what was granted', function (): void {
    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        // A rule is skipped, not passed, when its capability is missing — so "no findings" needs that
        // caveat attached or it reads as a clean bill of health.
        ->assertSee('skipped, not passed');
});

// --------------------------------------------------------------------------------------------------
// Settings
// --------------------------------------------------------------------------------------------------

it('shows platform health from the same checks the doctor runs', function (): void {
    $this->actingAs($this->owner)
        ->get('/settings')
        ->assertOk()
        ->assertSee('Platform health')
        ->assertSee('manager:doctor')
        ->assertSee('Audit log protection')
        ->assertSee('Replay-protection store');
});

it('states what a new site can do, and that it is not configurable', function (): void {
    $this->actingAs($this->owner)
        ->get('/settings')
        ->assertOk()
        ->assertSee('inventory:read')
        // A setting that could grant more at pairing time would make "read-only by default" a
        // preference rather than a property.
        ->assertSee('deliberately not configurable');
});

it('lets an owner require two-factor authentication', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('settings.mfa'), ['mfa_required' => 1])
        ->assertRedirect();

    expect($this->organisation->fresh()->mfa_required)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'organisation.mfa.required')->exists())->toBeTrue();
});

it('says how many members still need to enrol', function (): void {
    $laggard = User::factory()->create();
    Membership::factory()->for($laggard)->for($this->organisation)->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('settings.mfa'), ['mfa_required' => 1]);

    // Locking people out of a control plane to improve its security is a trade made once and
    // regretted at 2am, so the message says they will be asked rather than blocked.
    expect(session('status'))->toContain('not enrolled yet')
        ->and(session('status'))->toContain('asked to');
});

it('will not let a non-owner change organisation settings', function (): void {
    $admin = User::factory()->create();
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->withSession($this->recentAuth)
        ->post(route('settings.mfa'), ['mfa_required' => 1])
        ->assertForbidden();

    expect($this->organisation->fresh()->mfa_required)->toBeFalse();
});

it('requires the organisation name typed before revoking every connector', function (): void {
    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('settings.connectors.rotate'), ['confirm_organisation' => 'Wrong Name'])
        ->assertSessionHasErrors('confirm_organisation');

    expect($this->connector->fresh()->state)->toBe(Connector::STATE_ACTIVE);
});

it('revokes every connector and its capabilities together', function (): void {
    $second = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($second)->create();
    CapabilityGrant::factory()->for($second)->capability('inventory:read')->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('settings.connectors.rotate'), ['confirm_organisation' => 'Coysh Digital'])
        ->assertRedirect();

    expect($this->connector->fresh()->state)->toBe(Connector::STATE_REVOKED)
        ->and($this->site->fresh()->grantedCapabilities())->toBe([])
        ->and($this->site->fresh()->status)->toBe(Site::STATUS_NOT_CONNECTED)
        ->and($second->fresh()->activeConnector()->first())->toBeNull()
        ->and($second->fresh()->grantedCapabilities())->toBe([]);

    expect(session('warning'))->toContain('fresh enrolment code');
});

it('leaves another organisation untouched by a rotation', function (): void {
    $otherOrg = Organisation::factory()->create();
    $otherSite = Site::factory()->for($otherOrg)->connected()->create();
    $otherConnector = Connector::factory()->for($otherSite)->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('settings.connectors.rotate'), ['confirm_organisation' => 'Coysh Digital']);

    expect($otherConnector->fresh()->state)->toBe(Connector::STATE_ACTIVE);
});

it('hides the irreversible actions from a non-owner', function (): void {
    $admin = User::factory()->create();
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    $this->actingAs($admin)
        ->get('/settings')
        ->assertOk()
        ->assertDontSee('Actions that cannot be undone');
});

/*
 | Mail
 |-------------------------------------------------------------------------------------------------
 |
 | Configured in the environment and displayed nowhere. "A transport is configured" and "mail leaves
 | this server" are different claims, and only one of them can be tested from a button.
 */

it('sends a test message to the owner and nobody else', function (): void {
    // Asserted against the callback rather than through Mail::fake(). Mail::raw() builds no Mailable,
    // so there is nothing for assertSent() to inspect — the same limitation EmailTransport documents,
    // and the reason its body is a public method.
    $addressed = null;

    Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body, callable $callback) use (&$addressed): void {
        $message = new Message(new Email);
        $callback($message);

        $addressed = array_map(
            static fn (Address $address): string => $address->getAddress(),
            $message->getSymfonyMessage()->getTo(),
        );
    });

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertRedirect()
        ->assertSessionHas('status');

    // No destination field, so no way to point this installation's relay at an arbitrary address.
    expect($addressed)->toBe([$this->owner->email]);

    expect(AuditEvent::query()->where('action', 'settings.mail.tested')->sole()->outcome)
        ->toBe(AuditEvent::OUTCOME_SUCCESS);
});

it('never puts mail configuration on the settings screen', function (): void {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.internal.example');
    config()->set('mail.mailers.smtp.username', 'postmaster@example.org');
    config()->set('mail.mailers.smtp.password', 'hunter2');
    config()->set('mail.from.address', 'manager@example.org');

    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    // Whoever can reach this screen is not necessarily whoever holds the relay's credentials, and the
    // health check answers the only question the screen needs to: will a password reset arrive.
    expect($html)->not->toContain('smtp.internal.example')
        ->and($html)->not->toContain('postmaster@example.org')
        ->and($html)->not->toContain('hunter2')
        ->and($html)->not->toContain('manager@example.org')
        ->and($html)->toContain('Send a test email');
});

it('warns rather than failing when no mail transport is configured', function (): void {
    config()->set('mail.default', 'log');

    $mail = collect(app(Diagnostics::class)->all())->firstWhere('name', 'Mail');

    expect($mail->warned())->toBeTrue()
        ->and($mail->remedy)->toContain('MAIL_MAILER');

    // Deliberately not a readiness probe: an orchestrator must not pull a working instance out of
    // rotation because nobody set up a relay.
    expect(collect(app(Diagnostics::class)->readiness())->pluck('name'))->not->toContain('Mail');
});

it('reports a failing transport without repeating what it was configured with', function (): void {
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('535 auth failed for postmaster@example.org with hunter2'));

    $response = $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertRedirect();

    $warning = session('warning');

    // A mail exception can carry the transport configuration, credentials included, and this response
    // is a web page. The class name is enough to tell somebody where to look; the command prints the
    // rest.
    expect($warning)->toContain('RuntimeException')
        ->and($warning)->not->toContain('hunter2')
        ->and($warning)->not->toContain('535 auth failed');

    expect(AuditEvent::query()->where('action', 'settings.mail.tested')->sole()->outcome)
        ->toBe(AuditEvent::OUTCOME_FAILURE);
});

it('keeps the test send to owners', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertForbidden();
});
