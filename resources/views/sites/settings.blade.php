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

                    {{-- Backups.

                         Note what is not on this form: nothing naming where a backup goes or who can
                         read it. Those come from the organisation's recovery keys and from the site's
                         own config file. A schedule decides when to ask. --}}
                    <div class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row">
                        <label class="flex flex-1 flex-col gap-1">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Backups</span>
                            <select name="backup_schedule"
                                    class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                                @foreach (['off' => 'Only when asked', 'daily' => 'Every day', 'weekly' => 'Every week'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('backup_schedule', $site->backup_schedule) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="flex flex-col gap-1 sm:w-[140px]">
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

                        <label class="flex flex-col gap-1 sm:w-[140px]">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">On</span>
                            <select name="backup_schedule_day"
                                    class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                                @foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $index => $day)
                                    <option value="{{ $index + 1 }}" @selected((int) old('backup_schedule_day', $site->backup_schedule_day) === $index + 1)>{{ $day }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        Times are in this organisation's time zone
                        (<span class="font-mono">{{ $site->organisation->timezone }}</span>), so
                        &ldquo;03:00&rdquo; is the quiet hour where the site is, not where this server
                        is. The day is ignored for a daily schedule.
                        @if ($site->backup_schedule !== 'off')
                            A scheduled backup is refused rather than attempted if this organisation
                            has no recovery key to encrypt it to.
                        @endif
                    </p>

                    {{-- Said before the change, not after it. The expected domain is what a pairing is
                         checked against, so editing it is editing a control rather than a label. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        The expected domain is the host this site must pair from. A connector
                        presenting a different one is held for confirmation rather than adopted, so
                        changing this changes what will be accepted next time.
                        The environment decides which findings apply — several rules only fire in
                        production. Both changes are recorded in the audit log with their previous
                        values.
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
                        {{-- Only ever the tail. The full key is public, but showing it in full invites
                             pasting it around as though it were meaningful. --}}
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
                        transaction — the credentials and the permissions cannot go away separately.
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
