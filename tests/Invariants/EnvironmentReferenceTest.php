<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The environment reference documents every setting.
 *
 * `docs/env.md` opens with "Everything Manager for Craft reads from the environment", which is a
 * promise rather than a description. It had drifted: fifteen variables the application reads were
 * absent, including `MANAGER_NUDGE_ENABLED` — the one the 1.5.0 release notes tell an operator to
 * set if their installation must make no outbound request to a managed site. Somebody following
 * that instruction would have arrived here and not found it.
 *
 * Nothing about that failure is visible. A missing row does not break a build or a page; the
 * variable keeps working, its default keeps applying, and the only person who finds out is the one
 * who needed it and concluded it did not exist.
 *
 * Scoped to `MANAGER_*` on purpose. The framework's own `APP_*`, `DB_*`, `MAIL_*` and `SESSION_*`
 * variables run to hundreds, most of them irrelevant to this product, and the page documents the
 * ones that matter rather than every key Laravel could theoretically read. What it must be complete
 * about is this application's own settings, which are the ones nobody can look up elsewhere.
 */

it('documents every MANAGER_ variable the application reads', function (): void {
    $read = [];

    foreach ([config_path(), app_path()] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Only the literal form. A variable built from a variable cannot be documented by name
            // and does not exist in this codebase; if one ever does, it wants a different check.
            preg_match_all("~env\(\s*'(MANAGER_[A-Z0-9_]+)'~", $file->getContents(), $matches);

            foreach ($matches[1] as $variable) {
                $read[$variable] = true;
            }
        }
    }

    expect($read)->not->toBeEmpty();

    $reference = File::get(base_path('docs/env.md'));

    $undocumented = array_values(array_filter(
        array_keys($read),
        static fn (string $variable): bool => ! str_contains($reference, '`'.$variable.'`'),
    ));

    sort($undocumented);

    expect($undocumented)->toBe([], implode("\n", [
        'These variables are read but not documented in docs/env.md:',
        '  '.implode("\n  ", $undocumented),
        'That page states it lists everything, so a missing row is a promise broken rather than',
        'a detail omitted. Naming it in prose is not enough - write the row, in backticks.',
    ]));
});
