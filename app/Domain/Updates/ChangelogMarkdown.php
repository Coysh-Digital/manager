<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use Illuminate\Support\Str;

/**
 * The one place release notes become HTML.
 *
 * Two sources now feed this — Craft's changelog, fetched from GitHub by {@see ChangelogFetcher}, and
 * plugin notes forwarded by connectors and held by {@see PluginChangelog} — and both are third-party
 * text arriving over a network and being put on a page inside an authenticated session. Duplicating
 * the render step would mean two places to get the options right and one of them eventually being
 * changed alone, so there is one.
 *
 * league/commonmark ships with the framework, and its unsafe-input defaults are exactly the two that
 * matter: raw HTML in the source is discarded rather than passed through, and `javascript:` links are
 * not turned into anchors.
 */
final class ChangelogMarkdown
{
    /**
     * How many released versions to render at once, and how much text.
     *
     * The caps are a rendering concern rather than a storage one: a plugin that has published forty
     * releases since a site last updated has produced a page nobody reads, and the first few are the
     * ones that matter.
     */
    public const MAX_SECTIONS = 20;

    public const MAX_CHARACTERS = 60000;

    /**
     * Render a list of already-filtered sections, or null when there are none.
     *
     * @param  list<string>  $sections
     */
    public static function render(array $sections): ?string
    {
        if ($sections === []) {
            return null;
        }

        $text = Str::limit(implode("\n\n", array_slice($sections, 0, self::MAX_SECTIONS)), self::MAX_CHARACTERS, ' …');

        return Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Whether a version falls in the half-open range a site would be moving across.
     *
     * Exclusive of what is installed — a site is not interested in the notes for the version it is
     * already running — and inclusive of the latest, which is where it is going. A null bound is
     * simply not applied, so a report missing one still renders what it can.
     */
    public static function isBetween(string $version, ?string $current, ?string $latest): bool
    {
        if ($current !== null && version_compare($version, $current, '<=')) {
            return false;
        }

        return ! ($latest !== null && version_compare($version, $latest, '>'));
    }
}
