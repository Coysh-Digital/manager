<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Where to read what a release changed.
 *
 * A link, built from a handle, and still the fallback everywhere the notes themselves are missing.
 *
 * The association worth protecting is "*this site* is behind on *these fixes*" - a map of an
 * exploitable installation - and every route to release notes here is shaped around never storing
 * it. {@see ChangelogFetcher} asks for one public file, once per installation, on a cache key that is
 * a constant, so the request is identical whatever the fleet contains and can carry nothing about it.
 * {@see PluginChangelog} makes no request at all: connectors forward what their own Craft install
 * already downloaded, and the notes are kept against a plugin and a version in a table with no site
 * column. Neither ever resolves a handle to a third-party destination, which is the thing that would
 * tell a plugin author which of somebody's sites are behind.
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
     * This platform's own changelog.
     *
     * The file rather than the releases page. `CHANGELOG.md` is written for somebody about to
     * upgrade a running installation and says what they have to do about it, which is the question
     * being asked by anybody who has just read a version number off a screen. The releases page can
     * also be empty - it is, until a version is tagged - and a link to nothing teaches people not to
     * trust the others.
     *
     * The core is public, so this resolves for a self-hosted reader and a Cloud one alike.
     */
    public static function manager(): string
    {
        return 'https://github.com/Coysh-Digital/manager/blob/main/CHANGELOG.md';
    }

    /**
     * A plugin's page, from its handle.
     *
     * The Plugin Store is the honest destination. Guessing at a repository from a handle would be
     * wrong often enough to be worse than useless - a link that 404s teaches people not to trust the
     * others - whereas the store either has the plugin or plainly does not.
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
