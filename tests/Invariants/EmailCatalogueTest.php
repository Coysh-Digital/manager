<?php

declare(strict_types=1);

use App\Domain\Notifications\EmailCatalogue;
use App\Domain\Notifications\EmailCatalogueEntry;
use App\Domain\Notifications\NotificationEvent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;

/*
 * The catalogue has to be complete, or it is worse than nothing.
 *
 * A screen headed "every email this installation sends" is read as exhaustive. Somebody deciding
 * whether Manager will mail their client at three in the morning is entitled to treat an absence as
 * an answer, so a notification that exists and is not listed does not merely omit a row — it makes
 * the page lie.
 *
 * Hand-written entries are the right trade, because the trigger sentence is the only column carrying
 * information and no amount of reflection produces it. The cost of that trade is drift, and these
 * tests are what is bought with it: the list is compared against the filesystem and against the
 * enumeration the notifications screen already uses, so an addition that skips the catalogue fails
 * the build rather than quietly shipping an incomplete page.
 */

it('lists every notification class the core defines', function (): void {
    /*
     | Read off the directory rather than from a list here, which is the whole point — a list would
     | have the same drift problem one layer down. Enumerating cannot miss the next one; a list can
     | only be as complete as whoever last remembered to edit it.
     |
     | The core has one notification today. That is not a reason to skip this: the first person to
     | add a second is exactly who this is for, and they will not be reading this file.
     */
    $directory = app_path('Notifications');

    $classes = collect(File::isDirectory($directory) ? File::files($directory) : [])
        ->map(fn ($file): string => 'App\\Notifications\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Notification::class))
        ->sort()
        ->values()
        ->all();

    $catalogued = collect(app(EmailCatalogue::class)->all())
        ->map(fn (EmailCatalogueEntry $entry): ?string => $entry->notification)
        ->filter()
        ->sort()
        ->values()
        ->all();

    // Set equality in both directions. Missing means the screen understates what is sent; extra means
    // it names a class somebody deleted, which is the same failure wearing the other coat.
    expect($catalogued)->toBe($classes);
});

it('lists every event a notification destination can subscribe to', function (): void {
    // The alerts are derived from NotificationEvent::catalogue() rather than restated, so this cannot
    // fail by omission — it fails if somebody stops deriving them.
    $labels = array_values(NotificationEvent::catalogue());

    $names = collect(app(EmailCatalogue::class)->all())
        ->map(fn (EmailCatalogueEntry $entry): string => $entry->name)
        ->all();

    foreach ($labels as $label) {
        expect($names)->toContain($label);
    }
});

it('says what triggers each email, in a sentence', function (): void {
    /*
     | The field the catalogue exists for. An entry whose trigger is a fragment, or a restatement of
     | its own name, is the failure this screen is supposed to prevent: a list of class names dressed
     | as documentation.
     */
    foreach (app(EmailCatalogue::class)->all() as $entry) {
        expect($entry->trigger)->not->toBe('')
            ->and(mb_strlen($entry->trigger))->toBeGreaterThan(20)
            ->and($entry->trigger)->toEndWith('.')
            ->and($entry->recipients)->not->toBe('');
    }
});

it('claims nothing hosted on an installation with no hosting layer', function (): void {
    // With nothing installed this repository resolves to itself, the same property EditionSeamTest
    // asserts for the contracts. A hosted row here would mean the core had learned about an edition
    // it is not running.
    expect(app(EmailCatalogue::class)->hosted())->toBe([]);
});
