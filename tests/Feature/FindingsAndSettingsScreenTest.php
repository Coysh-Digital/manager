<?php

declare(strict_types=1);

use App\Contracts\BillingAdministration;
use App\Contracts\MailAdministration;
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
    // the first server's percentage - reported from a live fleet, where it read as one site
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
        // A rule is skipped, not passed, when its capability is missing - so "no findings" needs that
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

it('says which version it is running, and where to read what changed', function (): void {
    // "Which version are you on?" was unanswerable from the interface: nothing in the application
    // read a version at all, so the only way to tell was to ask whoever deployed it.
    config()->set('manager.version', '1.2.0');

    $this->actingAs($this->owner)
        ->get('/settings')
        ->assertOk()
        ->assertSee('1.2.0')
        ->assertSee('Changelog')
        ->assertSee('https://github.com/Coysh-Digital/manager/blob/main/CHANGELOG.md', escape: false);
});

it('does not invent a version it has no way to know', function (): void {
    /*
     | The normal state, and the reason this is nullable rather than defaulted to something
     | plausible. The release tarball is produced by `git archive`, so it carries no `.git`; the
     | Docker image sets MANAGER_VERSION from its build argument and a clone sets nothing. A number
     | guessed here is a number somebody quotes back in a support conversation.
    */
    config()->set('manager.version', null);

    $this->actingAs($this->owner)
        ->get('/settings')
        ->assertOk()
        ->assertSee('unreleased build')
        // The changelog is still worth reaching, whatever the installation can say about itself.
        ->assertSee('Changelog');
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
 | Mail has a screen of its own now, and the tests that belong to it live in MailSettingsTest. What
 | stays here is what this screen still has to be true about: that the General tab is not that place,
 | and that the health check reports mail without configuring it.
 */

it('sends a test message to the owner and nobody else', function (): void {
    // Asserted against the callback rather than through Mail::fake(). Mail::raw() builds no Mailable,
    // so there is nothing for assertSent() to inspect - the same limitation EmailTransport documents,
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

it('keeps mail configuration off the general settings screen', function (): void {
    /*
     | This used to assert that mail configuration appeared nowhere in the interface at all, on the
     | reasoning that whoever can reach Settings is not necessarily whoever holds the relay's
     | credentials. That reasoning was right; the conclusion was not, because the alternative it left
     | was a shell on the server - and the one thing that cannot be used to tell somebody their mail
     | is broken is email.
     |
     | So the rule became a permission rather than an absence: there is a Mail screen, it is
     | owner-only and self-hosted-only, and the credential is write-only. What survives unchanged is
     | that *this* screen is not that place. Its reader is any member.
     */
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.internal.example');
    config()->set('mail.mailers.smtp.username', 'postmaster@example.org');
    config()->set('mail.mailers.smtp.password', 'hunter2');
    config()->set('mail.from.address', 'manager@example.org');

    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    expect($html)->not->toContain('smtp.internal.example')
        ->and($html)->not->toContain('postmaster@example.org')
        ->and($html)->not->toContain('hunter2')
        ->and($html)->not->toContain('manager@example.org')
        ->and($html)->not->toContain('Send a test email')
        // The health check still answers the question this screen exists to answer: will a password
        // reset arrive. It just no longer carries the controls.
        ->and($html)->toContain('Mail');
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

function hostedRelay(): void
{
    app()->bind(MailAdministration::class, fn (): MailAdministration => new class implements MailAdministration
    {
        public function operatorManaged(): bool
        {
            return false;
        }
    });
}

it('keeps the test send to owners', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create();

    $this->actingAs($member)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertForbidden();
});

it('offers no Mail tab on an edition that does not administer its own mail', function (): void {
    /*
     | The screen configures *this* server's relay, which is a useful thing for whoever owns the
     | server and a meaningless one for everybody else. On a hosted edition the relay belongs to
     | whoever runs the service; an administrator cannot change it, and a form that looked as though
     | they could would be worse than no form.
     |
     | Bound rather than configured, like every other seam: see App\Contracts\MailAdministration.
    */
    hostedRelay();

    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    expect($html)->not->toContain('settings/mail')
        // The health check still reports whether mail works; it just stops naming a screen that is
        // not there.
        ->and($html)->toContain('Mail')
        ->and($html)->not->toContain('Send a test from Settings');
});

it('refuses every mail route as well as hiding the tab', function (): void {
    // Hiding a control is not removing it. A route that still works when its control has gone is how
    // a removed feature comes back by URL.
    hostedRelay();

    $this->actingAs($this->owner)->get(route('settings.mail'))->assertNotFound();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('settings.mail.test'))->assertNotFound();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 587,
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertNotFound();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete(route('settings.mail.forget'))->assertNotFound();
});

/*
 | Billing, and the fact that it does not exist here.
 |
 | The problem these cover is not that billing was broken on the editions that have it. It worked.
 | It was reachable from a couple of emails and from nowhere inside the application, so somebody who
 | wanted to sort out payment before a trial ran out had to know the URL. The fix is a link, and a
 | link is the kind of thing that gets moved, wrapped in the wrong condition, or quietly dropped in
 | a refactor - so it gets tests rather than a comment.
 */

/** A hosted edition's answer: somewhere to go. */
function billingAt(string $url): void
{
    app()->bind(BillingAdministration::class, fn (): BillingAdministration => new class($url) implements BillingAdministration
    {
        public function __construct(private readonly string $url) {}

        public function url(): ?string
        {
            return $this->url;
        }
    });
}

it('shows an owner the way to billing when somebody is billing them', function (): void {
    billingAt('https://console.example.org/billing');

    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    // Both entry points, because they answer different questions. The sidebar is where somebody
    // goes looking for billing; the Settings block is where they end up when they are already
    // administering the organisation.
    expect(substr_count($html, 'https://console.example.org/billing'))->toBe(2)
        ->and($html)->toContain('Manage billing');
});

it('tells nobody what it costs', function (): void {
    // The core cannot know, and a figure it invented would be wrong somewhere it could not see.
    billingAt('https://console.example.org/billing');

    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    expect($html)->not->toContain('£')
        ->and($html)->not->toContain('Cloud')
        ->and($html)->not->toContain('per month');
});

it('keeps billing away from everybody who is not an owner', function (): void {
    // Only owners hear about money. An admin can administer the organisation without being the
    // person whose card pays for it.
    billingAt('https://console.example.org/billing');

    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->create([
        'role' => Membership::ROLE_ADMIN,
    ]);

    $html = $this->actingAs($member)->get(route('settings.show'))->assertOk()->getContent();

    // The sidebar entry is not owner-gated - it is the way to a page that decides for itself who may
    // see what - but the Settings block, which sits among this screen's owner-only controls, is.
    expect($html)->not->toContain('Manage billing');
});

it('offers no billing at all on an installation nobody bills', function (): void {
    /*
     | The self-hosted default, and the assertion most worth having.
     |
     | Nothing is bound here, so the seam answers null exactly as it does on a real self-hosted
     | install. There is no subscription, no card and no allowance to buy more of, so there is
     | nothing to link to - and the link disappears rather than becoming a page that explains why it
     | does not apply.
     |
     | This is the one that catches somebody dropping the condition around a link that is already
     | there: every hosted-edition test above would still pass.
    */
    $html = $this->actingAs($this->owner)->get(route('settings.show'))->assertOk()->getContent();

    expect($html)->not->toContain('Manage billing')
        ->and($html)->not->toContain('/billing')
        ->and($html)->not->toContain('>Billing<');
});
