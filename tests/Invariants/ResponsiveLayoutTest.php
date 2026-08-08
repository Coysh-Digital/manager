<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | The page does not scroll sideways on a phone.
 |
 | Reported from use: a horizontal scrollbar on most screens, worst on the site tabs. Measured in a
 | browser at 375px, three screens overflowed - Health by 100px, Updates by 153px, Security by 125px
 | - and both causes turned out to be the same utility used two ways.
 |
 | `sr-only` hides by making a one-pixel box with `overflow: hidden` and taking it out of flow with
 | `position: absolute`. Both halves of that have a sharp edge:
 |
 |  - **Absolute against what.** With no positioned ancestor it resolves against the page, so an
 |    `sr-only` span inside a horizontally scrolling table keeps the x it had *inside the scroll
 |    content* - measured at 527px on a 375px viewport - and extends the document instead of
 |    scrolling with the table.
 |  - **A caption is not clipped.** A `<caption>` is laid out outside its table's box, so
 |    `overflow: hidden` on the table does not contain it. The chart's summary sentence rendered at
 |    its full 443px while being invisible.
 |
 | Neither is visible in review and neither shows up in a test that renders HTML, because both are
 | facts about layout. What can be asserted is the shape that avoids them, which is what this does.
 */

it('keeps a horizontal scroll container positioned, so nothing absolute escapes it', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        // Element by element, and the tag name matters - see the exemption below.
        preg_match_all('~<([a-z]+)[^>]*class="([^"]*\boverflow-x-auto\b[^"]*)"~', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $tag, $class]) {
            /*
             | `<pre>` and `<code>` are exempt and stay exempt: they hold one command, they contain
             | no links and no visually-hidden text, and giving every one of them a stacking context
             | would be churn for a problem they cannot have.
             */
            if (in_array($tag, ['pre', 'code'], true)) {
                continue;
            }

            if (! preg_match('~\brelative\b~', $class)) {
                $offenders[] = $file->getRelativePathname().' - <'.$tag.'> '.$class;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These scroll containers are not positioned:',
        '  '.implode("\n  ", $offenders),
        'An sr-only element inside one resolves against the page instead, keeps the x it had inside',
        'the scrolled content, and makes the whole document wider than the phone it is on.',
    ]));
});

it('lets a strip of tabs scroll sideways and not up and down', function (): void {
    /*
     | Reported from use: the site and settings tabs moved vertically as well as horizontally on a
     | phone, so a thumb aiming sideways nudged the strip out of line instead.
     |
     | The cause is a rule nobody remembers: CSS resolves an `overflow` of `visible` to `auto` as
     | soon as the other axis is not `visible`. So `overflow-x-auto` on its own is really
     | "scrollable in both directions", and these navs put one pixel of their list outside the box
     | on purpose - `-mb-px`, so the active tab's underline sits on the container's own border
     | rather than below it. One pixel is enough to scroll.
     |
     | Scoped to <nav>. A table that scrolls sideways is a different question with a different
     | answer - it may legitimately want both - and this is about a control strip, where vertical
     | movement is never wanted and is always the accident above.
     */
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        preg_match_all('~<nav[^>]*class="([^"]*\boverflow-x-auto\b[^"]*)"~', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $class]) {
            if (! preg_match('~\boverflow-y-hidden\b~', $class)) {
                $offenders[] = $file->getRelativePathname().' - '.$class;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These navigation strips scroll in both directions:',
        '  '.implode("\n  ", $offenders),
        'overflow-x-auto resolves the other axis to auto, and -mb-px puts a pixel outside the box.',
        'Add overflow-y-hidden.',
    ]));
});

it('never hides a table by putting sr-only on the table itself', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('~<table[^>]*class="[^"]*\bsr-only\b~', $contents) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These views hide a table with sr-only on the table element:',
        '  '.implode("\n  ", $offenders),
        'A <caption> is laid out outside the table box, so the table\'s own overflow does not clip',
        'it. Wrap the table in an sr-only <div> instead.',
    ]));
});

it('still hides the chart figures from sight while keeping them for a reader', function (): void {
    // The table is not decoration. A canvas is a picture to a screen reader, to a printer that drops
    // background graphics, and to anybody whose script blocked - so the fix had to keep it in the
    // page rather than solve the overflow by deleting it.
    $chart = (string) file_get_contents(resource_path('views/components/chart.blade.php'));

    expect($chart)->toContain('<div class="sr-only">')
        ->and($chart)->toContain('<caption>')
        ->and($chart)->toContain('scope="row"');
});
