{{--
    Failed sign-ins to the site's control panel.

    Counts, and the caveat that makes them honest. Craft resets an account's failed-attempt counter
    on a successful sign-in, so an attacker who eventually gets in erases their own tally - which
    means a reassuring zero is exactly the number that deserves the footnote.

    What is not here is the design rather than an omission: no username, no email address, no source
    address, no per-attempt record. The schema has nowhere to put any of them.
--}}
<div class="overflow-hidden rounded-[10px] border border-border bg-surface">
    @if (! $site->hasCapability('logins:read'))
        <p class="px-4 py-6 text-center text-[13px] text-text-2">
            Sign-in counts need <code class="font-mono">logins:read</code>, which this site has not been
            granted.
            <a href="{{ route('sites.settings', $site) }}#capabilities" class="text-primary hover:text-primary-hover">Grant it</a>
            - it reports counts only, never a username or an address.
        </p>
    @elseif ($loginReport === null)
        <p class="px-4 py-6 text-center text-[13px] text-text-2">
            Granted, but the site has not reported yet. Counters arrive within half an hour of the
            connector's next check-in.
        </p>
    @else
        <div class="flex flex-wrap items-center gap-x-10 gap-y-4 border-b border-border px-4 py-3.5">
            <div class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">
                    Failed attempts · last {{ $loginReport->window_hours }}h
                </span>
                <div class="flex items-center gap-2.5">
                    <span class="text-[20px] font-semibold tabular tracking-[-0.01em] {{ $loginReport->isNotable() ? 'text-amber' : '' }}">
                        {{ number_format($loginReport->failed_attempts) }}
                    </span>
                    @if ($loginReport->accounts_locked > 0)
                        {{-- The number that most often needs a person: somebody cannot work right now. --}}
                        <x-status-badge tone="bad" :label="$loginReport->accounts_locked.' locked out'" />
                    @elseif ($loginReport->admin_accounts_affected > 0)
                        <x-status-badge tone="warn" label="Administrator targeted" />
                    @elseif ($loginReport->failed_attempts === 0)
                        <x-status-badge tone="ok" label="Nothing recorded" />
                    @endif
                </div>
            </div>

            @php
                $signInFigures = [
                    'Accounts affected' => $loginReport->accounts_with_failures,
                    'Administrators' => $loginReport->admin_accounts_affected,
                    'Locked out' => $loginReport->accounts_locked,
                    'Last failure' => $loginReport->last_failure_at?->diffForHumans(short: true) ?? 'none',
                ];
            @endphp

            @foreach ($signInFigures as $label => $value)
                <div class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.07em] text-text-3">{{ $label }}</span>
                    <span class="font-mono text-[13px] tabular">{{ $value }}</span>
                </div>
            @endforeach
        </div>

        <p class="bg-surface-2 px-3.5 py-2.5 text-[12px] leading-relaxed text-text-3">
            Counts only - never a username, an email address or the address anyone connected from.
            <strong>These are a floor, not a total:</strong> Craft resets an account's counter on a
            successful sign-in, so somebody who guessed correctly on the twentieth attempt leaves
            nothing behind here. Reported
            {{ $loginReport->received_at->diffForHumans() }}.
        </p>
    @endif
</div>
