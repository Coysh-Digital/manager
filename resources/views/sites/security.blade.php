@extends('layouts.app')

@section('title', 'Security · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Security'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        {{--
            Is the thing reporting to us still the thing we paired with?

            A connector authenticates with an Ed25519 signature, so a new source address is not an
            alarm on its own - sites move and hosts rotate egress. But nothing in the interface said
            where reports were arriving from at all, which meant the one shape a compromise takes was
            the one thing nobody could see.
        --}}
        <h2 class="mb-2.5 text-[13.5px] font-semibold">Connector trust</h2>

        <div class="mb-6 overflow-hidden rounded-[10px] border border-border bg-surface">
            @if ($trust['connector'] === null)
                <p class="px-4 py-6 text-center text-[13px] text-text-2">
                    No active connector, so nothing is reporting and there is nothing to trust.
                </p>
            @else
                <div class="flex flex-wrap items-start gap-x-10 gap-y-4 border-b border-border px-4 py-3.5">
                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Key fingerprint</span>
                        {{-- The full public key is not a secret, but printing it invites pasting it
                             around as though it were meaningful. A fingerprint is for the one thing
                             anybody does with it: checking whether two of them match. --}}
                        <span class="font-mono text-[13px] tracking-[0.04em]">{{ $trust['fingerprint'] }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Key age</span>
                        <span class="font-mono text-[13px] tabular">
                            {{ $trust['keyAgeDays'] === null ? '—' : $trust['keyAgeDays'].' days' }}
                        </span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Paired</span>
                        <span class="text-[13px]">{{ $trust['connector']->paired_at?->diffForHumans() ?? '—' }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Rotated</span>
                        <span class="text-[13px]">{{ $trust['connector']->key_rotated_at?->diffForHumans() ?? 'never' }}</span>
                    </div>

                    @if ($trust['newAddressSeen'])
                        <div class="flex items-center">
                            <x-status-badge tone="warn" label="Reporting from a new address" />
                        </div>
                    @endif
                </div>

                @if ($trust['addresses'] !== [])
                    <div class="relative overflow-x-auto">
                        <table class="w-full min-w-[520px] text-[13px]">
                            <thead>
                                <tr class="bg-surface-2">
                                    @foreach (['Source address', 'First seen', 'Last seen', 'Check-ins'] as $heading)
                                        <th class="whitespace-nowrap border-b border-border px-3 py-2 text-left font-mono text-[10px] font-medium uppercase tracking-[0.07em] text-text-3 {{ $loop->first ? 'pl-3.5' : '' }}">
                                            {{ $heading }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($trust['addresses'] as $address)
                                    <tr class="border-b border-border last:border-b-0">
                                        <td class="whitespace-nowrap py-2 pl-3.5 pr-3 font-mono text-[12px]">{{ $address['ip'] }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-text-2">
                                            {{ $address['first']->diffForHumans(short: true) }}
                                            @if ($address['first']->gt(now()->subDay()) && count($trust['addresses']) > 1)
                                                <span class="ml-1 text-amber">new</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-[12.5px] text-text-2">{{ $address['last']->diffForHumans(short: true) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 font-mono text-[12px] tabular text-text-3">{{ $address['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                    Addresses over the last 30 days. A change is not an alarm on its own - sites move
                    and hosts rotate egress - but a key that has never rotated, reporting from an
                    address it has never used, is worth a look. Revoking the connector under
                    <a href="{{ route('sites.settings', $site) }}#connector" class="text-primary hover:text-primary-hover">Settings</a>
                    stops it reporting immediately.
                </p>
            @endif
        </div>

        <h2 class="mb-2.5 text-[13.5px] font-semibold">
            Findings
            @if ($findings->isNotEmpty())
                <span class="ml-1 font-normal text-text-2">— {{ $findings->count() }} outstanding</span>
            @endif
        </h2>

        @if ($findings->isEmpty())
            <div class="rounded-[10px] border border-border bg-surface px-4 py-8 text-center">
                <p class="text-[13.5px] font-medium">Nothing outstanding.</p>
                <p class="mt-1.5 text-[13px] text-text-2">
                    Findings are derived here from what the site reports, and resolve themselves once
                    the problem is fixed - there is nothing to tick off.
                </p>
            </div>
        @else
            <div class="flex flex-col gap-2.5">
                @foreach ($findings as $finding)
                    <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                        <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1.5 border-b border-border px-4 py-3">
                            <x-status-badge :tone="$finding->tone()" :label="Str::title($finding->severity)" />
                            <h3 class="text-[14px] font-medium">{{ $finding->title }}</h3>

                            @if ($finding->isAcknowledged())
                                <x-status-badge tone="info" label="Acknowledged" />
                            @endif

                            <span class="font-mono text-[11px] text-text-3">first seen {{ $finding->age() }} ago</span>
                            <span class="ml-auto font-mono text-[11px] text-text-3">{{ $finding->rule }}</span>
                        </div>

                        <p class="max-w-[80ch] px-4 py-3 text-[13px] text-text-2">{{ $finding->detail }}</p>

                        @if ($finding->isAcknowledged() && $finding->acknowledgement_reason)
                            <p class="border-t border-border bg-surface-2 px-4 py-2.5 text-[12.5px] text-text-2">
                                <span class="font-medium">{{ $finding->acknowledged_label }}</span>
                                acknowledged this {{ $finding->acknowledged_at?->diffForHumans() }}:
                                {{ $finding->acknowledgement_reason }}
                            </p>
                        @endif

                        @if ($canAcknowledge)
                            <div class="relative flex justify-end border-t border-border px-4 py-2.5">
                                @if ($finding->isAcknowledged())
                                    <form method="POST" action="{{ route('findings.reopen', $finding) }}">
                                        @csrf
                                        <button type="submit"
                                                class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Withdraw acknowledgement
                                        </button>
                                    </form>
                                @else
                                    {{-- Behind a disclosure, and a reason is required: "acknowledged
                                         three weeks ago" with no explanation leaves the next person
                                         unable to tell a decision from a shrug. --}}
                                    <details class="group">
                                        <summary class="flex h-8 cursor-pointer list-none items-center justify-center whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Acknowledge
                                        </summary>

                                        <form method="POST" action="{{ route('findings.acknowledge', $finding) }}"
                                              class="absolute right-4 z-10 mt-1.5 flex flex-col gap-1.5 rounded-[9px] border border-border bg-surface p-2.5 shadow-[var(--shadow)]">
                                            @csrf
                                            <label class="sr-only" for="reason-{{ $finding->getRouteKey() }}">
                                                Why {{ $finding->title }} is not being fixed now
                                            </label>
                                            <input type="text" id="reason-{{ $finding->getRouteKey() }}"
                                                   name="reason" required minlength="3" maxlength="255"
                                                   placeholder="Why not now?"
                                                   class="h-8 w-[220px] max-w-full rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] text-text placeholder:text-text-3">
                                            <button type="submit"
                                                    class="h-8 whitespace-nowrap rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                                                Confirm
                                            </button>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{--
            What could not be checked.

            The rules the evaluator skipped because this site was never asked to report the facts they
            need. Named rather than counted, because "three rules skipped" tells nobody which risk
            they are carrying.
        --}}
        @if ($unchecked !== [])
            <div class="mt-3 rounded-[9px] border border-info-line bg-info-bg px-4 py-3.5">
                <p class="text-[13px] font-medium text-info">Some checks did not run</p>
                <p class="mt-0.5 mb-2 text-[12.5px] text-text-2">
                    A rule whose capability is not granted is skipped, not passed. These are the ones
                    this site cannot answer:
                </p>

                <ul class="flex list-none flex-col gap-1 p-0">
                    @foreach ($unchecked as $capability => $rules)
                        <li class="text-[12.5px] text-text-2">
                            <code class="font-mono text-[12px]">{{ $capability }}</code> —
                            {{ implode(', ', $rules) }}
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('sites.settings', $site) }}#capabilities"
                   class="mt-2 inline-block text-[12.5px] text-info hover:text-primary-hover">
                    Review this site's capabilities
                </a>
            </div>
        @endif

        @if (collect($timeline)->sum('opened') > 0 || collect($timeline)->last()['value'] > 0)
            {{-- Direction, not a snapshot. Four findings down from eleven is a site being looked
                 after; four up from none is not, and the current number is identical. --}}
            <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Over the last twelve weeks</h2>

            <div class="overflow-hidden rounded-[10px] border border-border bg-surface px-4 py-4">
                <x-chart kind="findings"
                         :points="$timeline"
                         :height="150"
                         label="Outstanding findings per week"
                         :summary="'Outstanding findings for '.$site->name.', week by week'" />
            </div>
        @endif

        {{--
            What Manager itself can do here, and what it has kept.

            The question an operator gets asked in an audit - "what does your monitoring platform
            have access to, and what has it stored" - and until now the only way to answer it was to
            read a capability list and infer.
        --}}
        <h2 id="exposure" class="mb-2.5 mt-6 scroll-mt-6 text-[13.5px] font-semibold">What Manager holds on this site</h2>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            <p class="border-b border-border px-4 py-3 text-[13px] leading-relaxed text-text-2">
                @if ($exposure['readsContent'])
                    Manager can take a copy of this site's <strong>entire database</strong>, including
                    user accounts, password hashes and any personal information the site holds.
                    @if ($exposure['backupCount'] > 0)
                        It currently holds <strong>{{ $exposure['backupCount'] }}</strong>
                        {{ Str::plural('backup', $exposure['backupCount']) }}
                        ({{ number_format($exposure['backupBytes'] / 1048576, 1) }} MB uncompressed), the
                        oldest taken {{ $exposure['oldestBackup']?->diffForHumans() }}.
                    @else
                        No backup has been stored yet.
                    @endif
                @else
                    Manager holds <strong>operational metadata only</strong> for this site - versions,
                    counts and configuration booleans. It has no copy of the database, no entries, no
                    assets and no user records, because the capability that would read them
                    (<code class="font-mono text-[12px]">backups:create</code>) is not granted.
                @endif
            </p>

            <div class="flex flex-col">
                @forelse ($exposure['grants'] as $grant)
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                        <code class="font-mono text-[12px]">{{ $grant->capability }}</code>
                        <span class="text-text-2">{{ __('capabilities.'.$grant->capability.'.title') }}</span>
                        <span class="ml-auto text-[12px] text-text-3">
                            {{ $grant->grantedBy?->name ?? 'System' }}
                            @if ($grant->granted_at)
                                · {{ $grant->granted_at->diffForHumans(short: true) }}
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="px-4 py-5 text-center text-[13px] text-text-2">
                        Nothing is granted, so this site reports nothing at all.
                    </p>
                @endforelse
            </div>

            <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                Manager never holds an administrator password, an SSH key or a database credential for
                any site - the schema has nowhere to put one. Everything above is revocable from
                <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Settings</a>,
                and revoking takes effect on the connector's next check-in.
            </p>
        </div>

        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Failed sign-ins</h2>

        @include('sites.partials.sign-ins')

        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Configuration</h2>

        @if ($latestReport === null || $latestReport->value('config_flags') === null)
            <div class="rounded-[10px] border border-border bg-surface px-4 py-8 text-center text-[13px] text-text-2">
                @if (! $site->hasCapability('security:read'))
                    Configuration flags need <code class="font-mono">security:read</code>, which this
                    site has not been granted.
                    <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
                    and they arrive on the next report.
                @else
                    Granted, but nothing has been reported yet.
                @endif
            </div>
        @else
            @php
                // A flag is only worth a reader's attention when it is the wrong way round.
                // "Dev mode: No" is the expected answer and reads as quietly as it deserves;
                // "Dev mode: Yes" is a finding, and looks like one.
                $flags = [
                    'dev_mode' => ['Dev mode', true],
                    'allow_admin_changes' => ['Admin changes allowed', true],
                    'allow_updates' => ['Updates allowed', true],
                    'headless_mode' => ['Headless mode', null],
                    'https_enforced' => ['HTTPS enforced', false],
                ];
            @endphp

            <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($flags as $key => [$label, $riskyWhenOn])
                    @php
                        $value = $latestReport->value('config_flags.'.$key);
                        $notable = $riskyWhenOn !== null && $value !== null && $value === $riskyWhenOn;
                    @endphp
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-text-2">{{ $label }}</dt>
                        <dd class="{{ $notable ? 'font-medium text-amber' : '' }}">
                            {{ $value === null ? '—' : ($value ? 'Yes' : 'No') }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-2 text-[12px] text-text-3">
                Booleans only. The connector never sends a configuration value or an environment
                variable, so there is nothing here that could carry a credential.
            </p>
        @endif

        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Licensing and runtime</h2>

        <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
            @php
                $licence = $latestReport?->value('licence');
                $eol = (bool) $updateReport?->value('php.end_of_life');

                $posture = [
                    'Craft licence' => [
                        $licence === null ? '—' : Str::title((string) ($licence['craft'] ?? 'unknown')),
                        $licence !== null && in_array($licence['craft'] ?? '', ['invalid', 'mismatched'], true),
                    ],
                    'Plugin licences' => [
                        $licence === null ? '—' : ($licence['plugins_valid'] ?? 0).' of '.($licence['plugins_total'] ?? 0).' valid',
                        $licence !== null && ($licence['plugins_valid'] ?? 0) < ($licence['plugins_total'] ?? 0),
                    ],
                    'Trials in use' => [
                        $licence === null ? '—' : (string) ($licence['trials_in_use'] ?? 0),
                        $licence !== null && ($licence['trials_in_use'] ?? 0) > 0,
                    ],
                    'PHP' => [
                        $updateReport?->value('php.current') ?? $site->php_version ?? '—',
                        $eol,
                    ],
                    'Security support until' => [
                        $updateReport?->value('php.security_support_until') ?? '—',
                        $eol,
                    ],
                    'Environment' => [Str::title($site->environment), false],
                ];
            @endphp

            @foreach ($posture as $label => [$value, $notable])
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-text-2">{{ $label }}</dt>
                    <dd class="font-mono {{ $notable ? 'font-medium text-amber' : '' }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($licence === null && ! $site->hasCapability('licences:read'))
            <p class="mt-2 text-[12px] text-text-3">
                Licence state needs <code class="font-mono">licences:read</code>. Only the state
                computed on the site crosses the wire - never a licence key.
            </p>
        @endif

        @if ($resolved->isNotEmpty())
            <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Recently resolved</h2>

            <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
                @foreach ($resolved as $finding)
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                        <x-status-badge tone="ok" label="Resolved" />
                        <span>{{ $finding->title }}</span>
                        <span class="ml-auto font-mono text-[11px] text-text-3">
                            {{ $finding->resolved_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
