@extends('layouts.app')

@section('title', 'Backups · Manager')
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
                                <form method="POST" action="{{ route('backups.store', $site) }}">
                                    @csrf
                                    <button type="submit"
                                            class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                        Back up now
                                    </button>
                                </form>
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
            </div>

            {{-- No download button, and the reason is stated rather than left as a gap somebody wonders
                 about. A button that works until a database is big enough to matter is worse than a
                 command that always works. --}}
            <div class="mt-3.5 rounded-[10px] border border-border bg-surface p-4">
                <p class="mb-1.5 text-[13px] font-medium">Retrieving a backup</p>
                <p class="mb-2.5 text-[12.5px] text-text-2">
                    Run this on the server. It streams the artifact, decrypts it, and verifies it against
                    the checksum recorded when the backup was taken — a download through the browser
                    would hold a worker against a timeout and could leave a half-written file that looks
                    complete.
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
