@props(['event', 'previous' => null])

{{--
    One audit row's action, plus what it has to do with the row above it.

    Written because of a real reading. A backup failed and the log showed two entries at the same
    minute, with the same sentence, against the same site - `job.failed` and `backup.failed` - and
    they read as two separate failures. They are not: they are one failure recorded against the two
    objects it touched. The job left the queue, and the artifact that job had already declared was
    settled as failed in the same request.

    Both rows have to stay. The table is append-only and hash-chained, so nothing here could remove
    one even if it should - and it should not, because each is the honest record of one object, and
    the backups screen reads the artifact while the job registry reads the job.

    What was missing was the connection, and it was already in the data: both rows are written inside
    one request, so both carry the same correlation id. Saying so turns two entries into one story.
--}}

@php
    $isBackupArtifact = $event->target_type === 'backup_artifact';
    $isBackupJob = $event->target_type === 'job' && ($event->after['type'] ?? null) === 'backup.create';

    // Only when the row above is genuinely part of the same request. The log is ordered by sequence,
    // which is the chain's own order, so "the row above" is the next event in the same organisation -
    // and a shared correlation id means the same request wrote both.
    $sameRequest = $previous !== null
        && $event->correlation_id !== null
        && $previous->correlation_id === $event->correlation_id;

    $site = $event->site;
@endphp

<code class="font-mono text-[12px]">{{ $event->action }}</code>

@if ($isBackupArtifact && $site !== null)
    <a href="{{ route('sites.backups', $site) }}"
       class="ml-2 whitespace-nowrap text-[11.5px] text-primary hover:text-primary-hover">
        View this backup
    </a>
@elseif ($isBackupJob && $sameRequest)
    {{-- The artifact's own row is the one carrying the reason a person can act on, and it is
         immediately above. Pointing at it is more useful than repeating it. --}}
    <span class="ml-2 whitespace-nowrap text-[11.5px] text-text-3">
        Same backup as #{{ $previous->seq }}
    </span>
@elseif ($isBackupJob && $site !== null)
    <a href="{{ route('sites.backups', $site) }}"
       class="ml-2 whitespace-nowrap text-[11.5px] text-primary hover:text-primary-hover">
        View this site's backups
    </a>
@endif
