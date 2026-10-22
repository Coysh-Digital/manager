<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * Which PHP releases are known to carry a vulnerability that a newer release fixes.
 *
 * Kept here, on the platform, rather than computed by the connector the way end-of-life is. The
 * connector sends the version it is running and nothing else, so a new advisory is a new entry in
 * this list and reaches every site on the next sweep - no connector release, no protocol change, and
 * nothing for a site's owner to upgrade before it is checked.
 *
 * **Only what a version string can prove.** An entry says "below this release, on this branch, the
 * fix is not in". It says nothing about whether a particular installation is exploitable, because
 * that depends on settings the connector does not report and on a web server it may not be
 * running under. The finding is worded accordingly, and the mitigations an entry carries are advice
 * rather than something that lowers its severity.
 *
 * **A distribution's package is the one case the version cannot settle.** Debian, Ubuntu and the
 * Sury repository backport fixes without moving the upstream number, and mark the package with a
 * suffix: `8.3.6-1ubuntu1.2`. Red Hat's builds do the same and leave no suffix at all, which is why
 * an acknowledgement exists - see {@see isDistroBuild()} for what this can and cannot tell apart.
 *
 * @phpstan-type Advisory array{
 *     id: string,
 *     name: string,
 *     cves: list<string>,
 *     published: string,
 *     fixed: array<string, string>,
 *     oldest_fixed_branch: string,
 *     mitigation: string,
 *     sapi_notes: array<string, string>
 * }
 */
final class PhpAdvisories
{
    /**
     * Newest first. An id is never reused or renamed: it is what an acknowledged finding compares
     * against to notice that it is now about something it was not when somebody acknowledged it.
     *
     * `fixed` maps a branch to the first release on it that carries the fix. A branch older than
     * `oldest_fixed_branch` has no fix upstream at all, and is always affected.
     *
     * @var list<Advisory>
     */
    public const ADVISORIES = [
        [
            'id' => 'php2shell-2026-10',
            'name' => 'PHP2Shell',
            'cves' => ['CVE-2026-109618', 'CVE-2026-109619'],
            'published' => '2026-10-21',
            'fixed' => [
                '8.5' => '8.5.12',
                '8.4' => '8.4.27',
                '8.3' => '8.3.36',
                '8.2' => '8.2.35',
            ],
            'oldest_fixed_branch' => '8.2',

            // Taken from the PHP project's own advisory. Said to reduce exposure to particular
            // attack paths and not to make an installation safe, and the finding repeats that.
            'mitigation' => 'Until it is updated: set memory_limit=-1 where the application cannot '
                .'override it (php_admin_value in the FPM pool or the Apache configuration), set '
                .'post_max_size to 8M, or 32M if the site needs uploads, with upload_max_filesize below '
                .'it, and set session.upload_progress.enabled=0 in php.ini or the pool, not ini_set() '
                .'or .user.ini, restarting every worker. On PHP 8.5 also set fatal_error_backtraces=0. '
                .'These reduce exposure to specific attack paths; only the update fixes the '
                .'vulnerability.',
            'sapi_notes' => [
                'apache2handler' => 'This site runs under mod_php, which the PHP project rates as the '
                    .'highest risk: the demonstrated exploit needs one unauthenticated request and runs '
                    .'before any application code.',
            ],
        ],
    ];

    /**
     * The advisories a PHP version is affected by.
     *
     * An unparseable version, or a branch newer than any this list knows, is affected by nothing.
     * Not recognising a version is not evidence that it is vulnerable, and a rule that said so would
     * be raising a critical finding about a string it could not read.
     *
     * @return list<Advisory>
     */
    public static function affecting(string $version): array
    {
        $parsed = self::parse($version);

        if ($parsed === null) {
            return [];
        }

        return array_values(array_filter(
            self::ADVISORIES,
            static fn (array $advisory): bool => self::affects($advisory, $parsed['branch'], $parsed['release']),
        ));
    }

    /**
     * The first release on a branch that carries the fix, or null where there is none upstream.
     *
     * @param  Advisory  $advisory
     */
    public static function fixedIn(array $advisory, string $branch): ?string
    {
        return $advisory['fixed'][$branch] ?? null;
    }

    /**
     * Whether the version is a distribution's package rather than the PHP project's own build.
     *
     * A hyphen after the numeric version - `-1ubuntu2.14`, `-0+deb12u1`, `-1+ubuntu22.04.1+deb.sury.org+1`
     * - is how Debian-family packaging marks a rebuild, and it is the only signal in a version string
     * that the fix may be there under an older number. Pre-release markers (`-dev`, `-rc1`) are the
     * project's own and do not count.
     *
     * Red Hat and its derivatives backport too and mark nothing, so a RHEL package below the fix
     * reads as an upstream build. That is a false positive this cannot remove; the finding can be
     * acknowledged, and says why.
     */
    public static function isDistroBuild(string $version): bool
    {
        $parsed = self::parse($version);

        if ($parsed === null || ! str_starts_with($parsed['suffix'], '-')) {
            return false;
        }

        return preg_match('/^-(dev|alpha|beta|rc)/i', $parsed['suffix']) !== 1;
    }

    /**
     * @return array{branch: string, release: string, suffix: string}|null
     */
    public static function parse(string $version): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(.*)$/s', trim($version), $matches) !== 1) {
            return null;
        }

        return [
            'branch' => $matches[1].'.'.$matches[2],
            'release' => $matches[1].'.'.$matches[2].'.'.$matches[3],
            'suffix' => $matches[4],
        ];
    }

    /**
     * @param  Advisory  $advisory
     */
    private static function affects(array $advisory, string $branch, string $release): bool
    {
        $fixed = self::fixedIn($advisory, $branch);

        if ($fixed !== null) {
            return version_compare($release, $fixed, '<');
        }

        // No release on this branch. Older than the oldest one that has a fix means it never will.
        // Newer is a branch this list was written before, and is left alone.
        return version_compare($branch, $advisory['oldest_fixed_branch'], '<');
    }
}
