<?php

declare(strict_types=1);

use App\Domain\Notifications\EmailCopy;
use App\Models\EmailCopyOverride;
use App\Models\User;
use App\Notifications\TeamInvitation;

/*
 * Changing what an email says, and the parts of it that no change can reach.
 *
 * The mechanism is small — an override if there is a row, the code's wording otherwise — so most of
 * what is worth asserting is about the boundaries rather than the happy path: that reverting really
 * does restore the default, that a token nothing substitutes is refused before it can reach an
 * inbox, and that the sentences held back in code stay there whatever somebody types.
 */

/** The rendered HTML of an invitation, which is what actually goes out. */
function renderedInvitation(): string
{
    return (string) (new TeamInvitation('token', 'Coysh Digital', 'Tim'))
        ->toMail(new User(['name' => 'Invitee', 'email' => 'invitee@example.org']))
        ->render();
}

it('sends the wording in the code when nothing has been overridden', function (): void {
    expect(EmailCopyOverride::query()->count())->toBe(0)
        ->and(renderedInvitation())->toContain('Tim has invited you to help look after Coysh Digital');
});

it('sends an operator rewording instead, with the placeholders filled in', function (): void {
    app(EmailCopy::class)->put(
        TeamInvitation::COPY_KEY,
        subject: ':inviter would like your help',
        body: ":inviter has invited you to :organisation.\n\nA second paragraph.",
    );

    $rendered = renderedInvitation();

    expect($rendered)->toContain('Tim has invited you to Coysh Digital.')
        ->and($rendered)->toContain('A second paragraph.')
        ->and($rendered)->not->toContain(':inviter')
        ->and($rendered)->not->toContain('help look after');
});

it('puts the subject on the message as well as the body', function (): void {
    app(EmailCopy::class)->put(TeamInvitation::COPY_KEY, subject: 'An invitation', body: null);

    $message = (new TeamInvitation('token', 'Coysh Digital', 'Tim'))
        ->toMail(new User(['name' => 'Invitee', 'email' => 'invitee@example.org']));

    expect($message->subject)->toBe('An invitation');
});

it('lets one half be overridden without restating the other', function (): void {
    // Subject and body are separately nullable so changing a subject does not oblige somebody to
    // paste the body back in, which is how a body gets accidentally truncated.
    app(EmailCopy::class)->put(TeamInvitation::COPY_KEY, subject: 'An invitation', body: null);

    expect(renderedInvitation())->toContain('Tim has invited you to help look after Coysh Digital');
});

it('restores the shipped wording when the override is forgotten', function (): void {
    $copy = app(EmailCopy::class);

    $copy->put(TeamInvitation::COPY_KEY, subject: 'Gone', body: 'Also gone.');
    expect(renderedInvitation())->toContain('Also gone.');

    $copy->forget(TeamInvitation::COPY_KEY);

    // Reverting is a delete: the absence of a row is the default, so there is no state left behind
    // that could later be mistaken for a deliberate empty rewording.
    expect(EmailCopyOverride::query()->count())->toBe(0)
        ->and(renderedInvitation())->toContain('Tim has invited you to help look after Coysh Digital');
});

it('refuses a placeholder nothing would replace', function (): void {
    /*
     | The failure this prevents is silent and customer-facing: an unsubstituted token sends as the
     | literal characters `:org`, in an email whose whole job is to look legitimate to somebody who
     | has never heard of us.
     */
    expect(fn () => app(EmailCopy::class)->put(
        TeamInvitation::COPY_KEY,
        subject: null,
        body: ':org has invited you.',
    ))->toThrow(InvalidArgumentException::class, ':org');

    expect(EmailCopyOverride::query()->count())->toBe(0);
});

it('does not mistake a URL scheme for a placeholder', function (): void {
    /*
     | `mailto:support` and `https://…` both contain a colon followed by a word, and a naive token
     | pattern reads those as placeholders — so an operator adding an ordinary contact link would be
     | told that nothing replaces `:support`. A placeholder is a word the colon *introduces*; a
     | colon with a letter already against it is punctuation.
     */
    app(EmailCopy::class)->put(
        TeamInvitation::COPY_KEY,
        subject: null,
        body: 'Write to us at mailto:support@example.org or read https://example.org/help first.',
    );

    expect(renderedInvitation())->toContain('mailto:support@example.org');
});

it('refuses to store wording for an email nobody may reword', function (): void {
    expect(fn () => app(EmailCopy::class)->put('core.password-reset', 'x', 'y'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the link, the expiry and the phishing note whatever the override says', function (): void {
    /*
     | The three things held back in code, asserted together because they are one decision: an
     | editable action URL would make this an open redirector, an editable expiry could contradict
     | what the token actually does, and the closing paragraph is the sentence that tells somebody
     | it is safe to ignore an invitation they cannot place.
     */
    app(EmailCopy::class)->put(
        TeamInvitation::COPY_KEY,
        subject: 'Everything replaced',
        body: 'Nothing of the original remains.',
    );

    $rendered = renderedInvitation();

    expect($rendered)->toContain('Set your password')
        ->and($rendered)->toContain('reset-password/token')
        ->and($rendered)->toContain('can be used once')
        ->and($rendered)->toContain('If you were not expecting this, ignore it.');
});

it('records who last changed it', function (): void {
    $operator = User::factory()->create();

    app(EmailCopy::class)->put(TeamInvitation::COPY_KEY, 'Subject', 'Body.', $operator);

    expect(EmailCopyOverride::query()->where('key', TeamInvitation::COPY_KEY)->value('updated_by'))
        ->toBe($operator->id);
});

it('keeps one row per email however many times it is edited', function (): void {
    $copy = app(EmailCopy::class);

    $copy->put(TeamInvitation::COPY_KEY, 'One', 'First.');
    $copy->put(TeamInvitation::COPY_KEY, 'Two', 'Second.');

    expect(EmailCopyOverride::query()->count())->toBe(1)
        ->and(renderedInvitation())->toContain('Second.');
});
