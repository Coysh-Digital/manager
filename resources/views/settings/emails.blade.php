@extends('layouts.app')

@section('title', 'Emails · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('Emails'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header />

        {{-- Whether anything can leave at all, above the list of what would.

             Absent on a hosted edition, and that is correct rather than a gap: the relay belongs to
             whoever runs the service, so the check is marked for the operator and forReader() drops
             it. A customer being shown a red row about infrastructure they cannot reach invites a
             support ticket whose answer is "yes, we know, that is ours". --}}
        @if ($mail !== null)
            <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <span class="text-[13px] font-medium">Delivery</span>
                            <x-status-badge
                                :tone="$mail->failed() ? 'bad' : ($mail->warned() ? 'warn' : 'ok')"
                                :label="$mail->failed() ? 'Not working' : ($mail->warned() ? 'Needs attention' : 'Configured')" />
                        </div>
                        <p class="text-[12.5px] text-text-2">{{ $mail->detail }}</p>
                        @if ($mail->remedy !== null)
                            <p class="text-[12.5px] text-text-3">{{ $mail->remedy }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3">
                <div class="text-[13.5px] font-medium">What this installation sends</div>
                <p class="mt-1 text-[12.5px] text-text-2">
                    Every email Manager can send, and what has to happen for somebody to receive one.
                    Nothing here is sent on a schedule for its own sake: each one is a consequence of
                    something occurring.
                </p>
            </div>

            <div class="p-4">
                @foreach ($entries as $entry)
                    <div class="flex flex-col gap-1.5 border-t border-border py-3.5 first:border-t-0 first:pt-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[13px] font-medium">{{ $entry->name }}</span>

                            @if ($entry->isHosted())
                                {{-- Only meaningful where a hosting layer is bound. Shown rather than
                                     hidden so the list reads as complete on the edition it describes. --}}
                                <x-status-badge tone="info" label="Manager Cloud" />
                            @endif

                            @if ($entry->queued() === false)
                                {{-- Sent on the thread that triggered it. Worth surfacing: an
                                     unqueued email means a mail failure is felt by whatever caused
                                     it, rather than by a retrying worker. --}}
                                <x-status-badge tone="grey" label="Sent immediately" />
                            @endif
                        </div>

                        <p class="text-[12.5px] text-text-2">{{ $entry->trigger }}</p>
                        <p class="text-[12.5px] text-text-3">{{ $entry->recipients }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="text-[12.5px] text-text-3">
            Manager sends nothing else. There is no newsletter, no product announcement and no
            marketing mail of any kind, and no address held here is ever used for one.
        </p>
    </div>
@endsection
