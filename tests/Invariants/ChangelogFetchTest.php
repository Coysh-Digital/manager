<?php

declare(strict_types=1);

use App\Domain\Updates\ChangelogFetcher;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * The one outbound request this application makes that is not a notification.
 *
 * Reading Craft's changelog is safe for a narrow and checkable reason, and this file is the check.
 * The danger was never the fetch - it is that a request made *because a particular site is behind*
 * carries that fact to whoever answers it, and enough of those describe a fleet. So:
 *
 *  - the destination is a constant, not something assembled from a report;
 *  - the cache key is a constant, so the request is made once per installation and its result is
 *    the same bytes whether that installation manages one site or a hundred;
 *  - an operator can switch it off, because an installation with no outbound access is a supported
 *    way to run this rather than a broken one.
 *
 * If any of those stops holding, the privacy argument in ChangelogLink's docblock stops holding with
 * it, and that argument is the reason release notes were kept out of this database in the first
 * place.
 */
it('will only ever ask for a destination written in this repository', function (): void {
    foreach (ChangelogFetcher::SOURCES as $source => $url) {
        expect($url)->toStartWith('https://')
            ->and(parse_url($url, PHP_URL_HOST))->toBeIn(['raw.githubusercontent.com', 'github.com'])
            // No interpolation. A destination with a placeholder in it is a destination something
            // else decides, and something else is a site's report.
            ->and($url)->not->toContain('{')
            ->and($url)->not->toContain('$');

        expect($source)->toMatch('/^[a-z]+$/');
    }
});

it('names no site in the request it makes or the key it caches under', function (): void {
    // Written against the fetcher's own behaviour rather than its source, so a refactor that starts
    // keying the cache per site fails here rather than passing on a comment.
    config()->set('manager.updates.fetch_changelogs', true);

    $keys = [];

    Cache::shouldReceive('remember')
        ->andReturnUsing(function (string $key) use (&$keys): ?string {
            $keys[] = $key;

            return null;
        });

    (new ChangelogFetcher(new Client))->between('craft', '5.6.0', '5.8.0');

    expect($keys)->toBe(['updates.changelog.craft']);
});

it('can be switched off, and then asks for nothing at all', function (): void {
    config()->set('manager.updates.fetch_changelogs', false);

    Cache::shouldReceive('remember')->never();

    $fetcher = new ChangelogFetcher(new Client);

    expect($fetcher->enabled())->toBeFalse()
        ->and($fetcher->between('craft', '5.6.0', '5.8.0'))->toBeNull();
});

it('refuses a source it was not given', function (): void {
    config()->set('manager.updates.fetch_changelogs', true);

    Cache::shouldReceive('remember')->never();

    // Plugins deliberately have no entry. If one is ever added it has to be a constant in SOURCES,
    // which is the point at which somebody has to decide whether asking a third party about every
    // plugin in the fleet is acceptable.
    expect((new ChangelogFetcher(new Client))->between('seomatic', '4.0.1', '4.0.9'))
        ->toBeNull();
});
