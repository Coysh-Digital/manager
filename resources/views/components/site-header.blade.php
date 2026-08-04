@props(['site', 'connector' => null, 'pendingConnector' => null])

{{--
    The block at the top of all six site screens: who this is, whether it is talking to us, and the
    one action available from anywhere.

    The banners below it are here rather than on the Overview because they are true regardless of
    which tab somebody happens to be on. An enrolment code issued from Settings has to be shown on
    Settings, and a connector paired from an unexpected domain is not a fact about the Overview.
--}}
<div class="mb-4 flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
    <div class="flex min-w-0 flex-col gap-2">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
            <h1 class="text-[19px] font-semibold tracking-[-0.015em] sm:text-[22px]">{{ $site->name }}</h1>
            <span class="rounded-[5px] border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-text-2">
                {{ Str::title($site->environment) }}
            </span>
            @if ($connector)
                <x-status-badge tone="ok" label="Connected" />
            @elseif ($pendingConnector)
                <x-status-badge tone="warn" label="Awaiting confirmation" />
            @else
                <x-status-badge tone="grey" label="Not connected" />
            @endif
        </div>
        <p class="text-[12.5px] text-text-2 sm:text-[13px]">
            <span class="break-all">{{ $site->expected_domain }}</span>
            <span class="hidden sm:inline">· Read-only access</span> ·
            Last seen {{ $site->last_seen_at?->diffForHumans() ?? 'never' }}
        </p>
    </div>

    <div class="flex flex-none items-center gap-2">
        {{-- Queues a job rather than fetching anything: the platform never calls out to a site.
             The confirmation message says so, because a button that looked instantaneous and
             was not would be worse than no button. --}}
        <form method="POST" action="{{ route('sites.refresh', $site) }}">
            @csrf
            <button type="submit"
                    class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text hover:bg-row-hover">
                Refresh
            </button>
        </form>
    </div>
</div>

{{-- Shown once, on the request that issued it. Only the hash is stored, so there is no route
     that will show it again — which is exactly why it is safe to show here. --}}
@if (session('enrolmentCode'))
    @php
        // Null on a self-hosted installation, where the operator chose the address and this
        // application cannot know it. Non-null only where somebody published a name for connector
        // traffic — see App\Contracts\PairingAddress.
        $pairingAddress = app(\App\Contracts\PairingAddress::class)->url();

        // Built once and used for both the visible command and the copy button. They were two
        // separate literals, which is the arrangement where one of them quietly stops matching.
        $pairCommand = 'php craft manager-connector/pair '.session('enrolmentCode')
            .($pairingAddress !== null ? ' --platform-url='.$pairingAddress : '');
    @endphp

    <div class="mb-5 rounded-[10px] border border-primary bg-pale p-4">
        <p class="mb-1.5 text-[13.5px] font-medium">Enrolment code — shown once</p>
        <p class="mb-3 text-[12.5px] text-text-2">
            Single-use, expires in {{ (int) (config('manager.enrolment.ttl') / 60) }} minutes,
            and stored only as a hash — if you lose it, issue another.
        </p>

        {{-- The code itself is the thing somebody came here for, so it is the largest thing on
             the panel. The command that consumes it is a detail, and detail belongs behind a
             disclosure. --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <code id="enrolment-code"
                  class="flex-1 overflow-x-auto rounded-lg border border-primary bg-surface px-3 py-2.5 font-mono text-[13px] whitespace-nowrap">{{ session('enrolmentCode') }}</code>

            <button type="button"
                    data-copy="{{ session('enrolmentCode') }}"
                    data-copy-from="enrolment-code"
                    data-copy-done="Copied"
                    class="h-[38px] flex-none rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                <span data-copy-label>Copy</span>
            </button>
        </div>

        {{-- Only where an address was published. Self-hosted this is absent rather than guessed:
             APP_URL is what this application generates links with, not a promise about what a Craft
             site somewhere else can reach, and a wrong address here would be read as instruction. --}}
        @if ($pairingAddress !== null)
            <p class="mb-3 text-[12.5px] text-text-2">
                Pair against
                <code class="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[12px]">{{ $pairingAddress }}</code>
                — enter that as the Manager platform address on the site. It is deliberately not the
                address of this page: a backup is a single request carrying the whole database, and
                connector traffic is served separately so that it can carry one.
            </p>
        @endif

        <details class="group">
            <summary class="cursor-pointer list-none text-[12.5px] text-text-2 hover:text-text">
                <span class="group-open:hidden">Show the command to run on the site</span>
                <span class="hidden group-open:inline">Hide the command</span>
            </summary>

            <div class="mt-2.5 flex flex-col gap-2">
                <p class="text-[12.5px] text-text-2">
                    Most people should use the control panel instead:
                    <strong>Utilities → Manager Connector</strong> on
                    <span class="font-mono text-[12px]">{{ $site->expected_domain }}</span>.
                    No shell needed, which matters on managed hosting.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <code id="enrolment-command"
                          class="flex-1 overflow-x-auto rounded-lg bg-surface-2 px-3 py-2 font-mono text-[12px] whitespace-nowrap">{{ $pairCommand }}</code>

                    <button type="button"
                            data-copy="{{ $pairCommand }}"
                            data-copy-from="enrolment-command"
                            class="h-8 flex-none rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                        <span data-copy-label>Copy</span>
                    </button>
                </div>
            </div>
        </details>

        <p class="mt-3 text-[12px] text-text-3">
            The connector generates its own keypair on the site and sends only the public half.
            Manager never receives a private key, an administrator password or a database
            credential.
        </p>
    </div>
@endif

@if ($pendingConnector)
    <div class="mb-5 flex items-start gap-3 rounded-[9px] border border-amber-line bg-amber-bg px-4 py-3.5">
        <span class="mt-px flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border border-amber-line font-mono text-[12px] text-amber">!</span>
        <div class="flex flex-1 flex-col gap-1">
            <span class="text-[13.5px] font-medium text-text">This connector paired from an unexpected domain</span>
            <span class="text-[13px] text-text-2">
                It reported <code class="font-mono">{{ $pendingConnector->submitted_domain }}</code>,
                but this site is recorded as <code class="font-mono">{{ $site->expected_domain }}</code>.
                Nothing is being reported until you confirm. If you did not expect this, revoke it instead.
            </span>
        </div>
        <form method="POST" action="{{ route('sites.connector.confirm', $site) }}">
            @csrf
            <button type="submit" class="h-[30px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] font-medium text-text hover:bg-row-hover">
                Confirm and adopt this domain
            </button>
        </form>
    </div>
@endif
