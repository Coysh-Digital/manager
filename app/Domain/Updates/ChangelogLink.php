<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Where to read what a release changed.
 *
 * A link, built from a handle. The connector deliberately strips release notes before sending an
 * update report — they describe, in detail, what a given version fixes, and holding that against a
 * named unpatched site puts a map of an exploitable installation in this database. That reasoning
 * has not changed; what changed is that "so there is no way to read them at all" was solving the
 * problem by removing the feature.
 *
 * Nothing is built here from anything a site reported beyond its own handle, and no part of what a
 * release fixes is ever stored against a site or transmitted by one.
 *
 * {@see ChangelogFetcher} reads Craft's changelog so it can be shown in place rather than in another
 * tab, and does not weaken any of the above: it asks for one public file, once per installation, on
 * a cache key that is a constant. The association worth protecting — *this site* is behind on *these
 * fixes* — is what never goes anywhere, and a request that is identical whatever the fleet contains
 * cannot carry it. Plugins have no equivalent, because a plugin's report carries a handle and no
 * repository, and resolving one would mean asking a third party about every plugin installed.
 *
 * Every link opens in a new tab with `rel="noopener noreferrer"`: these point off-platform, and a
 * referrer would tell a third party which of their packages an installation is behind on.
 */
final class ChangelogLink
{
    /**
     * Craft's own release notes.
     */
    public static function craft(): string
    {
        return 'https://github.com/craftcms/cms/blob/5.x/CHANGELOG.md';
    }

    /**
     * A plugin's page, from its handle.
     *
     * The Plugin Store is the honest destination. Guessing at a repository from a handle would be
     * wrong often enough to be worse than useless — a link that 404s teaches people not to trust the
     * others — whereas the store either has the plugin or plainly does not.
     *
     * Handles are validated against the protocol's own pattern before being interpolated, so a
     * malformed one produces no link rather than a malformed URL.
     */
    public static function plugin(string $handle): ?string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $handle) !== 1) {
            return null;
        }

        return 'https://plugins.craftcms.com/'.rawurlencode($handle);
    }

    /**
     * PHP's changelog for a branch, e.g. "8.3.14" → the 8.3 series.
     */
    public static function php(?string $version): ?string
    {
        if ($version === null || preg_match('/^(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return "https://www.php.net/ChangeLog-{$matches[1]}.php#PHP_{$matches[1]}_{$matches[2]}";
    }
}
