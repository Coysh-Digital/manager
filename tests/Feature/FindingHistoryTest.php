<?php

declare(strict_types=1);

use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;

/*
 * What happened to a finding after somebody looked at it.
 *
 * Two places answer that. The Security tab keeps resolved findings with who acknowledged them and
 * why, and the audit log - the site's, and the fleet-wide Activity screen that shares its row
 * component - says which finding an event was about and what reason was given. Both exist because
 * a closed finding used to be a title and a time, and the comment a person typed was recorded and
 * then shown nowhere.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('security:read')->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

/**
 * @param  array<string, mixed>  $attributes
 */
function resolvedFinding(Site $site, string $title, int $minutesAgo = 0, array $attributes = []): Finding
{
    return Finding::factory()->for($site)->resolved()->create([
        'rule' => 'rule_'.str_replace(' ', '_', strtolower($title)),
        'title' => $title,
        'resolved_at' => now()->subMinutes($minutesAgo),
    ] + $attributes);
}

// --------------------------------------------------------------------------------------------------
// Security tab
// --------------------------------------------------------------------------------------------------

it('shows who acknowledged a resolved finding, and why', function (): void {
    resolvedFinding($this->site, 'Certificate was renewed', attributes: [
        'acknowledged_at' => now()->subDays(3),
        'acknowledged_label' => 'Tim Coysh',
        'acknowledgement_reason' => 'Renews itself on the 1st',
    ]);

    $this->actingAs($this->owner)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('Certificate was renewed')
        ->assertSee('Tim Coysh')
        ->assertSee('Renews itself on the 1st');
});

it('escapes an acknowledgement reason on the Security tab', function (): void {
    resolvedFinding($this->site, 'Certificate was renewed', attributes: [
        'acknowledged_at' => now(),
        'acknowledged_label' => 'Tim Coysh',
        'acknowledgement_reason' => '<script>alert(1)</script>',
    ]);

    $this->actingAs($this->owner)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('keeps the short tail by default and offers the whole history', function (): void {
    foreach (range(1, 7) as $n) {
        resolvedFinding($this->site, "Closed finding {$n}", minutesAgo: $n);
    }

    $this->actingAs($this->owner)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('Recently resolved')
        ->assertSee('Closed finding 5')
        ->assertDontSee('Closed finding 6')
        ->assertSee('Show all 7')
        ->assertSee('history=all', false);
});

it('does not offer the history when the tail already shows everything', function (): void {
    resolvedFinding($this->site, 'Closed finding 1');

    $this->actingAs($this->owner)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('Closed finding 1')
        ->assertDontSee('Show all');
});

it('pages through every resolved finding with history=all', function (): void {
    foreach (range(1, 30) as $n) {
        resolvedFinding($this->site, sprintf('Closed finding %02d', $n), minutesAgo: $n);
    }

    // Newest first, 25 to a page: 01 is the most recent, so 26 onwards is the second page.
    $this->actingAs($this->owner)
        ->get(route('sites.security', [$this->site, 'history' => 'all']))
        ->assertOk()
        ->assertSee('Resolved findings')
        ->assertSee('Show recent only')
        ->assertSee('Closed finding 01')
        ->assertSee('Closed finding 25')
        ->assertDontSee('Closed finding 26')
        ->assertSee('history=all&amp;page=2', false);

    $this->actingAs($this->owner)
        ->get(route('sites.security', [$this->site, 'history' => 'all', 'page' => 2]))
        ->assertOk()
        ->assertSee('Closed finding 26')
        ->assertSee('Closed finding 30')
        ->assertDontSee('Closed finding 25');
});

it('lists only this site\'s resolved findings, whatever the organisation', function (): void {
    $other = Organisation::factory()->create(['name' => 'Someone Else']);
    $theirs = Site::factory()->for($other)->connected()->create();
    $sibling = Site::factory()->for($this->organisation)->connected()->create();

    resolvedFinding($theirs, 'Their private closure', attributes: [
        'acknowledged_at' => now(),
        'acknowledged_label' => 'Mallory',
        'acknowledgement_reason' => 'Their private reason',
    ]);
    resolvedFinding($sibling, 'Sibling site closure');

    foreach ([[], ['history' => 'all']] as $query) {
        $this->actingAs($this->owner)
            ->get(route('sites.security', [$this->site] + $query))
            ->assertOk()
            ->assertDontSee('Their private closure')
            ->assertDontSee('Their private reason')
            ->assertDontSee('Sibling site closure');
    }
});

it('refuses another organisation\'s site history', function (): void {
    $theirs = Site::factory()->for(Organisation::factory()->create())->connected()->create();

    $this->actingAs($this->owner)
        ->get(route('sites.security', [$theirs, 'history' => 'all']))
        ->assertNotFound();
});

// --------------------------------------------------------------------------------------------------
// Audit log
// --------------------------------------------------------------------------------------------------

it('shows the finding and the reason on the site audit log', function (): void {
    $finding = Finding::factory()->for($this->site)->create(['title' => 'Development mode is on in production']);

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $finding), ['reason' => 'Staging box, deliberate'])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.reopen', $finding->fresh()))
        ->assertRedirect();

    $event = AuditEvent::query()->where('action', 'finding.acknowledged')->firstOrFail();

    expect($event->after['title'])->toBe('Development mode is on in production')
        ->and(AuditEvent::query()->where('action', 'finding.reopened')->firstOrFail()->after['title'])
        ->toBe('Development mode is on in production');

    $this->actingAs($this->owner)
        ->get(route('sites.audit', $this->site))
        ->assertOk()
        ->assertSee('finding.acknowledged')
        ->assertSee('Development mode is on in production')
        ->assertSee('Staging box, deliberate');
});

it('shows the reason on the fleet activity log too', function (): void {
    app(AuditRecorder::class)->record(
        action: 'finding.acknowledged',
        site: $this->site,
        actor: $this->owner,
        targetType: 'finding',
        targetId: 'dev_mode_in_production',
        after: ['state' => 'acknowledged', 'title' => 'Development mode is on in production', 'reason' => 'Staging box, deliberate'],
    );

    $this->actingAs($this->owner)
        ->get('/activity')
        ->assertOk()
        ->assertSee('Development mode is on in production')
        ->assertSee('Staging box, deliberate');
});

it('escapes the reason and the title in the audit log', function (): void {
    app(AuditRecorder::class)->record(
        action: 'finding.acknowledged',
        site: $this->site,
        actor: $this->owner,
        targetType: 'finding',
        targetId: 'dev_mode_in_production',
        after: ['state' => 'acknowledged', 'title' => '<b>bold title</b>', 'reason' => '<script>alert(1)</script>'],
    );

    foreach ([route('sites.audit', $this->site), '/activity'] as $url) {
        $this->actingAs($this->owner)
            ->get($url)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>bold title</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;bold title&lt;/b&gt;', false);
    }
});

it('falls back to the rule key for events written before the title was recorded', function (): void {
    app(AuditRecorder::class)->record(
        action: 'finding.resolved',
        site: $this->site,
        actorType: AuditEvent::ACTOR_SYSTEM,
        actorLabel: 'Findings',
        targetType: 'finding',
        targetId: 'dev_mode_in_production',
        before: ['state' => 'open', 'severity' => 'high'],
        after: ['state' => 'resolved'],
    );

    $this->actingAs($this->owner)
        ->get(route('sites.audit', $this->site))
        ->assertOk()
        ->assertSee('finding.resolved')
        ->assertSee('dev_mode_in_production');
});

it('does not show another organisation\'s finding events', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create();

    app(AuditRecorder::class)->record(
        action: 'finding.acknowledged',
        site: $theirs,
        actorType: AuditEvent::ACTOR_SYSTEM,
        targetType: 'finding',
        targetId: 'dev_mode_in_production',
        after: ['state' => 'acknowledged', 'title' => 'Their finding title', 'reason' => 'Their private reason'],
    );

    foreach ([route('sites.audit', $this->site), '/activity'] as $url) {
        $this->actingAs($this->owner)
            ->get($url)
            ->assertOk()
            ->assertDontSee('Their finding title')
            ->assertDontSee('Their private reason');
    }
});

it('leaves the audit chain verifiable with the title in the payload', function (): void {
    $finding = Finding::factory()->for($this->site)->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post(route('findings.acknowledge', $finding), ['reason' => 'Staging box, deliberate']);

    $result = app(AuditChainVerifier::class)->verify($this->organisation->id);

    expect($result->isIntact())->toBeTrue()
        ->and($result->eventsChecked)->toBeGreaterThan(0);
});
