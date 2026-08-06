@extends('layouts.app')

@section('title', 'Security · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Security'))

@section('content')
    <div class="mb-5 flex items-start justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Security</h1>
            <p class="text-[13px] text-text-2">
                @unless ($showResolved)
                    @php
                        $tally = collect(App\Domain\Findings\Severity::ordered())
                            ->map(fn (string $key) => ($counts[$key] ?? 0) > 0 ? $counts[$key].' '.$key : null)
                            ->filter()
                            ->values();
                    @endphp

                    @if ($tally->isNotEmpty())
                        <span class="font-medium text-text">{{ $tally->join(' · ') }}</span> outstanding.
                    @endif
                @endunless
                Every site, worst first. Anything that is work rather than exposure - updates, licences,
                disk, queues - is on <a href="{{ route('findings.index') }}" class="text-primary no-underline hover:underline">Findings</a>.
            </p>
        </div>

        <a href="{{ route('security.index', ['resolved' => $showResolved ? null : 1]) }}"
           class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
            {{ $showResolved ? 'Show outstanding' : 'Show resolved' }}
        </a>
    </div>

    @if ($sites->isEmpty())
        <div class="rounded-[10px] border border-border bg-surface p-10 text-center shadow-[var(--shadow)]">
            <p class="text-[13.5px] font-medium">No sites yet.</p>
            <p class="mt-1.5 text-[13px] text-text-2">
                Add one from <a href="{{ route('sites.index') }}" class="text-primary no-underline hover:underline">Sites</a>
                and it will appear here once it reports.
            </p>
        </div>
    @else
        {{--
            Grouped by site, which is the opposite of Findings and the reason both screens exist.

            Findings answers "what is wrong across the fleet", so twelve copies of one misconfiguration
            belong under one heading. This answers "is this site safe", and that is asked one client at
            a time - so the site is the heading and its findings sit underneath it.

            Clean sites are listed too. A screen that omitted them could not distinguish "nothing is
            wrong here" from "nothing has been checked here", and a rule whose capability is not
            granted is skipped rather than passed.
        --}}
        <div class="flex flex-col gap-2.5">
            @foreach ($sites as $site)
                @php
                    $siteFindings = $findings->get($site->id, collect());
                    $siteUnchecked = $unchecked[$site->id] ?? [];
                @endphp

                <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                    <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1.5 border-b border-border px-4 py-3">
                        <a href="{{ route('sites.security', $site) }}"
                           class="text-[14px] font-medium text-text no-underline hover:text-primary">
                            {{ $site->name }}
                        </a>
                        <span class="font-mono text-[11.5px] text-text-3">{{ $site->expected_domain }}</span>

                        @if ($site->environment !== 'production')
                            <x-status-badge tone="info" :label="Str::title($site->environment)" />
                        @endif

                        <span class="ml-auto text-[12.5px] text-text-2">
                            @if ($siteFindings->isEmpty())
                                {{ $showResolved ? 'Nothing resolved' : 'No security findings' }}
                            @else
                                {{ $siteFindings->count() }} {{ Str::plural('finding', $siteFindings->count()) }}
                            @endif
                        </span>
                    </div>

                    @if ($siteFindings->isEmpty() && $siteUnchecked === [])
                        <p class="px-4 py-3 text-[12.5px] text-text-2">
                            @if ($showResolved)
                                Nothing has been resolved on this site yet.
                            @else
                                Every security rule ran and none matched.
                            @endif
                        </p>
                    @endif

                    <ul class="flex list-none flex-col p-0">
                        @foreach ($siteFindings as $finding)
                            <li class="relative flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-2.5 first:border-t-0">
                                <x-status-badge :tone="$finding->tone()" :label="Str::title($finding->severity)" />
                                <span class="text-[13px] font-medium">{{ $finding->title }}</span>

                                @if ($finding->isAcknowledged())
                                    <x-status-badge tone="info" label="Acknowledged" />
                                @endif

                                @if ($finding->state === App\Models\Finding::STATE_RESOLVED)
                                    <x-status-badge tone="ok" label="Resolved" />
                                @endif

                                <span class="font-mono text-[11px] text-text-3">
                                    {{ $finding->rule }} · first seen {{ $finding->age() }} ago
                                    @if ($finding->state === App\Models\Finding::STATE_RESOLVED && $finding->resolved_at)
                                        · resolved {{ $finding->resolved_at->diffForHumans(short: true) }}
                                    @endif
                                </span>

                                {{-- basis-full on the wrapping flex row, so the detail takes its own line
                                     without the layout needing to change shape. Never hoisted to the site
                                     heading the way Findings hoists it to the rule: here the group is the
                                     site, so every detail in it is about a different problem. --}}
                                <p class="max-w-[80ch] basis-full text-[12.5px] text-text-2">{{ $finding->detail }}</p>

                                @if ($finding->isAcknowledged() && $finding->acknowledgement_reason)
                                    <span class="basis-full text-[12.5px] text-text-2">
                                        <span class="font-medium">{{ $finding->acknowledged_label }}</span>
                                        acknowledged this {{ $finding->acknowledged_at?->diffForHumans() }}:
                                        {{ $finding->acknowledgement_reason }}
                                    </span>
                                @endif

                                @if ($canAcknowledge && $finding->isOutstanding())
                                    <div class="ml-auto flex-none">
                                        @if ($finding->isAcknowledged())
                                            <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                                    Withdraw<span class="sr-only"> acknowledgement for {{ $finding->title }} on {{ $site->name }}</span>
                                                </button>
                                            </form>
                                        @else
                                            {{-- Behind a disclosure, as on Findings: this list is read far
                                                 more often than it is acknowledged, and an always-open
                                                 text input on every row competes with what the reader came
                                                 to read. --}}
                                            <details class="group">
                                                <summary class="flex h-8 cursor-pointer list-none items-center justify-center whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                                    Acknowledge<span class="sr-only"> {{ $finding->title }} on {{ $site->name }}</span>
                                                </summary>

                                                <form method="POST" action="{{ route('findings.acknowledge', $finding) }}"
                                                      class="absolute right-4 z-10 mt-1.5 flex flex-col gap-1.5 rounded-[9px] border border-border bg-surface p-2.5 shadow-[var(--shadow)]">
                                                    @csrf
                                                    <label class="sr-only" for="security-reason-{{ $finding->getRouteKey() }}">
                                                        Why {{ $finding->title }} on {{ $site->name }} is not being fixed now
                                                    </label>
                                                    <input type="text" id="security-reason-{{ $finding->getRouteKey() }}"
                                                           name="reason" required minlength="3" maxlength="255"
                                                           placeholder="Why not now?"
                                                           class="h-8 w-[220px] max-w-full rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] text-text placeholder:text-text-3">
                                                    <button type="submit"
                                                            class="h-8 whitespace-nowrap rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                                                        Confirm
                                                    </button>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    {{-- What could not be looked at. Stated on the site it applies to rather than once at
                         the top, because the answer differs per site and the reader is deciding about
                         one site at a time. --}}
                    @if ($siteUnchecked !== [])
                        <p class="border-t border-border bg-surface-2 px-4 py-2.5 text-[12px] text-text-2">
                            {{ count($siteUnchecked) }} security {{ Str::plural('check', count($siteUnchecked)) }}
                            could not run on this site - the capability is not granted, so these are
                            unknown rather than clean:
                            <span class="font-mono text-[11.5px] text-text-3">{{ implode(', ', $siteUnchecked) }}</span>.
                            <a href="{{ route('sites.settings', $site) }}#capabilities"
                               class="text-primary no-underline hover:underline">Permissions</a>
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
