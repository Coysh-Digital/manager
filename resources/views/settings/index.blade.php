@extends('layouts.app')

@section('title', 'Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Settings'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <div class="mb-5 flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Settings</h1>
            <p class="text-[13px] text-text-2">
                This installation runs on your own infrastructure. Coysh Digital has no access to it.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- The same checks manager:doctor runs. Two implementations would eventually disagree, and
             the one somebody is looking at would be the wrong one. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Platform health</span>
                <span class="font-mono text-[11px] text-text-3">php artisan manager:doctor</span>
            </div>

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
                Mail is configured in the environment and nothing about it is displayed here — not the
                host, not the port, not the sender address. Whoever can reach this screen is not
                necessarily whoever holds those credentials, and the check above already answers the
                only question this screen needs to: will a password reset arrive.

                What is offered instead is proof. "A transport is configured" and "mail leaves this
                server" are different claims, and only one of them can be tested from a button.
            --}}
            @if ($membership->isOwner())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface-2 px-4 py-3">
                    <p class="max-w-[70ch] text-[12px] leading-relaxed text-text-2">
                        Mail is configured with the <span class="font-mono text-[11.5px]">MAIL_*</span>
                        variables in <span class="font-mono text-[11.5px]">.env</span> — SMTP, Postmark,
                        Resend and SES all work out of the box. See
                        <span class="font-mono text-[11.5px]">docs/env.md</span> for the full list. Sending
                        a test proves delivery in a way a configuration check cannot.
                    </p>

                    <form method="POST" action="{{ route('settings.mail.test') }}">
                        @csrf
                        <button type="submit"
                                class="h-[34px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text hover:bg-row-hover">
                            Send a test email
                        </button>
                    </form>
                </div>
            @endif
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
        </div>

        {{--
            People.

            There was no way to add a second person to an installation before this: accounts came
            from the one-time setup flow or from a shell command, so every installation was either
            one account or an SSH session away from one.
        --}}
        <div id="people" class="mb-3.5 scroll-mt-16 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">People</span>
                <span class="text-[12.5px] text-text-2">
                    {{ $members->whereNull('revoked_at')->count() }} with access
                </span>
            </div>

            <div class="flex flex-col">
                @foreach ($members as $member)
                    @php $revoked = $member->revoked_at !== null; @endphp

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-border px-4 py-3 last:border-b-0 {{ $revoked ? 'opacity-60' : '' }}">
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[13.5px] font-medium">{{ $member->user->name }}</span>

                                @if ($revoked)
                                    <x-status-badge tone="grey" label="Access revoked" />
                                @elseif ($member->role === App\Models\Membership::ROLE_OWNER)
                                    <x-status-badge tone="info" label="Owner" />
                                @endif

                                @if ($member->user_id === auth()->id())
                                    <span class="rounded-[5px] border border-border px-1.5 py-0.5 font-mono text-[11px] text-text-3">You</span>
                                @endif

                                {{-- An account that has never signed in is usually an invitation that
                                     never arrived, and saying so is more useful than an empty column
                                     somebody has to interpret. --}}
                                @if (! $revoked && in_array($member->user->email, $awaitingPassword, true))
                                    <x-status-badge tone="warn" label="Password link outstanding" />
                                @endif
                            </div>
                            <span class="truncate font-mono text-[11.5px] text-text-3">{{ $member->user->email }}</span>
                        </div>

                        @if ($membership->isOwner() && ! $revoked)
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('team.role', $member) }}" class="flex items-center gap-2">
                                    @csrf
                                    <label class="sr-only" for="role-{{ $member->id }}">Role for {{ $member->user->name }}</label>
                                    <select name="role" id="role-{{ $member->id }}"
                                            class="h-8 rounded-[7px] border border-border-2 bg-surface px-2 text-[12.5px]">
                                        @foreach ($assignableRoles as $value => $description)
                                            <option value="{{ $value }}" @selected($member->role === $value)>{{ Str::title($value) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="h-8 rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] text-text hover:bg-row-hover">
                                        Save
                                    </button>
                                </form>

                                @if (in_array($member->user->email, $awaitingPassword, true))
                                    <form method="POST" action="{{ route('team.resend', $member) }}">
                                        @csrf
                                        <button type="submit"
                                                class="h-8 rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                                            Resend
                                        </button>
                                    </form>
                                @endif

                                @if ($member->user_id !== auth()->id())
                                    <form method="POST" action="{{ route('team.revoke', $member) }}"
                                          onsubmit="return confirm('Revoke access for {{ $member->user->email }}? Their sessions end immediately.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="h-8 rounded-[7px] border border-danger-line bg-danger-bg px-2.5 text-[12.5px] font-medium text-danger hover:border-danger">
                                            Revoke
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <span class="font-mono text-[11.5px] text-text-3">{{ Str::title($member->role) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($membership->isOwner())
                <form method="POST" action="{{ route('team.invite') }}" class="flex flex-col gap-3 border-t border-border bg-surface-2 p-4">
                    @csrf

                    <span class="text-[13px] font-medium">Invite somebody</span>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] text-text-2">Name</span>
                            <input type="text" name="name" required maxlength="120" value="{{ old('name') }}"
                                   class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                            @error('name')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] text-text-2">Email</span>
                            <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"
                                   class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 font-mono text-[12.5px]">
                            @error('email')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] text-text-2">Role</span>
                            <select name="role" class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2 text-[13px]">
                                @foreach ($assignableRoles as $value => $description)
                                    <option value="{{ $value }}" @selected(old('role', 'member') === $value)>{{ Str::title($value) }}</option>
                                @endforeach
                            </select>
                            @error('role')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <ul class="flex list-none flex-col gap-1 p-0 text-[12px] text-text-3">
                        @foreach ($assignableRoles as $description)
                            <li>{{ $description }}</li>
                        @endforeach
                    </ul>

                    {{-- Stated plainly, because it is the part people expect to work the other way.
                         An administrator who can set a colleague's password can sign in as them; one
                         who can only send a link cannot. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        No password passes through you. The account is created with a random secret
                        nobody ever sees, and they set their own through a single-use link that
                        expires — the same mechanism as a forgotten password. Until they do, the
                        account cannot be signed in to at all.
                    </p>

                    <div>
                        <button type="submit"
                                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Send invitation
                        </button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Retention and the schedule's time zone.

             Owner-level, because shortening retention decides how far back this organisation can
             recover from. The policy is rendered back as a sentence so somebody can check it against
             what they meant rather than reading three numbers. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Backup retention</div>

            <div class="p-4">
                <p class="mb-3 max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                    Currently keeping <strong>{{ $retention->describe() }}</strong>.
                </p>

                <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                    Retention is by period rather than by count, and that is deliberate. &ldquo;Keep the
                    most recent thirty&rdquo; sounds safer and is not: a site that starts producing bad
                    backups produces them nightly, each one pushing out the oldest good copy, until the
                    only backups you hold are thirty copies of the problem. The count never drops and
                    nothing looks wrong. Keeping one a week and one a month means the oldest copy you
                    have is genuinely old, from before whatever started going wrong.
                </p>
            </div>

            @if ($membership->isOwner())
                <form method="POST" action="{{ route('settings.retention') }}"
                      class="flex flex-col gap-3 border-t border-border bg-surface-2 p-4">
                    @csrf

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <label class="flex flex-1 flex-col gap-1">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Every backup for</span>
                            <div class="flex items-center gap-2">
                                <input type="number" name="backup_retention_days" min="0" max="3650" required
                                       value="{{ old('backup_retention_days', $organisation->backup_retention_days) }}"
                                       class="h-[34px] w-[90px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px] tabular">
                                <span class="text-[12.5px] text-text-2">days</span>
                            </div>
                            @error('backup_retention_days')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>

                        <label class="flex flex-1 flex-col gap-1">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Then one a week for</span>
                            <div class="flex items-center gap-2">
                                <input type="number" name="backup_retention_weeks" min="0" max="520" required
                                       value="{{ old('backup_retention_weeks', $organisation->backup_retention_weeks) }}"
                                       class="h-[34px] w-[90px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px] tabular">
                                <span class="text-[12.5px] text-text-2">weeks</span>
                            </div>
                            @error('backup_retention_weeks')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>

                        <label class="flex flex-1 flex-col gap-1">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Then one a month for</span>
                            <div class="flex items-center gap-2">
                                <input type="number" name="backup_retention_months" min="0" max="120" required
                                       value="{{ old('backup_retention_months', $organisation->backup_retention_months) }}"
                                       class="h-[34px] w-[90px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px] tabular">
                                <span class="text-[12.5px] text-text-2">months</span>
                            </div>
                            @error('backup_retention_months')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <label class="flex flex-col gap-1 sm:w-[280px]">
                        <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Time zone</span>
                        <select name="timezone"
                                class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                            @foreach ($timezones as $timezone)
                                <option value="{{ $timezone }}" @selected(old('timezone', $organisation->timezone) === $timezone)>{{ $timezone }}</option>
                            @endforeach
                        </select>
                        @error('timezone')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                    </label>

                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        The time zone is what a site's backup schedule reads, so &ldquo;03:00&rdquo;
                        means the quiet hour where your sites are rather than where this server is.
                        Changing retention governs <em>future</em> backups: each one is given an expiry
                        when it is stored, from the policy in force at that moment, so shortening this
                        does not reach back and re-date what you already have.
                    </p>

                    <div>
                        <button type="submit"
                                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Save retention
                        </button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Recovery keys.

             The screen where an organisation decides who can read its backups. Two things it must
             never do: offer to generate a key here, and imply that we could help if one is lost. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Recovery keys</span>
                <span class="font-mono text-[11px] text-text-3">manager-restore keygen</span>
            </div>

            <div class="p-4">
                @php
                    $activeKeys = $recoveryKeys->where('state', App\Models\RecoveryKey::STATE_ACTIVE);
                    $awaitingProof = $recoveryKeys->where('state', App\Models\RecoveryKey::STATE_PENDING_PROOF);
                @endphp

                @if ($activeKeys->isEmpty())
                    <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg p-3">
                        <p class="mb-1.5 text-[13px] font-medium">No backups can be taken yet</p>
                        <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                            A backup is encrypted to keys you hold and to nothing else, so until this
                            organisation has one active recovery key there is nothing to encrypt a
                            backup to, and sites will refuse to take one rather than send us a database
                            we could read.
                        </p>
                    </div>
                @elseif ($activeKeys->count() === 1)
                    <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg p-3">
                        <p class="mb-1.5 text-[13px] font-medium">One recovery key</p>
                        <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                            If it is lost, every backup encrypted to it becomes permanently unreadable.
                            We cannot recover it — we have never held the other half, which is the whole
                            point. Add a second key, kept somewhere the first is not. Every backup is
                            sealed to every active key, so a second one costs nothing.
                        </p>
                    </div>
                @endif

                @forelse ($recoveryKeys as $key)
                    <div class="flex flex-col gap-2 border-t border-border py-3 first:border-t-0 first:pt-0">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[13px] font-medium">{{ $key->label ?: 'Recovery key' }}</span>

                                    @if ($key->isActive())
                                        <x-status-badge tone="ok" label="Active" />
                                    @elseif ($key->isAwaitingProof())
                                        <x-status-badge tone="warn" label="Not proven" />
                                    @else
                                        <x-status-badge tone="grey" label="Revoked" />
                                    @endif

                                    @if ($key->isDueReproof())
                                        {{-- A key nobody has touched in six months is a key nobody can
                                             find. Nothing is disabled; this only asks. --}}
                                        <x-status-badge tone="warn" label="Confirm you still hold this" />
                                    @endif
                                </div>

                                <code class="break-all font-mono text-[12px] text-text-2">{{ $key->fingerprint }}</code>

                                <span class="text-[11.5px] text-text-3">
                                    @if ($key->isRevoked())
                                        Revoked {{ $key->revoked_at?->diffForHumans() }}
                                        @if ($key->revoked_by_label) by {{ $key->revoked_by_label }}@endif —
                                        backups taken before then still open with it.
                                    @elseif ($key->isActive())
                                        Proven {{ $key->last_proved_at?->diffForHumans() }}
                                        @if ($key->created_by_label) · added by {{ $key->created_by_label }}@endif
                                    @else
                                        Added {{ $key->created_at?->diffForHumans() }} — not yet used for anything.
                                    @endif
                                </span>
                            </div>

                            @if ($membership->isOwner() && ! $key->isRevoked())
                                <form method="POST" action="{{ route('recovery-keys.revoke', $key) }}"
                                      class="flex items-center gap-2"
                                      data-confirm="Stop using this key for new backups?">
                                    @csrf
                                    @method('DELETE')
                                    <input type="text" name="reason" required minlength="3" maxlength="255"
                                           placeholder="Reason"
                                           class="h-[30px] w-[160px] rounded-[6px] border border-border bg-surface px-2 text-[12px]">
                                    <button type="submit"
                                            class="h-[30px] rounded-[6px] border border-border px-2.5 text-[12px] text-danger hover:bg-row-hover">
                                        Revoke
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($key->isAwaitingProof() && $membership->isOwner())
                            {{-- The ceremony. Almost nothing can be checked about a public key — any 32
                                 bytes is a valid one — so the only real test is whether somebody can
                                 decrypt with the other half. It doubles as a restore rehearsal. --}}
                            <div class="rounded-lg border border-border bg-surface-2 p-3">
                                <p class="mb-2 text-[12.5px] text-text-2">
                                    Prove you hold the other half. Run this where the secret key is:
                                </p>

                                @if ($key->challenge && ! $key->challengeHasExpired() && ! $key->challengeIsExhausted())
                                    <code class="mb-3 block break-all rounded border border-border bg-surface p-2 font-mono text-[11.5px]">manager-restore prove --key=recovery-key.secret --challenge={{ $key->challenge }}</code>

                                    <form method="POST" action="{{ route('recovery-keys.prove', $key) }}"
                                          class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="text" name="proof" required maxlength="100"
                                               placeholder="MGRP-…" autocomplete="off" spellcheck="false"
                                               class="h-[32px] w-[300px] rounded-[6px] border border-border bg-surface px-2 font-mono text-[12px]">
                                        <button type="submit"
                                                class="h-[32px] rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                                            Activate
                                        </button>
                                    </form>
                                @else
                                    <p class="mb-2 text-[12.5px] text-text-3">
                                        That challenge has expired or had too many wrong answers.
                                    </p>

                                    <form method="POST" action="{{ route('recovery-keys.challenge', $key) }}">
                                        @csrf
                                        <button type="submit"
                                                class="h-[32px] rounded-[7px] border border-border px-3 text-[12.5px] hover:bg-row-hover">
                                            Issue a new challenge
                                        </button>
                                    </form>
                                @endif

                                @error('proof')<p class="mt-2 text-[12px] text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                @empty
                @endforelse

                @error('accept_last_key')
                    <p class="mt-3 text-[12px] text-danger">{{ $message }}</p>
                @enderror
            </div>

            @if ($membership->isOwner())
                <form method="POST" action="{{ route('recovery-keys.store') }}"
                      class="flex flex-col gap-3 border-t border-border bg-surface-2 p-4">
                    @csrf

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <label class="flex flex-1 flex-col gap-1">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Public key</span>
                            <textarea name="public_key" required rows="3" spellcheck="false"
                                      placeholder="-----BEGIN MANAGER RECOVERY KEY----- …"
                                      class="rounded-[7px] border border-border bg-surface px-2.5 py-2 font-mono text-[12px]">{{ old('public_key') }}</textarea>
                            @error('public_key')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>

                        <label class="flex w-full flex-col gap-1 sm:w-[180px]">
                            <span class="font-mono text-[10.5px] uppercase tracking-[0.07em] text-text-3">Label</span>
                            <input type="text" name="label" maxlength="120" placeholder="Ops laptop"
                                   value="{{ old('label') }}"
                                   class="h-[34px] rounded-[7px] border border-border bg-surface px-2.5 text-[13px]">
                            @error('label')<span class="text-[12px] text-danger">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    {{-- The instruction that carries the most weight in the whole feature, so it is on
                         the screen rather than in a document. Without pinning, this platform chooses
                         who can read your backups and nothing would look wrong if it chose itself. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        Generate the key on your own machine with
                        <code class="font-mono">manager-restore keygen</code> and paste the
                        <code class="font-mono">.pub</code> half here. We never see the other half and
                        have nowhere to put one. After activating it, add its fingerprint to
                        <code class="font-mono">config/manager-connector.php</code> on each site — that
                        pin lives on your server, and it is what stops us handing your sites a key of
                        our own.
                    </p>

                    <div>
                        <button type="submit"
                                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Register key
                        </button>
                    </div>
                </form>
            @endif
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Notifications</div>

            <div class="p-4">
                @if (session('freshSigningSecret'))
                    {{-- Shown once. A receiver needs it to verify deliveries; we have no reason to be
                         able to show it again. --}}
                    <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg p-3">
                        <p class="mb-1.5 text-[13px] font-medium">Signing secret — save this now</p>
                        <p class="mb-2 text-[12.5px] text-text-2">
                            Verify each delivery with
                            <code class="font-mono">HMAC-SHA256(timestamp + "\n" + body)</code>
                            against this secret, comparing with the <code class="font-mono">Manager-Signature</code>
                            header. Reject anything whose <code class="font-mono">Manager-Timestamp</code>
                            is not recent, or a captured delivery can be replayed at you.
                        </p>
                        <code class="block break-all rounded border border-amber-line bg-surface p-2 font-mono text-[12px]">{{ session('freshSigningSecret') }}</code>
                    </div>
                @endif

                @forelse ($destinations as $destination)
                    <div class="flex flex-col gap-2 border-t border-border py-3 first:border-t-0 first:pt-0">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-[13px] font-medium">{{ $destination->label }}</span>
                                    <span class="rounded-[5px] border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[10.5px] text-text-2">
                                        {{ $destination->transport }}
                                    </span>
                                    @if ($destination->hasFailedTooOften())
                                        {{-- Stopped rather than retried forever: the failures stay on
                                             the record, but a dead endpoint is not worth a worker. --}}
                                        <x-status-badge tone="bad" label="Stopped after repeated failures" />
                                    @elseif ($destination->consecutive_failures > 0)
                                        <x-status-badge tone="warn" :label="$destination->consecutive_failures.' failures'" />
                                    @endif
                                </div>
                                <span class="truncate font-mono text-[11.5px] text-text-3">{{ $destination->target }}</span>
                                <span class="font-mono text-[11px] text-text-3">
                                    {{ implode(', ', $destination->events) }}
                                </span>
                            </div>

                            @if ($membership->isOwner())
                                <div class="flex flex-none gap-2">
                                    <form method="POST" action="{{ route('notifications.test', $destination) }}">
                                        @csrf
                                        <button type="submit" class="h-8 rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Send a test
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('notifications.destroy', $destination) }}"
                                          onsubmit="return confirm('Remove this destination?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="h-8 rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                            Remove
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>

                        @if ($destination->deliveries->isNotEmpty())
                            <div class="flex flex-col gap-1 rounded-lg bg-surface-2 px-3 py-2">
                                @foreach ($destination->deliveries as $delivery)
                                    <span class="font-mono text-[11px] {{ $delivery->succeeded() ? 'text-text-3' : 'text-danger' }}">
                                        {{ $delivery->created_at->diffForHumans(short: true) }} ·
                                        {{ $delivery->event }} ·
                                        {{ $delivery->succeeded() ? 'sent' : ($delivery->failure_reason ?? 'failed') }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-[13px] text-text-2">
                        No destinations yet. Without one, a security finding waits for somebody to open
                        this interface and notice it.
                    </p>
                @endforelse

                @if ($membership->isOwner())
                    <form method="POST" action="{{ route('notifications.store') }}"
                          class="mt-4 flex flex-col gap-3 border-t border-border pt-4">
                        @csrf

                        <div class="flex flex-wrap items-end gap-2">
                            <label class="flex flex-col gap-1.5">
                                <span class="text-[12.5px] font-medium">Kind</span>
                                <select name="transport" class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2 text-[12.5px]">
                                    <option value="email">Email</option>
                                    <option value="webhook">Webhook</option>
                                </select>
                            </label>

                            <label class="flex flex-col gap-1.5">
                                <span class="text-[12.5px] font-medium">Label</span>
                                <input type="text" name="label" required maxlength="80" placeholder="Operations mailbox"
                                       class="h-[34px] w-[180px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] placeholder:text-text-3">
                            </label>

                            <label class="flex flex-col gap-1.5">
                                <span class="text-[12.5px] font-medium">Address or HTTPS URL</span>
                                <input type="text" name="target" required maxlength="512" placeholder="ops@example.org"
                                       class="h-[34px] w-[260px] max-w-full rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[12.5px] placeholder:text-text-3">
                            </label>
                        </div>

                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="mb-1 text-[12.5px] font-medium">Send when</legend>
                            @foreach ($eventCatalogue as $event => $description)
                                <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" class="accent-[var(--primary)]">
                                    {{ $description }}
                                </label>
                            @endforeach
                        </fieldset>

                        <button type="submit"
                                class="h-8 w-fit rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Add destination
                        </button>

                        <p class="text-[12px] text-text-3">
                            Webhooks must be HTTPS, and cannot point at a private or reserved network —
                            a notification names which site is unpatched, and a destination on an
                            internal address would make this a way to probe your own infrastructure.
                        </p>
                    </form>
                @endif
            </div>
        </div>

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
