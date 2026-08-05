<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use InvalidArgumentException;

/**
 * The wording an email ships with, and the tokens it is allowed to contain.
 *
 * A value object in the same style as {@see EmailCatalogueEntry}: constructed once, read-only
 * afterwards, carrying no behaviour beyond describing itself and checking what it is given.
 *
 * The `placeholders` map is the field that earns this class its existence, and it is *declared*
 * rather than inferred from the body. Inferring would mean a typo defines itself: somebody writing
 * `:org` where they meant `:organisation` would produce a template with a placeholder called `org`
 * that nothing ever substitutes, and the literal text `:org` would go out to a customer. Declared,
 * the same typo is a validation error before it is saved.
 *
 * The map is token name to a short description of what replaces it, because the editor has to be
 * able to list them. A token nobody can see is a token nobody uses.
 */
final class EmailCopyTemplate
{
    /**
     * Matches `:token` — lowercase, digits and underscores, which is what strtr() is given below.
     *
     * The lookbehind is load-bearing. Without it a URL scheme is indistinguishable from a
     * placeholder: `mailto:support` yields a token called `support`, and the editor would reject a
     * perfectly ordinary link because nothing declares it. A placeholder is a word the colon
     * introduces, so a colon with a letter or digit already against it is punctuation, not a token.
     */
    private const TOKEN_PATTERN = '/(?<![A-Za-z0-9]):([a-z][a-z0-9_]*)/';

    /**
     * @param  string  $key  the catalogue key this is the wording for
     * @param  string  $subject  the default subject line
     * @param  string  $body  the default body, paragraphs separated by blank lines
     * @param  array<string, string>  $placeholders  token name => what it is replaced with
     */
    public function __construct(
        public readonly string $key,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $placeholders = [],
    ) {}

    /**
     * Every `:token` in a piece of text.
     *
     * @return list<string>
     */
    public static function tokensIn(string $text): array
    {
        preg_match_all(self::TOKEN_PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The tokens this template's own default wording uses.
     *
     * @return list<string>
     */
    public function tokensUsed(): array
    {
        return array_values(array_unique([
            ...self::tokensIn($this->subject),
            ...self::tokensIn($this->body),
        ]));
    }

    /**
     * Tokens in the given text that this template does not declare.
     *
     * What the editor validates against. An operator may use fewer tokens than are offered — a
     * paragraph they have rewritten may no longer need the expiry time — but never more, because
     * there would be nothing to substitute and the raw `:token` would send.
     *
     * @return list<string>
     */
    public function unknownTokensIn(string ...$texts): array
    {
        $used = [];

        foreach ($texts as $text) {
            $used = [...$used, ...self::tokensIn($text)];
        }

        return array_values(array_diff(array_unique($used), array_keys($this->placeholders)));
    }

    /**
     * Substitute the declared tokens.
     *
     * strtr() rather than str_replace() with two arrays, because strtr does not rescan what it has
     * already written: a replacement value that happens to contain `:name` cannot then be
     * substituted again. Organisation names are operator-supplied text, so that is a real path.
     *
     * @param  array<string, string>  $replacements
     */
    public function render(string $text, array $replacements): string
    {
        $pairs = [];

        foreach ($replacements as $token => $value) {
            if (! array_key_exists($token, $this->placeholders)) {
                throw new InvalidArgumentException(
                    "Nothing declares the placeholder `:{$token}` on `{$this->key}`.",
                );
            }

            $pairs[':'.$token] = $value;
        }

        return strtr($text, $pairs);
    }
}
