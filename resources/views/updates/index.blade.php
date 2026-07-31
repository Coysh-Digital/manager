@extends('layouts.app')

@section('title', 'Updates · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Updates'))

@section('content')
    <div class="mb-5 flex flex-col gap-1.5">
        <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Updates</h1>
        <p class="text-[13px] text-text-2">
            @if ($summary['sites'] === 0)
                No site has reported update availability yet.
            @else
                {{ $summary['withUpdates'] }} of {{ $summary['sites'] }} reporting
                {{ Str::plural('site', $summary['sites']) }} {{ $summary['withUpdates'] === 1 ? 'has' : 'have' }}
                updates available
                @if ($summary['security'] > 0)
                    · <span class="font-medium text-amber">{{ $summary['security'] }} with a security release</span>
                @endif
                @if ($summary['oldestCheck'])
                    · oldest check {{ $summary['oldestCheck']->diffForHumans() }}
                @endif
            @endif
        </p>
    </div>

    <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
        <div class="overflow-x-auto">
            <table class="table-sticky w-full min-w-[900px] text-[13px]">
                <thead>
                    <tr class="bg-surface-2">
                        @foreach (['Site', 'Craft', 'Plugin updates', 'Checked', ''] as $heading)
                            <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reported as $site)
                        @php
                            $report = $reports->get($site->id);
                            $security = $report->craft_security_release || $report->plugin_security_releases > 0;
                            $tone = $security ? 'warn' : ($report->totalUpdates() > 0 ? 'info' : 'ok');
                        @endphp

                        {{-- No colour rail on the row. Urgency here is carried by the "Security
                             release" badge, which has a word in it; a 3px stripe repeating the same
                             fact silently was the third encoding of one thing. --}}
                        <tr class="border-b border-border hover:bg-row-hover">
                            <td class="py-3 pl-3.5 pr-3 align-middle">
                                <a href="{{ route('sites.show', $site) }}" class="flex flex-col gap-0.5 no-underline">
                                    <span class="text-[13.5px] font-medium text-text">{{ $site->name }}</span>
                                    <span class="font-mono text-[11px] text-text-3">{{ $site->expected_domain }}</span>
                                </a>
                            </td>

                            <td class="whitespace-nowrap px-3 py-3">
                                @if ($report->craft_update_available)
                                    <span class="font-mono text-[12px] tabular {{ $report->craft_security_release ? 'text-amber' : 'text-text-2' }}">
                                        {{ $report->craft_current }} → {{ $report->craft_latest }}
                                    </span>
                                    @if ($report->craft_security_release)
                                        <div class="mt-0.5">
                                            {{-- The one field that decides urgency. Which vulnerability
                                                 it fixes is deliberately not transmitted. --}}
                                            <x-status-badge tone="warn" label="Security release" />
                                        </div>
                                    @endif
                                @else
                                    <span class="font-mono text-[12px] text-text-3 tabular">{{ $report->craft_current }}</span>
                                @endif
                            </td>

                            <td class="px-3 py-3">
                                @if ($report->plugin_updates === 0)
                                    <span class="text-[12.5px] text-text-3">None</span>
                                @else
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[12.5px] {{ $report->plugin_security_releases > 0 ? 'text-amber' : 'text-text-2' }}">
                                            {{ $report->plugin_updates }} available
                                            @if ($report->plugin_security_releases > 0)
                                                · {{ $report->plugin_security_releases }} security
                                            @endif
                                        </span>
                                        <span class="font-mono text-[11px] text-text-3">
                                            @foreach (array_slice($report->pluginsNeedingUpdates(), 0, 3) as $plugin)
                                                {{ $plugin['handle'] }}@if (! $loop->last), @endif
                                            @endforeach
                                            @if (count($report->pluginsNeedingUpdates()) > 3)
                                                and {{ count($report->pluginsNeedingUpdates()) - 3 }} more
                                            @endif
                                        </span>
                                    </div>
                                @endif

                                @if ($report->abandoned_plugins > 0)
                                    <div class="mt-1">
                                        {{-- Worth its own flag: an abandoned plugin will never receive
                                             a security fix, which makes it a more permanent problem
                                             than one that is merely out of date. --}}
                                        <x-status-badge tone="warn" :label="$report->abandoned_plugins.' abandoned'" />
                                    </div>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-3 py-3 font-mono text-[11.5px] text-text-3">
                                {{ $report->checked_at->diffForHumans(short: true) }}
                            </td>

                            <td class="px-3 py-3 text-right">
                                @if ($canRequest)
                                    <form method="POST" action="{{ route('updates.refresh', $site) }}">
                                        @csrf
                                        <button type="submit"
                                                class="h-[30px] whitespace-nowrap rounded-[7px] border border-border bg-surface px-2.5 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                                            Check again
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-[13px] text-text-2">
                                No site has reported update availability yet. Grant
                                <code class="font-mono">updates:read</code> to a site and it will report on its next check.
                            </td>
                        </tr>
                    @endforelse

                    @if ($unreported->isNotEmpty())
                        <tr>
                            <td colspan="5" class="border-y border-border bg-surface-2 px-3.5 py-2">
                                <span class="flex items-center gap-2.5">
                                    <span class="font-mono text-[10px] font-medium uppercase tracking-[0.08em] text-text-2">Not reporting updates</span>
                                    <span class="font-mono text-[10.5px] text-text-3">{{ $unreported->count() }} {{ Str::plural('site', $unreported->count()) }}</span>
                                </span>
                            </td>
                        </tr>

                        @foreach ($unreported as $site)
                            <tr class="border-b border-border hover:bg-row-hover">
                                <td class="py-3 pl-3.5 pr-3">
                                    <a href="{{ route('sites.show', $site) }}" class="flex flex-col gap-0.5 no-underline">
                                        <span class="text-[13.5px] font-medium text-text">{{ $site->name }}</span>
                                        <span class="font-mono text-[11px] text-text-3">{{ $site->expected_domain }}</span>
                                    </a>
                                </td>
                                <td colspan="3" class="px-3 py-3 text-[12.5px] text-text-2">
                                    @if (! $site->hasCapability('updates:read'))
                                        Not granted <code class="font-mono text-[12px]">updates:read</code>.
                                        <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
                                    @else
                                        Granted, but has not reported yet.
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    @if ($canRequest && $site->hasCapability('updates:read'))
                                        <form method="POST" action="{{ route('updates.refresh', $site) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="h-[30px] whitespace-nowrap rounded-[7px] border border-border bg-surface px-2.5 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                                                Request a check
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>

        <div class="bg-surface-2 px-3.5 py-2.5 text-[12px] text-text-3">
            Manager reports that an update exists and whether it is a security release. No site ever
            sends release notes, and none are stored against a site: those describe what a version
            fixes, and holding that beside the name of an unpatched site is a liability rather than a
            feature. Craft's own published changelog can be read on a site's Updates screen, fetched
            once for this installation and cached, carrying nothing about which sites exist or which
            are behind.
        </div>
    </div>
@endsection
