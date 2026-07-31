@extends('layouts.app')

@section('title', 'Backups · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Backups'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

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
                                {{-- A week is not a policy, it is a prompt. Manager does not schedule
                                     backups, so an old one means nobody has asked lately. --}}
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

                    @if ($membership->canAdminister())
                        <form method="POST" action="{{ route('backups.store', $site) }}" class="ml-auto">
                            @csrf
                            <button type="submit"
                                    class="h-[34px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[13px] text-text hover:bg-row-hover">
                                Back up now
                            </button>
                        </form>
                    @endif
                </div>

                @if ($inFlight->isNotEmpty())
                    <div data-backup-progress-list
                         data-backup-status-url="{{ route('sites.backups.status', $site) }}">
                        @foreach ($inFlight as $backup)
                            <x-backup-progress :backup="$backup" :window="$checkInWindow" />
                        @endforeach
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
                                                <span>{{ $artifact->taken_at->format('j M Y, H:i') }}</span>
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
                                            @if ($artifact->isRetrievable() && $membership->isOwner())
                                                <form method="POST" action="{{ route('backups.destroy', $artifact) }}"
                                                      onsubmit="return confirm('Delete this backup? Its encryption key is destroyed with it and it cannot be recovered.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="reason" value="Deleted by hand">
                                                    <button type="submit" class="text-[12.5px] text-text-2 hover:text-danger">Delete</button>
                                                </form>
                                            @endif
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

                {{-- No download button, and the reason is stated rather than left as a gap somebody
                     wonders about. --}}
                <div class="mt-3 rounded-[10px] border border-border bg-surface p-4">
                    <p class="mb-1.5 text-[13px] font-medium">Retrieving one</p>
                    <p class="mb-2.5 max-w-[80ch] text-[12.5px] text-text-2">
                        Run this on the server. It streams the artifact, decrypts it and verifies it
                        against the checksum recorded when the backup was taken — a download through the
                        browser would hold a worker against a timeout and could leave a half-written file
                        that looks complete.
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">php artisan manager:backups:fetch {{ $latest?->external_id ?? '<identifier>' }} ./backup.sql</code></pre>
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
