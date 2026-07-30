@extends('layouts.app')

@section('title', $site->name.' · Manager')
@section('crumb', $site->name)

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <div class="mb-2 flex items-start justify-between gap-6">
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2.5">
                    <h1 class="text-[22px] font-semibold tracking-[-0.015em]">{{ $site->name }}</h1>
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
                <p class="text-[13px] text-text-2">
                    {{ $site->expected_domain }} · Read-only access ·
                    Last seen {{ $site->last_seen_at?->diffForHumans() ?? 'never' }}
                </p>
            </div>

            <a href="{{ route('sites.capabilities', $site) }}"
               class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
                Capabilities
            </a>
        </div>

        {{-- Shown once, on the request that issued it. Only the hash is stored, so there is no route
             that will show it again — which is exactly why it is safe to show here. --}}
        @if (session('enrolmentCode'))
            <div class="mb-5 mt-5 rounded-[10px] border border-primary bg-pale p-4">
                <p class="mb-1.5 text-[13.5px] font-medium">Enrolment code — shown once</p>
                <p class="mb-3 text-[12.5px] text-text-2">
                    Run this on <code class="font-mono">{{ $site->expected_domain }}</code>. It is
                    single-use, expires in
                    {{ (int) (config('manager.enrolment.ttl') / 60) }} minutes, and is not stored anywhere
                    it can be read back — if you lose it, issue another.
                </p>

                <pre class="mb-3 overflow-x-auto rounded-lg border border-primary bg-surface p-3"><code class="font-mono text-[12.5px]">php craft manager-connector/pair {{ session('enrolmentCode') }}</code></pre>

                <p class="text-[12px] text-text-3">
                    The connector generates its own keypair on the site and sends only the public half.
                    Manager never receives a private key, an administrator password or a database
                    credential.
                </p>
            </div>
        @endif

        @if ($membership->canAdminister())
            <div class="mb-5 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-border bg-surface-2 px-4 py-3">
                <div class="flex flex-col gap-0.5">
                    <span class="text-[13px] font-medium">
                        {{ $connector ? 'Re-pair this site' : 'Pair this site' }}
                    </span>
                    <span class="text-[12.5px] text-text-2">
                        @if ($connector)
                            This site has a working connector. A new code will not replace it unless you
                            say so, which is what stops a compromised site re-pairing itself.
                        @else
                            Issues a single-use code to run on the site.
                        @endif
                    </span>
                </div>

                <form method="POST" action="{{ route('sites.enrolment-code', $site) }}"
                      class="flex flex-wrap items-center gap-3">
                    @csrf

                    @if ($connector)
                        <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                            <input type="checkbox" name="authorise_replacement" value="1" required
                                   class="accent-[var(--primary)]">
                            Replace the current connector
                        </label>
                    @endif

                    <button type="submit"
                            class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                        Issue a code
                    </button>
                </form>
            </div>
        @endif

        @if ($pendingConnector)
            <div class="mb-5 mt-5 flex items-start gap-3 rounded-[9px] border border-amber-line bg-amber-bg px-4 py-3.5">
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

        <div class="mt-5 grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-3">
            <div class="flex flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Connection</span>
                    <span class="text-[12px] font-medium {{ $connector ? 'text-ok' : 'text-text-2' }}">
                        {{ $connector ? '✓ Connected' : '— Not connected' }}
                    </span>
                </div>
                <dl class="flex flex-col gap-2 text-[12.5px]">
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Connector version</dt><dd class="font-mono">{{ $connector?->connector_version ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Last verified</dt><dd class="font-mono">{{ $connector?->last_seen_at?->diffForHumans(short: true) ?? 'never' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Key rotated</dt><dd class="font-mono">{{ $connector?->key_rotated_at?->diffForHumans(short: true) ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Request signing</dt><dd class="{{ $connector ? 'text-ok' : 'text-text-2' }}">{{ $connector ? 'Verified' : '—' }}</dd></div>
                </dl>
            </div>

            <div class="flex flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Craft and PHP</span>
                </div>
                <dl class="flex flex-col gap-2 text-[12.5px]">
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Craft CMS</dt><dd class="font-mono">{{ $site->craft_version ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Edition</dt><dd class="font-mono">{{ $site->craft_edition ? Str::title($site->craft_edition) : '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">PHP</dt><dd class="font-mono">{{ $site->php_version ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-text-2">Plugins</dt>
                        <dd class="font-mono">{{ $latestReport ? count($latestReport->value('plugins', [])).' installed' : '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="flex flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Reporting</span>
                </div>
                <dl class="flex flex-col gap-2 text-[12.5px]">
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Last report</dt><dd class="font-mono">{{ $site->last_inventory_at?->diffForHumans(short: true) ?? 'never' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Schema</dt><dd class="font-mono">{{ $latestReport?->schema_version ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-text-2">Environment</dt><dd>{{ $latestReport ? Str::title((string) $latestReport->value('environment')) : '—' }}</dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-text-2">Capabilities</dt>
                        <dd class="font-mono">{{ count($site->grantedCapabilities()) }} granted</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($latestReport && $latestReport->value('queue'))
            <div class="mt-3.5 grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-3">
                <div class="flex flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Queue and migrations</span>
                    <dl class="flex flex-col gap-2 text-[12.5px]">
                        <div class="flex justify-between gap-3"><dt class="text-text-2">Jobs waiting</dt><dd class="font-mono tabular">{{ $latestReport->value('queue.pending', 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-text-2">Jobs failed</dt><dd class="font-mono tabular {{ $latestReport->value('queue.failed', 0) > 0 ? 'text-amber' : '' }}">{{ $latestReport->value('queue.failed', 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-text-2">Migrations pending</dt><dd class="font-mono tabular">{{ $latestReport->value('migrations.pending', 0) }}</dd></div>
                    </dl>
                </div>

                <div class="flex flex-col gap-3 rounded-[10px] border border-border bg-surface p-4 shadow-[var(--shadow)]">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Configuration</span>
                    <dl class="flex flex-col gap-2 text-[12.5px]">
                        @foreach ([
                            'dev_mode' => 'Dev mode',
                            'allow_admin_changes' => 'Admin changes',
                            'allow_updates' => 'Updates allowed',
                            'https_enforced' => 'HTTPS enforced',
                        ] as $key => $label)
                            @php $value = $latestReport->value('config_flags.'.$key); @endphp
                            <div class="flex justify-between gap-3">
                                <dt class="text-text-2">{{ $label }}</dt>
                                <dd>{{ $value === null ? '—' : ($value ? 'Yes' : 'No') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>
        @endif

        <div class="mt-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Recent activity</span>
                <a href="{{ route('activity.index', ['site' => $site->external_id]) }}" class="text-[12.5px] text-primary hover:text-primary-hover">
                    View full activity log
                </a>
            </div>
            @forelse ($recentActivity as $event)
                <div class="grid grid-cols-[130px_1fr_160px] items-center gap-4 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                    <span class="font-mono text-[11.5px] text-text-3">{{ $event->created_at->diffForHumans(short: true) }}</span>
                    <span class="{{ $event->succeeded() ? '' : 'text-danger' }}">
                        {{ Str::of($event->action)->replace('.', ' ')->ucfirst() }}
                        @unless ($event->succeeded())
                            <span class="text-text-2">— {{ $event->failure_reason }}</span>
                        @endunless
                    </span>
                    <span class="text-text-2">{{ $event->actor_label ?? Str::title($event->actor_type) }}</span>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-[13px] text-text-2">Nothing has happened on this site yet.</p>
            @endforelse
        </div>
    </div>
@endsection
