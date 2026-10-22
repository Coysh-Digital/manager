<?php

declare(strict_types=1);

use App\Domain\Findings\PhpAdvisories;

/**
 * Which versions the advisory table says are affected.
 *
 * The boundaries are the whole of it: a release one below the fix is exposed and the fix itself is
 * not, and getting either wrong is a critical alert about a patched site or silence about an exposed
 * one.
 */
it('treats the release below the fix as affected and the fix itself as clean', function (string $version, bool $affected): void {
    expect(PhpAdvisories::affecting($version) !== [])->toBe($affected);
})->with([
    'the 8.2 fix' => ['8.2.35', false],
    'one below the 8.2 fix' => ['8.2.34', true],
    'the 8.3 fix' => ['8.3.36', false],
    'one below the 8.3 fix' => ['8.3.35', true],
    'the 8.4 fix' => ['8.4.27', false],
    'one below the 8.4 fix' => ['8.4.26', true],
    'the 8.5 fix' => ['8.5.12', false],
    'one below the 8.5 fix' => ['8.5.11', true],
    'well past the fix' => ['8.3.100', false],
    'a patch level that only sorts right numerically' => ['8.3.9', true],
]);

it('treats a branch with no upstream fix as always affected', function (string $version): void {
    $advisories = PhpAdvisories::affecting($version);

    expect($advisories)->toHaveCount(1)
        ->and(PhpAdvisories::fixedIn($advisories[0], PhpAdvisories::parse($version)['branch']))->toBeNull();
})->with(['8.1.31', '8.0.30', '7.4.33', '5.6.40']);

it('says nothing about a branch newer than the list knows', function (): void {
    expect(PhpAdvisories::affecting('8.6.0'))->toBe([])
        ->and(PhpAdvisories::affecting('9.0.1'))->toBe([]);
});

it('says nothing about a version it cannot read', function (string $version): void {
    // Not recognising a string is not evidence that it is vulnerable.
    expect(PhpAdvisories::affecting($version))->toBe([]);
})->with(['', 'unknown', '8.3', 'PHP 8.3.35', '8.x.1']);

it('reads a version with a suffix by its numbers', function (): void {
    expect(PhpAdvisories::affecting('8.3.35-dev'))->toHaveCount(1)
        ->and(PhpAdvisories::affecting('8.3.36RC1'))->toBe([])
        ->and(PhpAdvisories::affecting('8.3.6-1ubuntu1.2'))->toHaveCount(1);
});

it('recognises a distribution package by its hyphenated suffix', function (string $version, bool $distro): void {
    expect(PhpAdvisories::isDistroBuild($version))->toBe($distro);
})->with([
    'Ubuntu' => ['8.1.2-1ubuntu2.14', true],
    'Debian' => ['8.2.7-1~deb12u1', true],
    'Sury' => ['8.3.6-1+ubuntu22.04.1+deb.sury.org+1', true],
    'upstream' => ['8.3.35', false],
    'upstream development build' => ['8.3.35-dev', false],
    'upstream release candidate' => ['8.4.0-RC1', false],
    'upstream release candidate, no hyphen' => ['8.4.0RC1', false],
    'unreadable' => ['unknown', false],
]);

it('keeps an advisory id, its CVEs and a fix per supported branch', function (): void {
    foreach (PhpAdvisories::ADVISORIES as $advisory) {
        expect($advisory['id'])->toMatch('/^[a-z0-9-]+$/')
            ->and($advisory['cves'])->not->toBeEmpty()
            ->and($advisory['fixed'])->toHaveKey($advisory['oldest_fixed_branch'])
            ->and($advisory['mitigation'])->not->toBeEmpty();

        // The fix for a branch has to be on that branch, or the comparison is against the wrong line.
        foreach ($advisory['fixed'] as $branch => $release) {
            expect($release)->toStartWith($branch.'.');
        }
    }

    expect(array_column(PhpAdvisories::ADVISORIES, 'id'))->toBe(array_values(array_unique(array_column(PhpAdvisories::ADVISORIES, 'id'))));
});
