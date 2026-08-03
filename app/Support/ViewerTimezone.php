<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What time it is where the person reading the screen is.
 *
 * Two answers, and it used to be three. The middle one was the organisation's zone, on the
 * reasoning that it already meant "where your sites are" and so was a sensible default for a
 * colleague who had never opened their account screen.
 *
 * That reasoning was wrong twice over. It was never presented as a setting anybody would recognise
 * — it lived inside a block about backup retention — so a time silently rendered in it was a time
 * rendered in a zone the reader had never chosen and could not find. And "where your sites are" is
 * not one place: a fleet spread across London and Sydney has no single quiet hour, which is why
 * scheduling now reads the *site's* zone and the organisation column is gone.
 *
 * So: the reader's own setting, or the application's, which is UTC unless an operator changed it.
 * Nothing in between guesses on their behalf.
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
