@php
    /*
     | Seven screens, in the order somebody works through them: what this installation is, who they
     | are, how they get in, who else can, what gets told about it, what can open a backup, and how
     | mail leaves.
     |
     | The count in that first sentence is load-bearing prose and goes stale the moment somebody adds
     | a tab without reading it. If you are adding one, this line is the second edit — and so is the
     | count in the comment above the nav below, which says the same thing about a different number.
     |
     | `also` exists because the writes on these screens are named for the thing they act on rather
     | than for the screen - team.invite, recovery-keys.revoke, notifications.store, passkeys.store.
     | Every one of them redirects, so in practice a tab is rarely rendered under those names; the
     | patterns are here so that stays true by construction rather than by luck, and so the
     | confirm-password interstitial does not lose its place.
     |
     | No count pills, unlike <x-site-tabs>. A count there means something demanding attention —
     | outstanding updates, open findings. "6 people" is not that, and computing three of them would
     | be nine wasted queries across seven screens.
     */
    $tabs = [
        ['route' => 'settings.show', 'label' => 'General'],
        ['route' => 'settings.account', 'label' => 'Account',
            'also' => ['account.profile', 'account.password', 'account.timezone']],
        ['route' => 'settings.security', 'label' => 'Security',
            'also' => ['account.totp.*', 'account.recovery-codes', 'account.sessions.*', 'passkeys.*']],
        ['route' => 'settings.people', 'label' => 'People', 'also' => ['team.*']],
        ['route' => 'settings.notifications', 'label' => 'Notifications', 'also' => ['notifications.*']],

        ['route' => 'settings.recovery-keys', 'label' => 'Recovery keys', 'also' => ['recovery-keys.*']],
    ];

    /*
     | Mail, on the editions that hold their own relay.
     |
     | Resolved here rather than passed in as a prop, the same way the sidebar resolves
     | BillingAdministration and the health card resolves ServerAccess: this component renders on
     | every settings screen, and a prop would have to be supplied by every controller or default to
     | hiding the tab - which is the failure it exists to prevent.
     |
     | Owner-gated as well as edition-gated. The screen holds a relay's host and login, and whoever
     | can administer sites is not necessarily whoever holds those. MailSettingsController refuses on
     | both counts too: hiding a control is not removing it.
     */
    if ($currentMembership->isOwner() && app(App\Contracts\MailAdministration::class)->operatorManaged()) {
        $tabs[] = ['route' => 'settings.mail', 'label' => 'Mail', 'also' => ['settings.mail.*']];
    }
@endphp

{{-- Scrolls sideways rather than wrapping to two rows on a phone, for the same reason the site tabs
     do: six tabs wrapped is a block of navigation taller than the content under it.

     `relative` is load-bearing. An sr-only element inside an unpositioned overflow-x-auto container
     resolves its absolute position against the page and extends the document sideways —
     tests/Invariants/ResponsiveLayoutTest.php fails the build for it. --}}
<nav aria-label="Settings sections" class="relative -mx-4 mb-5 overflow-x-auto border-b border-border px-4 sm:mx-0 sm:mb-6 sm:px-0">
    <ul class="-mb-px flex w-max min-w-full items-end gap-x-1">
        @foreach ($tabs as $tab)
            @php $active = request()->routeIs($tab['route'], ...($tab['also'] ?? [])); @endphp

            <li>
                <a href="{{ route($tab['route']) }}"
                   @if ($active) aria-current="page" @endif
                   class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-[13px] no-underline
                          {{ $active
                              ? 'border-primary font-medium text-text'
                              : 'border-transparent text-text-2 hover:border-border-2 hover:text-text' }}">
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
