@extends('layouts.app', ['siteCount' => $totalSites, 'fleetSummary' => $summary])

@section('title', 'Sites · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Sites'))

@section('content')
    <div class="mb-5 flex items-start justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Sites</h1>
            <p class="text-[13px] text-text-2">
                @if ($summary['total'] === 0)
                    No sites yet. Add one, then pair its connector.
                @else
                    {{ $summary['total'] }} {{ Str::plural('site', $summary['total']) }} ·
                    {{ $summary['connected'] }} reporting ·
                    {{ $summary['needingAttention'] }} needing attention
                @endif
            </p>
        </div>

        <div class="flex flex-none items-center gap-2">
            @if ($summary['total'] > 0)
                {{-- Queues a job per site rather than fetching anything: the platform never calls out.
                     The message says how many were queued and how many were skipped. --}}
                <form method="POST" action="{{ route('sites.refresh-all') }}">
                    @csrf
                    <button type="submit"
                            class="flex h-8 items-center whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                        Refresh all
                    </button>
                </form>
            @endif

            @if ($membership->canAdminister())
            {{-- A details element rather than a modal. No JavaScript, and the form is linkable and
                 keyboard-navigable for free. --}}
            {{-- z-20 so the panel sits above the table's sticky header, which is z-1. --}}
            {{-- Open when there is something in it to see: fields handed back after the recent-
                 authentication gate interrupted the submission, or a validation error. Both used to
                 land here collapsed, so the errors banner referred to a form nobody could see and a
                 restored form looked like nothing had happened. --}}
            <details class="group relative z-20" {{ $reopenAddSite || $errors->any() ? 'open' : '' }}>
                <summary class="flex h-8 cursor-pointer list-none items-center rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                    Add a site
                </summary>

                <form method="POST" action="{{ route('sites.store') }}"
                      class="absolute right-0 z-20 mt-2 flex w-[360px] max-w-[calc(100vw-2rem)] flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow-lg,var(--shadow))]">
                    @csrf

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Name</span>
                        <input type="text" name="name" required maxlength="120" value="{{ old('name') }}"
                               placeholder="Example Client"
                               class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] placeholder:text-text-3">
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Domain</span>
                        <input type="text" name="expected_domain" required maxlength="255" value="{{ old('expected_domain') }}"
                               placeholder="example.org"
                               class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[12.5px] placeholder:text-text-3">
                        <span class="text-[11.5px] text-text-3">
                            The host the site is served from. Pairing compares this against what the
                            connector reports, and holds the pairing for approval if they differ.
                        </span>
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Environment</span>
                        <select name="environment" class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2 text-[12.5px]">
                            <option value="production" @selected(old('environment', 'production') === 'production')>Production</option>
                            <option value="staging" @selected(old('environment') === 'staging')>Staging</option>
                            <option value="development" @selected(old('environment') === 'development')>Development</option>
                        </select>
                    </label>

                    <fieldset class="flex flex-col gap-1.5 border-t border-border pt-3">
                        <legend class="mb-1 text-[12.5px] font-medium">What it may report</legend>

                        @foreach ($grantableCapabilities as $capability)
                            <label class="flex items-start gap-2 text-[12.5px] text-text-2">
                                <input type="checkbox" name="capabilities[]" value="{{ $capability }}"
                                       @checked(in_array($capability, old('capabilities', $grantableCapabilities), true))
                                       class="mt-0.5 flex-none accent-[var(--primary)]">
                                <span>{{ __('capabilities.'.$capability.'.title') }}</span>
                            </label>
                        @endforeach

                        {{-- Every read capability on by default. They are read-only, they cannot change
                             the site, and a fleet dashboard with nothing in it is not much of a
                             dashboard. backups:create is deliberately absent: it reads the entire
                             database, so it is granted per site through its own confirmation. --}}
                        <p class="mt-1 text-[11.5px] text-text-3">
                            All read-only, and revocable at any time. Taking backups is granted
                            separately, per site.
                        </p>
                    </fieldset>

                    <button type="submit"
                            class="h-8 rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                        Add site and issue a code
                    </button>
                </form>
            </details>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
        {{-- Filters bind to the query string, so a filtered view can be linked and bookmarked.
             Hidden on an installation with no sites: three controls for filtering nothing are three
             things to rule out before you find the one sentence that tells you what to do next. --}}
        @if ($totalSites > 0)
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border p-3.5">
            {{-- Carried through, so applying a filter does not silently undo a sort somebody chose
                 a moment ago. The two compose; neither resets the other. --}}
            @if ($sort !== '')
                <input type="hidden" name="sort" value="{{ $sort }}">
            @endif

            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Filter sites"
                   class="h-[30px] w-[220px] max-w-full rounded-[7px] border border-border bg-surface-2 px-2.5 text-[12.5px] text-text placeholder:text-text-3">

            <select name="status" aria-label="Filter by status" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
                <option value="">Any status</option>
                @foreach ([
                    \App\Models\Site::STATUS_CONNECTED => 'Connected',
                    \App\Models\Site::STATUS_NOT_CONNECTED => 'Not connected',
                    \App\Models\Site::STATUS_NEVER_CONNECTED => 'Never connected',
                    \App\Models\Site::STATUS_PAUSED => 'Paused',
                ] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="environment" aria-label="Filter by environment" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
                <option value="">Any environment</option>
                @foreach (['production' => 'Production', 'staging' => 'Staging', 'development' => 'Development'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['environment'] === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="h-[30px] rounded-[7px] border border-border bg-surface px-3 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                Apply
            </button>

            @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['environment'] !== '')
                <a href="{{ route('sites.index') }}" class="text-[12.5px] text-primary hover:text-primary-hover">Clear</a>
            @endif

            <span class="ml-auto text-[12px] text-text-3">Showing {{ $shown }} of {{ $totalSites }}</span>
        </form>
        @endif

        @if ($shown === 0)
            <div class="px-4 py-10 text-center">
                @if ($totalSites === 0)
                    {{-- The one screen where a new installation lands. It used to say only "No sites
                         have been added yet", which states the obvious and withholds the next step. --}}
                    <p class="text-[13.5px] font-medium">Add your first site</p>
                    <p class="mx-auto mt-1.5 max-w-[52ch] text-[13px] text-text-2">
                        Adding a site issues a single-use enrolment code. Run it on the site — through
                        <strong>Utilities&nbsp;→&nbsp;Manager Connector</strong> in its control panel, or on
                        the command line — and the connector pairs itself and starts reporting.
                    </p>
                @else
                    <p class="text-[13px] text-text-2">No sites match these filters.</p>
                @endif
            </div>
        @else
            {{--
                Four of the seven columns are hidden below the large breakpoint.

                A seven-column table on a phone is a table you read one column at a time by dragging,
                and the version numbers are not what the fleet screen is for — "which of these needs
                me today" is answered by name, status and when it was last heard from. The versions
                are still one tap away on the site itself.
            --}}
            <div class="overflow-x-auto">
                <table class="table-sticky w-full text-[13px] lg:min-w-[1120px]">
                    <thead>
                        <tr class="bg-surface-2">
                            @php
                                // heading => [responsive classes, sort key or null]
                                $columns = [
                                    'Site' => ['', 'name'],
                                    'Environment' => ['hidden xl:table-cell', null],
                                    'Status' => ['', null],
                                    'Craft' => ['hidden lg:table-cell', 'craft'],
                                    'PHP' => ['hidden xl:table-cell', null],
                                    'Disk' => ['hidden lg:table-cell', 'disk'],
                                    'Response' => ['hidden xl:table-cell', 'response'],
                                    'Last seen' => ['hidden sm:table-cell', 'seen'],
                                ];
                            @endphp

                            @foreach ($columns as $heading => [$responsive, $key])
                                <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }} {{ $responsive }}"
                                    @if ($key && $sort === $key) aria-sort="ascending" @endif>
                                    @if ($key)
                                        {{-- In the query string, like the filters: a fleet somebody
                                             has sorted by disk should survive a reload and be
                                             linkable to whoever they are asking about it. --}}
                                        <a href="{{ route('sites.index', array_filter([...$filters, 'sort' => $sort === $key ? null : $key])) }}"
                                           class="inline-flex items-center gap-1 no-underline {{ $sort === $key ? 'text-primary' : 'text-text-3 hover:text-text' }}">
                                            {{ $heading }}
                                            <span aria-hidden="true" class="text-[9px]">{{ $sort === $key ? '▼' : '↕' }}</span>
                                        </a>
                                    @else
                                        {{ $heading }}
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $groupName => $sites)
                            @continue($sites->isEmpty())

                            <tr>
                                <td colspan="8" class="border-y border-border bg-surface-2 px-3.5 py-2">
                                    <span class="flex items-center gap-2.5">
                                        <span class="font-mono text-[10px] font-medium uppercase tracking-[0.08em] text-text-2">{{ $groupName }}</span>
                                        <span class="font-mono text-[10.5px] text-text-3">{{ $sites->count() }} {{ Str::plural('site', $sites->count()) }}</span>
                                    </span>
                                </td>
                            </tr>

                            @foreach ($sites as $site)
                                @php
                                    $tone = match (true) {
                                        $groupName === 'Needs attention' => 'warn',
                                        $groupName === 'Steady' => 'ok',
                                        default => 'grey',
                                    };
                                @endphp
                                {{-- Status is stated once, by the badge. It used to be said three times
                                     per row — group heading, a 3px coloured rail on this cell, and the
                                     badge — which is three things to read and one fact to learn. The
                                     rail went: it was the encoding that carried no words. --}}
                                <tr class="border-b border-border hover:bg-row-hover">
                                    <td class="py-3 pl-3.5 pr-3 align-middle">
                                        <a href="{{ route('sites.show', $site) }}" class="flex flex-col gap-0.5 no-underline">
                                            <span class="text-[13.5px] font-medium text-text">{{ $site->name }}</span>
                                            <span class="font-mono text-[11px] text-text-3">{{ $site->expected_domain }}</span>
                                            {{-- Folded in here rather than dropped: with its own column
                                                 gone on a phone, "when did we last hear from it" is
                                                 still the second thing anybody wants off this row. --}}
                                            <span class="font-mono text-[11px] text-text-3 sm:hidden">
                                                Last seen {{ $site->last_seen_at?->diffForHumans(short: true) ?? 'never' }}
                                            </span>
                                        </a>
                                    </td>
                                    <td class="hidden px-3 py-3 xl:table-cell">
                                        <span class="whitespace-nowrap rounded-[5px] border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-text-2">
                                            {{ Str::title($site->environment) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <x-status-badge :tone="$tone" :label="Str::of($site->status)->replace('_', ' ')->ucfirst()" />
                                    </td>
                                    <td class="hidden whitespace-nowrap px-3 py-3 font-mono text-[12px] tabular text-text-2 lg:table-cell">{{ $site->craft_version ?? '—' }}</td>
                                    <td class="hidden whitespace-nowrap px-3 py-3 font-mono text-[12px] tabular text-text-2 xl:table-cell">{{ $site->php_version ?? '—' }}</td>

                                    @php
                                        $figures = $runtime[$site->id] ?? ['disk' => null, 'p95' => null];
                                    @endphp

                                    {{-- The two figures from the runtime report that are worth
                                         comparing across a fleet. Both read as an em-dash where the
                                         site has never reported them, rather than as a zero: "we do
                                         not know" is not "it is fine". --}}
                                    <td class="hidden whitespace-nowrap px-3 py-3 font-mono text-[12px] tabular lg:table-cell">
                                        @if ($figures['disk'] === null)
                                            <span class="text-text-3">—</span>
                                        @else
                                            <span class="{{ $figures['disk'] >= 90 ? 'font-medium text-amber' : 'text-text-2' }}">
                                                {{ $figures['disk'] }}%
                                            </span>
                                        @endif
                                    </td>

                                    <td class="hidden whitespace-nowrap px-3 py-3 font-mono text-[12px] tabular xl:table-cell">
                                        @if ($figures['p95'] === null)
                                            <span class="text-text-3">—</span>
                                        @else
                                            <span class="{{ $figures['p95'] >= 2000 ? 'font-medium text-amber' : 'text-text-2' }}">
                                                {{ number_format($figures['p95']) }} ms
                                            </span>
                                        @endif
                                    </td>

                                    <td class="hidden whitespace-nowrap px-3 py-3 font-mono text-[11.5px] text-text-3 sm:table-cell">
                                        {{ $site->last_seen_at?->diffForHumans(short: true) ?? 'never' }}
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex items-center justify-between bg-surface-2 px-3.5 py-2.5 text-[12px] text-text-3">
            <span>Status reflects the last completed check. Manager never stores administrator passwords.</span>
        </div>
    </div>
@endsection
