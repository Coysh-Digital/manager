<?php

declare(strict_types=1);

use App\Models\BackupArtifact;
use App\Models\Heartbeat;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Support\Facades\DB;

/*
 * The Backup and Reporting columns on the fleet screen.
 *
 * Two properties matter more than the rendering. The first is that neither column costs a query per
 * row: this screen is the landing page, it is the one somebody leaves open, and a fleet of two
 * hundred is the size at which "one more query" stops being free. The second is that both columns
 * decline to answer when they have nothing to answer with — a site that has never backed up must not
 * read as recent, and a site paired ten minutes ago must not read as 100% available, because a
 * confident wrong number on a dashboard is worse than a dash.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Acme Ltd']);
});

it('shows when a site was last backed up', function (): void {
    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'taken_at' => now()->subHours(3),
    ]);

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('3h ago');
});

it('reads the last stored backup, not the last attempted one', function (): void {
    // A pending artifact is a backup in flight, and a failed one never became a restorable copy.
    // Either would be the wrong answer to "when could I last restore this".
    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'taken_at' => now()->subDays(4),
    ]);

    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_PENDING,
        'taken_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('4d ago');
});

it('says nothing rather than something reassuring when a site has never backed up', function (): void {
    $html = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($html)->not->toContain('ago</span>');
});

it('surfaces a backup that failed without ever declaring an artifact', function (): void {
    /*
     * The case this column exists for. A backup refused before it declared an artifact leaves no
     * backup_artifacts row at all — the whole record is a remote_jobs failure. A column reading only
     * the artifacts would report a fleet as fine on the morning it stopped backing up.
     */
    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'destination_unreachable',
    ]);

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('Failed');
});

it('calls the availability figure Reporting rather than Uptime', function (): void {
    /*
     * Asserted rather than left to the template, because "Uptime" is the word somebody will reach
     * for the next time this column is touched and it is the one claim this product does not make:
     * Manager never calls out to a site, so this measures whether the connector spoke to us. The
     * site's own Health tab has said Reporting since it was built.
     */
    $html = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($html)->toContain('Reporting')
        ->and($html)->not->toContain('Uptime');
});

it('refuses to print a percentage from a single heartbeat', function (): void {
    // One check-in is not a record. Without this a site paired ten minutes ago reads a confident
    // 100%, which is the number somebody would act on and the one we are least entitled to print.
    Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes(2)]);

    $html = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($html)->not->toContain('100%');
});

it('reports a site that has been checking in steadily', function (): void {
    for ($minutes = 0; $minutes <= 120; $minutes += 5) {
        Heartbeat::factory()->for($this->site)->create(['received_at' => now()->subMinutes($minutes)]);
    }

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('100%');
});

it('stays a handful of queries however many sites are listed', function (): void {
    /*
     * The property both new columns had to be built around, and the one nothing on this screen
     * asserted before. The obvious implementation of either — SiteUptime::for() inside the loop, or
     * $site->backupArtifacts()->latest() — is a query per row: invisible on the fleet of one this
     * suite usually builds, and painful on the fleet this screen is for.
     *
     * The budget is deliberately generous. It is not there to pin the exact number, which would fail
     * on any unrelated change; it is there to fail loudly if either column becomes O(sites).
     */
    for ($i = 0; $i < 25; $i++) {
        $site = Site::factory()->for($this->organisation)->connected()->create();

        BackupArtifact::factory()->for($site)->create([
            'organisation_id' => $this->organisation->id,
            'state' => BackupArtifact::STATE_STORED,
            'taken_at' => now()->subHours($i + 1),
        ]);

        Heartbeat::factory()->for($site)->create(['received_at' => now()->subMinutes(5)]);
        Heartbeat::factory()->for($site)->create(['received_at' => now()->subMinutes(10)]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($this->user)->get(route('sites.index'))->assertOk();

    expect($queries)->toBeLessThanOrEqual(20);
});

it('sorts the least trustworthy backups to the top', function (): void {
    $recent = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Recent Ltd']);
    BackupArtifact::factory()->for($recent)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'taken_at' => now()->subHour(),
    ]);

    $broken = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Broken Ltd']);
    RemoteJob::factory()->for($broken)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'destination_unreachable',
    ]);

    $html = $this->actingAs($this->user)->get(route('sites.index', ['sort' => 'backup']))
        ->assertOk()
        ->getContent();

    // A failure outranks any date: a site that failed this morning is a worse position than one
    // whose last good copy is old and still succeeding.
    expect(strpos($html, 'Broken Ltd'))->toBeLessThan(strpos($html, 'Recent Ltd'));
});

/*
 * The Updates column.
 *
 * The count and the security flag are mirrored onto the `sites` row by UpdatesIngestService, so this
 * column is free — and the query budget above is what keeps it that way. What it has to get right is
 * the distinction the number alone cannot carry: one security release is more urgent than nine
 * ordinary updates, and a column that sorted or coloured purely by count would say the opposite.
 */

it('shows how far behind each site is', function (): void {
    $this->site->forceFill(['available_updates' => 9, 'has_security_release' => false])->save();

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('9 updates');
});

it('says one update rather than one updates', function (): void {
    $this->site->forceFill(['available_updates' => 1, 'has_security_release' => false])->save();

    $this->actingAs($this->user)->get(route('sites.index'))
        ->assertOk()
        ->assertSee('1 update')
        ->assertDontSee('1 updates');
});

it('draws nothing at all for a site with nothing to do', function (): void {
    // Not a zero. "Nothing to do" is the resting state of a fleet being kept up to date, and a badge
    // down every row for it would bury the rows that need something.
    $this->site->forceFill(['available_updates' => 0, 'has_security_release' => false])->save();

    $html = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($html)->not->toContain('0 updates');
});

it('gives a security release the amber treatment and an ordinary update the quiet one', function (): void {
    /*
     * Matched as a badge rather than by looking for the class anywhere on the page. The sidebar's
     * own update badge goes amber on exactly the same condition, so a bare toContain('amber') here
     * would pass whether or not this column had ever been written.
     */
    $badge = static fn (string $tone, string $label): string => '/border-'.$tone.'-line[^>]*+>.{0,200}?'.preg_quote($label, '/').'/s';

    $this->site->forceFill(['available_updates' => 1, 'has_security_release' => true])->save();

    $urgent = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    $this->site->forceFill(['has_security_release' => false])->save();

    $ordinary = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($urgent)->toMatch($badge('amber', '1 update'))
        ->and($ordinary)->toMatch($badge('info', '1 update'))
        ->and($ordinary)->not->toMatch($badge('amber', '1 update'));
});

it('sorts a security release above a larger ordinary backlog', function (): void {
    $this->site->forceFill(['available_updates' => 12, 'has_security_release' => false])->save();

    $urgent = Site::factory()->for($this->organisation)->connected()->create([
        'name' => 'Urgent Ltd',
        'available_updates' => 1,
        'has_security_release' => true,
    ]);

    $html = $this->actingAs($this->user)->get(route('sites.index', ['sort' => 'updates']))
        ->assertOk()
        ->getContent();

    expect(strpos($html, $urgent->name))->toBeLessThan(strpos($html, $this->site->name));
});

it('reads a successful backup as a badge rather than as bare text', function (): void {
    // The column carried a red badge for a failure and plain text for a success, so the rows that
    // were fine looked like an absence of information rather than the answer.
    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'taken_at' => now()->subHours(4),
    ]);

    $html = $this->actingAs($this->user)->get(route('sites.index'))->assertOk()->getContent();

    expect($html)->toContain('border-ok-line')->and($html)->toContain('4h ago');
});
