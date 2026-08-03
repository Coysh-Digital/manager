@extends('layouts.app')

@section('title', 'Account and security · Manager for Craft')
@section('crumb', App\Support\Crumbs::top('Account and security'))

@section('content')
    <div class="mx-auto max-w-[820px]">
        <div class="mb-5 flex flex-col gap-1.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Account and security</h1>
            <p class="text-[13px] text-text-2">{{ $user->name }} · {{ $user->email }} · {{ $organisation->name }}</p>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Shown once, on the way past. There is no way to retrieve them again by design. --}}
        @if ($freshRecoveryCodes)
            <div class="mb-4 rounded-[10px] border border-amber-line bg-amber-bg p-4">
                <p class="mb-2 text-[13.5px] font-medium text-text">Save these recovery codes now</p>
                <p class="mb-3 text-[13px] text-text-2">
                    Each works once, and they are the only way back in if you lose your authenticator.
                    They will not be shown again — generating a new set replaces them.
                </p>
                <div class="grid grid-cols-2 gap-1.5 rounded-lg border border-amber-line bg-surface p-3 font-mono text-[13px] tracking-[0.05em] sm:grid-cols-3">
                    @foreach ($freshRecoveryCodes as $code)
                        <span>{{ $code }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Your details</div>

            <form method="POST" action="{{ route('account.profile') }}" class="flex flex-col gap-4 p-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Name</span>
                        <input type="text" name="name" required maxlength="191" autocomplete="name"
                               value="{{ old('name', $user->name) }}"
                               class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                        @error('name')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    {{-- Shown, and not editable. Disabled rather than hidden: somebody looking for
                         where to change it should find the answer here rather than conclude the
                         screen is incomplete. --}}
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Email address</span>
                        <input type="email" value="{{ $user->email }}" disabled
                               class="h-[34px] w-full cursor-not-allowed rounded-[7px] border border-border bg-surface-2 px-2.5 text-[13px] text-text-3">
                    </label>
                </div>

                <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                    Your name appears beside everything this account does in the audit log, so it is
                    worth keeping current. The email address cannot be changed here — it is how you
                    sign in, how a password reset reaches you, and what every audit entry already
                    written is filed against, so moving it is an account recovery process rather than a
                    field. An owner can invite a new account and revoke this one.
                </p>

                <div>
                    <button type="submit"
                            class="h-[34px] rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text hover:bg-row-hover">
                        Save
                    </button>
                </div>
            </form>
        </div>

        {{--
            The clock every absolute time on every screen is printed in.

            Its own form rather than a third field on the one above, and that is deliberate rather
            than tidy: AccountController::updateProfile validates the name and nothing else, the
            hosted edition documents relying on exactly that, and widening it to carry a preference
            would put a security assumption behind a convenience feature.
        --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Time zone</div>

            <form method="POST" action="{{ route('account.timezone') }}" class="flex flex-col gap-4 p-4">
                @csrf

                <label class="flex flex-col gap-1.5 sm:w-[320px]">
                    <span class="text-[12.5px] font-medium">Show times in</span>
                    <select name="timezone"
                            class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                        {{-- Blank is a real answer, not a prompt. Somebody who has never set this
                             should read times in the organisation's zone, and should be able to go
                             back to that having once set their own. --}}
                        <option value="" @selected(old('timezone', $user->timezone) === null || old('timezone', $user->timezone) === '')>
                            Use the organisation's ({{ $organisationTimezone }})
                        </option>

                        @foreach ($timezones as $timezone)
                            <option value="{{ $timezone }}" @selected(old('timezone', $user->timezone) === $timezone)>{{ $timezone }}</option>
                        @endforeach
                    </select>
                    @error('timezone')
                        <span class="text-[12px] text-danger">{{ $message }}</span>
                    @enderror
                </label>

                <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                    This is yours alone and changes nothing anybody else sees. It decides how dated
                    times are printed — audit entries, when a backup was taken, when a site fell
                    silent — and nothing about when anything runs: a backup schedule reads the
                    organisation's zone, so moving this will not move the hour your sites are backed
                    up at.
                </p>

                <div>
                    <button type="submit"
                            class="h-[34px] rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text hover:bg-row-hover">
                        Save
                    </button>
                </div>
            </form>
        </div>

        {{--
            Then the password: it is the thing people come here to change, and it was the one thing
            this screen could not do. Before this the only routes to it were the forgotten-password
            email or a shell command.
        --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Password</span>
                {{-- When it last changed is in the audit log, under user.password.changed, rather
                     than denormalised onto the account for a label. --}}
                <a href="{{ route('activity.index') }}" class="text-[12px] text-text-3 hover:text-primary">History</a>
            </div>

            <form method="POST" action="{{ route('account.password') }}" class="flex flex-col gap-4 p-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Current password</span>
                        <input type="password" name="current_password" required autocomplete="current-password"
                               class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                        @error('current_password')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">New password</span>
                        <input type="password" name="password" required autocomplete="new-password" minlength="12"
                               class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                        @error('password')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Confirm new password</span>
                        <input type="password" name="password_confirmation" required autocomplete="new-password" minlength="12"
                               class="h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]">
                    </label>
                </div>

                {{-- Said before it happens rather than discovered after. --}}
                <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                    At least twelve characters, and checked against known breach corpora — the same
                    rule a password reset applies, because two policies for one secret is one policy
                    and one loophole. Changing it signs out every other session on this account, which
                    is the point if you are changing it because something felt wrong.
                </p>

                <div>
                    <button type="submit"
                            class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                        Change password
                    </button>
                </div>
            </form>
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Authenticator app</span>
                @if ($user->hasConfirmedTotp())
                    <x-status-badge tone="ok" label="On" />
                @else
                    <x-status-badge tone="warn" label="Off" />
                @endif
            </div>

            <div class="p-4">
                @if ($user->hasConfirmedTotp())
                    <p class="mb-3 text-[13px] text-text-2">
                        Enabled {{ $user->totp_confirmed_at->diffForHumans() }}.
                        You have <strong>{{ $recoveryCodesRemaining }}</strong>
                        unused {{ Str::plural('recovery code', $recoveryCodesRemaining) }}.
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('account.recovery-codes') }}">
                            @csrf
                            <button type="submit" class="h-8 rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                Generate new recovery codes
                            </button>
                        </form>

                        @unless ($organisation->mfa_required)
                            <form method="POST" action="{{ route('account.totp.disable') }}"
                                  onsubmit="return confirm('Turn off two-factor authentication? Your recovery codes will be deleted too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="h-8 rounded-[7px] border border-danger-line bg-danger-bg px-3 text-[12.5px] font-medium text-danger hover:border-danger">
                                    Turn off
                                </button>
                            </form>
                        @endunless
                    </div>

                    @if ($organisation->mfa_required)
                        <p class="mt-3 text-[12.5px] text-text-3">
                            This organisation requires two-factor authentication, so it cannot be turned off.
                        </p>
                    @endif
                @elseif ($pendingSecret)
                    <p class="mb-3 text-[13px] text-text-2">
                        Scan this with your authenticator app, then enter the six-digit code it shows.
                        Nothing is saved until a valid code proves your device has the secret.
                    </p>

                    <div class="mb-4 flex flex-wrap items-start gap-5">
                        <div class="rounded-lg border border-border bg-white p-2">{!! $pendingQrCode !!}</div>
                        <div class="flex flex-col gap-1.5">
                            <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">Or enter by hand</span>
                            <code class="font-mono text-[13px] tracking-[0.08em]">{{ $pendingManualEntry }}</code>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('account.totp.confirm') }}" class="flex items-end gap-2">
                        @csrf
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Six-digit code</span>
                            <input type="text" name="code" required autocomplete="one-time-code" inputmode="numeric"
                                   class="h-[34px] w-[140px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[13px] tracking-[0.15em]">
                        </label>
                        <button type="submit" class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover">
                            Confirm
                        </button>
                    </form>
                @else
                    <p class="mb-3 text-[13px] text-text-2">
                        A password alone protects a control plane that can read every site you manage.
                        Add an authenticator app.
                    </p>
                    <form method="POST" action="{{ route('account.totp.start') }}">
                        @csrf
                        <button type="submit" class="h-8 rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Set up two-factor authentication
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">Passkeys</span>
                @if ($passkeys->isNotEmpty())
                    <x-status-badge tone="ok" :label="$passkeys->count().' registered'" />
                @endif
            </div>

            <div class="p-4">
                <p class="mb-3 text-[13px] text-text-2">
                    A passkey is bound to this site's address, so it cannot be used on a convincing
                    copy of it — which a typed code can. It counts as your second factor.
                </p>

                @forelse ($passkeys as $passkey)
                    <div class="flex items-center justify-between gap-4 border-t border-border py-3 text-[12.5px] first:border-t-0">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="font-medium">{{ $passkey->name ?: 'Passkey' }}</span>
                            <span class="font-mono text-[11.5px] text-text-3">
                                added {{ $passkey->created_at?->diffForHumans() ?? 'recently' }}
                                {{-- The authenticator model, where it identified itself. Worth showing:
                                     it is how somebody tells two passkeys apart when the labels are
                                     unhelpful, and how they spot one they do not recognise. --}}
                                @if ($passkey->authenticator)
                                    · {{ $passkey->authenticator }}
                                @endif
                                · {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'never used' }}
                            </span>
                        </div>

                        <form method="POST" action="{{ route('passkeys.destroy', $passkey->id) }}"
                              onsubmit="return confirm('Remove this passkey?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                Remove
                            </button>
                        </form>
                    </div>
                @empty
                    <p class="mb-3 text-[12.5px] text-text-3">No passkeys registered yet.</p>
                @endforelse

                <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px] font-medium">Name this device</span>
                        <input type="text" data-passkey-name maxlength="60" placeholder="Work laptop"
                               class="h-8 w-[200px] max-w-full rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[12.5px] placeholder:text-text-3">
                    </label>

                    <button type="button"
                            data-passkey-register
                            data-options-url="{{ route('passkeys.options') }}"
                            data-register-url="{{ route('passkeys.store') }}"
                            class="h-8 rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-60">
                        Add a passkey
                    </button>
                </div>

                <p data-passkey-message class="mt-2 text-[12.5px] text-text-2 data-[state=error]:text-danger"></p>
            </div>
        </div>

        <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Where you are signed in</div>

            @foreach ($sessions as $session)
                <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-3 text-[12.5px] last:border-b-0">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="truncate">{{ $session->user_agent ?: 'Unknown device' }}</span>
                        <span class="font-mono text-[11.5px] text-text-3">
                            {{ $session->ip_address ?? 'unknown address' }} · active {{ $session->last_active->diffForHumans() }}
                        </span>
                    </div>

                    @if ($session->is_current)
                        <x-status-badge tone="info" label="This device" />
                    @else
                        <form method="POST" action="{{ route('account.sessions.revoke', $session->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                                Sign out
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endsection
