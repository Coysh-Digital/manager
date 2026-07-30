{{--
    The persistent sidebar.

    It lists only what exists. The design prototype showed Updates, Findings, Backups, Clients and
    Connector keys as well, but those land in later phases, and a nav full of links that go nowhere
    teaches people to distrust the whole menu.
--}}
<nav class="sticky top-0 flex h-screen w-[236px] flex-none flex-col border-r border-border bg-nav">
    <div class="flex h-14 items-center gap-2.5 border-b border-border px-[18px]">
        <div class="flex h-[22px] w-[22px] items-center justify-center rounded-md bg-primary text-[12px] font-semibold tracking-[-0.02em] text-primary-fg">M</div>
        <div class="flex flex-col gap-px">
            <span class="text-sm font-semibold tracking-[-0.01em]">Manager</span>
            <span class="font-mono text-[9.5px] uppercase tracking-[0.04em] text-text-3">
                {{ config('manager.edition') === 'cloud' ? 'Cloud' : 'Self-hosted' }}
            </span>
        </div>
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
        <a href="{{ route('settings.show') }}"
           @class([
               'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('settings.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('settings.*'),
           ])>
            Settings
        </a>

        <a href="{{ route('account.show') }}"
           @class([
               'flex items-center rounded-md px-2.5 py-[7px] text-[13.5px] font-medium no-underline',
               'bg-pale text-primary' => request()->routeIs('account.*'),
               'text-text-2 hover:bg-row-hover hover:text-text' => ! request()->routeIs('account.*'),
           ])>
            Account and security
        </a>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="w-full rounded-md px-2.5 py-[7px] text-left text-[13.5px] font-medium text-text-2 hover:bg-row-hover hover:text-text">
                Sign out
            </button>
        </form>
    </div>
</nav>
