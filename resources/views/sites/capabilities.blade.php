@extends('layouts.app')

@section('title', 'Capabilities · '.$site->name)
@section('crumb', 'Capabilities')

@section('content')
    <div class="mx-auto max-w-[1020px]">
        <div class="mb-5 flex items-start justify-between gap-6">
            <div class="flex flex-col gap-1.5">
                <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Capabilities</h1>
                <p class="text-[13px] text-text-2">
                    {{ $site->name }} · {{ $site->expected_domain }} · What Manager is permitted to do on this
                    site. Anything not granted here cannot be performed.
                </p>
            </div>
            <a href="{{ route('sites.show', $site) }}"
               class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
                Back to site
            </a>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-5 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
            <div class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Connector key</span>
                <span class="font-mono text-[13px]">
                    {{-- Only ever the tail. The full key is public, but showing it in full invites
                         pasting it around as though it were meaningful. --}}
                    {{ $connector ? '··········'.Str::substr($connector->public_key, -6) : 'Not paired' }}
                </span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Paired</span>
                <span class="text-[13px]">{{ $connector?->paired_at?->diffForHumans() ?? '—' }}</span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Stored credentials</span>
                <span class="text-[13px]">No administrator password stored</span>
            </div>

            @if ($connector)
                <form method="POST" action="{{ route('sites.connector.revoke', $site) }}" class="ml-auto"
                      onsubmit="return confirm('Revoke this connector? The site stops reporting immediately and will need a new enrolment code.');">
                    @csrf
                    <button type="submit"
                            class="h-8 rounded-[7px] border border-danger-line bg-danger-bg px-3 text-[12.5px] font-medium text-danger hover:border-danger">
                        Revoke access
                    </button>
                </form>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            @foreach ($capabilities as $capability)
                <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                    <div class="flex items-start gap-4 border-b border-border p-4">
                        <div class="flex flex-1 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="text-[14px] font-medium {{ $capability['granted'] ? '' : 'text-text-2' }}">
                                    {{ __('capabilities.'.$capability['name'].'.title') }}
                                </span>

                                @if ($capability['granted'])
                                    <x-status-badge tone="ok" label="Enabled" />
                                @else
                                    <x-status-badge tone="grey" label="Not granted" />
                                @endif

                                @if ($capability['readOnly'])
                                    <span class="rounded-[5px] border border-border px-1.5 py-0.5 font-mono text-[11px] text-text-2">Read-only</span>
                                @else
                                    <span class="rounded-[5px] border border-danger-line bg-danger-bg px-1.5 py-0.5 font-mono text-[11px] text-danger">Modifies the site</span>
                                @endif

                                @unless ($capability['implemented'])
                                    <span class="rounded-[5px] border border-info-line bg-info-bg px-1.5 py-0.5 font-mono text-[11px] text-info">Not yet available</span>
                                @endunless
                            </div>

                            <span class="text-[13px] text-text-2">{{ __('capabilities.'.$capability['name'].'.description') }}</span>
                        </div>

                        @if ($capability['granted'])
                            <form method="POST" action="{{ route('sites.capabilities.revoke', $site) }}"
                                  onsubmit="return confirm('Revoke {{ $capability['name'] }}? The site can no longer perform it.');">
                                @csrf
                                <input type="hidden" name="capability" value="{{ $capability['name'] }}">
                                <button type="submit"
                                        class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                    Revoke
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($capability['grant'])
                        <div class="grid grid-cols-1 gap-px bg-border sm:grid-cols-3">
                            <div class="flex flex-col gap-1 bg-surface-2 px-4 py-2.5">
                                <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">State changed</span>
                                <span class="text-[12.5px]">
                                    {{ ($capability['granted'] ? $capability['grant']->granted_at : $capability['grant']->revoked_at)?->diffForHumans() ?? '—' }}
                                </span>
                            </div>
                            <div class="flex flex-col gap-1 bg-surface-2 px-4 py-2.5">
                                <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Reason</span>
                                <span class="text-[12.5px]">{{ $capability['grant']->reason ?? '—' }}</span>
                            </div>
                            <div class="flex flex-col gap-1 bg-surface-2 px-4 py-2.5">
                                <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Access</span>
                                <span class="text-[12.5px]">{{ $capability['readOnly'] ? 'Cannot modify the website' : 'Can modify the website' }}</span>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Permission history</div>
            @forelse ($history as $event)
                <div class="grid grid-cols-[130px_1fr_150px] items-center gap-4 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                    <span class="font-mono text-[11.5px] text-text-3">{{ $event->created_at->diffForHumans(short: true) }}</span>
                    <span>
                        <code class="font-mono text-[12px]">{{ $event->capability }}</code>
                        {{ $event->previous_state ? Str::of($event->previous_state)->append(' → ') : '' }}{{ $event->new_state }}
                        @if ($event->reason)
                            <span class="text-text-2">— {{ $event->reason }}</span>
                        @endif
                    </span>
                    <span class="text-text-2">{{ $event->actor_label ?? 'System' }}</span>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-[13px] text-text-2">No capability changes recorded.</p>
            @endforelse
        </div>
    </div>
@endsection
