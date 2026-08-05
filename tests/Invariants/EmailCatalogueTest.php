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

it('gives every editable email a well-formed, unique key', function (): void {
    $keys = collect(app(EmailCatalogue::class)->editable())
        ->map(fn (EmailCatalogueEntry $entry): ?string => $entry->key())
        ->all();

    // The key reaches a URL and a database column, so it is held to a shape rather than left to
    // whatever somebody typed.
    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z0-9]+\.[a-z0-9-]+$/');
    }

    expect($keys)->toBe(array_values(array_unique($keys)));
});

it('ships wording for everything it says is editable', function (): void {
    // An editable entry with an empty default would give the editor a blank box and the reader no
    // idea what the email used to say.
    foreach (app(EmailCatalogue::class)->editable() as $entry) {
        expect($entry->copy?->subject)->not->toBe('')
            ->and($entry->copy?->body)->not->toBe('');
    }
});

it('declares every placeholder its wording uses, and uses every one it declares', function (): void {
    /*
     | The drift guard, and the reason placeholders are declared rather than inferred from the text.
     |
     | A token nothing declares is a token nothing substitutes, so `:organisation` mistyped as `:org`
     | would send to a customer as those four literal characters. A declared token nothing uses is
     | the other half: the editor offers it, somebody puts it in their rewording, and it renders as
     | itself for the same reason.
     */
    foreach (app(EmailCatalogue::class)->editable() as $entry) {
        $template = $entry->copy;
        $used = $template->tokensUsed();
        $declared = array_keys($template->placeholders);

        sort($used);
        sort($declared);

        expect($used)->toBe($declared);
    }
});

it('advertises the same wording the sending class actually uses', function (): void {
    // The catalogue takes each template from the class rather than restating it, so this asserts
    // that nobody has started restating one.
    foreach (app(EmailCatalogue::class)->editable() as $entry) {
        expect($entry->notification)->not->toBeNull();

        expect($entry->copy)->toEqual($entry->notification::copy());
    }
});

it('refuses to make the monitoring alerts or the password reset editable', function (): void {
    /*
     | Pinned, because "let's make all the copy editable" is the obvious next instruction and these
     | are the exceptions to it.
     |
     | The alerts go out through EmailTransport as plain text, and its docblock gives the reason: an
     | HTML mail about a security finding is a phishing template somebody has been trained to click.
     | There is also no MailMessage to override — Mail::raw builds the body itself.
     |
     | The password reset is the framework's own notification. Rewording it here would mean either
     | reimplementing it or pretending we had.
     */
    $uneditable = collect(app(EmailCatalogue::class)->all())
        ->filter(fn (EmailCatalogueEntry $entry): bool => ! $entry->editable())
        ->map(fn (EmailCatalogueEntry $entry): string => $entry->name)
        ->values()
        ->all();

    foreach (NotificationEvent::catalogue() as $label) {
        expect($uneditable)->toContain($label);
    }

    expect($uneditable)->toContain('Password reset')
        ->and($uneditable)->toContain('Test message');
});

it('reads its wording through the resolver in every class that has some', function (): void {
    // Source-level, in the idiom MailBrandingTest already uses. A class carrying a template but
    // building its text inline would pass every other test in this file and ignore every override.
    foreach (app(EmailCatalogue::class)->editable() as $entry) {
        $path = app_path(str_replace(['App\\', '\\'], ['', '/'], $entry->notification).'.php');

        expect(File::get($path))->toContain('EmailCopy');
    }
});

it('claims nothing hosted on an installation with no hosting layer', function (): void {
    // With nothing installed this repository resolves to itself, the same property EditionSeamTest
    // asserts for the contracts. A hosted row here would mean the core had learned about an edition
    // it is not running.
    expect(app(EmailCatalogue::class)->hosted())->toBe([]);
});
