<?php

declare(strict_types=1);

use App\Contracts\MailAdministration;
use App\Contracts\ServerAccess;
use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;
use App\Models\BackupArtifact;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\Site;
use App\Models\User;

/**
 * What a hosted customer is told to do.
 *
 * Reported from use: the Backups screen tells the reader to run `php artisan manager:backups:fetch`
 * on the server. On a hosted edition that server belongs to whoever runs the service, so the
 * instruction reads as the answer to "how do I get my backup out" while being impossible to follow.
 * Two more screens said the same thing about `manager:audit:verify`, and Settings about
 * `manager:doctor`.
 *
 * This is the second time. {@see MailAdministration} exists because paying customers
 * were shown a paragraph telling them to edit `MAIL_*` in a `.env` file they cannot reach, and
 * both repositories were green throughout.
 *
 * Every case here binds the hosted answer explicitly, because the default in this repository is the
 * self-hosted one and always will be — the Cloud implementation lives in the private overlay, so
 * this file is the only place the core can see the other side of the seam at all.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    RecoveryKey::factory()->for($this->organisation)->create();

    BackupArtifact::factory()->for($this->site)->create(['organisation_id' => $this->organisation->id]);
});

function hosted(): void
{
    app()->instance(ServerAccess::class, new class implements ServerAccess
    {
        public function reachable(): bool
        {
            return false;
        }
    });
}

it('does not tell a hosted customer to run a command on a server they have no account on', function (
    string $path,
    string $command
): void {
    hosted();

    $this->actingAs($this->owner)->get($path)
        ->assertOk()
        ->assertDontSee($command);
})->with([
    ['/backups', 'manager:backups:fetch'],
    ['/settings', 'manager:doctor'],
    ['/activity', 'manager:audit:verify'],
]);

it('does not tell a hosted customer to run a command on a site screen either', function (
    string $suffix,
    string $command
): void {
    hosted();

    $this->actingAs($this->owner)->get("/sites/{$this->site->external_id}{$suffix}")
        ->assertOk()
        ->assertDontSee($command);
})->with([
    ['/backups', 'manager:backups:fetch'],
    ['/audit', 'manager:audit:verify'],
]);

it('still says what a hosted customer can do about a backup only we can open', function (): void {
    // Removing the command must not remove the answer. These artifacts predate the customer's first
    // recovery key, so no key of theirs opens one, and the honest answer is that we produce it.
    hosted();

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('ask us and we will produce');
});

it('keeps the claim about the audit chain when it drops the command', function (): void {
    // The command was evidence for a claim, not the claim itself. An append-only chain is still
    // append-only on a hosted edition, and dropping the sentence with the command would quietly
    // withdraw a security property rather than an instruction.
    hosted();

    $this->actingAs($this->owner)->get('/activity')
        ->assertOk()
        ->assertSee('any alteration is detectable');
});

it('leaves the command in place on a self-hosted installation', function (): void {
    /*
     * The other half, and the one that keeps this from being solved by deletion. Self-hosted, the
     * command is the better instruction: `manager:backups:fetch` streams, decrypts and verifies in
     * one pass with no request timing out underneath it, which no download button can promise.
     *
     * No binding here — this is the default the repository ships with.
     */
    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('manager:backups:fetch');

    $this->actingAs($this->owner)->get('/activity')
        ->assertOk()
        ->assertSee('manager:audit:verify');
});

it('never hides an instruction for the customer\'s own Craft site', function (): void {
    // The seam is about the machine Manager runs on, and nothing else. A hosted customer still owns
    // the sites being managed, still has a shell on them, and pairing is still a command they run.
    hosted();

    // The empty state is where that instruction lives, so this test needs the fleet empty.
    BackupArtifact::query()->delete();
    Site::query()->delete();

    $this->actingAs($this->owner)->get('/sites')
        ->assertOk()
        ->assertSee('command line');
});

/*
 | The health panel
 |-------------------------------------------------------------------------------------------------
 |
 | Reported from use: most of the pass/fail list on Settings is not relevant to a customer of the
 | hosted edition. It is not — almost every check is about the machine, and a red row somebody
 | cannot act on invites a support ticket whose answer is "yes, we know, that one is ours".
 */

it('shows a hosted customer only the checks about their own data', function (): void {
    hosted();

    $response = $this->actingAs($this->owner)->get('/settings')->assertOk();

    // What survives: the reader's own audit log, and what may be backed up.
    $response->assertSee('Audit log protection')
        ->assertSee('Backup size ceiling');

    // What does not: the machine.
    $response->assertDontSee('Application key')
        ->assertDontSee('Database role')
        ->assertDontSee('Replay-protection store')
        ->assertDontSee('Session cookie')
        ->assertDontSee('Migrations')
        ->assertDontSee('Debug mode');
});

it('says why the rest is missing rather than leaving a short list unexplained', function (): void {
    hosted();

    $this->actingAs($this->owner)->get('/settings')
        ->assertOk()
        ->assertSee('are ours to watch and are not shown here');
});

it('leaves the whole panel in place for somebody who runs the thing', function (): void {
    $this->actingAs($this->owner)->get('/settings')
        ->assertOk()
        ->assertSee('Platform health')
        ->assertSee('Application key')
        ->assertSee('Audit log protection');
});

it('keeps every check for the operator, whatever the screen shows', function (): void {
    /*
     | The filter is on the reading, not on the checking. `manager:doctor` and the back-office are
     | for the operator, who is exactly the person the hidden rows are for — a hosted deployment
     | that stopped reporting its own database role to us would be the filter doing real damage.
    */
    hosted();

    $everything = app(Diagnostics::class)->all();
    $shown = app(Diagnostics::class)->forReader();

    $names = array_map(fn (Check $check): string => $check->name, $everything);

    expect($names)->toContain('Application key')
        ->and($names)->toContain('Database role')
        ->and(count($shown))->toBeLessThan(count($everything));
});

it('treats a new check as the operator\'s until somebody says otherwise', function (): void {
    // The default matters more than the two exceptions: forgetting to think about the audience
    // hides a row from a customer, which is recoverable, rather than showing them one about a
    // machine they do not run.
    expect(Check::pass('Something new', 'Fine.')->isForOperator())->toBeTrue()
        ->and(Check::pass('Something new', 'Fine.')->forEveryone()->isForOperator())->toBeFalse();
});
