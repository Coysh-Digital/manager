{{--
    The persistent sidebar.

    It lists only what exists. The design prototype showed Updates, Findings, Backups, Clients and
    Connector keys as well, but those land in later phases, and a nav full of links that go nowhere
    teaches people to distrust the whole menu.

    Below the large breakpoint it becomes a drawer: off-canvas, driven by the checkbox in the layout,
    and sliding in over a scrim. It is still the same list — a phone gets the whole navigation rather
    than an abridged one, because the screen somebody reaches for during an incident is usually the
    one in their pocket.
--}}
<nav id="sidebar"
     class="fixed inset-y-0 left-0 z-40 flex h-screen w-[264px] flex-none -translate-x-full flex-col border-r border-border bg-nav transition-transform duration-200 ease-out peer-checked:translate-x-0 motion-reduce:transition-none lg:sticky lg:top-0 lg:w-[236px] lg:translate-x-0">
    <div class="flex h-14 flex-none items-center gap-2.5 border-b border-border px-[18px]">
        <x-wordmark />

        {{-- Only reachable while the drawer is open, which is the only time it means anything. --}}
        <label for="nav-drawer"
               class="ml-auto flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-text-2 hover:bg-row-hover hover:text-text lg:hidden">
            <span aria-hidden="true" class="text-[15px] leading-none">&times;</span>
            <span class="sr-only">Close menu</span>
        </label>
    </div>

    <div class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-2.5 pt-3.5">
        <div class="px-2.5 pb-2 pt-1.5 font-mono text-[10px] uppercase tracking-[0.08em] text-text-3">Fleet</div>

        <a href="{{ route('sites.index') }}"
           @class([
               'flex items-center justify-between rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('sites.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('sites.*'),
           ])>
            <span>Sites</span>
            @isset($siteCount)
                <span class="font-mono text-[11px] text-text-3">{{ $siteCount }}</span>
            @endisset
        </a>

        <a href="{{ route('updates.index') }}"
           @class([
               'flex items-center justify-between rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('updates.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('updates.*'),
           ])>
            <span>Updates</span>
            @isset($updateCount)
                @if ($updateCount > 0)
                    {{-- A security release anywhere in the fleet gets the amber treatment, because it
                         is the one number on this screen that should interrupt somebody. --}}
                    <span @class([
                        'font-mono text-[11px]',
                        'rounded px-1.5 py-px border border-amber-line bg-amber-bg text-amber' => $securityUpdates ?? false,
                        'text-text-3' => ! ($securityUpdates ?? false),
                    ])>{{ $updateCount }}</span>
                @endif
            @endisset
        </a>

        <a href="{{ route('findings.index') }}"
           @class([
               'flex items-center justify-between rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('findings.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('findings.*'),
           ])>
            <span>Findings</span>
            @isset($findingCount)
                @if ($findingCount > 0)
                    <span @class([
                        'font-mono text-[11px]',
                        'rounded px-1.5 py-px border border-danger-line bg-danger-bg text-danger' => $severeFindings ?? false,
                        'rounded px-1.5 py-px border border-amber-line bg-amber-bg text-amber' => ! ($severeFindings ?? false),
                    ])>{{ $findingCount }}</span>
                @endif
            @endisset
        </a>

        <a href="{{ route('backups.index') }}"
           @class([
               'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('backups.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('backups.*'),
           ])>
            Backups
        </a>

        <div class="px-2.5 pb-2 pt-[18px] font-mono text-[10px] uppercase tracking-[0.08em] text-text-3">Access</div>

        <a href="{{ route('activity.index') }}"
           @class([
               'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('activity.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('activity.*'),
           ])>
            Activity log
        </a>
    </div>

    <div class="flex flex-col gap-0.5 border-t border-border p-2.5">
        {{--
            Billing, on the editions where somebody is billed.

            Resolved here rather than passed in by the view composer, the same way <x-wordmark />
            resolves ProductLabel: this partial renders on every authenticated page, and a composer
            key would have to be supplied on all of them or default to hiding the link, which is the
            failure this is meant to prevent.

            Null self-hosted, so the entry does not exist there — which is the honest answer and also
            what the note at the top of this file asks for. Nobody bills a self-hosted installation,
            and a nav item leading to a page that explains that is worse than no nav item.

            fullUrlIs rather than routeIs, because the route belongs to a hosting layer this
            repository does not know the name of. The trailing * keeps it highlighted when the page
            comes back from a checkout with a query string on it.
        --}}
        @php($billingUrl = app(App\Contracts\BillingAdministration::class)->url())

        @if ($billingUrl)
            <a href="{{ $billingUrl }}"
               @class([
                   'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
                   'bg-pale text-primary' => request()->fullUrlIs($billingUrl.'*'),
                   'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->fullUrlIs($billingUrl.'*'),
               ])>
                Billing
            </a>
        @endif

        {{--
            Every settings-domain route, not only the ones named settings.*.

            The writes on those screens are named for the thing they act on — team.invite,
            recovery-keys.revoke, notifications.store — so none of them ever lit this entry. They are
            all POSTs that redirect, so it rarely showed; where it did was the confirm-password
            interstitial, which is exactly the moment somebody wants to know where they still are.
        --}}
        @php($inSettings = request()->routeIs(
            'settings.*', 'account.*', 'team.*', 'recovery-keys.*', 'notifications.*', 'passkeys.*',
        ))

        <a href="{{ route('settings.show') }}"
           @class([
               'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => $inSettings,
               'text-text-2 hover:bg-row-hover hover:text-text' => ! $inSettings,
           ])>
            Settings
        </a>

        {{-- "Account and security" was a second entry here, and a second destination. Both are now
             tabs of Settings: one place to go for anything that is a setting, whether it belongs to
             the installation or to the person reading. --}}

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="w-full rounded-md px-2.5 py-[7px] text-left text-[13.5px] font-medium text-text-2 hover:bg-row-hover hover:text-text">
                Sign out
            </button>
        </form>

        {{--
            Which version this is, wherever somebody happens to be standing.

            It was on Settings and nowhere else, so answering "which version are you running?" — the
            question a support conversation opens with — needed a navigation first. It stays on
            Settings too; this is an addition rather than a move.

            Resolved here rather than passed in by the view composer, for the same reason the billing
            URL above is: this partial renders on every authenticated page, and a composer key would
            have to be supplied on all of them or default to hiding it.

            Unset is the normal state for a clone or a tarball — `git archive` leaves no .git behind,
            so an installation genuinely cannot know. Saying so beats printing a number somebody would
            later quote at us. See config/manager.php.
        --}}
        <div class="mt-1 border-t border-border px-2.5 pt-2.5">
            <x-changelog-link :href="\App\Domain\Updates\ChangelogLink::manager()"
                              :label="config('manager.version') ?? 'unreleased build'"
                              :title="config('manager.version') ? null : 'Set MANAGER_VERSION to record which release this is'"
                              mono />
        </div>
    </div>
</nav>
