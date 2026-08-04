<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What a form may carry across the recent-authentication gate.
 *
 * The gate redirects a POST to the confirm-password screen, and Laravel's `Redirector::guest()`
 * cannot preserve a POST: it records `previous()` — the Referer — as the intended URL and discards
 * the body. So somebody who filled in "Add a site", pressed the button and was asked for their
 * password arrived back at an empty, collapsed panel with no indication that anything had been
 * typed. Reported from live use, where the answer was to type it all again.
 *
 * The obvious fix is to stash the request and replay it after the password is proved. That is
 * rejected, and not narrowly:
 *
 *  - The gate proves *identity*, not *intent*. Several routes behind it require a typed
 *    confirmation — `sites.destroy` wants the domain, `capabilities.grant-confirmed` wants the site
 *    name — precisely so that the person types the thing at the moment they do it. Replaying a
 *    stashed confirmation hands that token back for free and removes the only thing it was for.
 *  - The audit log would stop being literally true. "The user pressed the button" should stay a
 *    fact rather than a reconstruction of one.
 *  - Re-entry. A back button, a refresh on the confirm-password POST, or somebody who reaches the
 *    gate, changes their mind and confirms for an unrelated reason, would each fire a stashed
 *    destructive POST.
 *
 * So nothing is replayed. The input is given back, the panel is reopened, and the person presses
 * the button again — which is the whole of the reported pain, at a cost of one click.
 *
 * Two layers decide what may be kept, and both have to agree. That is deliberate: the failure mode
 * here is a secret sitting in a session, and a single list is one careless edit away from holding
 * one.
 */
final class ResumableInput
{
    /**
     * The session key. Flash lifetime, read exactly once.
     */
    private const SESSION_KEY = 'manager.resumable_input';

    /**
     * How much may be stashed, serialised. A form is a few hundred bytes; anything approaching this
     * is not a form.
     */
    private const MAX_BYTES = 8192;

    /**
     * Layer one: which routes may be resumed, and exactly which of their fields.
     *
     * Keyed by route name. `return` is where to send the person afterwards; `fields` is an explicit
     * allowlist, not a denylist, so a route that later grows a secret field does not start leaking
     * it the day it is added — the new field is simply not restored until somebody puts it here.
     *
     * Deliberately absent, each for its own reason:
     *
     *  - `recovery-keys.prove` and `recovery-keys.challenge` — the body is a proof of possession of
     *    a key that can read every backup this organisation holds. Parking it in the session is
     *    exactly where a stolen session would look, and the challenge is single-use, so restoring
     *    it would not even work.
     *  - `sites.destroy`, `capabilities.grant-confirmed`, `settings.connectors.rotate` — typed
     *    confirmations. See the class docblock. Never resumable.
     *  - `settings.mail.test`, `updates.refresh`, `sites.refresh-all`, `findings.*` and the rest —
     *    bare buttons. There is nothing typed to give back.
     *  - `notifications.store` — its `target` is a webhook URL, and a webhook URL is a bearer
     *    credential: whoever holds it can post into that channel. It is a typed field somebody
     *    would be glad to have back, which is exactly why it needed deciding rather than assuming.
     *
     * @var array<string, array{return: string, label: string, fields: list<string>}>
     */
    private const RESUMABLE = [
        'sites.store' => [
            'return' => 'sites.index',
            'label' => 'add a site',
            'fields' => ['name', 'expected_domain', 'environment', 'capabilities'],
        ],
        'sites.settings.update' => [
            'return' => 'sites.settings',
            'label' => "change a site's details",

            // `environment` was missing, and its absence was invisible: the field falls back to
            // `old('environment', $site->environment)`, so a change to it came back looking like the
            // value that was already saved. Somebody set staging, confirmed their password, and was
            // shown production with no indication anything had been dropped.
            'fields' => ['name', 'expected_domain', 'environment'],
        ],
        'sites.backups.schedule' => [
            'return' => 'sites.backups',
            'label' => 'change when a site is backed up',
            'fields' => ['backup_schedule', 'backup_schedule_hour', 'backup_schedule_day', 'timezone'],
        ],
        'sites.backups.retention' => [
            'return' => 'sites.backups',
            'label' => 'change how long a site\'s backups are kept',
            'fields' => ['backup_retention_days', 'backup_retention_weeks', 'backup_retention_months'],
        ],
        'team.invite' => [
            'return' => 'settings.people',
            'label' => 'invite somebody',
            'fields' => ['name', 'email', 'role'],
        ],
        'settings.mail.update' => [
            'return' => 'settings.mail',
            'label' => 'change how mail is sent',
            // No credential here, and layer two would strip it anyway — FORBIDDEN_KEY matches /pass/.
            // That is the right answer rather than an omission: the field is write-only and a blank
            // one means "keep what is stored", so coming back with it empty restores the form to
            // exactly the state it should be in.
            'fields' => ['transport', 'host', 'port', 'encryption', 'username', 'region', 'from_address', 'from_name'],
        ],
    ];

    /**
     * Layer two, applied to every field that survived layer one.
     *
     * With a correct allowlist this removes nothing, which is the point: it is what stops an
     * incorrect allowlist from mattering. `confirm` is here as well as being excluded by route,
     * because the cost of being wrong about a typed confirmation is that the gate stops meaning
     * anything.
     */
    private const FORBIDDEN_KEY = '/pass|secret|token|key|signature|challenge|otp|code|confirm|proof/i';

    /**
     * Stash what the gate is about to throw away.
     *
     * Called from the middleware, before the redirect. Silent when the route is not resumable —
     * most of them are not, and a button with nothing typed in it loses nothing.
     */
    public static function capture(Request $request): void
    {
        $name = $request->route()?->getName();

        if ($name === null || ! isset(self::RESUMABLE[$name])) {
            return;
        }

        $rule = self::RESUMABLE[$name];
        $input = self::clean($request->only($rule['fields']));

        if ($input === []) {
            return;
        }

        // The return URL is resolved here, from the route parameters this request already carries,
        // rather than trusted from `url.intended`. That is what makes `sites.settings.update` come
        // back to the site it was editing, and it does not depend on a Referer header that a
        // Referrer-Policy is entitled to strip.
        $url = route($rule['return'], $request->route()?->parameters() ?? []);

        $payload = [
            'route' => $name,
            'url' => $url,

            // What the person was doing, in their words rather than a route name. The confirm screen
            // used to say only "you are about to do something", which is true of every interruption
            // and helps with none of them.
            'label' => $rule['label'],
            'input' => $input,
        ];

        if (strlen((string) json_encode($payload)) > self::MAX_BYTES) {
            return;
        }

        $request->session()->flash(self::SESSION_KEY, $payload);
    }

    /**
     * Keep it for one more request.
     *
     * Called while rendering the confirm-password screen, and without it none of this works in a
     * browser — which is how it shipped.
     *
     * Flash data lives for exactly one request. The gate flashes on the POST it refuses, so the
     * payload is readable on the GET of the confirm screen and gone by the POST that proves the
     * password. Three requests, not two. Every test covering this went straight from the refused
     * POST to the confirming POST, which is a sequence no browser performs, so the suite agreed
     * with a feature that never once fired for a person.
     */
    public static function keep(): void
    {
        session()->keep([self::SESSION_KEY]);
    }

    /**
     * What is waiting, without consuming it.
     *
     * For the confirm-password screen, which says what was interrupted. Reading it there must not
     * spend it — the screen that needs it next is the one after.
     *
     * @return array{route: string, url: string, label: string, input: array<string, mixed>}|null
     */
    public static function pending(): ?array
    {
        $payload = session(self::SESSION_KEY);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Take it back out, once.
     *
     * @return array{route: string, url: string, label: string, input: array<string, mixed>}|null
     */
    public static function resume(): ?array
    {
        $payload = session()->pull(self::SESSION_KEY);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Whether the screen being rendered is the one somebody was sent back to.
     *
     * The views use this to reopen a disclosure panel, so the restored fields are visible rather
     * than merely present.
     */
    public static function wasRestoredFor(string $routeName): bool
    {
        return session('manager.resumed_form') === $routeName;
    }

    /**
     * Route names that may be resumed. For the invariants test.
     *
     * @return list<string>
     */
    public static function resumableRoutes(): array
    {
        return array_keys(self::RESUMABLE);
    }

    /**
     * The allowlist itself. For the invariants test.
     *
     * @return array<string, array{return: string, label: string, fields: list<string>}>
     */
    public static function rules(): array
    {
        return self::RESUMABLE;
    }

    /**
     * Layer two.
     *
     * Drops anything whose name suggests a secret, and anything that is not a scalar or a flat list
     * of scalars — a nested array is not something a form on this side of the application produces,
     * and unpacking one into a session is how a stash becomes an object graph.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function clean(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY, (string) $key) === 1) {
                continue;
            }

            if (is_scalar($value)) {
                $clean[$key] = $value;

                continue;
            }

            if (is_array($value) && array_is_list($value) && $value === array_filter($value, is_scalar(...))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
