<?php

declare(strict_types=1);

use App\Domain\Backup\BackupFailureNotice;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\FailureReason;
use App\Mail\AlertMail;
use App\Models\Site;

/*
 | What a failed backup actually tells somebody.
 |
 | Written against a real one. A production alert read:
 |
 |   Reason  RuntimeException: The platform rejected the request (HTTP 422). This organisation has
 |           no room left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN
 |
 | Four things in one line. The class name is an implementation detail, the status code says the
 | platform refused - which the reader knows, because they are reading an email about a refusal —
 | and the identifier is for whoever they forward it to. One clause in the middle says what happened,
 | and it was the only part without a label.
 |
 | It also never said what to do, and the answer was a two-minute setting.
 |
 | The wire is deliberately not part of this. `context.reason` still carries the string exactly as
 | reported, which is what the 1.5.1 notes promised webhook consumers; the tidying happens where the
 | message is rendered.
 */

it('reads the reason out of the exception it arrived wrapped in', function (): void {
    $reason = FailureReason::from(
        'RuntimeException: The platform rejected the request (HTTP 422). This organisation has no '
        .'room left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN'
    );

    expect($reason->sentence)->toBe('This organisation has no room left for another backup.')
        ->and($reason->correlationId)->toBe('01KZK4EVWHSX9MYD7R5SHTAACN')
        ->and($reason->advice)->toContain('Shorten the retention');
});

it('passes a message it does not recognise through untouched', function (string $raw): void {
    // The failure mode worth avoiding most. Trimming an unfamiliar message into something shorter
    // risks trimming it into something that misleads, and the reader has no way to tell.
    expect(FailureReason::from($raw)->sentence)->toBe($raw);
})->with([
    'plain sentence' => ['Something nobody has seen before happened.'],
    'a colon that is not a class' => ['Restore failed: the archive is truncated.'],
    'refusal with no explanation' => ['The platform rejected the request (HTTP 413).'],
]);

it('keeps the framing when it is the whole message', function (): void {
    // A proxy answering instead of the application looks exactly like this. An empty reason is not
    // an improvement on a vague one.
    $reason = FailureReason::from('The platform rejected the request (HTTP 413). Correlation ID: unknown');

    expect($reason->sentence)->toBe('The platform rejected the request (HTTP 413).')
        ->and($reason->correlationId)->toBeNull();
});

it('offers no advice for a failure whose remedy is not certain', function (): void {
    // Guessing teaches people that the advice is worth ignoring, which costs more than the alert
    // that said nothing.
    expect(FailureReason::from('The dump produced no file.')->advice)->toBeNull();
});

it('gives the correlation identifier a row of its own', function (): void {
    $site = Site::factory()->create(['name' => 'Example Site', 'expected_domain' => 'example.org']);

    $rows = app(EmailTransport::class)->rows(BackupFailureNotice::event(
        $site,
        'RuntimeException: The platform rejected the request (HTTP 422). This organisation has no '
        .'room left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN',
    ));

    // Findable, which is the entire reason somebody forwarding this needs it, and which a sentence
    // is the worst place for.
    expect($rows[EmailTransport::CAUSE_LABEL])->toBe('This organisation has no room left for another backup.')
        ->and($rows['Correlation ID'])->toBe('01KZK4EVWHSX9MYD7R5SHTAACN');
});

it('keeps the wire promise that context.reason is verbatim', function (): void {
    $site = Site::factory()->create(['name' => 'Example Site']);
    $raw = 'RuntimeException: The platform rejected the request (HTTP 422). This organisation has '
        .'no room left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN';

    $payload = BackupFailureNotice::event($site, $raw)->toPayload();

    // 1.5.1 told webhook consumers this field carries the failure verbatim. A payload is not the
    // place to be helpful, and somebody parsing it may be matching on the whole string.
    expect($payload['context']['reason'])->toBe($raw);
});

it('says what to do about a full organisation, in both parts of the message', function (): void {
    $site = Site::factory()->create(['name' => 'Example Site', 'expected_domain' => 'example.org']);
    $event = BackupFailureNotice::event(
        $site,
        'RuntimeException: The platform rejected the request (HTTP 422). This organisation has no '
        .'room left for another backup. Correlation ID: 01KZK4EVWHSX9MYD7R5SHTAACN',
    );

    $html = (string) (new AlertMail($event))->render();
    $text = app(EmailTransport::class)->body($event);

    /*
     | The thing the alert never said. A backup stopped happening for a reason with a two-minute
     | remedy, and the message reported the failure and left the reader to work out that it was a
     | limit rather than a fault.
     |
     | Asserted in the text part as well, because that has to stand alone as a complete message -
     | advice appearing only in the HTML would leave whoever reads plain text with the half that
     | states a problem.
    */
    expect($html)->toContain('Shorten the retention')
        ->and($html)->not->toContain('RuntimeException')
        ->and($text)->toContain('Shorten the retention')
        ->and($text)->not->toContain('RuntimeException');
});

it('adds nothing to an alert whose reason has no known remedy', function (): void {
    $site = Site::factory()->create(['name' => 'Example Site']);
    $event = BackupFailureNotice::event($site, 'The dump produced no file.');

    expect(app(EmailTransport::class)->advice($event))->toBeNull()
        ->and((string) (new AlertMail($event))->render())->not->toContain('Shorten the retention');
});
