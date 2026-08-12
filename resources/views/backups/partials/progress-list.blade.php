{{--
    The in-progress backup cards, and nothing around them.

    Extracted so there is exactly one renderer. The poller replaces this region rather than patching
    the nodes inside it, and the alternative - rebuilding a card in JavaScript - would be a second
    renderer of the same object: five stepper segments, a phase label, an explanatory sentence, a
    "No change" badge measured against the job's own expiry, an elapsed time, a requester, a
    timezone-aware "given up on at", and a cancel form carrying a CSRF token. Two of those would
    drift, and the JavaScript one would sit outside every invariant that reads resources/views.

    Patching in place is what this replaced, and it is why the elapsed line used to go stale: only
    the phase was being written, so a card said "2m at this phase" for twenty minutes.
--}}
@foreach ($inFlight as $backup)
    <x-backup-progress
        :backup="$backup"
        :window="$checkInWindow"
        :show-site="$showSite ?? false"
        :can-cancel="$canCancel ?? false" />
@endforeach
