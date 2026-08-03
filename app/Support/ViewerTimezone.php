<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What time it is where the person reading the screen is.
 *
 * Three answers in a fixed order, and the order is the whole design:
 *
 *  1. **The reader's own setting**, when they have expressed one. A team is not necessarily in one
 *     place, and somebody in Sydney reading a London installation should not have to subtract.
 *  2. **The organisation's**, otherwise. It already existed and already means "where your sites
 *     are" — it is what a backup schedule reads — so it is the right default for a colleague who
 *     has never opened their account screen.
 *  3. **The application's**, which is UTC unless an operator changed it.
 *
 * A preference nobody has expressed must not become a third answer, which is why the user column is
 * nullable rather than defaulted: writing 'UTC' at registration would pin every account to UTC and
 * make the organisation setting unreachable for anybody who never noticed the field.
 *
 * Relative times — `diffForHumans()`, which is most of what these screens render — are the same in
 * every zone and deliberately do not come through here. This is only for the absolute ones.
 */
final class ViewerTimezone
{
    /**
     * The identifier to render in.
     */
    public static function for(?User $user = null): string
    {
        $user ??= auth()->user();

        if ($user?->timezone !== null && $user->timezone !== '') {
            return $user->timezone;
        }

        // From the container first: the organisation is already bound per request by the middleware
        // that scopes everything else, and a screen renders dozens of timestamps — a lazy load per
        // row would be an N+1 nobody would go looking for behind a date.
        if (app()->bound(Organisation::class)) {
            $organisation = app(Organisation::class);

            if ($organisation instanceof Organisation && $organisation->timezone !== '') {
                return $organisation->timezone;
            }
        }

        /*
         | Nothing bound: a queued job, a console command, a test that never made a request.
         |
         | Not memoised, deliberately. During a request the branch above always answers — the
         | middleware that scopes every query binds the organisation — so this runs only where
         | timestamps are formatted a handful at a time rather than down a table. A cache here would
         | buy nothing measurable and would hold a stale zone for the life of a queue worker.
         */
        if ($user !== null) {
            $timezone = $user->memberships()
                ->with('organisation')
                ->get()
                ->map(fn (Membership $membership): ?string => $membership->organisation?->timezone)
                ->first(fn (?string $zone): bool => $zone !== null && $zone !== '');

            if ($timezone !== null && $timezone !== '') {
                return $timezone;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * The same instant, in the reader's zone.
     *
     * Copied rather than mutated. Carbon's setTimezone() changes the instance in place, and these
     * are model attributes — shifting one would leave the model holding a time in a zone that is
     * nobody's business but the view's.
     */
    public static function apply(Carbon $at, ?User $user = null): Carbon
    {
        return $at->copy()->setTimezone(self::for($user));
    }
}
