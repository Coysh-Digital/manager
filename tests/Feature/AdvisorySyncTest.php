<?php

declare(strict_types=1);

use App\Models\SecurityAdvisory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Keeping the stored advisories in step with the feed.
 *
 * Nothing here touches the network. The thing worth proving is what happens when the feed misbehaves,
 * because every way it can misbehave looks, to a careless sync, like "there are no advisories left".
 */
beforeEach(function (): void {
    // A fresh client for each answer. Stubs on one stack in the order they were added, and the first
    // that matches wins, so a second fake() in the same test would be ignored.
    $this->feedAnswers = static function (PromiseInterface|Response|Closure $answer): void {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['packagist.org/*' => $answer]);
    };

    config(['manager.advisories.enabled' => true]);

    $this->entry = static fn (string $id, string $range = '>=5.0.0-RC1,<5.10.6', array $extra = []): array => $extra + [
        'advisoryId' => $id,
        'packageName' => 'craftcms/cms',
        'remoteId' => 'GHSA-'.$id,
        'title' => 'Not stored',
        'link' => 'https://github.com/advisories/GHSA-'.$id,
        'cve' => 'CVE-2026-0001',
        'affectedVersions' => $range,
        'severity' => 'high',
        'reportedAt' => '2026-08-06 10:00:00',
    ];

    $this->feedReturns = fn (array $entries) => ($this->feedAnswers)(
        Http::response(['advisories' => ['craftcms/cms' => $entries]]),
    );
});

it('stores what the feed lists, without the title or the description', function (): void {
    ($this->feedReturns)([($this->entry)('PKSA-1'), ($this->entry)('PKSA-2', '>=4.0.0-RC1,<4.18.2')]);

    $this->artisan('manager:advisories:sync')->expectsOutputToContain('2 advisories stored')->assertSuccessful();

    $row = SecurityAdvisory::query()->where('source_id', 'PKSA-1')->firstOrFail();

    expect(SecurityAdvisory::query()->count())->toBe(2)
        ->and($row->package)->toBe('craftcms/cms')
        ->and($row->advisory_id)->toBe('GHSA-PKSA-1')
        ->and($row->severity)->toBe('high')
        ->and($row->affected_versions)->toBe('>=5.0.0-RC1,<5.10.6')
        ->and(array_keys($row->getAttributes()))->not->toContain('title');
});

it('asks for the package by name and says nothing about the installation', function (): void {
    ($this->feedReturns)([($this->entry)('PKSA-1')]);

    $this->artisan('manager:advisories:sync')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'packages%5B%5D=craftcms%2Fcms')
        && $request->url() === 'https://packagist.org/api/security-advisories/?packages%5B%5D=craftcms%2Fcms'
        && ! $request->hasHeader('Authorization')
        && $request->header('User-Agent') === ['Manager advisory sync']);
});

it('updates an advisory the feed has revised and removes one it has withdrawn', function (): void {
    ($this->feedReturns)([($this->entry)('PKSA-1'), ($this->entry)('PKSA-2')]);
    $this->artisan('manager:advisories:sync')->assertSuccessful();

    ($this->feedReturns)([($this->entry)('PKSA-1', '>=5.0.0-RC1,<5.11.2', ['severity' => 'critical'])]);
    $this->artisan('manager:advisories:sync')->expectsOutputToContain('1 removed')->assertSuccessful();

    expect(SecurityAdvisory::query()->pluck('source_id')->all())->toBe(['PKSA-1'])
        ->and(SecurityAdvisory::query()->firstOrFail()->severity)->toBe('critical')
        ->and(SecurityAdvisory::query()->firstOrFail()->affected_versions)->toBe('>=5.0.0-RC1,<5.11.2');
});

it('leaves everything alone when the feed fails', function (): void {
    ($this->feedReturns)([($this->entry)('PKSA-1')]);
    $this->artisan('manager:advisories:sync')->assertSuccessful();

    ($this->feedAnswers)(Http::response('', 503));

    $this->artisan('manager:advisories:sync')
        ->expectsOutputToContain('What is stored is unchanged')
        ->assertFailed();

    expect(SecurityAdvisory::query()->count())->toBe(1);
});

it('leaves everything alone when the feed answers in a shape it does not recognise', function (mixed $body): void {
    ($this->feedReturns)([($this->entry)('PKSA-1')]);
    $this->artisan('manager:advisories:sync')->assertSuccessful();

    ($this->feedAnswers)(Http::response($body));

    $this->artisan('manager:advisories:sync')->assertFailed();

    expect(SecurityAdvisory::query()->count())->toBe(1);
})->with([
    'no advisories key' => [['error' => 'nope']],
    'advisories is not a map' => [['advisories' => 'none']],
    'package is not a list' => [['advisories' => ['craftcms/cms' => 'none']]],
]);

it('does not read an empty answer as "no advisories" and wipe what it holds', function (): void {
    ($this->feedReturns)([($this->entry)('PKSA-1'), ($this->entry)('PKSA-2')]);
    $this->artisan('manager:advisories:sync')->assertSuccessful();

    ($this->feedReturns)([]);
    $this->artisan('manager:advisories:sync')->assertSuccessful();

    expect(SecurityAdvisory::query()->count())->toBe(2);
});

it('drops an entry whose range it cannot read, and says so, but keeps the rest', function (): void {
    ($this->feedReturns)([
        ($this->entry)('PKSA-1'),
        ($this->entry)('PKSA-2', '^5.0 || garbage'),
        ['advisoryId' => '', 'affectedVersions' => '<1.0.0'],
        'not an array',
    ]);

    $this->artisan('manager:advisories:sync')
        ->expectsOutputToContain('3 skipped as unreadable')
        ->assertSuccessful();

    expect(SecurityAdvisory::query()->pluck('source_id')->all())->toBe(['PKSA-1']);
});

it('makes no request at all when it is switched off', function (): void {
    config(['manager.advisories.enabled' => false]);
    ($this->feedAnswers)(Http::response([]));

    $this->artisan('manager:advisories:sync')->expectsOutputToContain('switched off')->assertSuccessful();

    Http::assertNothingSent();
});

it('is on unless somebody turns it off', function (): void {
    expect(config('manager.advisories.enabled'))->toBeTrue();
});
