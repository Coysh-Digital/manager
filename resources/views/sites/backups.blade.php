@extends('layouts.app')

@section('title', 'Backups · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Backups'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        {{-- This screen had no errors block at all, so its own validation messages — "that site does
             not have permission", and now the readiness ones — were flashed and then silently
             discarded. The fleet screen has always rendered them. --}}
        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        @if (! $site->hasCapability('backups:create'))
            {{--
                Not merely "no backups yet". The permission is off, it is off deliberately, and the
                screen says which of the two states this is — somebody who granted it last week and
                sees an empty list needs to be able to tell them apart.
            --}}
            <div class="rounded-[10px] border border-border bg-surface p-8 text-center">
                <p class="mb-1.5 text-[14px] font-medium">This site is not being backed up</p>
                <p class="mx-auto mb-4 max-w-[560px] text-[13px] leading-relaxed text-text-2">
                    Backups are off until they are granted per site. That is deliberate: this permission
                    reads the entire database, so it is never granted when a site is paired and never
                    offered as a switch beside the read-only ones.
                </p>
                <p class="mx-auto mb-4 max-w-[560px] rounded-[9px] border border-border bg-surface-2 px-4 py-3 text-[12.5px] leading-relaxed text-text-2">
                    {{ $acknowledgement }}
                </p>
                <a href="{{ route('sites.settings', $site) }}#capabilities"
                   class="inline-flex h-[34px] items-center rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text no-underline hover:bg-row-hover">
                    Grant it in Settings
                </a>
            </div>
        @else
            <div class="mb-4 overflow-hidden rounded-[10px] border border-border bg-surface">
                <div class="flex flex-wrap items-center gap-x-10 gap-y-4 border-b border-border px-4 py-3.5">
                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Last backup</span>
                        <div class="flex items-center gap-2.5">
                            <span class="text-[15px] font-medium">
                                {{ $latest?->taken_at->diffForHumans() ?? 'Never' }}
                            </span>
                            @if ($latest === null)
                                <x-status-badge tone="grey" label="None stored" />
                            @elseif ($latest->taken_at->lt(now()->subWeek()))
                                {{-- A week is not a policy, it is a prompt. With no schedule it means
                                     nobody has asked lately; with one it means the schedule is not
                                     doing what somebody believes it is, which is worth more. --}}
                                <x-status-badge tone="warn" label="Over a week old" />
                            @else
                                <x-status-badge tone="ok" label="Recent" />
                            @endif
                        </div>
                    </div>

                    @php
                        $figures = [
                            'Stored' => $storedCount.' '.Str::plural('backup', $storedCount),
                            'In storage' => number_format($storedBytes / 1048576, 1).' MB',
                            'Latest size' => $latest?->humanSize() ?? '—',
                            'Deleted' => $latest?->expires_at?->diffForHumans(short: true) ?? '—',
                        ];
                    @endphp

                    @foreach ($figures as $label => $value)
                        <div class="flex flex-col gap-1">
                            <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">{{ $label }}</span>
                            <span class="font-mono text-[13px] tabular">{{ $value }}</span>
                        </div>
                    @endforeach

                    {{-- On or off, in the summary strip, because it is the difference between "no
                         backups yet" and "no backups ever, and nothing is going to change that". A
                         screen that lists artifacts without saying whether more are coming answers
                         half the question somebody opened it with. --}}
                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Schedule</span>
                        <span class="flex items-center gap-2">
                            @if ($site->hasBackupSchedule())
                                <x-status-badge tone="ok" label="On" />
                                <span class="text-[12.5px] text-text-2">
                                    {{ $site->backup_schedule === 'daily' ? 'Daily' : 'Weekly' }},
                                    {{ sprintf('%02d:00', $site->backup_schedule_hour) }}
                                </span>
                            @else
                                <x-status-badge tone="grey" label="Off" />
                                <span class="text-[12.5px] text-text-2">Only when asked</span>
                            @endif
                        </span>
                    </div>

                    @if ($membership->canAdminister())
                        {{-- Disabled with the reason beside it, rather than enabled and disappointing.
                             The blocking conditions are the ones the job service and the connector
                             apply anyway; the difference is that they used to apply minutes later,
                             after this screen had already said "Backup requested". --}}
                        <form method="POST" action="{{ route('backups.store', $site) }}" class="ml-auto">
                            @csrf
                            <button type="submit"
                                    @disabled(! $readiness['ready'])
                                    class="h-[34px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text hover:bg-row-hover disabled:cursor-not-allowed disabled:border-border disabled:text-text-3 disabled:hover:bg-surface">
                                Back up now
                            </button>
                        </form>
                    @endif
                </div>

                @if (! $readiness['ready'] || $readiness['warnings'] !== [])
                    <div class="flex flex-col gap-1.5 border-b border-border px-4 py-3 text-[12.5px] leading-relaxed">
                        @foreach ($readiness['blockers'] as $blocker)
                            <p class="text-text">
                                <span class="font-medium">No backup can be taken.</span> {{ $blocker }}
                            </p>
                        @endforeach

                        @foreach ($readiness['warnings'] as $warning)
                            <p class="text-text-2">{{ $warning }}</p>
                        @endforeach

                        @if ($readiness['needsRecoveryKey'])
                            <p class="text-text-2">
                                A backup is encrypted to keys you hold and to nothing else, so until this
                                organisation has one active recovery key there is nothing to encrypt it to.
                                <a href="{{ route('settings.show') }}#recovery-keys" class="text-primary hover:text-primary-hover">
                                    Add one in Settings
                                </a>.
                            </p>
                        @endif
                    </div>
                @endif

                {{--
                    The schedule, on the screen its effect is visible on.

                    It used to be on this site's Settings form, sharing a Save button with the site's
                    name and its expected domain — so the answer to "why has this site not been
                    backed up" lived on a different screen from the evidence that it had not. The
                    fields are unchanged; only where they are has moved.
                --}}
                @if ($membership->canAdminister())
                    <div class="border-b border-border">
                        <form method="POST" action="{{ route('sites.backups.schedule', $site) }}"
                              class="flex flex-col gap-3 px-4 py-3.5"
                              data-backup-schedule>
                            @csrf

                            @php
                                // The saved value decides what is on screen at load, so a browser with
                                // no JavaScript gets the right controls rather than all of them.
                                // schedule.js maintains the same two attributes afterwards.
                                $schedule = old('backup_schedule', $site->backup_schedule);
                            @endphp

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <label class="flex flex-1 flex-col gap-1">
                                    <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Back up this site</span>
                                    <select name="backup_schedule"
                                            data-backup-schedule-frequency
                                            class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                                        @foreach (['off' => 'Only when asked', 'daily' => 'Every day', 'weekly' => 'Every week'] as $value => $label)
                                            <option value="{{ $value }}" @selected($schedule === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="flex flex-col gap-1 sm:w-[140px]"
                                       data-backup-schedule-field="hour"@if ($schedule === 'off') hidden @endif>
                                    <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">At</span>
                                    <select name="backup_schedule_hour"
                                            class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                                        @for ($hour = 0; $hour < 24; $hour++)
                                            <option value="{{ $hour }}" @selected((int) old('backup_schedule_hour', $site->backup_schedule_hour) === $hour)>
                                                {{ sprintf('%02d:00', $hour) }}
                                            </option>
                                        @endfor
                                    </select>
                                </label>

                                {{-- Shown only for a weekly schedule, because that is the only one the
                                     scheduler reads it for. It used to be on screen for all three,
                                     saved, audited as a change, and then ignored — a control that
                                     does nothing is worse than no control, because somebody sets it
                                     and believes it. --}}
                                <label class="flex flex-col gap-1 sm:w-[140px]"
                                       data-backup-schedule-field="day"@if ($schedule !== 'weekly') hidden @endif>
                                    <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">On</span>
                                    <select name="backup_schedule_day"
                                            class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                                        @foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $index => $day)
                                            <option value="{{ $index + 1 }}" @selected((int) old('backup_schedule_day', $site->backup_schedule_day) === $index + 1)>{{ $day }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <button type="submit"
                                        class="h-[34px] rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text hover:bg-row-hover">
                                    Save schedule
                                </button>
                            </div>

                            {{-- The current state as a sentence, including the zone, because an hour
                                 without one is a number somebody has to guess at — and the guess is
                                 usually their own zone rather than the organisation's, which is the
                                 one the scheduler reads. --}}
                            <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                                {{ $site->backupScheduleSentence() }}
                                <span data-backup-schedule-note=""@if ($schedule === 'off') hidden @endif>
                                    A scheduled backup is refused rather than attempted if this
                                    organisation has no recovery key to encrypt it to.
                                </span>
                            </p>
                        </form>
                    </div>
                @elseif ($site->hasBackupSchedule())
                    {{-- A member cannot change it, but "when is this backed up" is not privileged
                         information and leaving it blank reads as "never". --}}
                    <p class="border-b border-border px-4 py-3 text-[12.5px] text-text-2">
                        {{ $site->backupScheduleSentence() }}
                    </p>
                @endif

                @if ($inFlight->isNotEmpty())
                    <div data-backup-progress-list
                         data-backup-status-url="{{ route('sites.backups.status', $site) }}">
                        @foreach ($inFlight as $backup)
                            <x-backup-progress :backup="$backup" :window="$checkInWindow" />
                        @endforeach
                    </div>
                @endif

                @if ($failedJobs->isNotEmpty())
                    <div class="border-t border-border">
                        @foreach ($failedJobs as $failure)
                            <x-backup-failure :failure="$failure" :can-dismiss="$membership->canAdminister()" />
                        @endforeach

                        @if ($membership->canAdminister() && $failedJobs->count() > 1)
                            {{-- Only worth its own row when there is more than one to clear;
                                 otherwise the Dismiss beside the single notice already is it. --}}
                            <form method="POST" action="{{ route('backups.failures.clear') }}"
                                  class="border-t border-border px-4 py-2.5">
                                @csrf
                                <input type="hidden" name="site" value="{{ $site->external_id }}">
                                <button type="submit" class="text-[12px] text-text-3 hover:text-text">
                                    Clear these notices
                                </button>
                            </form>
                        @endif
                    </div>
                @endif

                @if (count($trend) > 1)
                    {{-- The shape is the point. A database growing steadily reads differently from one
                         that halved overnight because a table was dropped, and a table of sizes makes
                         the reader find that out by subtraction. --}}
                    <div class="px-4 py-4">
                        <x-chart kind="backupSize"
                                 :points="$trend"
                                 :height="170"
                                 label="Uncompressed size of each stored backup, oldest first"
                                 :summary="'Backup size over time for '.$site->name" />
                    </div>
                @endif
            </div>

            @if ($artifacts->isEmpty())
                <div class="rounded-[10px] border border-border bg-surface p-8 text-center">
                    <p class="mb-1.5 text-[14px] font-medium">Granted, but nothing has arrived yet</p>
                    <p class="mx-auto max-w-[560px] text-[13px] text-text-2">
                        Manager cannot reach into the site to take one — it queues the job and the
                        connector collects it on its next check-in.
                        @if ($membership->canAdminister())
                            Press <strong>Back up now</strong> above, and the first artifact appears once
                            the site has run it and uploaded it.
                        @endif
                    </p>
                </div>
            @else
                <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                    <div class="overflow-x-auto">
                        <table class="table-sticky w-full min-w-[820px] text-[13px]">
                            <thead>
                                <tr class="bg-surface-2">
                                    @foreach (['Taken', 'Size', 'Engine', 'Verified', 'Checksum', 'Deleted', ''] as $heading)
                                        <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                            {{ $heading }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($artifacts as $artifact)
                                    <tr class="border-b border-border last:border-b-0 hover:bg-row-hover">
                                        <td class="whitespace-nowrap py-2.5 pl-3.5 pr-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span><x-timestamp :at="$artifact->taken_at" format="j M Y, H:i" /></span>
                                                @if ($artifact->isPending())
                                                    <x-status-badge tone="warn" label="Uploading" />
                                                @elseif ($artifact->state === App\Models\BackupArtifact::STATE_FAILED)
                                                    <x-status-badge tone="bad" label="Failed" />
                                                @endif
                                            </div>
                                            @if ($artifact->failure_reason)
                                                <span class="text-[11.5px] text-danger">{{ $artifact->failure_reason }}</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[12px] tabular">
                                            {{ $artifact->humanSize() }}
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2.5 text-[12.5px] text-text-2">
                                            {{ trim($artifact->engine.' '.$artifact->engine_version) ?: '—' }}
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2.5">
                                            @if ($artifact->verified_at)
                                                {{-- Verified means the bytes that arrived hashed to what
                                                     the site said it sent. Worth its own column: an
                                                     unverified artifact is a file, not a backup. --}}
                                                <x-status-badge tone="ok" label="Checksum matched" />
                                            @else
                                                <span class="text-[12.5px] text-text-3">—</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[11.5px] text-text-3">
                                            {{ $artifact->shortChecksum() }}
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2.5 text-[12.5px] text-text-2">
                                            {{ $artifact->expires_at?->diffForHumans(short: true) ?? '—' }}
                                        </td>

                                        <td class="px-3 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-3">
                                                @if ($artifact->isRetrievable() && $membership->canAdminister())
                                                    <a href="{{ route('backups.download', $artifact) }}"
                                                       class="text-[12.5px] text-text-2 hover:text-primary">Download</a>
                                                @endif
                                                @if ($artifact->isRetrievable() && $membership->isOwner())
                                                    <form method="POST" action="{{ route('backups.destroy', $artifact) }}"
                                                          onsubmit="return confirm('Delete this backup? Its encryption key is destroyed with it and it cannot be recovered.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <input type="hidden" name="reason" value="Deleted by hand">
                                                        <button type="submit" class="text-[12.5px] text-text-2 hover:text-danger">Delete</button>
                                                    </form>
                                                @elseif ($artifact->neverStored() && $membership->isOwner())
                                                    {{-- A different word and a different sentence, because it is a
                                                         different act. There is no key to destroy and no copy to
                                                         lose: this row is the record of a backup that never
                                                         happened, and without this it could never be removed. --}}
                                                    <form method="POST" action="{{ route('backups.destroy', $artifact) }}"
                                                          onsubmit="return confirm('Remove this row? Nothing was stored for it, and the activity log keeps the record.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-[12.5px] text-text-2 hover:text-danger">Remove</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                        Each artifact is encrypted on the site with its own key before it is uploaded,
                        and stored to <span class="font-mono">{{ $storage }}</span>. This platform holds
                        the key that opens them, so it is not end-to-end encryption and is not described
                        as such — treat the backup store as being as sensitive as the site itself.
                    </div>
                </div>

                {{-- Download is ciphertext. Decryption is still a command and still off the web
                     request — that distinction is the whole design, and it is why a download button
                     can exist here when a plaintext one still should not. --}}
                <div class="mt-3 rounded-[10px] border border-border bg-surface p-4">
                    <p class="mb-1.5 text-[13px] font-medium">Retrieving one</p>
                    <p class="mb-2.5 max-w-[80ch] text-[12.5px] text-text-2">
                        <strong>Download</strong> hands over the artifact exactly as it is stored, still
                        encrypted. Decrypt it on your own machine, where the recovery key is — nothing is
                        decrypted through the browser, because on a database of any size that would hold a
                        worker against a timeout and could leave a half-written file that looks complete.
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">manager-restore decrypt --key=your-key.secret --out=backup.sql {{ $latest?->external_id ?? '<identifier>' }}.artifact</code></pre>
                    @if (app(App\Contracts\ServerAccess::class)->reachable())
                        <p class="mt-2.5 mb-1.5 max-w-[80ch] text-[12.5px] text-text-2">
                            A backup taken before any recovery key was enrolled is encrypted to a key this
                            platform can unwrap instead. For one of those, run this on the server:
                        </p>
                        <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">php artisan manager:backups:fetch {{ $latest?->external_id ?? '<identifier>' }} ./backup.sql</code></pre>
                    @else
                        <p class="mt-2.5 mb-1.5 max-w-[80ch] text-[12.5px] text-text-2">
                            A backup taken before any recovery key was enrolled is encrypted to a key this
                            platform can unwrap instead, and there is no key of yours that opens one — ask
                            us and we will produce it. Every backup taken since is sealed to your keys
                            alone, which is why it is the last thing we can do this for.
                        </p>
                    @endif
                    <p class="mt-2.5 text-[12px] text-text-3">
                        Restoring is not automated. It needs a confirmation flow and a tested recovery
                        path of its own, and until those exist a restore button would be a way of
                        pretending otherwise.
                    </p>
                </div>
            @endif
        @endif
    </div>
@endsection
