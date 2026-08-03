{{--
    A backup that was asked for and did not happen, leaving nothing behind.

    The counterpart to <x-backup-progress>, and it exists because these were invisible. A site that
    refuses the job — too large for what its connector is configured to take, no recovery key to
    encrypt to — refuses before it declares an artifact, so there is no row on the backups list. The
    job then leaves the queue, so there is no in-progress row either. The request a person watched
    disappeared, and the screen went back to showing the previous backup, which succeeded.

    Amber rather than red. Nothing has been lost — the earlier backups are all still there — and the
    thing that needs to change is usually one setting. Red is for when there is nothing to restore.
--}}
@props(['failure', 'showSite' => false, 'canDismiss' => false])

<div class="flex flex-col gap-2 border-b border-border px-4 py-3 last:border-b-0">
    <div class="flex flex-wrap items-center gap-2">
        <x-status-badge tone="amber" label="Did not complete" />

        @if ($showSite)
            <a href="{{ route('sites.show', $failure->site) }}" class="text-[13px] font-medium no-underline hover:underline">
                {{ $failure->site->name }}
            </a>
        @endif

        <span class="ml-auto font-mono text-[11.5px] text-text-3">
            {{ $failure->failedAt->diffForHumans(short: true) }}
            @if ($failure->requestedBy)
                · requested by {{ $failure->requestedBy }}
            @endif
        </span>

        {{-- No confirmation dialog: this hides a sentence. The job, the reason and the audit entry
             are all still there, which is what the panel heading says. --}}
        @if ($canDismiss)
            <form method="POST" action="{{ route('backups.failures.dismiss') }}">
                @csrf
                <input type="hidden" name="job" value="{{ $failure->jobId }}">
                <button type="submit" class="text-[11.5px] text-text-3 hover:text-text">Dismiss</button>
            </form>
        @endif
    </div>

    {{-- The site's own words, or the platform's. Never anything the site said about its contents. --}}
    <p class="text-[12.5px] leading-relaxed text-text-2">{{ $failure->reason }}</p>

    @if ($remedy = $failure->remedy())
        {{-- Only where the reason is one the platform recognises. A guess dressed as advice sends
             somebody to change a setting that was not the problem. --}}
        <p class="text-[12px] leading-relaxed text-text-3">{{ $remedy }}</p>
    @endif
</div>
