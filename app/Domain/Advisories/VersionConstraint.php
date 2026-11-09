<?php

declare(strict_types=1);

namespace App\Domain\Advisories;

use InvalidArgumentException;

/**
 * Whether a version falls inside the range an advisory says is affected.
 *
 * Advisories describe their range as a Composer constraint of one shape: groups joined by `|`, each
 * group a comma-separated list of comparisons - `>=5.0.0-RC1,<5.10.6|>=4.0.0-RC1,<4.18.2`. That is
 * the whole grammar this reads. It is not Composer's constraint language: there is no caret, tilde
 * or wildcard, because a range written as "everything below the fix" never needs one, and a parser
 * that accepted more would be accepting syntax nobody tested.
 *
 * **A constraint this cannot read throws, and never matches.** Treating "I could not understand the
 * range" as "not affected" is how a vulnerable site gets a clean bill of health, and treating it as
 * "affected" raises a critical finding about nothing. The caller decides what to do about a range it
 * cannot use; the sync drops it and says so.
 *
 * Versions compare with PHP's version_compare(), which orders `5.0.0-RC1` before `5.0.0` and copes
 * with Craft's four-part releases (`3.7.20.1`).
 */
final class VersionConstraint
{
    /**
     * @throws InvalidArgumentException when the constraint is not in the supported shape
     */
    public static function matches(string $version, string $constraint): bool
    {
        return self::matchingGroup($version, $constraint) !== null;
    }

    /**
     * The first release outside the range that matched, or null.
     *
     * The upper bound of the group the version fell in: for `>=5.0.0-RC1,<5.10.6` that is `5.10.6`.
     * Null when the version is not affected, and also when the matching group is bounded with `<=`
     * or not at all, because "affected up to and including 5.9.8" does not say what the fix is and
     * guessing the next release would be inventing one.
     *
     * @throws InvalidArgumentException
     */
    public static function fixedIn(string $version, string $constraint): ?string
    {
        $group = self::matchingGroup($version, $constraint);

        if ($group === null) {
            return null;
        }

        foreach ($group as [$operator, $bound]) {
            if ($operator === '<') {
                return $bound;
            }
        }

        return null;
    }

    /**
     * Whether a constraint is one this can evaluate, for rejecting a bad one at the door.
     */
    public static function isReadable(string $constraint): bool
    {
        try {
            self::parse($constraint);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether the string is a release version this can compare at all.
     *
     * `dev-main`, `unknown` and the empty string are not, and a site reporting one of them is not
     * thereby vulnerable.
     */
    public static function isComparable(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+/', trim($version)) === 1;
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private static function matchingGroup(string $version, string $constraint): ?array
    {
        // Parsed before the version is looked at, so an unreadable constraint is reported even for a
        // version that could not have matched it.
        $groups = self::parse($constraint);

        if (! self::isComparable($version)) {
            return null;
        }

        $version = self::normalise($version);

        foreach ($groups as $group) {
            foreach ($group as [$operator, $bound]) {
                if (! version_compare($version, $bound, self::phpOperator($operator))) {
                    continue 2;
                }
            }

            return $group;
        }

        return null;
    }

    /**
     * @return list<list<array{0: string, 1: string}>>
     */
    private static function parse(string $constraint): array
    {
        $groups = [];

        // `||` is the other spelling of `|`, and splitting on a run of pipes accepts both.
        foreach (preg_split('/\|+/', trim($constraint)) ?: [] as $text) {
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $group = [];

            foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (preg_match('/^(>=|<=|!=|==|>|<|=)?v?(\d+(?:\.\d+)*(?:[-.]?[A-Za-z][A-Za-z0-9.]*)?)$/', $token, $matches) !== 1) {
                    throw new InvalidArgumentException("Unsupported version constraint: {$constraint}");
                }

                $group[] = [$matches[1] === '' ? '=' : $matches[1], self::normalise($matches[2])];
            }

            if ($group === []) {
                continue;
            }

            $groups[] = $group;
        }

        if ($groups === []) {
            throw new InvalidArgumentException("Unsupported version constraint: {$constraint}");
        }

        return $groups;
    }

    private static function normalise(string $version): string
    {
        return strtolower(ltrim(trim($version), 'vV'));
    }

    private static function phpOperator(string $operator): string
    {
        return $operator === '=' ? '==' : $operator;
    }
}
