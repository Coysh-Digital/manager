@extends('layouts.app', ['siteCount' => $totalSites, 'fleetSummary' => $summary])

@section('title', 'Sites · Manager')
@section('crumb', 'Sites')

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
    </div>

    <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
        {{-- Filters bind to the query string, so a filtered view can be linked and bookmarked. --}}
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border p-3.5">
            <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Filter sites"
                   class="h-[30px] w-[220px] rounded-[7px] border border-border bg-surface-2 px-2.5 text-[12.5px] text-text placeholder:text-text-3">

            <select name="status" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
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

            <select name="environment" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
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

        @if ($shown === 0)
            <div class="px-4 py-10 text-center">
                <p class="text-[13px] text-text-2">
                    @if ($totalSites === 0)
                        No sites have been added yet.
                    @else
                        No sites match these filters.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table-sticky w-full min-w-[1000px] text-[13px]">
                    <thead>
                        <tr class="bg-surface-2">
                            @foreach (['Site', 'Environment', 'Status', 'Craft', 'PHP', 'Connector', 'Last seen'] as $heading)
                                <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                    {{ $heading }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $groupName => $sites)
                            @continue($sites->isEmpty())

                            <tr>
                                <td colspan="7" class="border-y border-border bg-surface-2 px-3.5 py-2">
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
                                <tr class="border-b border-border hover:bg-row-hover">
                                    <td class="py-3 pl-3.5 pr-3 align-middle" style="border-left: 3px solid var(--{{ $tone === 'ok' ? 'ok' : ($tone === 'warn' ? 'amber' : 'grey') }});">
                                        <a href="{{ route('sites.show', $site) }}" class="flex flex-col gap-0.5 no-underline">
                                            <span class="text-[13.5px] font-medium text-text">{{ $site->name }}</span>
                                            <span class="font-mono text-[11px] text-text-3">{{ $site->expected_domain }}</span>
                                        </a>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="whitespace-nowrap rounded-[5px] border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-text-2">
                                            {{ Str::title($site->environment) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <x-status-badge :tone="$tone" :label="Str::of($site->status)->replace('_', ' ')->ucfirst()" />
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 font-mono text-[12px] text-text-2 tabular">{{ $site->craft_version ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 font-mono text-[12px] text-text-2 tabular">{{ $site->php_version ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 font-mono text-[12px] text-text-2 tabular">{{ $site->connector_version ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-3 font-mono text-[11.5px] text-text-3">
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
