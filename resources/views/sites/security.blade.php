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
                            {{ $trust['keyAgeDays'] === null ? '-' : $trust['keyAgeDays'].' days' }}
                        </span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Paired</span>
                        <span class="text-[13px]">{{ $trust['connector']->paired_at?->diffForHumans() ?? '-' }}</span>
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

        {{--
            The certificate a visitor validates.

            Shown whether or not anything is wrong with it, which is the point. Findings say what is
            broken; this says what was looked at, and the two answers a screen most needs to keep
            apart are "checked, and fine" and "never checked". A site that has never been swept has
            no certificate row anywhere else in the interface to distinguish it from a healthy one.

            The connector cannot answer any of this. TLS terminates at the edge, so PHP on the origin
            sees whatever a proxy put in $_SERVER - which on a CDN-fronted site is not the
            certificate anybody validates. This is the platform's own observation, made from outside.
        --}}
        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">TLS certificate</h2>

        <div class="mb-6 overflow-hidden rounded-[10px] border border-border bg-surface">
            @if ($site->certificate_checked_at === null)
                <p class="px-4 py-6 text-center text-[13px] text-text-2">
                    Not checked yet. The sweep runs once a day, and a site added since the last one
                    has nothing recorded rather than nothing wrong.
                </p>
            @elseif ($site->certificate_error !== null)
                <p class="px-4 py-6 text-center text-[13px] text-text-2">
                    {{ $site->certificate_error }}
                    <span class="mt-1 block text-[12px] text-text-3">
                        That is a statement about reaching {{ $site->expected_domain }}, not about its
                        certificate - so nothing here is judged rather than judged and passed.
                    </span>
                </p>
            @else
                @php
                    $days = $site->certificate_expires_at === null
                        ? null
                        : (int) now()->startOfDay()->diffInDays($site->certificate_expires_at->startOfDay(), false);

                    // Null is a third state throughout, and reads as "not judged" rather than as a
                    // pass. A tick against something nobody checked is the one thing this panel must
                    // not show.
                    $judgement = static fn (?bool $value, string $yes, string $no): array => match ($value) {
                        true => [$yes, false],
                        false => [$no, true],
                        default => ['Not determined', false],
                    };

                    $rows = [
                        'Expires' => $site->certificate_expires_at === null
                            ? ['Not determined', false]
                            : [
                                $site->certificate_expires_at->toFormattedDateString()
                                    .($days === null ? '' : ' ('.($days < 0 ? 'expired' : $days.' days').')'),
                                $days !== null && $days <= 30,
                            ],
                        'Issuer' => [$site->certificate_issuer ?? 'Not determined', false],
                        'Covers this domain' => $judgement($site->certificate_hostname_matches, 'Yes', 'No'),
                        'Chain trusted' => $judgement($site->certificate_trusted, 'Yes', 'No'),
                        'Self-signed' => $judgement(
                            $site->certificate_self_signed === null ? null : ! $site->certificate_self_signed,
                            'No',
                            'Yes',
                        ),
                        'Certificates sent' => [
                            $site->certificate_chain_length === null
                                ? 'Not determined'
                                : (string) $site->certificate_chain_length,
                            false,
                        ],
                    ];
                @endphp

                <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 px-4 py-3.5 text-[12.5px] sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($rows as $label => [$value, $notable])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-text-2">{{ $label }}</dt>
                            <dd class="font-mono {{ $notable ? 'font-medium text-danger' : '' }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                    Read by completing a TLS handshake with {{ $site->expected_domain }} from this
                    server, <span class="font-medium">not</span> by asking the site. A certificate is
                    terminated at the edge, so the site itself cannot see the one its visitors
                    validate. Last checked <x-timestamp :at="$site->certificate_checked_at" />.
                </p>
            @endif
        </div>

        {{--
            What the site serves to somebody who is not it.

            The vantage point is the whole value, and the panel says so rather than leaving it
            implicit. A plugin checking a site's own headers is reading them at the wrong end of the
            chain: nginx, a CDN or a WAF adds and removes headers on the way out, and the origin sees
            none of that. Checking from here can be right where checking from inside cannot be.
        --}}
        <h2 class="mb-2.5 mt-6 text-[13.5px] font-semibold">Served to the public</h2>

        <div class="mb-6 overflow-hidden rounded-[10px] border border-border bg-surface">
            @if ($probe === null)
                <p class="px-4 py-6 text-center text-[13px] text-text-2">
                    Not checked yet. The sweep runs once a day, and a site added since the last one
                    has nothing recorded rather than nothing wrong - press <strong>Refresh</strong>
                    at the top of the page to look now.
                </p>
            @elseif (! $probe->succeeded())
                <p class="px-4 py-6 text-center text-[13px] text-text-2">
                    {{ $probe->error }}
                    <span class="mt-1 block text-[12px] text-text-3">
                        Nothing is judged from a site that did not answer. A site nobody can reach has
                        no headers to be missing.
                    </span>
                </p>
            @else
                @php
                    // Everything this panel needs, resolved in one place. Present or absent, and
                    // nothing in between: what a *good* value looks like is the findings rules'
                    // business, and this panel's job is to show what was actually served - so a
                    // header that is set renders its own value rather than a tick.
                    $reportOnly = $probe->header('content-security-policy-report-only');

                    $rows = [];

                    foreach ([
                        'HSTS' => 'strict-transport-security',
                        'Content-Security-Policy' => 'content-security-policy',
                        'X-Frame-Options' => 'x-frame-options',
                        'X-Content-Type-Options' => 'x-content-type-options',
                        'Referrer-Policy' => 'referrer-policy',
                        'Permissions-Policy' => 'permissions-policy',
                    ] as $label => $name) {
                        $value = $probe->header($name);

                        // Report-only is a third state. A policy nobody enforces is not the same as
                        // no policy, and it is not the same as one that is on.
                        if ($value === null && $name === 'content-security-policy' && $reportOnly !== null) {
                            $rows[$label] = ['report-only', $reportOnly, false];

                            continue;
                        }

                        $rows[$label] = [$value ?? 'Not set', $value, $value === null];
                    }

                    $rows['Plain HTTP'] = match ($probe->redirects_to_https) {
                        null => ['Nothing on port 80', null, false],
                        true => ['Redirected to HTTPS', null, false],
                        false => ['Served in the clear', null, true],
                    };

                    $disclosed = array_filter([$probe->header('server'), $probe->header('x-powered-by')]);
                    $rows['Software disclosed'] = $disclosed === []
                        ? ['Nothing', null, false]
                        : [implode(', ', $disclosed), null, false];

                    $duplicated = $probe->duplicatedHeaders();
                    $exposed = $probe->exposedPaths();
                @endphp

                <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 border-b border-border px-4 py-3.5 text-[12.5px] sm:grid-cols-2">
                    @foreach ($rows as $label => [$value, $full, $notable])
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="shrink-0 text-text-2">{{ $label }}</dt>
                            <dd class="truncate font-mono {{ $notable ? 'text-amber' : '' }}"
                                @if ($full) title="{{ $full }}" @endif>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($duplicated !== [])
                    <p class="border-b border-border px-4 py-3 text-[12.5px] text-text-2">
                        <span class="font-medium text-amber">Sent twice:</span>
                        <span class="font-mono">{{ implode(', ', $duplicated) }}</span>
                        - two values for the same header is not a stricter site, it is an undefined
                        one, and browsers disagree about which wins. The usual cause is the
                        application and the web server each setting it without knowing about the
                        other, which is invisible from inside the site because both halves are
                        working.
                    </p>
                @endif

                @if ($probe->answers_everything)
                    <p class="border-b border-border px-4 py-3 text-[12.5px] text-text-2">
                        This site answers <span class="font-mono">200</span> for paths that do not
                        exist, so nothing can be concluded about which files are reachable. A request
                        for a made-up path was answered too - that is a fact about the routing rather
                        than about any file, and no exposure finding is raised from it.
                    </p>
                @elseif ($exposed !== [])
                    <div class="border-b border-border px-4 py-3">
                        <p class="mb-1.5 text-[12.5px] font-medium text-danger">Reachable over the web</p>
                        <ul class="space-y-1 text-[12.5px]">
                            @foreach ($exposed as $file)
                                @php
                                    // A size, because "0 bytes" and "8 megabytes" at the same path
                                    // are different situations - the first is usually a placeholder
                                    // somebody left, the second is the file.
                                    $bytes = $file['bytes'] ?? null;
                                    $note = $file['status'].($bytes === null ? '' : ', '.number_format((int) $bytes).' bytes');
                                @endphp
                                <li class="font-mono">
                                    {{ $file['path'] }}
                                    <span class="text-text-3">- {{ $note }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
                    Requested from this server, <span class="font-medium">not</span> from
                    {{ $site->expected_domain }} itself - so this is what a visitor receives after any
                    CDN, proxy or firewall in front of it, which is not what the site can see of
                    itself. Headers only: no page content is read or stored, and the file checks are
                    <span class="font-mono">HEAD</span> requests that transfer nothing.
                    Last checked <x-timestamp :at="$probe->probed_at" />.
                </p>
            @endif
        </div>

        <h2 class="mb-2.5 text-[13.5px] font-semibold">
            Findings
            @if ($findings->isNotEmpty())
                <span class="ml-1 font-normal text-text-2">- {{ $findings->count() }} outstanding</span>
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
                            <code class="font-mono text-[12px]">{{ $capability }}</code> -
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

                        {{-- The half this panel used to omit, and the half an auditor asks second.
                             It quoted a plaintext megabyte figure for a copy of somebody's entire
                             database and never once said the word "encrypted", which reads far worse
                             than the truth. --}}
                        @if ($exposure['readableCount'] === 0)
                            Each was encrypted on the site with its own key before it was uploaded,
                            and that key is sealed to this organisation's recovery keys - which exist
                            only where you put them - so <strong>nothing held here opens one</strong>,
                            by us or by anybody who reaches this storage.
                        @else
                            {{-- Never softened, and never left to the Backups screen to mention. A
                                 v1 artifact's key was sealed to this platform, so the unqualified
                                 sentence above would be false about the exact files it is about. --}}
                            <strong>{{ $exposure['readableCount'] }}</strong> of them
                            {{ $exposure['readableCount'] === 1 ? 'was' : 'were' }} taken before any
                            recovery key was enrolled and {{ $exposure['readableCount'] === 1 ? 'is' : 'are' }}
                            encrypted to a key this platform can unwrap, so
                            {{ $exposure['readableCount'] === 1 ? 'it' : 'they' }} can be read here.
                            @if ($exposure['backupCount'] > $exposure['readableCount'])
                                The rest are sealed to this organisation's recovery keys alone - which
                                exist only where you put them - and nothing held here opens one.
                            @endif
                        @endif
                    @else
                        No backup has been stored yet. When one is, it is encrypted on the site with
                        its own key before it is uploaded, and that key is sealed to this
                        organisation's recovery keys - so what arrives here is ciphertext this
                        platform cannot open.
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

                        // allowAdminChanges off overrides allowUpdates, and the connector reports the raw
                        // allowUpdates. Show what Craft will actually do.
                        $overridden = $key === 'allow_updates'
                            && $value === true
                            && $latestReport->value('config_flags.allow_admin_changes') === false;
                        $notable = ! $overridden && $riskyWhenOn !== null && $value !== null && $value === $riskyWhenOn;
                    @endphp
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-text-2">{{ $label }}</dt>
                        <dd class="{{ $notable ? 'font-medium text-amber' : '' }}">
                            {{ $overridden ? 'No (admin changes off)' : ($value === null ? '-' : ($value ? 'Yes' : 'No')) }}
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
                        $licence === null ? '-' : Str::title((string) ($licence['craft'] ?? 'unknown')),
                        $licence !== null && in_array($licence['craft'] ?? '', ['invalid', 'mismatched'], true),
                    ],
                    'Plugin licences' => [
                        $licence === null ? '-' : ($licence['plugins_valid'] ?? 0).' of '.($licence['plugins_total'] ?? 0).' valid',
                        $licence !== null && ($licence['plugins_valid'] ?? 0) < ($licence['plugins_total'] ?? 0),
                    ],
                    'Trials in use' => [
                        $licence === null ? '-' : (string) ($licence['trials_in_use'] ?? 0),
                        $licence !== null && ($licence['trials_in_use'] ?? 0) > 0,
                    ],
                    'PHP' => [
                        $updateReport?->value('php.current') ?? $site->php_version ?? '-',
                        $eol,
                    ],
                    'Security support until' => [
                        $updateReport?->value('php.security_support_until') ?? '-',
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
