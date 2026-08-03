@extends('layouts.app')

@section('title', 'Updates · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Updates'))

@use('App\Domain\Updates\ChangelogLink')
@use('App\Domain\Updates\PluginInventory')

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        @unless ($site->hasCapability('updates:read'))
            <div class="mb-5 rounded-[9px] border border-info-line bg-info-bg px-4 py-3.5">
                <p class="text-[13px] font-medium text-info">Update checking is not granted</p>
                <p class="mt-0.5 text-[12.5px] text-text-2">
                    Without <code class="font-mono">updates:read</code> the site never compares its
                    versions against published releases, so the list below shows what is installed and
                    nothing about what is available.
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-info hover:text-primary-hover">Grant it</a>
                    and the first check arrives within a day.
                </p>
            </div>
        @endunless

        <div class="mb-2.5 flex flex-wrap items-baseline justify-between gap-4">
            <h2 class="text-[13.5px] font-semibold">Craft and PHP</h2>

            <div class="flex items-center gap-3">
                @if ($updateReport)
                    <span class="font-mono text-[11.5px] text-text-3">
                        Checked {{ $updateReport->checked_at->diffForHumans(short: true) }}
                    </span>
                @endif

                @if ($canRequest && $site->hasCapability('updates:read'))
                    <form method="POST" action="{{ route('updates.refresh', $site) }}">
                        @csrf
                        <button type="submit"
                                class="h-[30px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                            {{ $updateReport ? 'Check again' : 'Request a check' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            @php
                $core = [
                    [
                        'name' => 'Craft CMS',
                        'current' => $updateReport?->craft_current ?? $site->craft_version ?? '—',
                        'latest' => $updateReport?->craft_latest,
                        'available' => (bool) $updateReport?->craft_update_available,
                        'security' => (bool) $updateReport?->craft_security_release,
                        'behind' => $updateReport?->value('craft.releases_behind'),
                        'breaking' => (bool) $updateReport?->value('craft.latest_is_breaking'),
                        'changelog' => ChangelogLink::craft(),
                    ],
                    [
                        'name' => 'PHP',
                        'current' => $updateReport?->value('php.current') ?? $site->php_version ?? '—',
                        'latest' => null,
                        'available' => false,
                        'security' => (bool) $updateReport?->value('php.end_of_life'),
                        'behind' => null,
                        'breaking' => false,
                        'note' => $updateReport?->value('php.security_support_until')
                            ? ($updateReport->value('php.end_of_life')
                                ? 'Security support ended '.$updateReport->value('php.security_support_until')
                                : 'Supported until '.$updateReport->value('php.security_support_until'))
                            : null,
                        'changelog' => ChangelogLink::php($updateReport?->value('php.current') ?? $site->php_version),
                    ],
                ];
            @endphp

            @foreach ($core as $row)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 last:border-b-0">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[13.5px] font-medium">{{ $row['name'] }}</span>
                        @if (($row['note'] ?? null) !== null)
                            <span class="text-[12px] {{ $row['security'] ? 'text-amber' : 'text-text-3' }}">{{ $row['note'] }}</span>
                        @elseif ($row['behind'])
                            <span class="text-[12px] text-text-3">{{ $row['behind'] }} {{ Str::plural('release', $row['behind']) }} behind</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="font-mono text-[12.5px] tabular {{ $row['security'] ? 'text-amber' : 'text-text-2' }}">
                            {{ $row['current'] }}@if ($row['available'] && $row['latest']) <span class="text-text-3">→</span> {{ $row['latest'] }}@endif
                        </span>

                        @if ($row['name'] === 'PHP')
                            @if ($row['security'])
                                <x-status-badge tone="warn" label="End of life" />
                            @endif
                        @elseif ($row['security'])
                            {{-- The one field that decides urgency. Which vulnerability it fixes is
                                 deliberately not transmitted. --}}
                            <x-status-badge tone="warn" label="Security release" />
                        @elseif ($row['available'])
                            <x-status-badge tone="info" label="Update available" />
                        @elseif ($updateReport)
                            <x-status-badge tone="ok" label="Up to date" />
                        @else
                            <x-status-badge tone="grey" label="Not checked" />
                        @endif

                        @if ($row['breaking'])
                            <span class="rounded-[5px] border border-border px-1.5 py-0.5 font-mono text-[11px] text-text-2">Major version</span>
                        @endif

                        <x-changelog-link :href="$row['changelog']" />
                    </div>

                    @if ($row['name'] === 'Craft CMS' && $canReadChangelog)
                        {{-- A <details>, like everything else on these screens that expands. The
                             fetch happens on first open — the same moment the link beside it would
                             have sent somebody to GitHub. --}}
                        <details class="w-full border-t border-border pt-3"
                                 data-changelog
                                 data-changelog-url="{{ route('sites.updates.changelog', $site) }}">
                            <summary class="cursor-pointer text-[12.5px] text-text-2 hover:text-primary">
                                What changed between these versions
                            </summary>

                            <div class="pt-2.5" data-changelog-body>
                                <p class="text-[12.5px] text-text-3">Reading the notes…</p>
                            </div>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>

        @php
            $needing = collect($plugins)->whereIn('state', [
                PluginInventory::SECURITY,
                PluginInventory::ABANDONED,
                PluginInventory::UPDATE,
            ])->count();
        @endphp

        <div class="mb-2.5 mt-6 flex flex-wrap items-baseline justify-between gap-4">
            <h2 class="text-[13.5px] font-semibold">Plugins</h2>
            <span class="text-[12.5px] text-text-2">
                {{ count($plugins) }} installed
                @if ($needing > 0)
                    · <span class="text-amber">{{ $needing }} needing attention</span>
                @endif
            </span>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            <div class="relative overflow-x-auto">
                <table class="table-sticky w-full min-w-[760px] text-[13px]">
                    <thead>
                        <tr class="bg-surface-2">
                            @foreach (['Plugin', 'Installed', 'Latest', 'Status'] as $heading)
                                <th class="whitespace-nowrap border-b border-border px-3 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                    {{ $heading }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plugins as $plugin)
                            @php
                                $badge = PluginInventory::badge($plugin['state']);

                                // Whether this plugin has anything to expand. Decided once for the
                                // whole table by PluginChangelog::handlesWithNotes(), so a hundred
                                // plugins is still one query.
                                $hasNotes = isset($pluginNotes[$plugin['handle']]);
                            @endphp

                            {{-- The rule goes under the notes row when there is one, so a plugin and
                                 its own notes read as one block rather than two. --}}
                            <tr class="{{ $hasNotes ? '' : 'border-b border-border last:border-b-0' }} hover:bg-row-hover">
                                <td class="py-2.5 pl-3.5 pr-3">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="text-[13px] {{ $plugin['enabled'] ? '' : 'text-text-2' }}">{{ $plugin['name'] }}</span>
                                        @if ($plugin['name'] !== $plugin['handle'])
                                            <span class="font-mono text-[11px] text-text-3">{{ $plugin['handle'] }}</span>
                                        @endif
                                    </div>
                                </td>

                                <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[12px] tabular text-text-2">
                                    {{ $plugin['current'] }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2.5 font-mono text-[12px] tabular">
                                    @if ($plugin['latest'] === null)
                                        <span class="text-text-3">—</span>
                                    @elseif ($plugin['latest'] === $plugin['current'])
                                        <span class="text-text-3">{{ $plugin['latest'] }}</span>
                                    @else
                                        <span class="{{ $plugin['state'] === PluginInventory::SECURITY ? 'text-amber' : 'text-text' }}">
                                            {{ $plugin['latest'] }}
                                        </span>
                                        @if ($plugin['releases_behind'])
                                            <span class="text-text-3">({{ $plugin['releases_behind'] }})</span>
                                        @endif
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-3 py-2.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-status-badge :tone="$badge['tone']" :label="$badge['label']" />

                                        {{-- Abandoned and out of date at once: the badge can only say
                                             one of them, and "will never be fixed" is the part that
                                             outlives the other. --}}
                                        @if ($plugin['abandoned'] && $plugin['state'] === PluginInventory::SECURITY)
                                            <x-status-badge tone="warn" label="Abandoned" />
                                        @endif

                                        @unless ($plugin['enabled'])
                                            <span class="rounded-[5px] border border-border px-1.5 py-0.5 font-mono text-[11px] text-text-3">Disabled</span>
                                        @endunless

                                        {{-- Only where there is something to read about. A plugin the
                                             store has never heard of gets no link rather than one that
                                             404s — a broken link teaches people not to trust the
                                             working ones. Kept even when the panel below exists: the
                                             store listing is the fuller record, and the panel only
                                             covers releases a site has actually reported. --}}
                                        @if ($plugin['latest'] !== null)
                                            <x-changelog-link :href="ChangelogLink::plugin($plugin['handle'])" label="Plugin Store" />
                                        @endif
                                    </div>
                                </td>
                            </tr>

                            @if ($hasNotes)
                                {{-- Its own row rather than a cell, so the notes get the full width
                                     of the table instead of the Status column. Same <details> and
                                     the same script as Craft's panel above; the fetch happens on
                                     first open, though for a plugin that fetch reaches no further
                                     than this database. --}}
                                <tr class="border-b border-border last:border-b-0">
                                    <td colspan="4" class="px-3.5 pb-2.5">
                                        <details data-changelog
                                                 data-changelog-url="{{ route('sites.updates.changelog.plugin', ['site' => $site, 'handle' => $plugin['handle']]) }}">
                                            <summary class="cursor-pointer text-[12.5px] text-text-2 hover:text-primary">
                                                What changed between these versions
                                            </summary>

                                            <div class="pt-2.5" data-changelog-body>
                                                <p class="text-[12.5px] text-text-3">Reading the notes…</p>
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-[13px] text-text-2">
                                    @if ($inventoryReport === null)
                                        This site has not reported yet, so there is no plugin list.
                                    @else
                                        The last report listed no plugins.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                Release notes are held against a plugin and a version, never against a site. What a
                version fixes is public — the Plugin Store hands it to anyone — but "this site is
                behind on these fixes" is not, so that pairing is made on this screen and stored
                nowhere. Craft's changelog above is read from Craft's own repository, once for this
                installation and cached; plugin notes are forwarded by the sites already running the
                plugin, so showing them asks nothing of anybody and tells no plugin author which of
                your sites are behind.
                <strong>Not checked</strong> means exactly that — a plugin the site's update service
                knows nothing about, usually a private or VCS-installed one. It is not the same as up
                to date.
            </div>
        </div>
    </div>
@endsection
