<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Where to read what a release changed.
 *
 * A link, never the text. The connector deliberately strips release notes before sending an update
 * report — they describe, in detail, what a given version fixes, and holding that against a named
 * unpatched site puts a map of an exploitable installation in this database. That reasoning has not
 * changed; what changed is that "so there is no way to read them at all" was solving the problem by
 * removing the feature.
 *
 * So the notes stay where the publisher put them. Manager holds a URL built from a handle it already
 * has, the browser fetches nothing until somebody clicks, and no part of what a release fixes is
 * ever stored here or transmitted by a site.
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
