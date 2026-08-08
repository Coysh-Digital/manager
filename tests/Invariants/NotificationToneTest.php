<?php

declare(strict_types=1);

use App\Domain\Backup\BackupFailureNotice;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Mail\AlertMail;
use App\Models\Site;
use Illuminate\Support\Facades\File;

/*
 * Every alert arrives in the right colour, and the loud one stays rare.
 *
 * The tone is derived from the event type rather than passed in, so it cannot be set correctly in
 * one place and forgotten in another. What that trades away is a compiler error when somebody adds
 * an event: `toneFor()` has a `default`, deliberately - an unrecognised type should produce a calm
 * alert rather than an exception on the way out of the queue - so a new entry in the catalogue
 * silently becomes INFO. This is the thing that notices.
 *
 * The second property matters more than the first. Red has to mean "act now" and nothing else; the
 * moment a routine confirmation arrives in the same box as a failed backup, the box has stopped
 * saying anything and the next real one is skimmed past.
 */

it('gives every catalogued event a tone', function (): void {
    $catalogue = NotificationEvent::catalogue();

    expect($catalogue)->not->toBeEmpty();

    $tones = [NotificationEvent::TONE_BAD, NotificationEvent::TONE_WARN, NotificationEvent::TONE_INFO];

    foreach (array_keys($catalogue) as $type) {
        // toBeTrue rather than toContain, because Pest reads every further argument to toContain as
        // another needle - so a "message" passed there becomes a second thing being asserted, and
        // the failure it produces describes neither.
        expect(in_array(NotificationEvent::toneFor($type), $tones, true))
            ->toBeTrue($type.' has no tone the mail theme can render');
    }
});

it('keeps a tone for every type the theme has a colour for, and vice versa', function (): void {
    /*
     * The two halves of one decision live in different languages - a match() in PHP and class names
     * in a CSS file the mail pipeline inlines. A tone with no matching class does not error: the
     * inliner finds nothing, the box arrives with no border and no tint, and the alert looks like a
     * plain table. Nothing is logged, and the only way to find out is to receive one.
     */
    $theme = File::get(resource_path('views/vendor/mail/html/themes/manager.css'));

    foreach ([NotificationEvent::TONE_BAD, NotificationEvent::TONE_WARN, NotificationEvent::TONE_INFO] as $tone) {
        expect(str_contains($theme, '.detail-'.$tone))
            ->toBeTrue("the theme has no box colour for the {$tone} tone");

        expect(str_contains($theme, '.detail-cause-'.$tone))
            ->toBeTrue("the theme has no cause colour for the {$tone} tone");
    }
});

it('reserves the loudest tone for the things that have actually broken', function (): void {
    // A judgement, written down so changing it is deliberate. A backup that is not happening and an
    // exploitable finding are the two events somebody has to act on today.
    expect(NotificationEvent::toneFor(NotificationEvent::BACKUP_FAILED))->toBe(NotificationEvent::TONE_BAD)
        ->and(NotificationEvent::toneFor(NotificationEvent::FINDING_OPENED))->toBe(NotificationEvent::TONE_BAD)
        ->and(NotificationEvent::toneFor(NotificationEvent::CAPABILITY_CONFIRMED))->toBe(NotificationEvent::TONE_INFO);
});

it('colours the reason and nothing else in the box', function (): void {
    /*
     * The distinctive part of the design, and the part that quietly stops working. If the label the
     * template compares against ever drifts from the label rows() actually produces, every value
     * renders plain - the email still arrives, still has its box, and simply no longer points at
     * the one line somebody needs.
     */
    $site = Site::factory()->create(['name' => 'Example Site', 'expected_domain' => 'example.org']);
    $reason = 'stopped responding; no progress within the allowed time';

    $rows = app(EmailTransport::class)->rows(BackupFailureNotice::event($site, $reason, '01ARTIFACT'));

    expect($rows)->toHaveKey(EmailTransport::CAUSE_LABEL)
        ->and($rows[EmailTransport::CAUSE_LABEL])->toBe($reason);

    $html = (string) (new AlertMail(BackupFailureNotice::event($site, $reason, '01ARTIFACT')))->render();

    // The red is inlined onto the cell holding the reason, and onto no other.
    expect($html)->toContain('#9e1733')
        ->and($html)->toMatch('/#9e1733[^"]*"[^>]*>'.preg_quote($reason, '/').'/')
        ->and(substr_count($html, '#9e1733'))->toBe(1);
});

it('does not say the same thing twice now the reason has a row of its own', function (): void {
    /*
     * The failure this replaced ran the other way: the reason led the prose, so the labelled row was
     * dropped to avoid printing it twice. Moving it into the box has to not reintroduce the
     * duplicate from the other direction.
     */
    $site = Site::factory()->create(['name' => 'Example Site']);
    $reason = 'The site refused the artifact';

    $event = BackupFailureNotice::event($site, $reason);

    expect(substr_count(app(EmailTransport::class)->body($event), $reason))->toBe(1)
        ->and($event->summary)->not->toContain($reason);
});

it('still carries the reason to a webhook, which is why it was never only prose', function (): void {
    $site = Site::factory()->create();
    $reason = 'The site refused the artifact';

    expect(BackupFailureNotice::event($site, $reason)->toPayload()['context']['reason'])->toBe($reason);
});
