<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;
use App\Domain\Security\SiteProbe;
use App\Models\ProbeReport;

/**
 * A file that should never have been reachable, answering over the web.
 *
 * The most serious thing this platform can tell somebody, and the one with the shortest distance
 * between the finding and the consequence. A readable `.env` is not a weakness that might be
 * exploited later; it is the database password, the mail credentials and every API key the site
 * holds, published, to anybody who asks. There is no exploit to write.
 *
 * ## Why this needs a control, and what happens without one
 *
 * The check behind it asks whether a path answers 200. On most sites that is exactly what it sounds
 * like. On a site with a catch-all route, a single-page front end, or a proxy that falls back to the
 * application for anything it does not recognise, *every* path answers 200 - and asking whether
 * `/.env` does gets a yes that means nothing at all.
 *
 * A rule that read those results directly would send its loudest possible finding, naming a
 * customer's site and their credentials, to sites whose only fault is permissive routing. That is
 * not a false positive somebody shrugs at: it is an emergency call at nine at night about nothing,
 * and it is the sort of thing that gets a monitoring product turned off.
 *
 * So {@see SiteProbe} asks for a path that certainly does not exist, and
 * {@see ProbeReport::exposureIsConclusive()} is the gate. This rule reads
 * `exposedPaths()`, which returns nothing at all unless that control came back clean. **It is
 * deliberately impossible to read the raw list from here.**
 *
 * ## Severity
 *
 * Critical for credentials and databases, high for the rest, and the split is about whether the file
 * *is* the harm or merely helps somebody find it:
 *
 *  - `.env` is every secret the site has, in one request.
 *  - A database dump is the customer's data, and their customers' data, to anybody who guesses a
 *    filename - which is not guessing, because these are the filenames everybody uses.
 *  - `.git/config` answering means the repository directory is served, which usually means the
 *    entire history is downloadable, which usually contains the credentials that were committed
 *    before somebody knew better.
 *  - `composer.lock` is not itself a leak. It is the exact version of every dependency: a shopping
 *    list of published vulnerabilities, with no reconnaissance required.
 *
 * One finding covering all of them, because a site with `.env` in its webroot usually has the rest
 * of them there too, and it is one misconfigured document root rather than four problems.
 */
final class SensitiveFileExposed implements Rule
{
    /**
     * Paths whose exposure is the harm itself, rather than a step towards it.
     *
     * @var list<string>
     */
    private const CRITICAL_PATHS = [
        '/.env',
        '/.git/config',
        '/backup.sql',
        '/database.sql',
        '/db.sql.gz',
    ];

    public function key(): string
    {
        return 'sensitive_file_exposed';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): ?string
    {
        return null;
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentProbe()) {
            return null;
        }

        // Empty whenever the control request said the answers cannot be trusted. That gate lives on
        // the model rather than here, so no rule can read the raw list by accident.
        $exposed = $snapshot->probe?->exposedPaths() ?? [];

        if ($exposed === []) {
            return null;
        }

        $paths = array_values(array_filter(array_map(
            static fn (array $file): mixed => $file['path'] ?? null,
            $exposed,
        ), 'is_string'));

        if ($paths === []) {
            return null;
        }

        $critical = array_values(array_intersect($paths, self::CRITICAL_PATHS));

        return new RuleMatch(
            severity: $critical === [] ? Severity::HIGH : Severity::CRITICAL,
            title: count($paths) === 1
                ? 'A file that should not be public is reachable on this site'
                : count($paths).' files that should not be public are reachable on this site',
            detail: $this->detail($snapshot->site->expected_domain, $paths, $critical),
            evidence: [
                'paths' => $paths,

                // Recorded so a reader can tell a real file from a zero-byte placeholder somebody
                // left behind. Never any part of the contents: these were HEAD requests and there
                // was no body to have.
                'sizes' => $this->sizes($exposed),
            ],
        );
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $critical
     */
    private function detail(string $domain, array $paths, array $critical): string
    {
        $detail = sprintf(
            'https://%s%s answered a request from outside the site%s. ',
            $domain,
            $paths[0],
            count($paths) === 1 ? '' : ', as did '.(count($paths) - 1).' more',
        );

        if (in_array('/.env', $critical, true)) {
            $detail .= 'The .env file is every credential this site holds - its database password, '
                .'its mail configuration, its API keys - and it is being served to anybody who asks '
                .'for it. Treat those credentials as public and rotate them, rather than only moving '
                .'the file. ';
        }

        if (array_intersect(['/backup.sql', '/database.sql', '/db.sql.gz'], $critical) !== []) {
            $detail .= 'A database dump in the webroot is the site\'s entire contents, including '
                .'everything it holds about its own users, downloadable by anybody who tries the '
                .'filename - and these are the filenames everybody tries. ';
        }

        if (in_array('/.git/config', $critical, true)) {
            $detail .= 'A readable .git directory usually means the whole repository history can be '
                .'downloaded, which usually includes credentials committed before somebody knew '
                .'better. ';
        }

        return $detail.'This is the document root pointing at the project directory rather than at '
            .'its web directory, or a rule that stopped excluding dotfiles. Manager checked with a '
            .'HEAD request and never read any of the contents.';
    }

    /**
     * @param  list<array{path: string, status: int, bytes: int|null}>  $exposed
     * @return array<string, int>
     */
    private function sizes(array $exposed): array
    {
        $sizes = [];

        foreach ($exposed as $file) {
            if (isset($file['path'], $file['bytes']) && is_string($file['path']) && is_int($file['bytes'])) {
                $sizes[$file['path']] = $file['bytes'];
            }
        }

        return $sizes;
    }
}
