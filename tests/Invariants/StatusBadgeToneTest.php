<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * Every status badge asks for a tone the component actually has.
 *
 * `<x-status-badge>` resolves its tone against a fixed map and falls back to grey for anything it
 * does not recognise. That fallback is right - a badge is not worth a 500 - but it means a typo is
 * invisible: the badge still renders, still carries a glyph, still sits where it was put, and is
 * simply the wrong colour.
 *
 * This is not hypothetical. `<x-status-badge tone="amber">` appeared in two components, both with a
 * docblock arguing for amber over red, and both rendered grey from the day they were written. The
 * tone that carries amber is spelled `warn`. Nobody noticed because there is nothing to notice: no
 * error, no warning, and a grey badge on a screen full of grey text.
 *
 * So the check is a grep, and it is written to fail rather than warn. The set below is read from the
 * component itself rather than restated here, because a copy of it would be the next thing to drift.
 */

it('never asks a status badge for a tone it does not have', function (): void {
    $component = resource_path('views/components/status-badge.blade.php');

    expect(File::exists($component))->toBeTrue();

    // The tone map, read from the component. `'ok' => 'bg-ok-bg ...'` and friends.
    preg_match_all("/'([a-z]+)'\s*=>\s*'bg-/", File::get($component), $found);

    $tones = $found[1];

    expect($tones)->not->toBeEmpty()->and($tones)->toContain('ok', 'warn', 'bad', 'info', 'grey');

    $offences = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        /*
         | Only the literal form. A tone computed in PHP - `:tone="$window->tone()"` or a match()
         | inside the tag - cannot be read statically, and guessing at one would make this check
         | fail on correct code, which is how a check gets deleted.
         */
        preg_match_all('/<x-status-badge[^>]*\stone="([^"$]*)"/', $file->getContents(), $uses);

        foreach ($uses[1] as $tone) {
            if (! in_array($tone, $tones, true)) {
                $offences[] = $file->getRelativePathname().' asks for tone "'.$tone.'"';
            }
        }
    }

    expect($offences)->toBe([], implode("\n", $offences));
});
