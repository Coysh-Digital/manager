{{--
    A backup that has been asked for and has not arrived.

    A stepper rather than a bar, and that is a constraint rather than a style choice: the protocol
    carries a phase name and nothing else. There is no percentage to draw, and inventing one from
    elapsed time would be a number that looks like a measurement and is not.

    The site column is optional so the fleet screen can name the site and a single site's screen can
    leave it out.
--}}
@props(['backup', 'window', 'showSite' => false])

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

        <span class="ml-auto font-mono text-[11.5px] text-text-3" data-backup-elapsed>
            requested {{ $backup->requestedAt->diffForHumans(short: true) }}
            @if ($backup->requestedBy)
                by {{ $backup->requestedBy }}
            @endif
        </span>
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
    </p>
</div>
