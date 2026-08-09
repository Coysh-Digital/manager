<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Which reads hand back bytes rather than a page.
 *
 * Nearly every GET route here renders a screen. One does not: an artifact's ciphertext is streamed
 * to the caller, and it is the only read in the application that returns a file.
 *
 * That distinction carries weight past the route file. Anything deciding that a session may read but
 * not write - a support tool, an operator looking at an account, a read-only role - starts from the
 * HTTP method, because the method is the only signal available before dispatch. Method alone is not
 * enough: a read that returns a database, even an encrypted one, is not the harmless thing "GET" is
 * being taken to mean. So such a decision ends up carrying a hand-written list of exceptions.
 *
 * Both ways that list rots are invisible from wherever it is written. An exception recorded as a
 * route name stops matching the moment the route is renamed, and nothing fails - the guard keeps
 * working, on a route that no longer exists. A download added later is not on the list at all, and
 * whoever added it had no reason to know a list existed.
 *
 * Pinning the set here puts both failures in front of somebody, in the repository where the routes
 * actually are. If this test fails, the fix is not to widen the expectation: it is to decide what the
 * new download means for anyone who was told reads are safe, and to say so where that decision is
 * made.
 */
it('keeps the artifact download under the name the rest of the system knows it by', function (): void {
    $route = Route::getRoutes()->getByName('backups.download');

    expect($route === null)->toBe(
        false,
        'The artifact download route has been renamed or removed. It is named as an exception by '
        .'anything that treats a GET as a safe read, and a rename turns that exception off silently.'
    );

    // The method matters as much as the name. Were this to stop being a GET it would be caught by the
    // ordinary write handling, and the exception would no longer be needed - which is also a change
    // worth making deliberately rather than discovering.
    expect($route->methods())->toContain('GET')
        ->and($route->getActionName())->toContain('BackupDownloadController');
});

it('returns a downloadable body from exactly one place', function (): void {
    $markers = [
        'streamDownload(',
        'response()->file(',
        'Content-Disposition',
        '->download(',
    ];

    $found = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach ($markers as $marker) {
            if (str_contains($contents, $marker)) {
                $found[] = $file->getFilename();

                break;
            }
        }
    }

    sort($found);

    expect($found)->toBe(
        ['BackupDownloadController.php'],
        'The set of places returning a file has changed: '.implode(', ', $found).'. A new download is '
        .'a new way for a session that was only ever meant to read to leave with bytes in its hands.'
    );
});
