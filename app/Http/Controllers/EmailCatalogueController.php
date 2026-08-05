<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Health\Diagnostics;
use App\Domain\Notifications\EmailCatalogue;
use App\Models\Membership;
use Illuminate\Contracts\View\View;

/**
 * What this installation sends by email, and when.
 *
 * Settings → Notifications already says *where* mail goes. Nothing said what Manager would ever send,
 * so the only way to find out was to read the source — which a self-hosted operator can do and a
 * hosted customer cannot, and neither should have to in order to answer "will this thing email my
 * client at three in the morning".
 *
 * Read-only, and it holds nothing secret: role names, event types and sentences. So it sits outside
 * the recent-authentication group that the writing screens are in, and it is gated on
 * `canAdminister()` rather than on ownership.
 *
 * That is the deliberate contrast with {@see MailSettingsController}, which is owner-only. The Mail
 * screen holds a relay's host and login, and whoever administers sites is not necessarily whoever
 * holds those. This screen holds no credential at all, so the same gate would be borrowed authority
 * rather than a reason.
 */
final class EmailCatalogueController
{
    public function __construct(
        private readonly EmailCatalogue $catalogue,
        private readonly Diagnostics $diagnostics,
    ) {}

    public function show(): View
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        return view('settings.emails', [
            'entries' => $this->catalogue->all(),

            /*
             | Whether mail leaves this installation at all, rendered beside the list of what it would
             | send. The two facts are only useful together: a complete catalogue above a transport
             | that is writing to a log file is a list of things that are not happening.
             |
             | Reuses the check manager:doctor runs rather than asking the question again, for the
             | reason SettingsController gives about the same check — two implementations would
             | eventually disagree, and the one nobody is looking at is the one that would be wrong.
             */
            'mail' => collect($this->diagnostics->forReader())
                ->first(fn ($check): bool => $check->name === 'Mail'),
        ]);
    }
}
