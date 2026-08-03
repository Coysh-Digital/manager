@extends('layouts.app')

@section('title', 'Health · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Health'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-4">
            <h2 class="text-[13.5px] font-semibold">Check-in record</h2>

            {{-- In the query string, not the session: a window somebody has chosen should survive a
                 reload and be linkable, which is how these screens actually get used. --}}
            <div class="flex items-center gap-1" role="group" aria-label="Period">
                @foreach ($windows as $option)
                    <a href="{{ route('sites.health', ['site' => $site, 'window' => $option]) }}"
                       @if ($option === $window) aria-current="true" @endif
                       class="rounded-[6px] border px-2.5 py-1 font-mono text-[11.5px] no-underline
                              {{ $option === $window
                                  ? 'border-border-2 bg-surface-2 text-text'
                                  : 'border-transparent text-text-2 hover:bg-row-hover hover:text-text' }}">
                        {{ $option }}
                    </a>
                @endforeach
            </div>
        </div>

        @if (! $uptime->hasEvidence())
            {{-- One heartbeat is not a record. "100%" off a single check-in would be a claim the
                 data cannot support. --}}
            <div class="rounded-[10px] border border-border bg-surface px-4 py-8 text-center">
                <p class="text-[13px] text-text-2">
                    @if ($site->last_seen_at === null)
                        This site has never checked in, so there is nothing to measure yet.
                        Pair it under <a href="{{ route('sites.settings', $site) }}#connector" class="text-primary hover:text-primary-hover">Settings</a>.
                    @else
                        Not enough check-ins in this period to say anything useful.
                        Last heard from {{ $site->last_seen_at->diffForHumans() }}.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                <div class="flex flex-wrap items-center gap-x-10 gap-y-4 border-b border-border px-4 py-3.5">
                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Reporting</span>
                        <div class="flex items-center gap-2.5">
                            <span class="text-[20px] font-semibold tabular tracking-[-0.01em]">{{ $uptime->availabilityLabel() }}</span>
                            @if ($uptime->isCurrentlySilent())
                                <x-status-badge tone="bad" label="Silent now" />
                            @endif
                        </div>
                    </div>

                    @php
                        $figures = [
                            'Check-ins' => [
                                $uptime->received.' of ~'.$uptime->expected,
                                'Approximate because the period is measured from whichever is later, the start of the window or the site being added — and because a site may report from more than one trigger.',
                            ],
                            'Gaps' => [(string) count($uptime->outages), null],
                            'Longest gap' => [$uptime->longest?->duration() ?? 'None', null],
                            'Last check-in' => [$uptime->lastSeenAt?->diffForHumans(short: true) ?? 'never', null],
                        ];
                    @endphp

                    @foreach ($figures as $label => [$value, $explanation])
                        <div class="flex flex-col gap-1">
                            <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3"
                                  @if ($explanation) title="{{ $explanation }}" @endif>{{ $label }}</span>
                            <span class="font-mono text-[13px] tabular">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @if ($uptime->reportsMoreOftenThanExpected())
                    {{-- More check-ins than intervals is the normal result of running cron *and*
                         leaving the connector's web trigger on: the two throttle separately and
                         neither knows about the other. Without this line the figure reads as a
                         broken counter. --}}
                    <p class="border-b border-border px-4 py-2.5 text-[12.5px] text-text-2">
                        This site checks in more often than the {{ $uptime->intervalMinutes() }}-minute schedule asks for,
                        which usually means cron and the connector's web trigger are both reporting. Not a fault —
                        reporting above is measured from time covered, not from check-ins counted, so the extra ones
                        change nothing.
                    </p>
                @endif

                <div class="px-4 py-4">
                    @php
                        $points = collect($uptime->buckets)->map(fn (array $bucket): array => [
                            'label' => $bucket['label'],
                            'value' => round($bucket['percentage'], 1),
                            'received' => $bucket['received'],
                            'expected' => $bucket['expected'],
                            'text' => $bucket['received'].' of '.$bucket['expected'].' check-ins',
                        ])->all();
                    @endphp

                    <x-chart kind="uptime"
                             :points="$points"
                             :height="190"
                             label="Check-ins received per period, as a percentage of those expected"
                             :summary="'Check-in record for '.$site->name.' over the last '.$window" />
                </div>
            </div>

            @if ($uptime->outages !== [])
                <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Gaps in reporting</h2>

                <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                    @foreach (array_reverse($uptime->outages) as $outage)
                        <div class="grid grid-cols-[1fr_auto_auto] items-center gap-4 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                            <span>
                                <x-timestamp :at="$outage->from" format="j M, H:i" />
                                <span class="text-text-3">→</span>
                                @if ($outage->isOngoing)still silent@else<x-timestamp :at="$outage->to" format="H:i" />@endif
                            </span>
                            <span class="font-mono tabular text-text-2">{{ $outage->duration() }}</span>
                            @if ($outage->isOngoing)
                                <x-status-badge tone="bad" label="Ongoing" />
                            @else
                                <span class="text-[12px] text-text-3">Recovered</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        {{--
            The caveat, on the screen rather than in the documentation.

            "Uptime" is the word everybody reaches for and it is not quite what this is. Saying so
            here costs four lines; not saying so costs somebody a wrong conclusion during an
            incident.
        --}}
        <p class="mt-3 rounded-[9px] border border-border bg-surface-2 px-4 py-3 text-[12px] leading-relaxed text-text-2">
            This measures whether the <strong>connector</strong> checked in, not whether the website
            answered a visitor — Manager never calls out to a site, which is why a connector works
            from behind NAT with no inbound port open. The two usually agree, but not always: a site
            whose schedule is driven by ordinary traffic rather than cron reports only while it has
            visitors, so a quiet night shows as a gap, and a site whose web server is down but whose
            cron still runs will keep reporting. Gaps are counted after
            {{ (int) (config('manager.health.heartbeat_interval', 300) * config('manager.health.heartbeat_grace_multiplier', 3) / 60) }}
            minutes of silence.
        </p>

        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">How it is coping</h2>

        @if ($latestReport === null)
            <div class="rounded-[10px] border border-border bg-surface px-4 py-8 text-center text-[13px] text-text-2">
                Nothing reported yet, so there is no queue or migration state to show.
            </div>
        @else
            @php
                $operational = [
                    'Jobs waiting' => [$latestReport->value('queue.pending', '—'), false],
                    'Jobs reserved' => [$latestReport->value('queue.reserved', '—'), false],
                    'Jobs failed' => [$latestReport->value('queue.failed', 0), (int) $latestReport->value('queue.failed', 0) > 0],
                    'Migrations pending' => [$latestReport->value('migrations.pending', 0), (int) $latestReport->value('migrations.pending', 0) > 0],
                    'Migrations applied' => [$latestReport->value('migrations.applied', '—'), false],
                    'PHP' => [$updateReport?->value('php.current') ?? $site->php_version ?? '—', (bool) $updateReport?->value('php.end_of_life')],
                    'Connector' => [$connector?->connector_version ?? '—', false],
                    'Report collected' => [$latestReport->collected_at->diffForHumans(short: true), false],
                ];
            @endphp

            <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($operational as $label => [$value, $notable])
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-text-2">{{ $label }}</dt>
                        <dd class="tabular font-mono {{ $notable ? 'text-amber' : '' }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @unless ($site->hasCapability('system:read'))
                <p class="mt-2 text-[12px] text-text-3">
                    Queue and migration counts need <code class="font-mono">system:read</code>, which
                    this site has not been granted.
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
                    and they arrive on the next report.
                </p>
            @endunless
        @endif

        {{--
            Response time.

            Called what it is. "TTFB" is the phrase everybody reaches for and this is not that: the
            connector times its own site from inside the PHP process, so there is no DNS, no TLS, no
            queueing in front of PHP-FPM and no network to the visitor in these figures. A site with
            a two-second TTFB and a 40ms render looks fast here and is not.

            These sections sit outside the inventory conditional above on purpose: they come from the
            runtime report, which arrives separately and on its own capability. Nesting them under
            "has this site sent an inventory report" would hide disk usage from a site that had sent
            one and not the other.
        --}}
        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Server response time</h2>

        @if ($runtimeReport?->value('response') === null)
            <div class="rounded-[10px] border border-border bg-surface px-4 py-6 text-center text-[13px] text-text-2">
                @if (! $site->hasCapability('runtime:read'))
                    Timing needs <code class="font-mono">runtime:read</code>, which this site has not
                    been granted.
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
                    and figures arrive once the site has served enough traffic to measure.
                @else
                    Granted, but not enough traffic sampled yet. The connector needs twenty requests
                    before it will report a figure — a mean of three would move violently for reasons
                    nobody can act on.
                @endif
            </div>
        @else
            @php
                $timings = [
                    'Median' => [$runtimeReport->value('response.p50_ms'), false],
                    'Mean' => [$runtimeReport->value('response.mean_ms'), false],
                    '95th percentile' => [
                        $runtimeReport->value('response.p95_ms'),
                        (float) $runtimeReport->value('response.p95_ms', 0) > 1000,
                    ],
                    'Slowest' => [$runtimeReport->value('response.max_ms'), false],
                ];
            @endphp

            <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                <dl class="grid grid-cols-2 gap-x-10 gap-y-2.5 px-4 py-3.5 text-[12.5px] sm:grid-cols-4">
                    @foreach ($timings as $label => [$value, $notable])
                        <div class="flex flex-col gap-1">
                            <dt class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">{{ $label }}</dt>
                            <dd class="font-mono text-[14px] tabular {{ $notable ? 'text-amber' : '' }}">
                                {{ $value === null ? '—' : number_format((float) $value, 0).' ms' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                    Measured on the site, across
                    {{ $runtimeReport->value('response.samples') }} requests it was serving anyway.
                    This is how long PHP took to build a response — <strong>not</strong> time to
                    first byte: it excludes DNS, TLS, any queueing in front of PHP and the network
                    to the visitor. Manager never calls out to a site, so there is nobody outside
                    holding a stopwatch. Nothing about who made those requests is recorded.
                </p>
            </div>
        @endif

        {{-- Storage --}}
        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Storage</h2>

        @if ($runtimeReport?->value('storage') === null)
            <div class="rounded-[10px] border border-border bg-surface px-4 py-6 text-center text-[13px] text-text-2">
                @if (! $site->hasCapability('runtime:read'))
                    Disk usage needs <code class="font-mono">runtime:read</code>.
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
                    and the first measurement arrives within six hours.
                @else
                    Granted, but nothing measured yet.
                @endif
            </div>
        @else
            @php $usedPercent = $runtimeReport->diskUsedPercent(); @endphp

            <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                <div class="flex flex-wrap items-center gap-x-10 gap-y-4 border-b border-border px-4 py-3.5">
                    @php
                        $diskFigures = [
                            'Craft holds' => [
                                number_format(($runtimeReport->storage_bytes ?? 0) / 1073741824, 2).' GB',
                                false,
                            ],
                            'Disk free' => [
                                $runtimeReport->disk_free_bytes === null
                                    ? '—'
                                    : number_format($runtimeReport->disk_free_bytes / 1073741824, 1).' GB',
                                $usedPercent !== null && $usedPercent >= 90,
                            ],
                            'Disk used' => [
                                $usedPercent === null ? '—' : $usedPercent.'%',
                                $usedPercent !== null && $usedPercent >= 90,
                            ],
                            'Measured' => [$runtimeReport->collected_at->diffForHumans(short: true), false],
                        ];
                    @endphp

                    @foreach ($diskFigures as $label => [$value, $notable])
                        <div class="flex flex-col gap-1">
                            <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">{{ $label }}</span>
                            <span class="font-mono text-[13px] tabular {{ $notable ? 'font-medium text-amber' : '' }}">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @if ($runtimeReport->volumes() !== [])
                    @php $showsLocation = $runtimeReport->reportsVolumeLocation(); @endphp

                    <div class="relative overflow-x-auto">
                        <table class="w-full min-w-[480px] text-[13px]">
                            <thead>
                                <tr class="bg-surface-2">
                                    {{-- Where drawn only when something can fill it. An older
                                         connector sends system.v1, which cannot say, and a column of
                                         em-dashes teaches people to ignore a column. --}}
                                    @foreach ($showsLocation ? ['Volume', 'Where', 'Size', 'Files'] : ['Volume', 'Size', 'Files'] as $heading)
                                        <th class="whitespace-nowrap border-b border-border px-3 py-2 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                            {{ $heading }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($runtimeReport->volumes() as $volume)
                                    @php
                                        $isLocal = \App\Models\RuntimeReport::isLocalVolume($volume);
                                        $reason = \App\Models\RuntimeReport::unmeasuredReason($volume);
                                    @endphp

                                    <tr class="border-b border-border last:border-b-0 {{ $reason ? 'border-b-0' : '' }}">
                                        <td class="py-2 pl-3.5 pr-3">
                                            <span class="font-mono text-[12px]">{{ $volume['handle'] }}</span>
                                            @if (($volume['measured'] ?? true) === false)
                                                {{-- Not zero. A volume nobody could walk and a volume
                                                     with nothing in it are different facts, and only
                                                     one of them should worry anybody. --}}
                                                <x-status-badge tone="grey" label="Not measured" class="ml-2" />
                                            @endif
                                        </td>

                                        @if ($showsLocation)
                                            <td class="whitespace-nowrap px-3 py-2 text-[12px]">
                                                @if ($isLocal === true)
                                                    <span class="text-text-2">This server</span>
                                                @elseif ($isLocal === false)
                                                    {{-- Named as what it means rather than as a
                                                         provider: the report carries no bucket, no
                                                         region and no endpoint, on purpose. --}}
                                                    <span class="text-text-2">Remote storage</span>
                                                @else
                                                    <span class="text-text-3">—</span>
                                                @endif
                                            </td>
                                        @endif

                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-[12px] tabular">
                                            @if (($volume['measured'] ?? true) === false && ($volume['unmeasured_reason'] ?? null) === 'timeout')
                                                {{-- A floor, and said as one. The figure is real and
                                                     throwing it away would leave a huge volume with
                                                     no number at all. --}}
                                                at least {{ number_format(($volume['bytes'] ?? 0) / 1073741824, 2) }} GB
                                            @elseif (($volume['measured'] ?? true) === false)
                                                —
                                            @else
                                                {{ number_format(($volume['bytes'] ?? 0) / 1073741824, 2) }} GB
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-[12px] tabular text-text-3">
                                            {{ isset($volume['files']) ? number_format($volume['files']) : '—' }}
                                        </td>
                                    </tr>

                                    @if ($reason)
                                        {{-- Under the row rather than in a tooltip. "Not measured"
                                             covered three unrelated situations wanting three
                                             different responses, and which one applies is the whole
                                             of what somebody needs. --}}
                                        <tr class="border-b border-border last:border-b-0">
                                            <td colspan="{{ $showsLocation ? 4 : 3 }}" class="pb-2.5 pl-3.5 pr-3 text-[12px] leading-relaxed text-text-3">
                                                {{ $reason }}
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                    File sizes, never file names — a byte count says how much is there and nothing
                    about what.
                    @if ($runtimeReport->hasUnmeasuredVolumes())
                        A volume that could not be measured contributes nothing to the total above,
                        so treat it as a floor.
                    @endif
                    @if ($runtimeReport->hasRemoteVolumes())
                        A volume on remote storage is not on this server's disk at all, so it counts
                        towards neither the total nor the free space above it — those describe the
                        machine Craft runs on.
                    @endif
                </p>
            </div>
        @endif

        {{-- PHP limits --}}
        @if ($runtimeReport?->value('php') !== null)
            <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">PHP limits</h2>

            @php
                $mb = static fn (?int $bytes): string => $bytes === null
                    ? '—'
                    : ($bytes < 0 ? 'Unlimited' : number_format($bytes / 1048576, 0).' MB');

                $limits = [
                    'Version' => [$runtimeReport->value('php.version') ?? '—', false],
                    'SAPI' => [$runtimeReport->value('php.sapi') ?? '—', false],
                    'Memory limit' => [$mb($runtimeReport->value('php.memory_limit_bytes')), false],
                    'Max execution' => [
                        $runtimeReport->value('php.max_execution_time') === null
                            ? '—'
                            : $runtimeReport->value('php.max_execution_time').'s',
                        false,
                    ],
                    'Max upload' => [$mb($runtimeReport->value('php.upload_max_filesize_bytes')), false],
                    'Max post' => [$mb($runtimeReport->value('php.post_max_size_bytes')), false],
                    'Opcache' => [
                        $runtimeReport->value('php.opcache_enabled') ? 'On' : 'Off',
                        // Off in production is a real performance finding, not a preference.
                        $runtimeReport->value('php.opcache_enabled') === false && $site->environment === 'production',
                    ],
                    'Extensions' => [$runtimeReport->value('php.extensions') ?? '—', false],
                ];
            @endphp

            <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($limits as $label => [$value, $notable])
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-text-2">{{ $label }}</dt>
                        <dd class="font-mono {{ $notable ? 'font-medium text-amber' : '' }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-2 text-[12px] text-text-3">
                Numbers only. The connector never sends <code class="font-mono">phpinfo()</code>, an
                ini path or any setting whose value would name the host.
            </p>
        @endif
    </div>
@endsection
