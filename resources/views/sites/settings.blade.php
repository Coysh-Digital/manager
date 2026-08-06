@extends('layouts.app')

@section('title', 'Settings · '.$site->name)
@section('crumb', App\Support\Crumbs::site($site, 'Settings'))

@section('content')
    <div class="mx-auto max-w-[1180px]">
        <x-site-header :site="$site" :connector="$connector" :pending-connector="$pendingConnector" />
        <x-site-tabs :site="$site" :update-count="$updateCount" :finding-count="$findingCount" />

        {{-- Site details --}}
        <h2 id="details" class="mb-2.5 scroll-mt-6 text-[13.5px] font-semibold">Site details</h2>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            @if ($membership->canAdminister())
                <form method="POST" action="{{ route('sites.settings.update', $site) }}" class="flex flex-col gap-4 p-4">
                    @csrf

                    <div class="flex flex-wrap gap-4">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Name</span>
                            <input type="text" name="name" required maxlength="120"
                                   value="{{ old('name', $site->name) }}"
                                   class="h-[34px] w-[260px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                            @error('name')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Expected domain</span>
                            <input type="text" name="expected_domain" required maxlength="255"
                                   value="{{ old('expected_domain', $site->expected_domain) }}"
                                   class="h-[34px] w-[260px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 font-mono text-[13px]">
                            @error('expected_domain')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Environment</span>
                            <select name="environment"
                                    class="h-[34px] w-[160px] rounded-[7px] border border-border-2 bg-surface px-2 text-[13px]">
                                @foreach (['production', 'staging', 'development'] as $environment)
                                    <option value="{{ $environment }}" @selected(old('environment', $site->environment) === $environment)>
                                        {{ Str::title($environment) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    {{-- Said before the change, not after it. The expected domain is what a pairing is
                         checked against, so editing it is editing a control rather than a label. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        The expected domain is the host this site must pair from. A connector
                        presenting a different one is held for confirmation rather than adopted, so
                        changing this changes what will be accepted next time.
                        The environment decides which findings apply - several rules only fire in
                        production. Both changes are recorded in the audit log with their previous
                        values.
                    </p>

                    {{-- The backup schedule used to be on this form, which put a decision about
                         dumping a production database beside a field for the site's name, and put
                         it on a screen where its effect could not be seen. It lives with the
                         backups now. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        When this site is backed up is set on its
                        <a href="{{ route('sites.backups', $site) }}" class="text-primary hover:text-primary-hover">Backups</a>
                        screen, beside the backups it has produced.
                    </p>

                    <div>
                        <button type="submit"
                                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Save
                        </button>
                    </div>
                </form>
            @else
                <dl class="grid grid-cols-1 gap-x-10 gap-y-2.5 px-4 py-3.5 text-[12.5px] sm:grid-cols-3">
                    @foreach (['Name' => $site->name, 'Expected domain' => $site->expected_domain, 'Environment' => Str::title($site->environment)] as $label => $value)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-text-2">{{ $label }}</dt>
                            <dd class="font-mono">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="border-t border-border bg-surface-2 px-4 py-2.5 text-[12px] text-text-3">
                    Only an administrator can change these.
                </p>
            @endif
        </div>

        {{-- Capabilities --}}
        <h2 id="capabilities" class="mb-2.5 mt-8 scroll-mt-6 text-[13.5px] font-semibold">Capabilities</h2>
        <p class="mb-3 max-w-[80ch] text-[12.5px] text-text-2">
            What Manager is permitted to do on this site. Anything not granted here cannot be
            performed, and a security rule whose capability is missing is skipped rather than passed.
        </p>

        @include('sites.partials.capabilities')

        {{-- Connector and pairing --}}
        <h2 id="connector" class="mb-2.5 mt-8 scroll-mt-6 text-[13.5px] font-semibold">Connector</h2>

        <div class="overflow-hidden rounded-[10px] border border-border">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4 border-b border-border bg-surface p-4">
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Connector key</span>
                    <span class="font-mono text-[13px]">
                        {{-- The tail here, and the whole thing below. This used to be the tail and
                             nothing else, on the reasoning that showing a public key in full invites
                             pasting it around as though it were meaningful. It is meaningful: it is
                             the input to `manager-restore verify --site-key`, which is the only way a
                             customer can confirm a backup came from their own site without asking us
                             - and asking us is exactly what that check exists to avoid. --}}
                        {{ $connector ? '··········'.Str::substr($connector->public_key, -6) : 'Not paired' }}
                    </span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Paired</span>
                    <span class="text-[13px]">{{ $connector?->paired_at?->diffForHumans() ?? '—' }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Version</span>
                    <span class="font-mono text-[13px]">{{ $connector?->connector_version ?? '—' }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Stored credentials</span>
                    <span class="text-[13px]">No administrator password stored</span>
                </div>

                @if ($connector && $membership->canAdminister())
                    <form method="POST" action="{{ route('sites.connector.revoke', $site) }}" class="ml-auto"
                          onsubmit="return confirm('Revoke this connector? The site stops reporting immediately and will need a new enrolment code.');">
                        @csrf
                        <button type="submit"
                                class="h-8 rounded-[7px] border border-danger-line bg-danger-bg px-3 text-[12.5px] font-medium text-danger hover:border-danger">
                            Revoke access
                        </button>
                    </form>
                @endif
            </div>

            @if ($connector)
                {{--
                    The key a customer needs to check a backup themselves.

                    Manager signs nothing about a backup's origin - the site does, with this key, over
                    the manifest. `manager-restore verify --site-key` is how somebody confirms an
                    artifact came from their own site, and it is deliberately a check they can run
                    against us: it needs no Manager installation, no network, and no trust in this
                    screen beyond copying a public value from it once.

                    Which is why it could not be run. The key was shown only as its last six
                    characters, so the check the zero-knowledge story rests on had no obtainable
                    input.

                    Shown to every member rather than to administrators only. It is a public key, and
                    the person holding the recovery key and doing the restore at three in the morning
                    is not necessarily the person who can administer the site.
                --}}
                <div class="flex flex-col gap-2 border-b border-border bg-surface-2 px-4 py-3">
                    <span class="text-[13px] font-medium">Verifying a backup came from this site</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        This site signs every backup manifest with the key below. Checking that signature
                        needs no Manager installation and no network - which is the point, because it is
                        the check that does not take our word for it.
                    </p>

                    <code class="select-all break-all rounded-[7px] border border-border bg-surface px-2.5 py-2 font-mono text-[12px]">{{ $connector->public_key }}</code>

                    <code class="max-w-full overflow-x-auto rounded-[7px] border border-border bg-surface px-2.5 py-2 font-mono text-[12px] text-text-2">manager-restore verify --key=your-recovery.secret --site-key={{ $connector->public_key }} ./artifact</code>
                </div>
            @endif

            @if ($membership->canAdminister())
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-2 px-4 py-3">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[13px] font-medium">
                            {{ $connector ? 'Re-pair this site' : 'Pair this site' }}
                        </span>
                        <span class="text-[12.5px] text-text-2">
                            @if ($connector)
                                This site has a working connector. A new code will not replace it unless you
                                say so, which is what stops a compromised site re-pairing itself.
                            @else
                                Issues a single-use code to run on the site.
                            @endif
                        </span>
                    </div>

                    <form method="POST" action="{{ route('sites.enrolment-code', $site) }}"
                          class="flex flex-wrap items-center gap-3">
                        @csrf

                        @if ($connector)
                            <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                                <input type="checkbox" name="authorise_replacement" value="1" required
                                       class="accent-[var(--primary)]">
                                Replace the current connector
                            </label>
                        @endif

                        <button type="submit"
                                class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                            Issue a code
                        </button>
                    </form>
                </div>
            @endif

            {{-- Permanently rendered rather than disappearing once it has worked: instructions that
                 vanish on success are no use to somebody whose cron broke three months later. --}}
            <div class="bg-surface-2 px-4 py-3.5">
                <x-connector-schedule :site="$site" :open="$connector !== null && $site->craft_version === null" />
            </div>
        </div>

        {{-- Danger zone --}}
        @if ($membership->canAdminister())
            <h2 id="remove" class="mb-2.5 mt-8 scroll-mt-6 text-[13.5px] font-semibold">Remove this site</h2>

            <div class="overflow-hidden rounded-[10px] border border-danger-line bg-surface">
                <div class="border-b border-border p-4">
                    <p class="max-w-[80ch] text-[13px] text-text-2">
                        Removes {{ $site->name }} from the fleet and revokes its connector in the same
                        transaction - the credentials and the permissions cannot go away separately.
                        Its audit history is kept: the log is append-only and removing a site does not
                        remove the record of what was done to it.
                    </p>
                </div>

                <form method="POST" action="{{ route('sites.destroy', $site) }}" class="flex flex-wrap items-end gap-3 bg-surface-2 p-4">
                    @csrf
                    @method('DELETE')

                    <label class="flex flex-col gap-1.5">
                        {{-- Typing the domain is the confirmation. A dialog people click through is not one. --}}
                        <span class="text-[12.5px] font-medium">Type <code class="font-mono">{{ $site->expected_domain }}</code> to confirm</span>
                        <input type="text" name="confirm_domain" required autocomplete="off"
                               class="h-[34px] w-[260px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 font-mono text-[12.5px]">
                        @error('confirm_domain')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Why <span class="font-normal text-text-3">(optional)</span></span>
                        <input type="text" name="reason" maxlength="255"
                               placeholder="Client offboarded"
                               class="h-[34px] w-[280px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] placeholder:text-text-3">
                    </label>

                    <button type="submit"
                            class="h-[34px] rounded-[7px] border border-danger-line bg-danger-bg px-3.5 text-[12.5px] font-medium text-danger hover:border-danger">
                        Remove site
                    </button>
                </form>
            </div>
        @endif
    </div>
@endsection
