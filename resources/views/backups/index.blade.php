@extends('layouts.app')

@section('title', 'Backups · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Backups'))

@section('content')
    <div class="mx-auto max-w-[1100px]">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-col gap-1.5">
                <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Backups</h1>
                <p class="text-[13px] text-text-2">
                    Encrypted on the site before they are uploaded, and stored to
                    <span class="font-mono text-[12px]">{{ $storage }}</span>.
                </p>
            </div>

            @if ($artifacts->isNotEmpty())
                <div class="flex gap-5 text-[13px]">
                    <div class="flex flex-col gap-0.5">
                        <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Stored</span>
                        <span class="tabular">{{ $artifacts->where('state', 'stored')->count() }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">In storage</span>
                        <span class="tabular">{{ number_format($storedBytes / 1048576, 1) }} MB</span>
                    </div>
                </div>
            @endif
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-ok-line bg-ok-bg px-3.5 py-2.5 text-[12.5px] text-ok">
                {{ session('status') }}
            </div>
        @endif

        {{-- One organisation-wide cause, stated once rather than repeated down every row — and with
             somewhere to go, which the per-row text has no space for. --}}
        @if ($needsRecoveryKey)
            <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg px-3.5 py-3 text-[12.5px] leading-relaxed text-text">
                <p><span class="font-medium">No backups can be taken yet.</span>
                    A backup is encrypted to keys you hold and to nothing else, so until this organisation
                    has one active recovery key there is nothing to encrypt one to. You generate it on your
                    own machine — it never exists on this server.</p>
                <p class="mt-1.5">
                    <a href="{{ route('settings.show') }}#recovery-keys" class="text-primary hover:text-primary-hover">Add a recovery key in Settings</a>,
                    or read
                    <a href="https://managerforcraft.com/docs/recovery-keys" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-primary-hover">how recovery keys work ↗</a>.
                </p>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Said once, at the top, rather than implied by the word "encrypted". The specification is
             explicit that end-to-end encryption must not be claimed unless it is true, and here it is
             not: this platform holds the key. --}}
        <div class="mb-3.5 rounded-[10px] border border-border bg-surface-2 px-4 py-3 text-[12.5px] text-text-2">
            A backup is a complete copy of a site's database, including user accounts, password hashes
            and any personal information the site holds. Each one is encrypted on the site with its own
            key before it is uploaded, which protects it in storage and in transit — but this platform
            can decrypt them, so this is not end-to-end encryption. Treat the backup store as being as
            sensitive as the sites themselves.
        </div>

        {{-- Requested and not yet arrived. Above the stored artifacts, because it is the thing
             somebody who just pressed the button came back to look for. --}}
        @if ($inFlight->isNotEmpty())
            <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]"
                 data-backup-progress-list
                 data-backup-status-url="{{ route('backups.status') }}">
                <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">
                    In progress
                </div>

                @foreach ($inFlight as $backup)
                    <x-backup-progress :backup="$backup" :window="$checkInWindow" show-site />
                @endforeach
            </div>
        @endif

        @if ($failedJobs->isNotEmpty())
            <div class="mb-3.5 overflow-hidden rounded-[10px] border border-amber-line bg-surface shadow-[var(--shadow)]">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-border px-4 py-3">
                    <span class="text-[13.5px] font-medium">Did not complete</span>
                    <span class="text-[12px] text-text-2">Asked for in the last 7 days, and nothing was stored</span>
                </div>

                @foreach ($failedJobs as $failure)
                    <x-backup-failure :failure="$failure" show-site />
                @endforeach
            </div>
        @endif

        @if ($permittedSites->isEmpty())
            <div class="rounded-[10px] border border-border bg-surface p-8 text-center shadow-[var(--shadow)]">
                <p class="mb-1.5 text-[14px] font-medium">No site has permission to back up</p>
                <p class="mx-auto max-w-[520px] text-[13px] text-text-2">
                    Backups are off until they are granted per site, from a site's Capabilities screen.
                    That is deliberate: this permission reads the entire database, so it is never granted
                    when a site is paired and never offered as a switch beside the read-only ones.
                </p>
            </div>
        @else
            <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Sites with permission</div>

                <div class="flex flex-col">
                    @foreach ($permittedSites as $site)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 last:border-b-0">
                            <div class="flex flex-col gap-0.5">
                                <a href="{{ route('sites.show', $site) }}" class="text-[13px] font-medium no-underline hover:underline">
                                    {{ $site->name }}
                                </a>
                                <span class="font-mono text-[11.5px] text-text-3">
                                    {{ $site->expected_domain }} ·
                                    {{ $artifacts->where('site_id', $site->id)->where('state', 'stored')->count() }} stored
                                </span>
                            </div>

                            @if ($membership->canAdminister())
                                @php $siteReadiness = $readiness[$site->id] ?? ['ready' => true, 'blockers' => [], 'warnings' => []]; @endphp

                                <div class="flex items-center gap-2.5">
                                    @unless ($siteReadiness['ready'])
                                        {{-- The reason, not just a dead button. Most often one
                                             organisation-wide cause repeated down the column, which
                                             is itself the useful observation. --}}
                                        <span class="text-[12px] text-text-3">{{ $siteReadiness['blockers'][0] }}</span>
                                    @else
                                        {{-- Warnings were computed and then dropped on this screen,
                                             so a fleet with one recovery key looked identical to a
                                             fleet with three. The single-site screen has always said
                                             it; the screen showing every site said nothing. --}}
                                        @foreach (array_slice($siteReadiness['warnings'] ?? [], 0, 1) as $warning)
                                            <span class="text-[12px] text-amber">{{ $warning }}</span>
                                        @endforeach
                                    @endunless

                                    <form method="POST" action="{{ route('backups.store', $site) }}">
                                        @csrf
                                        <button type="submit"
                                                @disabled(! $siteReadiness['ready'])
                                                class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover disabled:cursor-not-allowed disabled:border-border disabled:text-text-3 disabled:hover:bg-surface">
                                            Back up now
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($artifacts->isNotEmpty())
            <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead class="sticky top-0 bg-surface-2">
                            <tr>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Site</th>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Taken</th>
                                <th class="border-b border-border px-4 py-2.5 text-right font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Size</th>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Engine</th>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Checksum</th>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Deleted</th>
                                <th class="border-b border-border px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($artifacts as $artifact)
                                <tr class="hover:bg-row-hover">
                                    <td class="border-b border-border px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('sites.show', $artifact->site) }}" class="no-underline hover:underline">
                                                {{ $artifact->site->name }}
                                            </a>
                                            @if ($artifact->state === 'pending')
                                                <x-status-badge tone="warn" label="Uploading" />
                                            @elseif ($artifact->state === 'failed')
                                                <x-status-badge tone="bad" label="Failed" />
                                            @endif
                                        </div>
                                        @if ($artifact->failure_reason)
                                            <span class="text-[11.5px] text-danger">{{ $artifact->failure_reason }}</span>
                                        @endif
                                        {{-- Which recovery key opens this one. An organisation can have
                                             several, and rotating them means older backups need older
                                             keys — so "which key do I need" is a real question with a
                                             recorded answer, and making somebody download the file and
                                             inspect it to find out would be perverse. --}}
                                        @if ($artifact->isZeroKnowledge() && $artifact->recipients->isNotEmpty())
                                            <span class="block text-[11.5px] text-text-3">
                                                Sealed to {{ $artifact->recipients->map(fn ($r) => $r->label ?? $r->fingerprint)->join(', ') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 whitespace-nowrap">
                                        {{ $artifact->taken_at->diffForHumans(short: true) }}
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 text-right tabular whitespace-nowrap">
                                        {{ $artifact->humanSize() }}
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 whitespace-nowrap text-text-2">
                                        {{ $artifact->engine }} {{ $artifact->engine_version }}
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 font-mono text-[11.5px] text-text-3">
                                        {{ $artifact->shortChecksum() }}
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 whitespace-nowrap text-text-2">
                                        {{ $artifact->expires_at?->diffForHumans(short: true) ?? '—' }}
                                    </td>
                                    <td class="border-b border-border px-4 py-2.5 text-right">
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
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Download hands over ciphertext and nothing else. This panel used to say there was no
                 download button at all, and gave the timeout argument for it — which is an argument
                 about decrypting inside a web request, not about handing over the bytes as they are
                 already stored. The first is still refused here. The second was leaving customers
                 told to run a command against a file they had no way to obtain. --}}
            <div class="mt-3.5 rounded-[10px] border border-border bg-surface p-4">
                <p class="mb-1.5 text-[13px] font-medium">Retrieving a backup</p>
                <p class="mb-2.5 text-[12.5px] text-text-2">
                    <strong>Download</strong> gives you the artifact exactly as it is stored, still
                    encrypted. Decrypt it on your own machine with the recovery key named on the row —
                    the secret half never comes here, which is the entire point of it. Nothing is
                    decrypted through the browser: on a database of any size that would hold a worker
                    against a timeout and could leave a half-written file that looks complete.
                </p>
                <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">manager-restore decrypt --key=your-key.secret --out=backup.sql &lt;identifier&gt;.artifact</code></pre>
                <p class="mt-2.5 text-[12px] text-text-3">
                    Check the file before waiting on it: <code class="font-mono">manager-restore inspect &lt;identifier&gt;.artifact</code>
                    needs no key and prints the size and checksum listed above.
                    <a href="https://managerforcraft.com/docs/recovery-keys" target="_blank" rel="noopener noreferrer" class="text-primary hover:text-primary-hover">How recovery keys work ↗</a>
                </p>

                <p class="mt-3.5 mb-1.5 text-[12.5px] font-medium">Backups this platform holds a key for</p>
                <p class="mb-2.5 text-[12.5px] text-text-2">
                    A backup taken before any recovery key was enrolled is encrypted to a key this
                    platform can unwrap, and needs no key of yours. Run this on the server — it streams,
                    decrypts and verifies against the checksum recorded when the backup was taken, in one
                    pass and with no timeout to lose.
                </p>
                <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">php artisan manager:backups:fetch &lt;identifier&gt; ./backup.sql</code></pre>

                <p class="mt-2.5 text-[12px] text-text-3">
                    Restoring is not automated. It needs a confirmation flow and a tested recovery path of
                    its own, and until those exist a restore button would be a way of pretending
                    otherwise.
                </p>
            </div>
        @endif
    </div>
@endsection
