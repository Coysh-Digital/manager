@extends('layouts.app')

@section('title', 'Audit · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Audit'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        {{-- Craft's side first, because it is the one this page used to say nothing about at all. --}}
        <h2 class="mb-2.5 text-[13.5px] font-semibold">Failed sign-ins to the site</h2>

        @include('sites.partials.sign-ins')

        <div class="mb-2.5 mt-6 flex flex-wrap items-baseline justify-between gap-4">
            <h2 class="text-[13.5px] font-semibold">Everything done to this site</h2>
            <a href="{{ route('activity.index', ['site' => $site->external_id]) }}"
               class="text-[12.5px] text-primary hover:text-primary-hover">
                Open in the fleet log
            </a>
        </div>

        <p class="mb-3 max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
            Append-only. Entries cannot be edited or deleted, and each one commits to the entry before
            it, so any alteration is detectable.

            @if (app(App\Contracts\ServerAccess::class)->reachable())
                Verify the chain with
                <code class="font-mono text-[12px]">php artisan manager:audit:verify</code>.
            @endif

            Removing this site would not remove these rows.
        </p>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border p-3.5">
                <select name="outcome" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
                    <option value="">Any outcome</option>
                    <option value="success" @selected($outcome === 'success')>Succeeded</option>
                    <option value="failure" @selected($outcome === 'failure')>Failed</option>
                </select>
                <button type="submit" class="h-[30px] rounded-[7px] border border-border bg-surface px-3 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                    Apply
                </button>
                @if ($outcome !== '')
                    <a href="{{ route('sites.audit', $site) }}" class="text-[12.5px] text-primary hover:text-primary-hover">Clear</a>
                @endif
            </form>

            <div class="relative overflow-x-auto">
                <table class="table-sticky w-full min-w-[820px] text-[13px]">
                    <thead>
                        <tr class="bg-surface-2">
                            @foreach (['#', 'When', 'Action', 'Actor', 'Source', 'Outcome'] as $heading)
                                <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3">
                                    {{ $heading }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($events as $event)
                            <tr class="border-b border-border last:border-b-0 hover:bg-row-hover">
                                <td class="px-3 py-2.5 font-mono text-[11.5px] tabular text-text-3">{{ $event->seq }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[11.5px] text-text-3"><x-timestamp :at="$event->created_at" format="d M Y H:i" /></td>
                                <td class="px-3 py-2.5"><code class="font-mono text-[12px]">{{ $event->action }}</code></td>
                                <td class="px-3 py-2.5 text-text-2">{{ $event->actor_label ?? Str::title($event->actor_type) }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[11.5px] text-text-3">{{ $event->ip ?? '—' }}</td>
                                <td class="px-3 py-2.5">
                                    @if ($event->succeeded())
                                        <x-status-badge tone="ok" label="Succeeded" />
                                    @else
                                        <x-status-badge tone="bad" :label="$event->failure_reason ?? 'Failed'" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-[13px] text-text-2">
                                    Nothing has happened on this site yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($events->hasPages())
                <div class="border-t border-border bg-surface-2 px-3.5 py-2.5">{{ $events->links() }}</div>
            @endif
        </div>

        {{--
            What this log is not.

            It records what Manager did — who granted a capability, who asked for a refresh, who
            removed a site. It does not record what happened *inside* Craft: somebody signing in to
            the control panel, an entry being edited, a failed password. Nothing reports that yet, and
            an audit screen that quietly implies otherwise would be worse than one that says so.
        --}}
        <p class="mt-3 rounded-[9px] border border-border bg-surface-2 px-4 py-3 text-[12px] leading-relaxed text-text-2">
            This is Manager's own record: what was done to this site <em>from here</em>, and by whom.
            It is hash-chained and append-only. The sign-in figures above are a different kind of
            thing — reported telemetry from the site, neither chained nor tamper-evident — and Craft's
            own log of content changes stays on the site, where it belongs.
        </p>
    </div>
@endsection
