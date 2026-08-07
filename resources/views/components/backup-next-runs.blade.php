{{--
    When this site's schedule will next fire.

    A component rather than markup in two places, because it renders in two: under the schedule form
    for somebody who can change it, and beside the read-only sentence for somebody who cannot. Those
    two branches already disagreed once about how much to say - the read-only one said only the
    cadence - and "when is this backed up" is not privileged information. Leaving it blank for a
    member reads as "never".

    The times are projected by App\Domain\Backup\BackupSchedule, the same object
    ScheduleBackupsCommand asks whether a site is due. That is the only reason it is honest to print
    them: a "next run" a screen works out for itself is a claim about somebody else's code, and when
    the two disagree it is the screen people believe.

    `compact` is for the read-only placement, where this sits inline under one sentence rather than
    under a form and does not need its own heading.
--}}
@props(['runs', 'compact' => false])

@if ($runs !== [])
    <div class="flex flex-col gap-1">
        @unless ($compact)
            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Next runs</span>
        @endunless

        @foreach ($runs as $run)
            <span class="font-mono text-[12px] tabular text-text-2">
                {{-- The zone abbreviation is part of the time, not decoration. An hour without one
                     is a number the reader guesses at, and the guess is usually their own zone
                     rather than the site's - which is the one the scheduler reads. --}}
                {{ $run->format('j M Y, H:i T') }}
                <span class="text-text-3">({{ $run->diffForHumans(short: true) }})</span>
            </span>
        @endforeach

        {{-- The scheduler decides on the hour, so a minute-accurate time would be a promise it does
             not make. --}}
        <span class="text-[11.5px] text-text-3">
            Requested on the hour each one is due, in this site's own time zone.
        </span>
    </div>
@endif
