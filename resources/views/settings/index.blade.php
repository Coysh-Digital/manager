@extends('layouts.app')

@section('title', 'Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Settings'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header subtitle="This installation runs on your own infrastructure. Coysh Digital has no access to it." />

        {{-- The same checks manager:doctor runs. Two implementations would eventually disagree, and
             the one somebody is looking at would be the wrong one. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-border px-4 py-3">
                {{-- "Platform health" is the operator's framing. A customer of a hosted edition is
                     not looking at a platform they run, and the two rows they do see are about
                     their own data. --}}
                <span class="text-[13.5px] font-medium">
                    {{ app(App\Contracts\ServerAccess::class)->reachable() ? 'Platform health' : 'Your data' }}
                </span>

                {{-- What this installation is, beside how it is doing. Two questions a support
                     conversation opens with, and this screen only answered one of them. The edition
                     word is not repeated here: it is under the wordmark, and it comes from a bound
                     implementation rather than from anything written down. --}}
                <span class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    @if ($version !== null)
                        <span class="font-mono text-[11px] text-text-2">{{ $version }}</span>
                    @else
                        {{-- Unset is the normal state for a clone or a tarball: `git archive` leaves
                             no .git behind, so an installation genuinely cannot know. Saying so beats
                             printing a number somebody would quote back at us. --}}
                        <span class="font-mono text-[11px] text-text-3" title="Set MANAGER_VERSION to record which release this is">unreleased build</span>
                    @endif

                    <x-changelog-link :href="\App\Domain\Updates\ChangelogLink::manager()" label="Changelog" />

                    {{-- The same checks below, from a shell. Worth pointing at when the reader has
                         one; noise when the machine belongs to somebody else. --}}
                    @if (app(App\Contracts\ServerAccess::class)->reachable())
                        <span class="font-mono text-[11px] text-text-3">php artisan manager:doctor</span>
                    @endif
                </span>
            </div>

            @unless (app(App\Contracts\ServerAccess::class)->reachable())
                {{-- Said once, rather than leaving somebody to wonder what happened to the rest.
                     The checks that are missing are about the machine — the database role, the
                     queue, the disk, the session cookie — and a red row a customer cannot act on
                     invites a support ticket whose answer is "yes, we know, that one is ours". --}}
                <p class="border-b border-border bg-surface-2 px-4 py-2.5 text-[12px] leading-relaxed text-text-3">
                    Checks about the servers themselves — the database, the queue, the disk, the keys
                    — are ours to watch and are not shown here. These are the ones about your data.
                </p>
            @endunless

            <div class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2">
                @foreach ($checks as $check)
                    <div class="flex items-start justify-between gap-4 bg-surface px-4 py-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="text-[13px] font-medium">{{ $check->name }}</span>
                            <span class="font-mono text-[11.5px] text-text-3">{{ $check->detail }}</span>
                            @if ($check->remedy)
                                <span class="mt-0.5 text-[12px] text-text-2">{{ $check->remedy }}</span>
                            @endif
                        </div>

                        <span class="flex-none whitespace-nowrap text-[12px] font-medium
                            {{ $check->failed() ? 'text-danger' : ($check->warned() ? 'text-amber' : 'text-ok') }}">
                            {{ $check->failed() ? '✕' : ($check->warned() ? '!' : '✓') }}
                            {{ Str::title($check->status) }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{--
                Mail configuration is not on this screen, and nothing about it is shown here — not
                the host, not the port, not the sender address. The check above already answers the
                only question this screen needs to: will a password reset arrive.

                It has a screen of its own now, and it is not this one. The Mail tab holds the
                relay's host and login, is offered only to an owner, and exists only on an edition
                that administers its own mail — which is the same reasoning that used to keep mail
                off every screen, kept as a permission instead of as an absence.
                See App\Contracts\MailAdministration and MailSettingsController.
            --}}
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">What a new site can do</div>

            <div class="flex flex-col gap-3 p-4 text-[13px]">
                <p class="text-text-2">
                    A newly paired site is granted
                    @foreach ($pairingDefaults as $capability)
                        <code class="font-mono text-[12px]">{{ $capability }}</code>@if (! $loop->last), @endif
                    @endforeach
                    and nothing else. Everything further has to be granted deliberately, per site.
                </p>
                <p class="text-text-2">
                    Available to grant from a site's Capabilities screen:
                    @foreach ($grantable as $capability)
                        <code class="font-mono text-[12px]">{{ $capability }}</code>@if (! $loop->last), @endif
                    @endforeach.
                    Anything that modifies a site, or reads its content, is not offered as a switch and
                    needs its own confirmation flow.
                </p>
                <p class="text-text-3">
                    These defaults are deliberately not configurable. A setting that could grant more at
                    pairing time would make "read-only by default" a preference rather than a property.
                </p>
            </div>
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">{{ $organisation->name }}</div>

            <div class="flex items-center justify-between gap-5 border-b border-border px-4 py-3.5">
                <div class="flex flex-col gap-1">
                    <span class="text-[13px] font-medium">Require two-factor authentication</span>
                    <span class="text-[12.5px] text-text-2">
                        Every member must hold a second factor. Anyone without one is asked to enrol
                        before they can continue, rather than being locked out.
                    </span>
                </div>

                @if ($membership->isOwner())
                    <form method="POST" action="{{ route('settings.mfa') }}">
                        @csrf
                        <input type="hidden" name="mfa_required" value="{{ $organisation->mfa_required ? 0 : 1 }}">
                        <button type="submit" @class([
                            'h-8 whitespace-nowrap rounded-[7px] px-3 text-[12.5px] font-medium',
                            'border border-border-2 bg-surface text-text hover:bg-row-hover' => $organisation->mfa_required,
                            'border border-primary bg-primary text-primary-fg hover:bg-primary-hover' => ! $organisation->mfa_required,
                        ])>
                            {{ $organisation->mfa_required ? 'Turn off' : 'Turn on' }}
                        </button>
                    </form>
                @else
                    <x-status-badge :tone="$organisation->mfa_required ? 'ok' : 'grey'"
                                    :label="$organisation->mfa_required ? 'Required' : 'Optional'" />
                @endif
            </div>

            <div class="grid grid-cols-2 gap-px bg-border sm:grid-cols-3">
                <div class="flex flex-col gap-1 bg-surface-2 px-4 py-3">
                    <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Sites</span>
                    <span class="text-[13px] tabular">{{ $siteCount }}</span>
                </div>
                <div class="flex flex-col gap-1 bg-surface-2 px-4 py-3">
                    <span class="font-mono text-[9.5px] uppercase tracking-[0.07em] text-text-3">Active connectors</span>
                    <span class="text-[13px] tabular">{{ $connectorCount }}</span>
                </div>
            </div>

            {{--
                Billing, for the editions that have any.

                Nothing here names a price, a plan or an edition, and it cannot: the core has no way
                to know what a hosted service charges, and a link that quoted one would go stale in a
                repository that has no way of noticing. It says what is on the other end and sends
                people there. See App\Contracts\BillingAdministration.

                On this card rather than a card of its own because billing is a fact about the
                organisation, alongside its name, its members and whether it requires a second
                factor. On Settings rather than Account because Account is per-user and this is not:
                one person's card pays for everybody.

                Owner-gated on the same idiom as every other write on this screen. Only owners hear
                about money.
            --}}
            @if ($membership->isOwner() && $billingUrl)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface-2 px-4 py-3">
                    <p class="max-w-[70ch] text-[12px] leading-relaxed text-text-2">
                        Payment, invoices and your backup storage allowance are managed on the billing
                        screen. Nothing about a card is held here.
                    </p>

                    <a href="{{ $billingUrl }}"
                       class="flex h-[34px] items-center whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text no-underline hover:bg-row-hover">
                        Manage billing
                    </a>
                </div>
            @endif
        </div>


        {{-- Retention and the schedule's zone moved to the sites themselves.

             Both were organisation-wide and both are decisions about a particular site: a busy shop
             and a brochure site do not warrant the same history, and "03:00 where the site is" means
             nothing for a fleet split between London and Sydney. Pointed at rather than silently
             gone, because somebody who set them here will come looking. --}}
        <div class="mb-3.5 rounded-[10px] border border-border bg-surface px-4 py-3.5 text-[12.5px] leading-relaxed text-text-2 shadow-[var(--shadow)]">
            <span class="font-medium text-text">Backup retention and schedule times are set per site.</span>
            How far back a site's backups are kept, and the zone its schedule reads, are on that
            site's <span class="font-medium">Backups</span> screen alongside the schedule itself.
            Every site kept whatever this organisation had, so nothing changed when they moved.

        @if ($membership->isOwner())
            <div class="overflow-hidden rounded-[10px] border border-danger-line bg-surface">
                <div class="flex items-center gap-2.5 border-b border-danger-line bg-danger-bg px-4 py-3">
                    <span class="flex h-[18px] w-[18px] items-center justify-center rounded-[5px] border border-danger-line font-mono text-[11px] text-danger">!</span>
                    <span class="text-[13.5px] font-medium text-danger">Actions that cannot be undone</span>
                </div>

                <div class="flex flex-col gap-4 p-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-[13px] font-medium">Revoke every connector</span>
                        <span class="text-[12.5px] text-text-2">
                            For when a platform credential is suspected of having leaked. All
                            {{ $connectorCount }} {{ Str::plural('connector', $connectorCount) }} stop
                            being trusted immediately, and each site needs a fresh enrolment code
                            before it reports again. Existing reports and history are untouched.
                        </span>
                    </div>

                    <form method="POST" action="{{ route('settings.connectors.rotate') }}"
                          class="flex flex-wrap items-end gap-2">
                        @csrf
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px]">Type <strong>{{ $organisation->name }}</strong> to confirm</span>
                            <input type="text" name="confirm_organisation" required autocomplete="off"
                                   class="h-[34px] w-[260px] max-w-full rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[12.5px]">
                        </label>
                        <button type="submit"
                                class="h-[34px] whitespace-nowrap rounded-[7px] border border-danger-line bg-danger-bg px-3.5 text-[12.5px] font-medium text-danger hover:border-danger">
                            Revoke all connectors
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
