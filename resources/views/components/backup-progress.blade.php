{{--
    A backup that has been asked for and has not arrived.

    A stepper rather than a bar, and that is a constraint rather than a style choice: the protocol
    carries a phase name and nothing else. There is no percentage to draw, and inventing one from
    elapsed time would be a number that looks like a measurement and is not.

    The site column is optional so the fleet screen can name the site and a single site's screen can
    leave it out.
--}}
@props(['backup', 'window', 'showSite' => false, 'canCancel' => false])

@php
    $phases = App\Domain\Backup\InFlightBackup::PHASES;
@endphp

<div class="flex flex-col gap-2 border-b border-border px-4 py-3 last:border-b-0"
     data-backup-progress
     data-backup-job="{{ $backup->jobId }}"
     data-backup-phase="{{ $backup->phase }}">
    <div class="flex flex-wrap items-center gap-2">
        {{-- One tone across every phase. A backup that is proceeding normally is not a warning, and
             amber for the ordinary case would leave nothing to say with when one actually fails. --}}
        <x-status-badge tone="info" :label="$backup->label()" />

        @if ($showSite)
            <a href="{{ route('sites.show', $backup->site) }}" class="text-[13px] font-medium no-underline hover:underline">
                {{ $backup->site->name }}
            </a>
        @endif

        @if ($backup->looksStalled())
            {{-- Not an error. The job has not expired and the site may still be working - but it has
                 been quiet for long enough that "large database" has stopped being the likeliest
                 explanation, and somebody watching a stepper that has not moved deserves to be told
                 that rather than left to guess. --}}
            <x-status-badge tone="amber" label="No change" />
        @endif

        <span class="ml-auto font-mono text-[11.5px] text-text-3" data-backup-elapsed>
            {{ $backup->since()->diffForHumans(short: true) }} at this phase
            @if ($backup->requestedBy)
                · requested by {{ $backup->requestedBy }}
            @endif
        </span>

        {{-- Says what it does rather than what somebody hopes it does. The confirmation is where
             the distinction lives: we stop accepting the backup, we do not stop the site making
             one, and a button labelled "Cancel" with nothing beside it would imply otherwise. --}}
        @if ($canCancel)
            <form method="POST" action="{{ route('backups.cancel') }}"
                  onsubmit="return confirm('Stop waiting for this backup? It will be refused if it arrives. The site may still finish its own copy - nothing here can reach it to stop that.');">
                @csrf
                <input type="hidden" name="job" value="{{ $backup->jobId }}">
                <button type="submit" class="text-[11.5px] text-text-3 hover:text-danger">Cancel</button>
            </form>
        @endif
    </div>

    {{-- Five segments, filled to the phase reached. Text beside it, never colour alone. --}}
    <div class="flex gap-1" role="presentation" data-backup-steps>
        @foreach ($phases as $index => $phase)
            <span class="h-[3px] flex-1 rounded-full {{ $index <= $backup->step() ? 'bg-primary' : 'bg-border-2' }}"
                  data-backup-step="{{ $index }}"></span>
        @endforeach
    </div>

    <p class="text-[12px] leading-relaxed text-text-3" data-backup-detail>
        {{ $backup->detail($window) }}
        @if ($backup->reportedBySite)
            Reported by the site rather than observed here.
        @endif

        @if ($backup->expiresAt)
            {{-- The honest end of the wait. Nothing here can make a site answer, so the useful thing
                 to say is when this stops being counted as running. --}}
            Given up on at <x-timestamp :at="$backup->expiresAt" format="H:i" /> if nothing further arrives.
        @endif
    </p>
</div>
