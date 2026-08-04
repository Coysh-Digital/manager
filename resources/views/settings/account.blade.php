@extends('layouts.app')

@section('title', 'Account · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('Account'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header :subtitle="$user->name.' · '.$user->email.' · '.$organisation->name" />

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
                    worth keeping current. The email address cannot be changed here - it is how you
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
                             reads times in the installation's own zone, and should be able to go
                             back to that having once set their own. --}}
                        <option value="" @selected(old('timezone', $user->timezone) === null || old('timezone', $user->timezone) === '')>
                            Use this installation's ({{ $defaultTimezone }})
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
                    times are printed - audit entries, when a backup was taken, when a site fell
                    silent - and nothing about when anything runs: a backup schedule reads the
                    zone set on that site, so moving this will not move the hour any site is backed
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
                    At least twelve characters, and checked against known breach corpora - the same
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
    </div>
@endsection
