@extends('layouts.app')

@section('title', 'Settings · Manager')
@section('crumb', 'Settings')

@section('content')
    <div class="mx-auto max-w-[900px]">
        <div class="mb-5 flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Settings</h1>
            <p class="text-[13px] text-text-2">
                @if ($edition === 'cloud')
                    This installation is hosted by Coysh Digital.
                @else
                    This installation runs on your own infrastructure. Coysh Digital has no access to it.
                @endif
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- The same checks manager:doctor runs. Two implementations would eventually disagree, and
             the one somebody is looking at would be the wrong one. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Platform health</span>
                <span class="font-mono text-[11px] text-text-3">php artisan manager:doctor</span>
            </div>

            <div class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2">
                @foreach ($checks as $check)
                    <div class="flex items-start justify-between gap-4 bg-surface px-4 py-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="text-[13px] font-medium">{{ $check->name }}</span>
                            <span class="font-mono text-[11.5px] text-text-3">{{ $check->detail }}</span>
                            @if ($check->remedy)
                                <span class="mt-0.5 text-[12px] text-text-2">{{ $check->remedy }}</span>
                            @endif
                        </div>

                        <span class="flex-none whitespace-nowrap text-[12px] font-medium
                            {{ $check->failed() ? 'text-danger' : ($check->warned() ? 'text-amber' : 'text-ok') }}">
                            {{ $check->failed() ? '✕' : ($check->warned() ? '!' : '✓') }}
                            {{ Str::title($check->status) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">What a new site can do</div>

            <div class="flex flex-col gap-3 p-4 text-[13px]">
                <p class="text-text-2">
                    A newly paired site is granted
                    @foreach ($pairingDefaults as $capability)
                        <code class="font-mono text-[12px]">{{ $capability }}</code>@if (! $loop->last), @endif
                    @endforeach
                    and nothing else. Everything further has to be granted deliberately, per site.
                </p>
                <p class="text-text-2">
                    Available to grant from a site's Capabilities screen:
                    @foreach ($grantable as $capability)
                        <code class="font-mono text-[12px]">{{ $capability }}</code>@if (! $loop->last), @endif
                    @endforeach.
                    Anything that modifies a site, or reads its content, is not offered as a switch and
                    needs its own confirmation flow.
                </p>
                <p class="text-text-3">
                    These defaults are deliberately not configurable. A setting that could grant more at
                    pairing time would make "read-only by default" a preference rather than a property.
                </p>
            </div>
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">{{ $organisation->name }}</div>

            <div class="flex items-center justify-between gap-5 border-b border-border px-4 py-3.5">
                <div class="flex flex-col gap-1">
                    <span class="text-[13px] font-medium">Require two-factor authentication</span>
                    <span class="text-[12.5px] text-text-2">
                        Every member must hold a second factor. Anyone without one is asked to enrol
                        before they can continue, rather than being locked out.
                    </span>
                </div>

                @if ($membership->isOwner())
                    <form method="POST" action="{{ route('settings.mfa') }}">
                        @csrf
                        <input type="hidden" name="mfa_required" value="{{ $organisation->mfa_required ? 0 : 1 }}">
                        <button type="submit" @class([
                            'h-8 whitespace-nowrap rounded-[7px] px-3 text-[12.5px] font-medium',
                            'border border-border-2 bg-surface text-text hover:bg-row-hover' => $organisation->mfa_required,
                            'border border-primary bg-primary text-primary-fg hover:bg-primary-hover' => ! $organisation->mfa_required,
                        ])>
                            {{ $organisation->mfa_required ? 'Turn off' : 'Turn on' }}
                        </button>
                    </form>
                @else
                    <x-status-badge :tone="$organisation->mfa_required ? 'ok' : 'grey'"
                                    :label="$organisation->mfa_required ? 'Required' : 'Optional'" />
                @endif
            </div>

            <div class="grid grid-cols-2 gap-px bg-border sm:grid-cols-3">
                <div class="flex flex-col gap-1 bg-surface-2 px-4 py-3">
                    <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Sites</span>
                    <span class="text-[13px] tabular">{{ $siteCount }}</span>
                </div>
                <div class="flex flex-col gap-1 bg-surface-2 px-4 py-3">
                    <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Active connectors</span>
                    <span class="text-[13px] tabular">{{ $connectorCount }}</span>
                </div>
                <div class="flex flex-col gap-1 bg-surface-2 px-4 py-3">
                    <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Edition</span>
                    <span class="text-[13px]">{{ $edition === 'cloud' ? 'Cloud' : 'Self-hosted' }}</span>
                </div>
            </div>
        </div>

        @if ($membership->isOwner())
            <div class="overflow-hidden rounded-[10px] border border-danger-line bg-surface">
                <div class="flex items-center gap-2.5 border-b border-danger-line bg-danger-bg px-4 py-3">
                    <span class="flex h-[18px] w-[18px] items-center justify-center rounded-[5px] border border-danger-line font-mono text-[11px] text-danger">!</span>
                    <span class="text-[13.5px] font-medium text-danger">Actions that cannot be undone</span>
                </div>

                <div class="flex flex-col gap-4 p-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-[13px] font-medium">Revoke every connector</span>
                        <span class="text-[12.5px] text-text-2">
                            For when a platform credential is suspected of having leaked. All
                            {{ $connectorCount }} {{ Str::plural('connector', $connectorCount) }} stop
                            being trusted immediately, and each site needs a fresh enrolment code
                            before it reports again. Existing reports and history are untouched.
                        </span>
                    </div>

                    <form method="POST" action="{{ route('settings.connectors.rotate') }}"
                          class="flex flex-wrap items-end gap-2">
                        @csrf
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px]">Type <strong>{{ $organisation->name }}</strong> to confirm</span>
                            <input type="text" name="confirm_organisation" required autocomplete="off"
                                   class="h-[34px] w-[260px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[12.5px]">
                        </label>
                        <button type="submit"
                                class="h-[34px] whitespace-nowrap rounded-[7px] border border-danger-line bg-danger-bg px-3.5 text-[12.5px] font-medium text-danger hover:border-danger">
                            Revoke all connectors
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
