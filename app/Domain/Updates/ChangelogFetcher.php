<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

/**
 * Craft's own release notes, read here instead of in another tab.
 *
 * The original design held a link and never fetched anything, for a reason that still holds: release
 * notes describe in detail what a version fixes, and a database that knows *this site* is three
 * versions behind *these fixes* is a map of an exploitable installation. {@see ChangelogLink} says
 * so, and none of that has been softened.
 *
 * What makes this safe is that the fetch carries no such association. One request, for one public
 * file, made once per installation and cached - not per site, not per organisation, not per viewer.
 * The cache key is a constant. Nothing about which sites exist, which are behind, or who is looking
 * reaches the network, and the result is the same bytes whether an installation manages one site or
 * a hundred. It is closer to reading the documentation than to reporting an inventory.
 *
 * Only Craft, and it stays that way. Plugin notes arrive by a different route entirely - connectors
 * forward what their own Craft install already downloaded, and {@see PluginChangelog} serves them —
 * precisely so that this class never has to resolve a handle to a destination. Doing that would mean
 * asking a third party about every plugin in the fleet, which is the leak this class exists to avoid,
 * and it is why {@see SOURCES} is a constant map with nothing interpolated into it.
 */
final class ChangelogFetcher
{
    /**
     * Everything this class is allowed to ask for, in full, as constants.
     *
     * A map rather than a pattern, and no part of it is ever built from input. There is nothing here
     * for a caller to influence, so there is no request to forge.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'craft' => 'https://raw.githubusercontent.com/craftcms/cms/5.x/CHANGELOG.md',
    ];

    /**
     * Six hours. A changelog that is a few hours stale has cost nobody anything.
     */
    private const CACHE_SECONDS = 21600;

    /**
     * Craft's CHANGELOG.md is well over a megabyte and grows. Bounded so a redirect to something
     * else entirely cannot fill memory.
     */
    private const MAX_BYTES = 4194304;

    public function __construct(private readonly Client $client) {}

    public function enabled(): bool
    {
        return (bool) config('manager.updates.fetch_changelogs', true);
    }

    /**
     * The sections between the version a site is on and the one it could be on.
     *
     * Returns null when the fetch is switched off or did not work. A caller renders the link it
     * already had rather than an error: the notes were always one click away, and they still are.
     */
    public function between(string $source, ?string $current, ?string $latest): ?string
    {
        if (! $this->enabled() || ! isset(self::SOURCES[$source])) {
            return null;
        }

        $markdown = $this->fetch($source);

        if ($markdown === null) {
            return null;
        }

        // Rendered with HTML stripped rather than escaped, by the one component that does that for
        // every source of release notes here. See ChangelogMarkdown for why it is not done inline.
        return ChangelogMarkdown::render($this->sections($markdown, $current, $latest));
    }

    /**
     * The whole file, cached against a key that says nothing about this installation.
     */
    private function fetch(string $source): ?string
    {
        return Cache::remember(
            'updates.changelog.'.$source,
            self::CACHE_SECONDS,
            function () use ($source): ?string {
                try {
                    $response = $this->client->request('GET', self::SOURCES[$source], [
                        'timeout' => 8,
                        'connect_timeout' => 4,

                        // The destination is a constant, so a redirect can only be taking us
                        // somewhere that was not it.
                        'allow_redirects' => false,
                        'headers' => ['Accept' => 'text/plain'],
                        'http_errors' => false,
                    ]);

                    if ($response->getStatusCode() !== 200) {
                        return null;
                    }

                    return $response->getBody()->read(self::MAX_BYTES);
                } catch (GuzzleException) {
                    // An installation with no outbound access is a supported way to run this, not a
                    // fault. The screen falls back to the link.
                    return null;
                }
            }
        );
    }

    /**
     * Split on version headings and keep the ones newer than what is installed.
     *
     * @return list<string>
     */
    private function sections(string $markdown, ?string $current, ?string $latest): array
    {
        // "## 5.8.1 - 2026-07-14", and anything else that opens with a version at heading level two.
        $parts = preg_split('/^##\s+(?=\d+\.\d+)/m', $markdown, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        $sections = [];

        foreach ($parts as $part) {
            if (preg_match('/^(\d+\.\d+[^\s]*)/', $part, $matches) !== 1) {
                continue;
            }

            if (! ChangelogMarkdown::isBetween($matches[1], $current, $latest)) {
                continue;
            }

            $sections[] = '## '.rtrim($part);

            if (count($sections) >= ChangelogMarkdown::MAX_SECTIONS) {
                break;
            }
        }

        return $sections;
    }
}
