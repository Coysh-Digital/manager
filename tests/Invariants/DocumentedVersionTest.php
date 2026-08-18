<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The documented version examples agree with the changelog.
 *
 * This repository declares no `version` in `composer.json` on purpose - Composer derives it from the
 * tag, and a manifest that disagreed with the tag is the failure `protocol` and `restore` both carry
 * tests against. The consequence is that every statement of the version here lives in prose, in a
 * worked example somebody pastes into a terminal, where nothing checks it and nothing breaks when it
 * is wrong.
 *
 * It drifted, as that arrangement always does. `docs/rollback.md` went on telling operators to check
 * out `v1.0.0` and set `MANAGER_VERSION=1.0.0` while the other two pages had moved to 1.7.1, seven
 * minor releases later. It is the page somebody opens during an incident, having just had an upgrade
 * go wrong, and its worked example was a rollback across the whole history of the project.
 *
 * Two rules, because the pages mean two different things by "the version":
 *
 *   - `getting-started.md` installs and `upgrade.md` moves you to the current release, so both state
 *     the number at the top of the changelog.
 *   - `rollback.md` moves you off it, so it states the release before that one. An example there
 *     matching the current version would be telling somebody to roll back to where they already are.
 *
 * The changelog is the source of truth rather than `git tag`, because under the rule in CLAUDE.md the
 * top heading is the number a human could tag this minute - so a pull request that bumps the heading
 * and forgets a document fails here, in that same pull request, rather than after the tag is cut and
 * cannot be changed.
 */

/**
 * Versions stated in a document's worked examples.
 *
 * Only the two forms these pages actually use: a `v1.2.3` tag and a `MANAGER_VERSION=1.2.3`
 * assignment. A bare three-part number matches too much - `127.0.0.1` appears in the reverse proxy
 * example on getting-started.md, and reading that as a version is how a check like this ends up
 * being deleted for crying wolf.
 *
 * @return list<string>
 */
function documentedVersions(string $document): array
{
    $contents = File::get(base_path($document));

    preg_match_all('~(?:\bv|MANAGER_VERSION=)(\d+\.\d+\.\d+)\b~', $contents, $matches);

    return array_values(array_unique($matches[1]));
}

/** @return list<string> Every version heading in the changelog, newest first. */
function changelogVersions(): array
{
    preg_match_all(
        '~^## (\d+\.\d+\.\d+)~m',
        File::get(base_path('CHANGELOG.md')),
        $matches
    );

    return $matches[1];
}

it('installs and upgrades to the version the changelog is on', function (string $document): void {
    $current = changelogVersions()[0] ?? null;

    expect($current)->not->toBeNull('CHANGELOG.md has no version heading.');

    $stated = documentedVersions($document);

    expect($stated)->not->toBeEmpty("{$document} states no version at all; it documents a worked example and should.");

    foreach ($stated as $version) {
        expect($version)->toBe(
            $current,
            "{$document} names {$version} but the changelog is on {$current}. That example gets pasted "
            .'into a terminal, and it would install the release before this one.'
        );
    }
})->with(['docs/getting-started.md', 'docs/upgrade.md']);

it('rolls back to the release before the current one', function (): void {
    $versions = changelogVersions();

    expect(count($versions))->toBeGreaterThan(1, 'Only one release exists; there is nothing to roll back to yet.');

    $previous = $versions[1];

    $stated = documentedVersions('docs/rollback.md');

    expect($stated)->not->toBeEmpty('docs/rollback.md states no version; its worked example needs one.');

    foreach ($stated as $version) {
        expect($version)->toBe(
            $previous,
            "docs/rollback.md names {$version}, but the release before the current one is {$previous}. "
            .'This page is read during an incident and its example should be the rollback somebody is '
            .'actually doing.'
        );
    }
});
