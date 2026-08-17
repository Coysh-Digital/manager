<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A site announcing the exact version of the software running it.
 *
 * **The version is the finding, not the header.** `Server: nginx` tells an attacker something they
 * could have guessed and can do nothing with. `Server: nginx/1.24.0` and `X-Powered-By: PHP/8.3.14`
 * are a specific build to look up in an advisory database, on every response, to anybody. That
 * distinction is the whole rule: flagging the presence of a `Server` header would fire on nearly
 * every site on the web and be muted within a week, and the sites that then stopped being read are
 * the ones publishing a patch level.
 *
 * Low, and it should stay low. This is not a vulnerability - it is one step removed from being
 * useful to somebody who has already decided to try. What makes it worth a row at all is that the
 * fix is one line in a configuration file and lasts forever, which is a good ratio.
 *
 * The other reason it earns a place: an announced version is a version somebody can compare against
 * the one Manager already knows this site is running. A fleet where the connector says PHP 8.3.14
 * and the response header agrees is a fleet where an attacker needs no reconnaissance at all.
 */
final class SoftwareVersionDisclosed implements Rule
{
    /**
     * A version number attached to a name: `nginx/1.24.0`, `PHP/8.3.14`, `Apache/2.4.58 (Debian)`.
     *
     * A digit alone is not enough. `Server: cloudflare` discloses nothing and `Server: LiteSpeed`
     * discloses nothing; it takes a separator and a number for this to be a build somebody can look
     * up.
     */
    private const VERSION_PATTERN = '~[/ ]v?\d+\.\d+~';

    public function key(): string
    {
        return 'software_version_disclosed';
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

        $disclosed = [];

        foreach (['Server' => 'server', 'X-Powered-By' => 'x-powered-by'] as $label => $header) {
            $value = $snapshot->servedHeader($header);

            if (is_string($value) && preg_match(self::VERSION_PATTERN, $value) === 1) {
                $disclosed[$label] = $value;
            }
        }

        if ($disclosed === []) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::LOW,
            title: 'This site announces the version of the software running it',
            detail: sprintf(
                'Every response carries %s. That is a specific build somebody can look up in an '
                .'advisory database without touching the site first, and it is published to anybody '
                .'who asks for the home page. It is not a vulnerability on its own - it removes the '
                .'reconnaissance step for anybody who has already decided to try one. Both are '
                .'turned off in the web server or PHP configuration rather than in Craft: '
                .'`server_tokens off` for nginx, `ServerTokens Prod` for Apache, and '
                .'`expose_php = Off` in php.ini.',
                $this->readable($disclosed),
            ),
            // The values, because they are the finding - and they are already public, on every
            // response this site serves.
            evidence: $disclosed,
        );
    }

    /**
     * @param  array<string, string>  $disclosed
     */
    private function readable(array $disclosed): string
    {
        $parts = [];

        foreach ($disclosed as $label => $value) {
            $parts[] = $label.': '.$value;
        }

        return implode(' and ', $parts);
    }
}
