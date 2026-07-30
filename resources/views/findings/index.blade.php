@extends('layouts.app')

@section('title', 'Findings · Manager')
@section('crumb', App\Support\Crumbs::top('Findings'))

@section('content')
    <div class="mb-5 flex items-start justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Findings</h1>
            {{-- The severity counts were a four-tile strip across the fold: a full band of chrome to
                 carry four integers, three of them usually zero, and on a fresh installation four
                 zeros shown to somebody with no sites. They are a sentence, so they are a sentence. --}}
            <p class="text-[13px] text-text-2">
                @unless ($showResolved)
                    @php
                        $tally = collect(['critical' => 'critical', 'high' => 'high', 'medium' => 'medium', 'low' => 'low'])
                            ->map(fn (string $label, string $key) => $counts[$key] > 0 ? $counts[$key].' '.$label : null)
                            ->filter()
                            ->values();
                    @endphp

                    @if ($tally->isNotEmpty())
                        <span class="font-medium text-text">{{ $tally->join(' · ') }}</span> outstanding.
                    @endif
                @endunless
                Derived on the platform from what each site reports. A finding resolves itself once the
                problem is fixed — there is nothing to tick off.
            </p>
        </div>

        <a href="{{ route('findings.index', ['resolved' => $showResolved ? null : 1]) }}"
           class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
            {{ $showResolved ? 'Show outstanding' : 'Show resolved' }}
        </a>
    </div>

    @if ($findings->isEmpty())
        <div class="rounded-[10px] border border-border bg-surface p-10 text-center shadow-[var(--shadow)]">
            <p class="text-[13.5px] font-medium">
                {{ $showResolved ? 'Nothing has been resolved yet.' : 'No outstanding findings.' }}
            </p>
            @unless ($showResolved)
                <p class="mt-1.5 text-[13px] text-text-2">
                    Bear in mind a rule is skipped, not passed, when its capability is not granted — so
                    an empty list is only as complete as what each site has been asked to report.
                </p>
            @endunless
        </div>
    @else
        {{--
            Grouped by rule, not listed by instance.

            A finding is rule × site. Listed flat, one misconfigured deploy template produced one
            full-width card per site — four identical "Development mode is on in production" cards
            at twelve sites, forty at forty, each repeating the same title, the same description and
            the same rule name. The reader was made to notice the repetition and then discount it.

            The rule is stated once. Underneath it, one line per affected site, which is the only
            part that actually differs.
        --}}
        <div class="flex flex-col gap-2.5">
            @foreach ($findings->groupBy('rule') as $rule => $group)
                @php $lead = $group->first(); @endphp

                <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                    <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1.5 border-b border-border px-4 py-3">
                        <x-status-badge :tone="$lead->tone()" :label="Str::title($lead->severity)" />
                        <h2 class="text-[14px] font-medium">{{ $lead->title }}</h2>
                        <span class="text-[12.5px] text-text-2">
                            on {{ $group->count() }} {{ Str::plural('site', $group->count()) }}
                        </span>
                        <span class="ml-auto font-mono text-[11px] text-text-3">{{ $rule }}</span>
                    </div>

                    <p class="max-w-[80ch] px-4 pt-3 text-[13px] text-text-2">{{ $lead->detail }}</p>

                    <ul class="mt-2 flex list-none flex-col p-0">
                        @foreach ($group as $finding)
                            <li class="relative flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-2.5">
                                <a href="{{ route('sites.show', $finding->site) }}"
                                   class="text-[13px] font-medium text-text no-underline hover:text-primary">
                                    {{ $finding->site->name }}
                                </a>
                                <span class="font-mono text-[11.5px] text-text-3">{{ $finding->site->expected_domain }}</span>

                                @if ($finding->isAcknowledged())
                                    <x-status-badge tone="info" label="Acknowledged" />
                                @endif

                                @if ($finding->state === App\Models\Finding::STATE_RESOLVED)
                                    <x-status-badge tone="ok" label="Resolved" />
                                @endif

                                <span class="font-mono text-[11px] text-text-3">
                                    first seen {{ $finding->age() }} ago
                                    @if ($finding->state === App\Models\Finding::STATE_RESOLVED && $finding->resolved_at)
                                        · resolved {{ $finding->resolved_at->diffForHumans(short: true) }}
                                    @endif
                                </span>

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
                                                    Withdraw<span class="sr-only"> acknowledgement for {{ $finding->site->name }}</span>
                                                </button>
                                            </form>
                                        @else
                                            {{-- Behind a disclosure. A findings list is read far more
                                                 often than it is acknowledged, and an always-open text
                                                 input on every row competes with what the reader came
                                                 to read. A reason is still required: "acknowledged
                                                 three weeks ago" with no explanation leaves the next
                                                 person unable to tell a decision from a shrug. --}}
                                            <details class="group">
                                                <summary class="flex h-8 cursor-pointer list-none items-center justify-center whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                                    Acknowledge<span class="sr-only"> for {{ $finding->site->name }}</span>
                                                </summary>

                                                <form method="POST" action="{{ route('findings.acknowledge', $finding) }}"
                                                      class="absolute right-4 z-10 mt-1.5 flex flex-col gap-1.5 rounded-[9px] border border-border bg-surface p-2.5 shadow-[var(--shadow)]">
                                                    @csrf
                                                    <label class="sr-only" for="reason-{{ $finding->getRouteKey() }}">
                                                        Why {{ $lead->title }} on {{ $finding->site->name }} is not being fixed now
                                                    </label>
                                                    <input type="text" id="reason-{{ $finding->getRouteKey() }}"
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
                </div>
            @endforeach
        </div>
    @endif
@endsection
