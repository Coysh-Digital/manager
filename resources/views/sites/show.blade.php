@extends('layouts.app')

@section('title', $site->name.' · Manager')
@section('crumb', App\Support\Crumbs::site($site, 'Overview'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        @if ($connector && $site->craft_version === null)
            {{-- The state that caused real confusion: paired successfully, but nothing reported yet,
                 so every version field is empty. Say which of the two it is. --}}
            <div class="mb-5 rounded-[9px] border border-info-line bg-info-bg px-4 py-3.5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[13px] font-medium text-info">Paired, but nothing reported yet</span>
                        <span class="text-[12.5px] text-text-2">
                            The connector has authenticated. Nothing is sent until something on the site
                            asks it to — <strong>Manager never calls out to a site</strong>, so there is
                            one more step: a scheduled task, set out under
                            <a href="{{ route('sites.settings', $site) }}#connector" class="text-info hover:text-primary-hover">Settings</a>.
                        </span>
                    </div>

                    <form method="POST" action="{{ route('sites.refresh', $site) }}">
                        @csrf
                        <button type="submit"
                                class="h-8 whitespace-nowrap rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Refresh now
                        </button>
                    </form>
                </div>
            </div>
        @endif

        {{--
            Three sentences, each a link to the tab that can do something about it. This replaces
            nothing — it is the one thing the Overview gained when everything else moved off it, and
            it exists because a tab bar shows where you can go and not whether it is worth going.
        --}}
        <div class="mb-6 grid grid-cols-1 gap-px overflow-hidden rounded-[10px] border border-border bg-border sm:grid-cols-2 xl:grid-cols-4">
            @php
                $signposts = [
                    [
                        'href' => route('sites.updates', $site),
                        'label' => 'Updates',
                        'value' => $updateReport === null
                            ? 'Not checked yet'
                            : ($updateCount === 0 ? 'Everything current' : $updateCount.' available'),
                        'notable' => $updateReport?->craft_security_release || ($updateReport?->plugin_security_releases ?? 0) > 0,
                    ],
                    [
                        'href' => route('sites.security', $site),
                        'label' => 'Findings',
                        'value' => $findingCount === 0 ? 'None outstanding' : $findingCount.' outstanding',
                        'notable' => $findingCount > 0,
                    ],
                    [
                        'href' => route('sites.health', $site),
                        'label' => 'Last check-in',
                        'value' => $site->last_seen_at?->diffForHumans(short: true) ?? 'Never',
                        'notable' => $site->last_seen_at !== null && $site->last_seen_at->lt(now()->subHour()),
                    ],
                    [
                        'href' => route('sites.backups', $site),
                        'label' => 'Last backup',
                        // Three states, not two. "Never" for a site being backed up but with nothing
                        // stored is a different problem from one where the permission was never
                        // granted, and only one of them needs somebody to chase it.
                        'value' => match (true) {
                            $latestBackup !== null => $latestBackup->taken_at->diffForHumans(short: true),
                            $site->hasCapability('backups:create') => 'None yet',
                            default => 'Not enabled',
                        },
                        'notable' => $latestBackup !== null && $latestBackup->taken_at->lt(now()->subWeek()),
                    ],
                ];
            @endphp

            @foreach ($signposts as $signpost)
                <a href="{{ $signpost['href'] }}"
                   class="flex flex-col gap-1 bg-surface px-4 py-3 no-underline hover:bg-row-hover">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">{{ $signpost['label'] }}</span>
                    <span class="text-[13.5px] {{ $signpost['notable'] ? 'font-medium text-amber' : 'text-text' }}">
                        {{ $signpost['value'] }}
                    </span>
                </a>
            @endforeach
        </div>

        {{--
            Pinned notes, above everything.

            The one thing on this page no connector can report: why the site is the way it is. A
            pinned note is an operational caveat somebody must read *before* acting — "PHP stays on
            8.2 until the gateway is replaced" belongs above the findings that will tell you to
            upgrade it, not in a disclosure underneath them.
        --}}
        @php $pinned = $notes->where('pinned', true); @endphp

        @if ($pinned->isNotEmpty())
            <div class="mb-6 flex flex-col gap-2">
                @foreach ($pinned as $note)
                    <div class="flex items-start gap-3 rounded-[9px] border border-amber-line bg-amber-bg px-4 py-3">
                        <span aria-hidden="true" class="mt-px flex h-5 w-5 flex-none items-center justify-center rounded-[5px] border border-amber-line font-mono text-[11px] text-amber">!</span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p class="whitespace-pre-line text-[13px] leading-relaxed text-text">{{ $note->body }}</p>
                            <span class="font-mono text-[11px] text-text-3">
                                {{ $note->authorName() }} · {{ $note->created_at->diffForHumans() }}
                            </span>
                        </div>

                        <form method="POST" action="{{ route('sites.notes.pin', [$site, $note]) }}" class="flex-none">
                            @csrf
                            <button type="submit" class="text-[12px] text-text-3 hover:text-text">Unpin</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        <h2 class="mb-2.5 text-[13.5px] font-semibold">What this site is running</h2>

        <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
            @php
                $inventory = [
                    'Craft CMS' => $site->craft_version ?? '—',
                    'Edition' => $site->craft_edition ? Str::title($site->craft_edition) : '—',
                    'PHP' => $site->php_version ?? '—',
                    'Database' => $latestReport
                        ? trim(Str::title((string) $latestReport->value('database.engine')).' '.$latestReport->value('database.version'))
                        : '—',
                    'Environment' => $latestReport ? Str::title((string) $latestReport->value('environment')) : '—',
                    'Connector version' => $connector?->connector_version ?? '—',
                    'Last report' => $site->last_inventory_at?->diffForHumans(short: true) ?? 'never',
                    'Key rotated' => $connector?->key_rotated_at?->diffForHumans(short: true) ?? '—',
                    'Report schema' => $latestReport?->schema_version ?? '—',
                ];
            @endphp

            @foreach ($inventory as $label => $value)
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-text-2">{{ $label }}</dt>
                    <dd class="font-mono">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach

            {{-- Was a dead count. The number was never the question; which ones was. --}}
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-text-2">Plugins</dt>
                <dd>
                    <a href="{{ route('sites.updates', $site) }}" class="font-mono text-primary hover:text-primary-hover">
                        {{ $latestReport ? count($latestReport->value('plugins', [])).' installed' : '—' }}
                    </a>
                </dd>
            </div>

            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-text-2">Capabilities</dt>
                <dd>
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="font-mono text-primary hover:text-primary-hover">
                        {{ count($site->grantedCapabilities()) }} granted
                    </a>
                </dd>
            </div>
        </dl>

        @if ($latestReport && $latestReport->value('queue'))
            @php
                $queue = [
                    'Jobs waiting' => [$latestReport->value('queue.pending', 0), false],
                    'Jobs failed' => [$latestReport->value('queue.failed', 0), $latestReport->value('queue.failed', 0) > 0],
                    'Migrations pending' => [$latestReport->value('migrations.pending', 0), $latestReport->value('migrations.pending', 0) > 0],
                ];
            @endphp

            <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">How it is behaving</h2>

            <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($queue as $label => [$value, $notable])
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-text-2">{{ $label }}</dt>
                        <dd class="tabular font-mono {{ $notable ? 'text-amber' : '' }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- The configuration flags used to sit in this grid. They are the facts findings are
                 derived from, so they belong beside the findings rather than three rows below the
                 queue depth. --}}
            <p class="mt-2 text-[12px] text-text-3">
                Configuration flags — dev mode, HTTPS, admin changes — are on
                <a href="{{ route('sites.security', $site) }}" class="text-primary hover:text-primary-hover">Security</a>,
                beside the findings derived from them.
            </p>
        @endif

        {{--
            Notes.

            The only free text in this product, and the only record here a connector cannot produce.
            Everything else on this page is a fact a site reported; this is a decision somebody made
            about it, and the reason — the thing that otherwise lives in a chat thread and leaves with
            whoever wrote it.
        --}}
        <div class="mb-2.5 mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-[13.5px] font-semibold">Notes</h2>
            <span class="text-[12px] text-text-3">Written by people here. Never sent to the site, never reported by it.</span>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            @forelse ($notes as $note)
                <div class="flex items-start gap-3 border-b border-border px-4 py-3 last:border-b-0">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <p class="whitespace-pre-line text-[13px] leading-relaxed">{{ $note->body }}</p>
                        <span class="font-mono text-[11px] text-text-3">
                            {{ $note->authorName() }} · {{ $note->created_at->diffForHumans() }}
                            @if ($note->pinned) · pinned @endif
                        </span>
                    </div>

                    <div class="flex flex-none items-center gap-3">
                        <form method="POST" action="{{ route('sites.notes.pin', [$site, $note]) }}">
                            @csrf
                            <button type="submit" class="text-[12px] text-text-3 hover:text-text">
                                {{ $note->pinned ? 'Unpin' : 'Pin' }}
                            </button>
                        </form>

                        {{-- The author, or an owner. One colleague deleting another's explanation of
                             why a site is configured the way it is should not be routine. --}}
                        @if ($note->author_id === auth()->id() || $membership->isOwner())
                            <form method="POST" action="{{ route('sites.notes.destroy', [$site, $note]) }}"
                                  onsubmit="return confirm('Delete this note?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-text-3 hover:text-danger">Delete</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-5 text-center text-[13px] text-text-2">
                    Nothing recorded yet. Worth a note: why this site is on the PHP version it is, what
                    the client has asked you not to touch, or which findings are deliberate here.
                </p>
            @endforelse

            <form method="POST" action="{{ route('sites.notes.store', $site) }}"
                  class="flex flex-col gap-2.5 border-t border-border bg-surface-2 p-4">
                @csrf

                <label class="sr-only" for="note-body">A note about {{ $site->name }}</label>
                <textarea name="body" id="note-body" rows="2" required minlength="2"
                          maxlength="{{ App\Models\SiteNote::MAX_LENGTH }}"
                          placeholder="Dev mode is on deliberately — this is a staging clone."
                          class="w-full rounded-[7px] border border-border-2 bg-surface px-2.5 py-2 text-[13px] leading-relaxed placeholder:text-text-3">{{ old('body') }}</textarea>
                @error('body')
                    <span class="text-[12px] text-danger">{{ $message }}</span>
                @enderror

                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                        <input type="checkbox" name="pinned" value="1" class="accent-[var(--primary)]">
                        Pin to the top of this page
                    </label>

                    <button type="submit"
                            class="ml-auto h-8 rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                        Add note
                    </button>
                </div>
            </form>
        </div>

        <div class="mb-2.5 mt-6 flex items-baseline justify-between gap-4">
            <h2 class="text-[13.5px] font-semibold">Recent activity</h2>
            <a href="{{ route('sites.audit', $site) }}" class="text-[12.5px] text-primary hover:text-primary-hover">
                View the full log
            </a>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
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
