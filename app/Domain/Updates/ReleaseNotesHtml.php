<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allowlist that decides what a release note may put on the page.
 *
 * Plugin release notes arrive as **HTML**. Craft's update API hands them over already rendered —
 * its own updates screen does `.html(notes.replace(/(<\/?h)(3|4|5)\b/g, …))` - and the connector
 * forwards what Craft downloaded, verbatim. The platform was rendering them through commonmark with
 * `html_input => 'strip'`, which returns an empty string for an HTML block, so every note body
 * vanished and the only thing left on screen was the `## 5.7.1 - 2026-07-22` heading the platform
 * had generated itself. That is the reported symptom: version headings, and nothing underneath.
 *
 * So the HTML has to survive, which means it has to be constrained. This is third-party text
 * arriving over a network from a site's connector, rendered inside an authenticated control-plane
 * session, so the list is short and it is an allowlist.
 *
 * **No media, and that is the interesting decision.** An `<img src="https://…">` inside a release
 * note is an outbound request from an authenticated page in this control plane to a third party,
 * telling whoever serves it that this installation is reading this plugin's notes at this moment.
 * That association is exactly what PluginChangelog and ChangelogFetcher are built to avoid - the
 * notes are stored against a plugin and a version with no site column, and the whole feature makes
 * no outbound request - and an image would have reintroduced it through a channel nobody was
 * watching. Dropped rather than blocked, so the contents go with the tag.
 *
 * Links keep `href` and nothing else, `http` and `https` only. `javascript:`, `data:` and
 * protocol-relative `//host` are all refused, and every anchor is forced to carry
 * `rel="nofollow noopener noreferrer"`.
 */
final class ReleaseNotesHtml
{
    /**
     * Elements a plugin author may use.
     *
     * Prose, lists, code and emphasis. Headings are included because Craft's own notes use `<h3>`
     * and `<h4>` freely, and stripping them would run every section together.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'p', 'br', 'hr',
        'strong', 'b', 'em', 'i', 'del', 's', 'sup', 'sub',
        'code', 'pre',
        'blockquote',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a',
    ];

    /**
     * Elements whose contents go with them.
     *
     * `dropElement` rather than the default block behaviour: the text inside a `<script>` or a
     * `<style>` is not prose that lost its formatting, it is code, and keeping it would put a
     * stylesheet in the middle of a changelog.
     *
     * @var list<string>
     */
    private const DROPPED = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'img', 'picture', 'source', 'video', 'audio', 'svg', 'canvas',
        'base', 'link', 'meta', 'template',
    ];

    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Sanitise one note body.
     *
     * Returns HTML fit to be echoed unescaped, and nothing else does. Callers hand this raw
     * third-party text; there is no path to the panel that skips it.
     */
    public static function sanitise(string $html): string
    {
        return trim(self::sanitizer()->sanitize($html));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // Nothing is permitted that is not named below. allowSafeElements() would have been
            // shorter and would have let in <img>; see the class docblock.
            ->allowLinkSchemes(['http', 'https'])
            ->allowRelativeLinks(false)
            // No scheme is permitted for media, which together with dropping the elements
            // themselves means a note cannot cause this page to fetch anything.
            ->allowMediaSchemes([])
            ->withMaxInputLength(ChangelogMarkdown::MAX_CHARACTERS);

        foreach (self::ALLOWED as $element) {
            $config = $element === 'a'
                ? $config->allowElement('a', ['href'])
                : $config->allowElement($element, []);
        }

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        // An anchor in a release note points at somebody else's site. noopener/noreferrer because
        // it opens from an authenticated page; nofollow because a changelog is not an endorsement.
        $config = $config->forceAttribute('a', 'rel', 'nofollow noopener noreferrer');

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
