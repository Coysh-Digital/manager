@extends('layouts.app')

@section('title', 'Findings · Manager')
@section('crumb', 'Findings')

@section('content')
    <div class="mb-5 flex items-start justify-between gap-6">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Findings</h1>
            <p class="text-[13px] text-text-2">
                Derived on the platform from what each site reports. A finding resolves itself once the
                problem is fixed — there is nothing to tick off.
            </p>
        </div>

        <a href="{{ route('findings.index', ['resolved' => $showResolved ? null : 1]) }}"
           class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
            {{ $showResolved ? 'Show outstanding' : 'Show resolved' }}
        </a>
    </div>

    @unless ($showResolved)
        <div class="mb-4 grid grid-cols-2 gap-px overflow-hidden rounded-[10px] border border-border bg-border sm:grid-cols-4">
            @foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $severity => $label)
                <div class="flex items-baseline gap-2.5 bg-surface-2 px-3.5 py-3">
                    <span @class([
                        'text-[17px] font-semibold tracking-[-0.02em] tabular',
                        'text-danger' => in_array($severity, ['critical', 'high'], true) && $counts[$severity] > 0,
                        'text-amber' => $severity === 'medium' && $counts[$severity] > 0,
                        'text-text-3' => $counts[$severity] === 0,
                    ])>{{ $counts[$severity] }}</span>
                    <span class="text-[12.5px] font-medium">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    @endunless

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
        <div class="flex flex-col gap-2.5">
            @foreach ($findings as $finding)
                <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                    <div class="flex items-start gap-3.5 p-4">
                        <div class="flex flex-1 flex-col gap-1.5">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <x-status-badge :tone="$finding->tone()" :label="Str::title($finding->severity)" />

                                <span class="text-[14px] font-medium">{{ $finding->title }}</span>

                                @if ($finding->isAcknowledged())
                                    <x-status-badge tone="info" label="Acknowledged" />
                                @endif

                                @if ($finding->state === App\Models\Finding::STATE_RESOLVED)
                                    <x-status-badge tone="ok" label="Resolved" />
                                @endif
                            </div>

                            <a href="{{ route('sites.show', $finding->site) }}"
                               class="w-fit font-mono text-[11.5px] text-text-3 no-underline hover:text-text-2">
                                {{ $finding->site->name }} · {{ $finding->site->expected_domain }}
                            </a>

                            <p class="mt-0.5 max-w-[80ch] text-[13px] text-text-2">{{ $finding->detail }}</p>

                            <div class="mt-1 flex flex-wrap items-center gap-3 font-mono text-[11px] text-text-3">
                                <span>first seen {{ $finding->age() }} ago</span>
                                <span>·</span>
                                <span>rule {{ $finding->rule }}</span>
                                @if ($finding->state === App\Models\Finding::STATE_RESOLVED && $finding->resolved_at)
                                    <span>·</span>
                                    <span>resolved {{ $finding->resolved_at->diffForHumans(short: true) }}</span>
                                @endif
                            </div>

                            @if ($finding->isAcknowledged())
                                <div class="mt-1.5 rounded-lg border border-info-line bg-info-bg px-3 py-2 text-[12.5px] text-text-2">
                                    <span class="font-medium">{{ $finding->acknowledged_label }}</span>
                                    acknowledged this {{ $finding->acknowledged_at->diffForHumans() }}:
                                    {{ $finding->acknowledgement_reason }}
                                </div>
                            @endif
                        </div>

                        @if ($canAcknowledge && $finding->isOutstanding())
                            <div class="flex flex-none flex-col gap-2">
                                @if ($finding->isAcknowledged())
                                    <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                                        @csrf
                                        <button type="submit"
                                                class="h-8 w-full whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Withdraw
                                        </button>
                                    </form>
                                @else
                                    {{-- A reason is required. "Acknowledged three weeks ago" with no
                                         explanation leaves the next person unable to tell a decision
                                         from a shrug. --}}
                                    <form method="POST" action="{{ route('findings.acknowledge', $finding) }}"
                                          class="flex flex-col gap-1.5">
                                        @csrf
                                        <input type="text" name="reason" required minlength="3" maxlength="255"
                                               placeholder="Why not now?"
                                               class="h-8 w-[200px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] text-text placeholder:text-text-3">
                                        <button type="submit"
                                                class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Acknowledge
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
