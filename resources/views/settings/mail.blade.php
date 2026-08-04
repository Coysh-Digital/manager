@extends('layouts.app')

@section('title', 'Mail · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('Mail'))

@section('content')
    @php
        // What the form starts from. A stored configuration if there is one, otherwise whatever the
        // environment holds - so a first visit shows the settings already in force rather than an
        // empty form that would silently replace them on save. Never the password, from either.
        $transport = old('transport', $settings->transport ?? $environment['transport']);
        $host = old('host', $settings->host ?? $environment['host']);
        $port = old('port', $settings->port ?? $environment['port']);
        $username = old('username', $settings->username ?? $environment['username']);
        $encryption = old('encryption', $settings->encryption ?? '');
        $region = old('region', $settings->region ?? '');
        $fromAddress = old('from_address', $settings->from_address ?? $environment['from_address']);
        $fromName = old('from_name', $settings->from_name ?? $environment['from_name']);

        $labels = [
            'smtp' => 'SMTP',
            'postmark' => 'Postmark',
            'resend' => 'Resend',
            'ses' => 'Amazon SES',
            'log' => 'Log file (sends nothing)',
        ];

        $field = 'h-[34px] w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[13px]';
        $label = 'text-[12.5px] font-medium';
    @endphp

    <div class="mx-auto max-w-[900px]">
        <x-settings-header />

        {{--
            Standing, not a flash message.

            "The configuration validates" and "mail leaves this server" are different claims, and
            only one of them can be tested from a button. Until somebody has pressed it, the honest
            state of this screen is that nobody knows - and the moment that matters is the one where
            a password reset is the only way back in.
        --}}
        @if ($settings && $settings->last_test_outcome !== App\Models\MailSetting::OUTCOME_SUCCESS)
            <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg px-3.5 py-2.5 text-[12.5px] leading-relaxed text-text-2">
                <span class="font-medium text-text">This configuration has not been proved to send.</span>
                @if ($settings->last_test_outcome === App\Models\MailSetting::OUTCOME_FAILURE)
                    The last test failed {{ $settings->last_tested_at?->diffForHumans() }}.
                @endif
                Send a test below, or discard it and go back to the environment.
            </div>
        @endif

        {{-- What is actually in force, before the form that changes it. Where a setting comes from is
             the question somebody arrives with, and it is not answerable from a filled-in field. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-border px-4 py-3">
                <span class="text-[13.5px] font-medium">In force now</span>
                <span class="font-mono text-[11px] text-text-3">
                    {{ $settings ? 'set on this screen' : 'from the environment' }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-px bg-border sm:grid-cols-2">
                <div class="flex flex-col gap-1 bg-surface px-4 py-3">
                    <span class="text-[13px] font-medium">Transport</span>
                    <span class="font-mono text-[11.5px] text-text-3">
                        {{ $labels[$settings->transport ?? $environment['transport']] ?? ($settings->transport ?? $environment['transport']) }}
                    </span>
                </div>

                <div class="flex flex-col gap-1 bg-surface px-4 py-3">
                    <span class="text-[13px] font-medium">From address</span>
                    <span class="font-mono text-[11.5px] text-text-3">
                        {{ $settings->from_address ?? $environment['from_address'] ?? 'not set' }}
                    </span>
                </div>
            </div>

            <p class="border-t border-border bg-surface-2 px-4 py-2.5 text-[12px] leading-relaxed text-text-3">
                Saving here overrides the <span class="font-mono text-[11.5px]">MAIL_*</span> variables
                the server was started with. The environment is never written to, so discarding this
                puts it straight back - which is the way out if a change stops mail working and mail
                is how you would normally be told.
            </p>
        </div>

        <form method="POST" action="{{ route('settings.mail.update') }}"
              class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            @csrf

            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">How mail is sent</div>

            <div class="flex flex-col gap-4 p-4">
                <label class="flex flex-col gap-1.5 sm:w-[320px]">
                    <span class="{{ $label }}">Transport</span>
                    <select name="transport" data-mail-transport class="{{ $field }}">
                        @foreach ($transports as $option)
                            @php $missing = App\Models\MailSetting::missingPackage($option); @endphp

                            {{-- Offered but refused, rather than quietly absent. config/mail.php has
                                 listed these since it was generated, so somebody who has already set
                                 MAIL_MAILER=postmark should find out here why it never sent, rather
                                 than wonder where the option went. --}}
                            <option value="{{ $option }}" @selected($transport === $option) @disabled($missing)>
                                {{ $labels[$option] ?? $option }}@if ($missing) - needs {{ $missing }} @endif
                            </option>
                        @endforeach
                    </select>
                    @error('transport')
                        <span class="text-[12px] text-danger">{{ $message }}</span>
                    @enderror
                </label>

                {{-- Every fieldset is rendered and the script below hides the ones that do not apply.
                     Without JavaScript they are all visible and the server-side rules still decide,
                     so the form degrades to something longer rather than to something broken. --}}
                <fieldset data-mail-fields="smtp" class="flex flex-col gap-4 border-t border-border pt-4">
                    <legend class="sr-only">SMTP relay</legend>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <label class="flex flex-col gap-1.5 sm:col-span-2">
                            <span class="{{ $label }}">Host</span>
                            <input type="text" name="host" maxlength="255" autocomplete="off"
                                   value="{{ $host }}" placeholder="smtp.your-relay.example"
                                   class="{{ $field }}">
                            @error('host')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="{{ $label }}">Port</span>
                            <input type="number" name="port" min="1" max="65535" value="{{ $port }}"
                                   placeholder="587" class="{{ $field }}">
                            @error('port')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    <label class="flex flex-col gap-1.5 sm:w-[320px]">
                        <span class="{{ $label }}">Encryption</span>
                        <select name="encryption" class="{{ $field }}">
                            {{-- "Let the port decide" is the framework's own default and the right
                                 answer for somebody who has not thought about it: 465 implies implicit
                                 TLS, everything else negotiates STARTTLS. --}}
                            <option value="" @selected($encryption === '')>Let the port decide</option>
                            <option value="tls" @selected($encryption === 'tls')>STARTTLS (usually port 587)</option>
                            <option value="ssl" @selected($encryption === 'ssl')>Implicit TLS (usually port 465)</option>
                        </select>
                        @error('encryption')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>
                </fieldset>

                <fieldset data-mail-fields="ses" class="flex flex-col gap-4 border-t border-border pt-4">
                    <legend class="sr-only">Amazon SES</legend>

                    <label class="flex flex-col gap-1.5 sm:w-[320px]">
                        <span class="{{ $label }}">Region</span>
                        <input type="text" name="region" maxlength="32" autocomplete="off"
                               value="{{ $region }}" placeholder="eu-west-1" class="{{ $field }}">
                        @error('region')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>
                </fieldset>

                {{--
                    The login and the secret, once, with labels the script rewrites per transport.

                    Not one pair per fieldset. Two inputs called `username` in one form is a bug
                    waiting for somebody to turn JavaScript off: both submit, the second wins, and
                    which one that is depends on the order they happen to be written in.
                --}}
                <div class="flex flex-col gap-4 border-t border-border pt-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5" data-mail-fields-except="postmark,resend,log">
                            <span class="{{ $label }}" data-mail-login-label>Username</span>
                            <input type="text" name="username" maxlength="255" autocomplete="off"
                                   value="{{ $username }}" class="{{ $field }}">
                            @error('username')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="{{ $label }}" data-mail-secret-label>Password</span>
                            <input type="password" name="password" maxlength="512" autocomplete="new-password"
                                   class="{{ $field }}">
                            @error('password')
                                <span class="text-[12px] text-danger">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    {{-- Write-only, and this is why the old rule survives the screen it was written
                         against. The stored value is never rendered back into the form, so there is
                         nothing here for a page source to give away - and an untouched password input
                         arrives empty, which is what makes "leave blank to keep" free. --}}
                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        @if ($settings?->password)
                            A credential is stored. Leave this blank to keep it - it is never shown
                            again, here or anywhere else.
                        @else
                            Stored encrypted, and never displayed again once saved.
                        @endif
                    </p>

                    @if ($settings?->password)
                        <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                            <input type="checkbox" name="clear_password" value="1" class="h-3.5 w-3.5">
                            Remove the stored credential
                        </label>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-4 border-t border-border pt-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5">
                        <span class="{{ $label }}">From address</span>
                        <input type="email" name="from_address" required maxlength="255"
                               value="{{ $fromAddress }}" placeholder="manager@yourdomain.com"
                               class="{{ $field }}">
                        @error('from_address')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="{{ $label }}">From name</span>
                        <input type="text" name="from_name" required maxlength="120"
                               value="{{ $fromName }}" placeholder="Manager" class="{{ $field }}">
                        @error('from_name')
                            <span class="text-[12px] text-danger">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-border bg-surface-2 px-4 py-3">
                <button type="submit"
                        class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                    Save
                </button>

                <span class="text-[12px] text-text-3">Saving does not send anything. The button beside it does.</span>
            </div>
        </form>

        <div class="mb-3.5 flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-border bg-surface px-4 py-3.5 shadow-[var(--shadow)]">
            <p class="max-w-[70ch] text-[12px] leading-relaxed text-text-2">
                A test goes to <span class="font-mono text-[11.5px]">{{ auth()->user()->email }}</span>
                and nowhere else - there is no destination field, because a form that sends mail from
                this relay to any address anybody types is an open relay with extra steps. It is sent
                immediately rather than queued: a queued send proves the queue works.
            </p>

            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('settings.mail.test') }}">
                    @csrf
                    <button type="submit"
                            class="h-[34px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] font-medium text-text hover:bg-row-hover">
                        Send a test email
                    </button>
                </form>

                @if ($settings)
                    <form method="POST" action="{{ route('settings.mail.forget') }}"
                          onsubmit="return confirm('Discard these settings and use the environment configuration instead?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="h-[34px] whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3.5 text-[12.5px] text-text-2 hover:bg-row-hover hover:text-text">
                            Use the environment configuration
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{--
            The part that decides whether any of the above matters.

            A correctly configured relay and a message in a spam folder look identical from this
            screen, and the message people notice missing is the password reset - which arrives at
            the moment somebody is already locked out and least inclined to trust us.
        --}}
        <div class="overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Deliverability checklist</div>

            <div class="flex flex-col gap-4 p-4 text-[13px]">
                <p class="max-w-[80ch] text-text-2">
                    Transactional mail lands in inboxes only when the sending domain is properly
                    authenticated. Work through the items below before going live.
                </p>

                <div class="flex flex-col gap-1 border-t border-border pt-4">
                    <span class="text-[13px] font-medium">SPF record</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        Add a TXT record to your domain DNS authorising your relay IP or hostname. For
                        example: <span class="font-mono text-[11.5px]">v=spf1 include:_spf.your-relay.example.com ~all</span>.
                        Without SPF, many providers will soft-fail or reject your mail.
                    </p>
                </div>

                <div class="flex flex-col gap-1 border-t border-border pt-4">
                    <span class="text-[13px] font-medium">DKIM signature</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        Enable DKIM on your relay and publish the public key as a TXT record under
                        <span class="font-mono text-[11.5px]">selector._domainkey.yourdomain.com</span>.
                        DKIM proves the message body was not altered in transit and is required by the
                        major mailbox providers.
                    </p>
                </div>

                <div class="flex flex-col gap-1 border-t border-border pt-4">
                    <span class="text-[13px] font-medium">DMARC policy</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        Publish a DMARC TXT record at
                        <span class="font-mono text-[11.5px]">_dmarc.yourdomain.com</span> telling
                        receivers what to do when SPF or DKIM fails. Start with
                        <span class="font-mono text-[11.5px]">p=none</span> to monitor, then move to
                        <span class="font-mono text-[11.5px]">p=quarantine</span> or
                        <span class="font-mono text-[11.5px]">p=reject</span> once you are confident
                        nothing legitimate will fail.
                    </p>
                </div>

                <div class="flex flex-col gap-1 border-t border-border pt-4">
                    <span class="text-[13px] font-medium">Reverse DNS / PTR record</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        Make sure the sending IP has a PTR record that resolves to a hostname matching
                        its forward A record. Many mail servers reject or heavily penalise mail from IPs
                        with no PTR. Ask your hosting provider or VPS control panel to set one.
                    </p>
                </div>

                <div class="flex flex-col gap-1 border-t border-border pt-4">
                    <span class="text-[13px] font-medium">From-address alignment</span>
                    <p class="max-w-[80ch] text-[12.5px] leading-relaxed text-text-2">
                        The "From address" you set above should be on the same domain as your SPF and
                        DKIM records. Misaligned From domains cause DMARC failures even when SPF and
                        DKIM individually pass. Password-reset links are particularly sensitive because
                        they arrive at the user's inbox directly and any spam classification damages
                        trust.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Progressive enhancement, like the theme switcher in the topbar: without this every fieldset
         is visible and the form still works, because the per-transport rules are enforced on the
         server. --}}
    <script>
        (function () {
            var select = document.querySelector('[data-mail-transport]');
            if (!select) return;

            var secretLabel = document.querySelector('[data-mail-secret-label]');
            var loginLabel = document.querySelector('[data-mail-login-label]');
            var secrets = { postmark: 'Server token', resend: 'API key', ses: 'Secret access key' };
            var logins = { ses: 'Access key ID' };

            function sync() {
                document.querySelectorAll('[data-mail-fields]').forEach(function (group) {
                    group.hidden = group.dataset.mailFields !== select.value;
                });

                document.querySelectorAll('[data-mail-fields-except]').forEach(function (group) {
                    group.hidden = group.dataset.mailFieldsExcept.split(',').indexOf(select.value) !== -1;
                });

                if (secretLabel) secretLabel.textContent = secrets[select.value] || 'Password';
                if (loginLabel) loginLabel.textContent = logins[select.value] || 'Username';
            }

            select.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
