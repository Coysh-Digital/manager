@extends('layouts.app')

@section('title', 'Activity log · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Activity log'))

@section('content')
    <div class="mb-5 flex flex-col gap-1.5">
        <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Activity log</h1>
        <p class="text-[13px] text-text-2">
            Append-only. Entries cannot be edited or deleted, and each one commits to the entry before it,
            so any alteration is detectable.

            {{-- The command is only an answer to somebody who can run it. Hosted, the claim still
                 holds and is still worth stating; the instruction is for a machine they have no
                 account on. --}}
            @if (app(App\Contracts\ServerAccess::class)->reachable())
                Verify the chain with
                <code class="font-mono text-[12px]">php artisan manager:audit:verify</code>.
            @endif
        </p>
    </div>

    <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border p-3.5">
            <select name="outcome" class="h-[30px] rounded-[7px] border border-border bg-surface px-2 text-[12.5px] text-text-2">
                <option value="">Any outcome</option>
                <option value="success" @selected($filters['outcome'] === 'success')>Succeeded</option>
                <option value="failure" @selected($filters['outcome'] === 'failure')>Failed</option>
            </select>
            <button type="submit" class="h-[30px] rounded-[7px] border border-border bg-surface px-3 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                Apply
            </button>
            @if ($filters['outcome'] !== '' || $filters['site'] !== '')
                <a href="{{ route('activity.index') }}" class="text-[12.5px] text-primary hover:text-primary-hover">Clear</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="table-sticky w-full min-w-[900px] text-[13px]">
                <thead>
                    <tr class="bg-surface-2">
                        @foreach (['#', 'When', 'Action', 'Site', 'Actor', 'Source', 'Outcome'] as $heading)
                            <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr class="border-b border-border hover:bg-row-hover">
                            <td class="px-3 py-2.5 font-mono text-[11.5px] text-text-3 tabular">{{ $event->seq }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[11.5px] text-text-3">{{ $event->created_at->format('d M Y H:i') }}</td>
                            <td class="px-3 py-2.5"><code class="font-mono text-[12px]">{{ $event->action }}</code></td>
                            <td class="px-3 py-2.5 text-text-2">{{ $event->site_label ?? '—' }}</td>
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
                        <tr><td colspan="7" class="px-4 py-10 text-center text-[13px] text-text-2">Nothing recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($events->hasPages())
            <div class="border-t border-border bg-surface-2 px-3.5 py-2.5">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
