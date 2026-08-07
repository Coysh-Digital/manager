@extends('layouts.app')

@section('title', 'Backups · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Backups'))

@section('content')
    <div class="mx-auto max-w-[1100px]">
        <div class="mb-4 flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Backups</h1>
            <p class="text-[13px] text-text-2">
                Encrypted on the site before they are uploaded, and stored to
                <span class="font-mono text-[12px]">{{ $storage }}</span>.
            </p>
        </div>

        @php $anyArtifacts = array_sum($summary['counts']) > 0; @endphp

        {{--
            What this organisation is holding, and the way into each part of it.

            The tiles are the filter. Three of them are counts that set `state` in the query string
            and one is a total that filters nothing, and they are drawn the same because they are the
            same kind of fact - the difference is whether there is anywhere to go.

            Findings deliberately does not have a strip like this, and the comment there gives the
            reason: four integers, three of them usually zero, is a band of chrome carrying nothing.
            The difference here is that these are not usually zero and each one is a destination. A
            fleet with two failed backups wants exactly one click to see which two.

            The numbers are the organisation's, not the page's, so they do not move when the table is
            filtered. A tile reading "Stored: 0" beside a table you had just filtered to failures
            would be answering a question nobody asked.
        --}}
        @if ($anyArtifacts)
            <div class="mb-3.5 grid grid-cols-2 gap-px overflow-hidden rounded-[10px] border border-border bg-border sm:grid-cols-4">
                @foreach ($stateLabels as $state => $label)
                    @php
                        $active = $filters['state'] === $state;
                        $count = $summary['counts'][$state] ?? 0;
                    @endphp

                    <a href="{{ route('backups.index', array_filter([...$filters, 'state' => $active ? null : $state])) }}"
                       @class([
                           'flex flex-col gap-1 px-4 py-3 no-underline',
                           'bg-pale' => $active,
                           'bg-surface hover:bg-row-hover' => ! $active,
                       ])
                       @if ($active) aria-current="true" @endif>
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] {{ $active ? 'text-primary' : 'text-text-3' }}">{{ $label }}</span>
                        <span class="font-mono text-[15px] tabular {{ $active ? 'text-primary' : ($count > 0 && $state === 'failed' ? 'text-danger' : 'text-text') }}">{{ $count }}</span>
                    </a>
                @endforeach

                {{-- No link on this one. There is no such thing as filtering to bytes, and a tile
                     that looked clickable and was not would be worse than one that plainly is not. --}}
                <div class="flex flex-col gap-1 bg-surface px-4 py-3">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">In storage</span>
                    <span class="font-mono text-[15px] tabular text-text">{{ number_format($summary['storedBytes'] / 1048576, 1) }} MB</span>
                </div>
            </div>
        @endif

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-ok-line bg-ok-bg px-3.5 py-2.5 text-[12.5px] text-ok">
                {{ session('status') }}
            </div>
        @endif

        {{-- One organisation-wide cause, stated once rather than repeated down every row - and with
             somewhere to go, which the per-row text has no space for. --}}
        @if ($needsRecoveryKey)
            <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg px-3.5 py-3 text-[12.5px] leading-relaxed text-text">
                <p><span class="font-medium">No backups can be taken yet.</span>
                    A backup is encrypted to keys you hold and to nothing else, so until this organisation
                    has one active recovery key there is nothing to encrypt one to. You generate it on your
                    own machine - it never exists on this server.</p>
                <p class="mt-1.5">
                    <a href="{{ route('settings.recovery-keys') }}" class="text-primary hover:text-primary-hover">Add a recovery key in Settings</a>,
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

        {{--
            Said once, at the top, rather than implied by the word "encrypted".

            The second half of this used to read "but this platform can decrypt them, so this is not
            end-to-end encryption". True of the v1 format, and left standing when v2 replaced it - so
            the screen went on saying it above a table where every row names the recovery keys its
            artifact is sealed to.

            `BackupService` writes `wrapped_key => null` for a v2 artifact and its decrypt path
            refuses one with "This platform cannot decrypt that artifact", so the sentence was the
            opposite of what the code does. The specification's rule is that end-to-end encryption
            must not be *claimed* unless it is true; it does not ask for a disclaimer that is false in
            the other direction, and a security notice nobody can rely on teaches people to skip the
            notices.

            What has not changed is the first half. The sensitivity of a database dump does not depend
            on who can decrypt it: whoever reaches the storage still holds every customer's data in
            ciphertext, and the retention, deletion and audit rules exist for that.
        --}}
        <div class="mb-3.5 rounded-[10px] border border-border bg-surface-2 px-4 py-3 text-[12.5px] text-text-2">
            A backup is a complete copy of a site's database, including user accounts, password hashes
            and any personal information the site holds. Each one is encrypted on the site with its own
            key before it is uploaded, and that key is sealed to this organisation's recovery keys -
            which exist only where you put them - so an artifact opens with one of those and with
            nothing held here. Treat the backup store as being as sensitive as the sites themselves.
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
                    <x-backup-progress :backup="$backup" :window="$checkInWindow" show-site :can-cancel="$membership->canAdminister()" />
                @endforeach
            </div>
        @endif

        @if ($failedJobs->isNotEmpty())
            <div class="mb-3.5 overflow-hidden rounded-[10px] border border-amber-line bg-surface shadow-[var(--shadow)]">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-border px-4 py-3">
                    <span class="text-[13.5px] font-medium">Did not complete</span>

                    <span class="flex items-baseline gap-3 text-[12px] text-text-2">
                        Asked for in the last 7 days, and nothing was stored

                        @if ($membership->canAdminister())
                            {{-- Clearing hides these; it does not delete the jobs or the audit
                                 entries behind them. Said here rather than in a dialog, because it
                                 is the thing somebody wants to know before pressing it. --}}
                            <form method="POST" action="{{ route('backups.failures.clear') }}">
                                @csrf
                                <button type="submit" class="text-[12px] text-text-3 hover:text-text">Clear all</button>
                            </form>
                        @endif
                    </span>
                </div>

                @foreach ($failedJobs as $failure)
                    <x-backup-failure :failure="$failure" show-site :can-dismiss="$membership->canAdminister()" />
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

        {{-- Guarded on the organisation having any artifacts at all, not on this page having rows.
             Filtering to something with no matches has to leave the filter bar on screen, or the
             only way back is the browser's back button. --}}
        @if ($anyArtifacts)
            @php
                /*
                 | Owners get the column, matching the per-row Delete and Remove buttons and the
                 | guard in BackupController::destroyMany(). Administrators may ask for backups and
                 | download them; destroying one has always been a step above that.
                 */
                $bulk = $membership->isOwner();
                $restored = $bulk ? (array) old('artifacts', []) : [];
                $filtered = $filters['state'] !== '' || $filters['site'] !== '';
            @endphp

            <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]"
                 @if ($bulk) data-bulk-scope @endif>

                {{-- A GET form, so the result is a URL. The tiles above set `state` and this sets
                     `site`; both write the same query string, so the two compose rather than
                     overwriting each other. --}}
                <form method="GET" action="{{ route('backups.index') }}"
                      class="flex flex-wrap items-center gap-2.5 border-b border-border bg-surface-2 px-4 py-3">
                    <label for="filter-site" class="sr-only">Site</label>
                    <select id="filter-site" name="site"
                            class="h-8 rounded-[7px] border border-border-2 bg-surface px-2 text-[12.5px] text-text">
                        <option value="">Every site</option>
                        @foreach ($filterSites as $filterSite)
                            <option value="{{ $filterSite->external_id }}" @selected($filters['site'] === $filterSite->external_id)>
                                {{ $filterSite->name }}
                            </option>
                        @endforeach
                    </select>

                    <label for="filter-state" class="sr-only">Status</label>
                    <select id="filter-state" name="state"
                            class="h-8 rounded-[7px] border border-border-2 bg-surface px-2 text-[12.5px] text-text">
                        <option value="">Any status</option>
                        @foreach ($stateLabels as $state => $label)
                            <option value="{{ $state }}" @selected($filters['state'] === $state)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <button type="submit"
                            class="h-8 rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                        Apply
                    </button>

                    @if ($filtered)
                        <a href="{{ route('backups.index') }}" class="text-[12.5px] text-text-2 hover:text-primary">Clear</a>
                    @endif

                    <span class="ml-auto text-[12px] text-text-3">
                        Showing {{ $artifacts->count() }} of {{ $artifacts->total() }}
                    </span>
                </form>

                @if ($bulk)
                    {{--
                        The bulk form, empty, and it has to stay empty.

                        This table already carries a form per row for single deletion and forms
                        cannot nest, so the trick the fleet screen uses - wrap the table - is not
                        available here. Instead the form sits beside the table and the checkboxes
                        join it by id, with `form="bulk-delete-artifacts"`. That is ordinary HTML,
                        honoured with no JavaScript at all, and it leaves every per-row form and
                        every per-row confirm sentence exactly as it was.

                        It is also why the id is written out literally rather than interpolated: the
                        association is by name, so a renamed or duplicated id detaches the checkboxes
                        from the form silently, with the boxes still drawn and the button still
                        there. BulkBackupDeleteTest posts to the route and renders this screen for
                        that reason.

                        Before the table rather than inside it: a <form> parsed inside <table> is
                        foster-parented out by the HTML parser and would end up somewhere else.
                    --}}
                    <form id="bulk-delete-artifacts" method="POST" action="{{ route('backups.destroy-many') }}"
                          onsubmit="return confirm('Delete the selected backups? Any that were stored have their encryption keys destroyed with them and cannot be recovered.');">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif

                <div class="relative overflow-x-auto">
                    <table class="w-full border-collapse text-[13px]">
                        <thead class="sticky top-0 bg-surface-2">
                            <tr>
                                @if ($bulk)
                                    <th class="w-[38px] border-b border-border py-2.5 pl-4 pr-1 text-left">
                                        {{-- Unchecked and inert without JavaScript, where it would
                                             have nothing to toggle. The per-row boxes work
                                             regardless. --}}
                                        <input type="checkbox" data-bulk-all
                                               aria-label="Select every backup shown"
                                               class="align-middle accent-[var(--primary)]">
                                    </th>
                                @endif
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Site</th>
                                <th class="border-b border-border px-4 py-2.5 text-left font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Status</th>
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
                                    @if ($bulk)
                                        <td class="border-b border-border py-2.5 pl-4 pr-1 align-top">
                                            {{--
                                                Only on rows that have something to delete, which is
                                                the same condition the action cell uses to draw a
                                                button at all.

                                                This deliberately departs from the fleet screen,
                                                whose comment argues the opposite - that a checkbox
                                                belongs on every row, because disabling some lets a
                                                fleet look fully covered while half of it is quietly
                                                unselectable. The question is different here. A site
                                                that cannot be backed up today can be tomorrow, so
                                                its row is worth offering; a row with no bytes and no
                                                removable record has no operation to perform at all,
                                                and its own action cell three columns to the right is
                                                already empty. A checkbox beside a blank cell, whose
                                                only possible outcome is a line in the skipped
                                                sentence, contradicts the row it sits in.
                                            --}}
                                            @if ($artifact->isRetrievable() || $artifact->neverStored())
                                                {{-- The site name alone is not unique down this
                                                     table, so the label carries the age too. --}}
                                                <input type="checkbox" name="artifacts[]"
                                                       value="{{ $artifact->external_id }}"
                                                       form="bulk-delete-artifacts"
                                                       data-bulk-item
                                                       @checked(in_array($artifact->external_id, $restored, true))
                                                       aria-label="Select the backup {{ $artifact->site->name }} took {{ $artifact->taken_at->diffForHumans(short: true) }}"
                                                       class="align-middle accent-[var(--primary)]">
                                            @endif
                                        </td>
                                    @endif
                                    <td class="border-b border-border px-4 py-2.5">
                                        <a href="{{ route('sites.show', $artifact->site) }}" class="no-underline hover:underline">
                                            {{ $artifact->site->name }}
                                        </a>
                                        @if ($artifact->failure_reason)
                                            <span class="text-[11.5px] text-danger">{{ $artifact->failure_reason }}</span>
                                        @endif
                                        {{-- Which recovery key opens this one. An organisation can have
                                             several, and rotating them means older backups need older
                                             keys - so "which key do I need" is a real question with a
                                             recorded answer, and making somebody download the file and
                                             inspect it to find out would be perverse. --}}
                                        @if ($artifact->isZeroKnowledge() && $artifact->recipients->isNotEmpty())
                                            <span class="block text-[11.5px] text-text-3">
                                                Sealed to {{ $artifact->recipients->map(fn ($r) => $r->label ?? $r->fingerprint)->join(', ') }}
                                            </span>
                                        @endif
                                    </td>
                                    {{-- Its own column now, rather than a badge beside the site name.
                                         Status was the one fact on this table you had to read the
                                         row to find, which is the opposite of what a status is for -
                                         and the labels come from BackupController::LISTED_STATES, so
                                         the word here, the word in the filter and the word on the
                                         tile above are one string. --}}
                                    <td class="border-b border-border px-4 py-2.5 whitespace-nowrap">
                                        <x-status-badge :tone="match ($artifact->state) {
                                                            'stored' => 'ok',
                                                            'pending' => 'warn',
                                                            default => 'bad',
                                                        }"
                                                        :label="$stateLabels[$artifact->state] ?? Str::ucfirst($artifact->state)" />
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
                                        {{ $artifact->expires_at?->diffForHumans(short: true) ?? '-' }}
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
                                            @elseif ($artifact->neverStored() && $membership->isOwner())
                                                {{-- A different word and a different sentence, because it is a
                                                     different act. There is no key to destroy and no copy to lose:
                                                     this row is the record of a backup that never happened, and
                                                     without this it could never be removed. --}}
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

                {{-- The filter matched nothing. Said inside the card, under the bar that caused it,
                     rather than by the whole card vanishing - which is what an empty page looked
                     like before, and took the Clear link with it. --}}
                @if ($artifacts->isEmpty())
                    <div class="px-4 py-8 text-center">
                        <p class="text-[13px] text-text-2">No backup matches that filter.</p>
                        <a href="{{ route('backups.index') }}" class="text-[12.5px] text-primary hover:text-primary-hover">Show every backup</a>
                    </div>
                @endif

                @if ($bulk && $artifacts->isNotEmpty())
                    {{-- Rendered visible, and bulk.js hides it once there is a selection to hide it
                         against. The other way round - hidden in Blade, shown by script - would mean
                         no button at all with JavaScript switched off, which is exactly the case
                         this markup is arranged to keep working. --}}
                    <div data-bulk-bar
                         class="flex flex-wrap items-center gap-3 border-t border-border bg-surface-2 px-4 py-3">
                        <button type="submit" form="bulk-delete-artifacts"
                                class="h-8 rounded-[7px] border border-danger-line bg-danger-bg px-3 text-[12.5px] font-medium text-danger hover:border-danger">
                            Delete selected
                        </button>

                        <span class="text-[12.5px] text-text-2">
                            <span data-bulk-count class="font-medium text-text">0</span> selected
                        </span>

                        {{-- The dialog says the part that cannot be undone. This says the rest,
                             where there is room for it: that the two words on the rows above are two
                             different acts, and that the selection is allowed to hold both. --}}
                        <span class="basis-full text-[12px] text-text-3">
                            Stored backups are deleted and their keys destroyed with them. Rows for
                            backups that stored nothing are removed outright, and the activity log
                            keeps the record either way.
                        </span>
                    </div>
                @endif

                {{-- Last, under the bulk bar rather than above it. The bar acts on the rows in the
                     table; the pager leaves them. `withQueryString()` on the paginator is what keeps
                     the filter attached to page two - without it, paging silently clears it. --}}
                @if ($artifacts->hasPages())
                    <div class="border-t border-border bg-surface-2 px-4 py-2.5">{{ $artifacts->links() }}</div>
                @endif
            </div>

        @endif

        {{--
            What the schedules are about to do, and what they have been doing.

            Both halves matter and neither was on this screen. The panels above are scoped to what
            still needs somebody: work in flight, and undismissed failures from the last seven days.
            So a fleet whose schedule had quietly stopped firing looked exactly like a fleet with
            nothing to do - which is the failure this product exists to catch, showing up in the
            product itself.

            The upcoming times are projected by the same object the scheduler asks. That is the only
            reason it is honest to print them: a "next run" a screen derives on its own is a promise
            about somebody else's code.
        --}}
        @if ($upcomingRuns !== [] || $pastRuns->isNotEmpty())
            <div class="mt-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
                <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Scheduled runs</div>

                <div class="grid gap-px bg-border sm:grid-cols-2">
                    <div class="bg-surface px-4 py-3">
                        <p class="mb-2 font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Upcoming</p>

                        @if ($upcomingRuns === [])
                            <p class="text-[12.5px] text-text-2">
                                No site has a backup schedule. Set one on a site's Backups tab, and its
                                next few runs appear here.
                            </p>
                        @else
                            {{-- Queued shortly before they are due rather than at the instant, and
                                 said plainly - a list of times with no qualifier reads as a
                                 guarantee, and this is an hourly command deciding per site. --}}
                            <p class="mb-2 text-[12px] text-text-3">Requested on the hour they are due.</p>

                            <ul class="flex flex-col gap-1.5">
                                @foreach ($upcomingRuns as $run)
                                    <li class="flex flex-wrap items-baseline justify-between gap-2 text-[12.5px]">
                                        <a href="{{ route('sites.backups', $run['site']) }}" class="no-underline hover:underline">
                                            {{ $run['site']->name }}
                                        </a>
                                        <span class="font-mono text-[11.5px] tabular text-text-2">
                                            {{ $run['at']->format('j M, H:i') }}
                                            <span class="text-text-3">({{ $run['at']->diffForHumans(short: true) }})</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="bg-surface px-4 py-3">
                        <p class="mb-2 font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Past</p>

                        @if ($pastRuns->isEmpty())
                            <p class="text-[12.5px] text-text-2">No backup has finished yet.</p>
                        @else
                            <ul class="flex flex-col gap-1.5">
                                @foreach ($pastRuns as $run)
                                    <li class="flex flex-wrap items-baseline justify-between gap-2 text-[12.5px]">
                                        <span class="flex items-center gap-2">
                                            <a href="{{ route('sites.backups', $run->site) }}" class="no-underline hover:underline">
                                                {{ $run->site->name }}
                                            </a>
                                            {{-- Expired and cancelled sit with failed for the same
                                                 reason the failures panel puts them together: to
                                                 somebody waiting for a backup they are one event. --}}
                                            <x-status-badge :tone="$run->state === 'succeeded' ? 'ok' : 'bad'"
                                                            :label="Str::ucfirst($run->state)" />
                                        </span>
                                        <span class="font-mono text-[11.5px] tabular text-text-3">
                                            {{ $run->updated_at->diffForHumans(short: true) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if ($anyArtifacts)
            {{-- Download hands over ciphertext and nothing else. This panel used to say there was no
                 download button at all, and gave the timeout argument for it - which is an argument
                 about decrypting inside a web request, not about handing over the bytes as they are
                 already stored. The first is still refused here. The second was leaving customers
                 told to run a command against a file they had no way to obtain. --}}
            <div class="mt-3.5 rounded-[10px] border border-border bg-surface p-4">
                <p class="mb-1.5 text-[13px] font-medium">Retrieving a backup</p>
                <p class="mb-2.5 text-[12.5px] text-text-2">
                    <strong>Download</strong> gives you the artifact exactly as it is stored, still
                    encrypted. Decrypt it on your own machine with the recovery key named on the row -
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

                {{-- Whether a command is an instruction anybody here can follow. Self-hosted it is the
                     better one - it streams and verifies in a single pass, which no web request can
                     promise. On a hosted edition the shell belongs to whoever runs the service, and a
                     paragraph ending "run this on the server" reads as the answer while being
                     impossible to act on. --}}
                <p class="mt-3.5 mb-1.5 text-[12.5px] font-medium">Backups this platform holds a key for</p>

                @if (app(App\Contracts\ServerAccess::class)->reachable())
                    <p class="mb-2.5 text-[12.5px] text-text-2">
                        A backup taken before any recovery key was enrolled is encrypted to a key this
                        platform can unwrap, and needs no key of yours. Run this on the server - it streams,
                        decrypts and verifies against the checksum recorded when the backup was taken, in one
                        pass and with no timeout to lose.
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[12px]">php artisan manager:backups:fetch &lt;identifier&gt; ./backup.sql</code></pre>
                @else
                    <p class="mb-2.5 text-[12.5px] text-text-2">
                        A backup taken before any recovery key was enrolled is encrypted to a key this
                        platform can unwrap, and no key of yours will open one - ask us and we will produce
                        it. Every backup taken since a key was enrolled is sealed to your keys alone, so
                        this is the last set we are able to do it for.
                    </p>
                @endif

                <p class="mt-2.5 text-[12px] text-text-3">
                    Restoring is not automated. It needs a confirmation flow and a tested recovery path of
                    its own, and until those exist a restore button would be a way of pretending
                    otherwise.
                </p>
            </div>
        @endif
    </div>
@endsection
