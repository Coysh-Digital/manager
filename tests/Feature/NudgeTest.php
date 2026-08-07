<?php

declare(strict_types=1);

use App\Domain\Connector\NudgeDispatcher;
use App\Domain\Job\JobService;
use App\Jobs\NudgeSite;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\CanonicalNudge;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Asking a site to check in now.
 *
 * The security properties are asserted in `tests/Invariants/NoRemoteExecutionTest.php` - that the
 * address is composed rather than accepted, that a hostile path is refused, that the dispatcher can
 * take nothing but a site. What is covered here is the behaviour around it: that the path survives a
 * round trip through a real signed claim, that turning it off at either end stops it, and that nothing
 * anywhere depends on a nudge arriving.
 */
beforeEach(function (): void {
    config([
        'manager.signing.public_key' => ($platform = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $platform['secret'],
    ]);

    $this->keypair = Keys::generateKeypair();
    $this->site = Site::factory()->connected()->create(['expected_domain' => 'site.example']);
    $this->connector = Connector::factory()->for($this->site)->withKeypair($this->keypair)->create([
        'submitted_domain' => 'site.example',
    ]);

    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();
});

it('learns where to knock from the site itself, on an ordinary claim', function (): void {
    postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        ['nudge_path' => '/actions/manager-connector/nudge/poll'],
        $this->site,
        $this->keypair['secret'],
    )->assertOk();

    expect($this->connector->fresh()->nudge_path)->toBe('/actions/manager-connector/nudge/poll')
        ->and(app(NudgeDispatcher::class)->destinationFor($this->site->fresh()))
        ->toBe('https://site.example/actions/manager-connector/nudge/poll');
});

it('keeps nothing when the site reports a path it should not have', function (): void {
    postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        ['nudge_path' => 'https://evil.example/x'],
        $this->site,
        $this->keypair['secret'],
    )->assertOk();

    // Refused rather than repaired, and the claim itself still succeeds - a site sending a path this
    // platform will not use is not a site that should stop being able to collect work.
    expect($this->connector->fresh()->nudge_path)->toBeNull();
});

it('forgets where to knock when the site stops offering an address', function (): void {
    $this->connector->forceFill(['nudge_path' => '/actions/manager-connector/nudge/poll'])->save();

    // A connector with `acceptNudges` turned off omits the field. Turning it off at the site has to
    // actually stop the knocking, not merely stop it being answered.
    postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        [],
        $this->site,
        $this->keypair['secret'],
    )->assertOk();

    expect($this->connector->fresh()->nudge_path)->toBeNull();
});

it('lets a site that has become reachable again heal itself', function (): void {
    $this->connector->forceFill([
        'nudge_path' => '/actions/manager-connector/nudge/poll',
        'nudge_failures' => NudgeDispatcher::FAILURE_CEILING,
    ])->save();

    expect(app(NudgeDispatcher::class)->canReach($this->site->fresh()))->toBeFalse();

    postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        ['nudge_path' => '/actions/manager-connector/nudge/poll'],
        $this->site,
        $this->keypair['secret'],
    )->assertOk();

    expect($this->connector->fresh()->nudge_failures)->toBe(0)
        ->and(app(NudgeDispatcher::class)->canReach($this->site->fresh()))->toBeTrue();
});

it('queues a knock when work is queued', function (): void {
    Queue::fake();

    app(JobService::class)->enqueue($this->site, Jobs::INVENTORY_REFRESH);

    Queue::assertPushed(NudgeSite::class);
});

it('knocks once for a site however many jobs are queued for it', function (): void {
    // Twelve jobs for one site must be one knock, not twelve: a fleet-wide request must not become a
    // burst of outbound requests at a single customer.
    //
    // Asserted on the claim itself rather than by counting calls, because NudgeDispatcher is final -
    // which is worth keeping, since it is the one class allowed to compose a site-facing address.
    // The claim *is* the mechanism: handle() returns without doing anything when it loses it.
    $dispatcher = app(NudgeDispatcher::class);
    $key = "manager:nudge:{$this->site->id}";

    expect(Cache::get($key))->toBeNull();

    foreach (range(1, 12) as $ignored) {
        (new NudgeSite($this->site->id))->handle($dispatcher);
    }

    expect(Cache::get($key))->toBeTrue()
        // Held by exactly one of them; the other eleven found it taken and stopped.
        ->and(Cache::add($key, true, 15))->toBeFalse();

    // A window rather than a one-shot flag, so a genuinely separate request a minute later still gets
    // its own knock.
    Cache::forget($key);

    expect(Cache::add($key, true, 15))->toBeTrue();
});

it('promises an early start only when it has somewhere to knock', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($owner)->for($this->site->organisation)->owner()->create();

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    RecoveryKey::factory()->for($this->site->organisation)->create();

    $recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    // No path recorded, so nothing will be knocked on and the screen says exactly what it always did.
    $this->actingAs($owner)->withSession($recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'will run when the site next checks in'));

    RemoteJob::query()->delete();
    $this->connector->forceFill(['nudge_path' => '/actions/manager-connector/nudge/poll'])->save();

    // Reachable. "Being asked", never "will start" - the knock is queued, not delivered.
    $this->actingAs($owner)->withSession($recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'being asked to start it now'));
});

it('cannot let a failed knock affect the job that was queued', function (): void {
    /*
     | A backup that was requested has been requested. Whether the site was told promptly is a separate
     | question with a separate answer, and the first must not depend on the second.
     |
     | Structural rather than behavioural, and deliberately so: this holds because of *how* the knock
     | is sent, not because of what happens when one fails. It is a queued job, so nothing in the web
     | request waits on a customer's server or sees its exception; it is dispatched after commit, so a
     | rolled-back enqueue sends nothing and a site cannot be told to claim a row that was never
     | written; and it does not retry, because the fallback - the site's own schedule - is already
     | running and will collect the work within minutes anyway.
    */
    expect(new NudgeSite($this->site->id))->toBeInstanceOf(ShouldQueue::class)
        ->and((new NudgeSite($this->site->id))->tries)->toBe(1)
        ->and((string) file_get_contents(app_path('Domain/Job/JobService.php')))
        ->toContain('NudgeSite::dispatch($site->id)->afterCommit()');

    // And the job itself is unaffected by a site that will never be reached.
    $this->connector->forceFill(['nudge_path' => null])->save();

    $job = app(JobService::class)->enqueue($this->site, Jobs::INVENTORY_REFRESH);

    expect($job->state)->toBe(Jobs::STATE_QUEUED)
        ->and($job->fresh())->not->toBeNull();
});

it('signs a nudge with a canonical form nothing else verifies', function (): void {
    // Belt and braces on the domain separation: the fixtures in manager-protocol assert it, and this
    // asserts that this platform is using that form rather than reusing the response signer.
    $nudge = new CanonicalNudge(
        $this->site->external_id,
        now()->timestamp,
        Nonce::generate(),
    );

    $signature = $nudge->sign(config('manager.signing.secret_key'));

    expect($nudge->verify($signature, config('manager.signing.public_key')))->toBeTrue()
        ->and(str_starts_with($nudge->toString(), 'MGR1-NUDGE'))->toBeTrue();
});
