<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use Illuminate\Support\Str;

/**
 * The one place release notes become HTML.
 *
 * Two sources feed this, and they do not speak the same language - which is the bug this class was
 * changed to fix.
 *
 * Craft's own changelog is Markdown: {@see ChangelogFetcher} pulls `CHANGELOG.md` from GitHub, and
 * {@see render} is right for it. Plugin notes are **HTML**, because Craft's update API hands them
 * over already rendered and the connector forwards what Craft downloaded. Both were going through
 * `render()`, whose `html_input => 'strip'` returns an empty string for an HTML block - so every
 * plugin note body was discarded and the panel showed only the version headings this class had
 * generated itself.
 *
 * So there are two entry points, one per source language, and {@see renderHtml} is the one that
 * knows the body is already markup. Both end at {@see ReleaseNotesHtml}, which is the allowlist: no
 * path reaches the panel without passing it. That is why this stays a single class rather than
 * becoming two - the options and the allowlist are the sort of thing that gets fixed in one place
 * and forgotten in the other.
 *
 * league/commonmark ships with the framework, and its unsafe-input defaults are exactly the two that
 * matter for the Markdown side: raw HTML in the source is discarded rather than passed through, and
 * `javascript:` links are not turned into anchors.
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

        $html = Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // Commonmark has already discarded raw HTML, so this removes nothing today. It is here so
        // that there is no route to the panel that skips the allowlist - including this one, if the
        // options above are ever loosened.
        return ReleaseNotesHtml::sanitise($html);
    }

    /**
     * Render sections whose bodies are already HTML.
     *
     * Used for plugin notes. Each section is `['heading' => string, 'body' => string]`, and the two
     * are kept apart deliberately: a Markdown `## 5.7.1` heading cannot be concatenated onto an HTML
     * body and put through one renderer, which is precisely how the bodies came to be dropped. The
     * whole document is composed in HTML instead.
     *
     * Three things about the order below are load-bearing:
     *
     *  - The body is truncated **before** it is sanitised, so a cut landing in the middle of a tag
     *    is repaired by the parser rather than emitted. Sanitising first and cutting after can
     *    produce an unbalanced document.
     *  - The budget runs across sections rather than per section, mirroring the connector's own
     *    NOTE_BUDGET_CHARACTERS, so twenty releases cannot each spend the maximum.
     *  - Our heading is composed **after** sanitising, from text this class built and escaped
     *    itself. A plugin author cannot forge a version heading, because their markup never passes
     *    through the same step that produces ours.
     *
     * @param  list<array{heading: string, body: string}>  $sections
     */
    public static function renderHtml(array $sections): ?string
    {
        $html = '';
        $budget = self::MAX_CHARACTERS;

        foreach (array_slice($sections, 0, self::MAX_SECTIONS) as $section) {
            if ($budget <= 0) {
                break;
            }

            $body = ReleaseNotesHtml::sanitise(mb_substr($section['body'], 0, $budget));
            $budget -= mb_strlen($body);

            $html .= '<h2>'.e($section['heading']).'</h2>'.$body;
        }

        return $html === '' ? null : $html;
    }

    /**
     * Whether a version falls in the half-open range a site would be moving across.
     *
     * Exclusive of what is installed - a site is not interested in the notes for the version it is
     * already running - and inclusive of the latest, which is where it is going. A null bound is
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
