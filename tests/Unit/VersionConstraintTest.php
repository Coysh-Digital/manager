<?php

declare(strict_types=1);

use App\Domain\Advisories\VersionConstraint;

/**
 * The range an advisory says is affected, read against a version.
 *
 * Every case here is a way a vulnerable site could be told it is fine, or a safe one told it is not,
 * so the boundaries get the most attention: the release that carries the fix is outside the range, the
 * one before it is inside, and a pre-release sorts below the release it precedes.
 */
const TWO_LINES = '>=5.0.0-RC1,<5.10.6|>=4.0.0-RC1,<4.18.2';

it('matches a version inside either line of a two-line range', function (string $version): void {
    expect(VersionConstraint::matches($version, TWO_LINES))->toBeTrue();
})->with(['5.7.5', '5.10.5', '5.0.0', '4.0.0', '4.18.1', '5.10.5.9', '4.18.1.2']);

it('does not match the release that carries the fix, or anything above it', function (string $version): void {
    expect(VersionConstraint::matches($version, TWO_LINES))->toBeFalse();
})->with(['5.10.6', '5.10.6.1', '5.11.2', '4.18.2', '4.18.9', '6.0.0', '3.9.9']);

it('treats a release candidate as older than the release, so it is inside a range that starts at it', function (): void {
    expect(VersionConstraint::matches('5.0.0-RC1', '>=5.0.0-RC1,<5.10.6'))->toBeTrue()
        ->and(VersionConstraint::matches('5.0.0-beta.1', '>=5.0.0-RC1,<5.10.6'))->toBeFalse();
});

it('reads an inclusive upper bound', function (): void {
    $range = '>=5.0.0-RC1,<=5.9.8';

    expect(VersionConstraint::matches('5.9.8', $range))->toBeTrue()
        ->and(VersionConstraint::matches('5.9.8.1', $range))->toBeFalse()
        ->and(VersionConstraint::matches('5.9.9', $range))->toBeFalse();
});

it('reads an exact version, and either spelling of "or"', function (): void {
    expect(VersionConstraint::matches('5.1.0', '=5.1.0'))->toBeTrue()
        ->and(VersionConstraint::matches('5.1.1', '5.1.0'))->toBeFalse()
        ->and(VersionConstraint::matches('4.1.0', '5.1.0||4.1.0'))->toBeTrue();
});

it('names the first fixed release of the line the version is on', function (): void {
    expect(VersionConstraint::fixedIn('5.7.5', TWO_LINES))->toBe('5.10.6')
        ->and(VersionConstraint::fixedIn('4.9.0', TWO_LINES))->toBe('4.18.2')
        ->and(VersionConstraint::fixedIn('5.11.2', TWO_LINES))->toBeNull();
});

it('does not invent a fix for a range bounded with <= or not at all', function (): void {
    expect(VersionConstraint::fixedIn('5.9.8', '>=5.0.0-RC1,<=5.9.8'))->toBeNull()
        ->and(VersionConstraint::fixedIn('5.9.8', '>=5.0.0-RC1'))->toBeNull();
});

it('does not match a version it cannot compare, rather than guessing', function (string $version): void {
    expect(VersionConstraint::isComparable($version))->toBeFalse()
        ->and(VersionConstraint::matches($version, TWO_LINES))->toBeFalse();
})->with(['', 'unknown', 'dev-main', 'abc']);

it('refuses a constraint outside the supported shape instead of matching it', function (string $constraint): void {
    expect(VersionConstraint::isReadable($constraint))->toBeFalse()
        ->and(fn () => VersionConstraint::matches('5.7.5', $constraint))->toThrow(InvalidArgumentException::class);
})->with(['^5.0', '~5.7.0', '5.*', '>=5.0.0 <', 'garbage', '']);

it('reports an unreadable constraint even for a version that could not match it', function (): void {
    expect(fn () => VersionConstraint::matches('1.0.0', '>=5.0.0,^6'))->toThrow(InvalidArgumentException::class);
});
