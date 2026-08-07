<?php

declare(strict_types=1);

namespace App\Domain\Connector;

use App\Models\Site;

/**
 * The path a site says to knock on, reduced to something safe to keep or thrown away.
 *
 * A Craft action URL is not derivable from outside the site: `actionTrigger` is configurable,
 * `headlessMode` changes it, `omitScriptNameInUrls` may produce `/index.php?p=...`, and a subfolder
 * install prefixes everything. So the site has to say. Which means this is attacker-controlled input in
 * the case that matters - a compromised or impersonated connector - and it is treated that way.
 *
 * **Everything that could name a destination is refused rather than stripped.** A sanitiser that
 * removes a scheme and keeps going is a sanitiser that can be fooled by an input carrying two; one that
 * returns null on anything it does not fully understand cannot be. The cost of refusing wrongly is that
 * one site is nudged a few minutes later than it might have been, which is the same as the cost of the
 * site never sending a path at all.
 *
 * This decides only whether a path is *shaped* safely. It has no say in where the request goes: the host
 * is always {@see Site::$expected_domain}, composed in {@see NudgeDispatcher}, which is the
 * half that makes the shape sufficient.
 */
final class NudgePath
{
    /**
     * Longest path kept, in bytes. Matches the column.
     */
    public const MAX_LENGTH = 255;

    /**
     * A path safe to store and later compose, or null.
     */
    public static function sanitise(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (strlen($path) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters, whitespace and a backslash. A newline is header injection; a backslash is
        // a path separator on the other side of enough parsers to be worth refusing outright.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $path) === 1) {
            return null;
        }

        // Must be absolute, and must not be protocol-relative. `//evil.example/x` is a *host* wearing a
        // path's clothes: parse_url reads it as one, and so does every HTTP client.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        // No traversal. Not because it escapes the document root - it does not - but because a path
        // that needs normalising to be understood is one where the composed URL and the request that
        // results can disagree.
        if (str_contains($path, '..')) {
            return null;
        }

        $parts = parse_url($path);

        if ($parts === false) {
            return null;
        }

        // Anything naming a destination, and a fragment, which has no meaning in a request and would
        // only ever be an attempt to make one string read as two.
        foreach (['scheme', 'host', 'user', 'pass', 'port', 'fragment'] as $forbidden) {
            if (isset($parts[$forbidden])) {
                return null;
            }
        }

        if (! isset($parts['path']) || ! str_starts_with($parts['path'], '/')) {
            return null;
        }

        // A query is permitted, and has to be: `/index.php?p=actions/manager-connector/nudge/poll` is
        // what a site with `omitScriptNameInUrls` off actually answers on, so refusing it would refuse
        // a perfectly ordinary configuration.
        $rebuilt = $parts['path'].(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');

        // Round-trip. If what parse_url understood does not reassemble into what arrived, then this
        // code and whatever sends the request are reading the same string differently, and the whole
        // point of the check is that they do not.
        return $rebuilt === $path ? $rebuilt : null;
    }
}
