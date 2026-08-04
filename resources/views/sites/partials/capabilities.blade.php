{{--
    What Manager is permitted to do on this site.

    Was a screen of its own at sites/{site}/capabilities. It is settings, so it is a section of
    Settings; the old route still resolves and redirects here.

    Every capability the platform defines appears, granted or not. A list of only the granted ones
    answers the less useful half of the question - what is *not* permitted is usually what somebody
    came to check.
--}}
<div class="flex flex-col gap-3">
    @foreach ($capabilities as $capability)
        <div class="overflow-hidden rounded-[10px] border border-border bg-surface">
            <div class="flex flex-wrap items-start gap-4 border-b border-border p-4">
                <div class="flex flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="text-[14px] font-medium {{ $capability['granted'] ? '' : 'text-text-2' }}">
                            {{ __('capabilities.'.$capability['name'].'.title') }}
                        </span>

                        @if ($capability['granted'])
                            <x-status-badge tone="ok" label="Enabled" />
                        @else
                            <x-status-badge tone="grey" label="Not granted" />
                        @endif

                        @if ($capability['readOnly'])
                            <span class="rounded-[5px] border border-border px-1.5 py-0.5 font-mono text-[11px] text-text-2">Read-only</span>
                        @else
                            <span class="rounded-[5px] border border-danger-line bg-danger-bg px-1.5 py-0.5 font-mono text-[11px] text-danger">Modifies the site</span>
                        @endif

                        @unless ($capability['grantable'])
                            {{-- Not offered as a switch. Either the connector cannot do it yet, or it
                                 modifies the site and needs its own confirmation flow - a toggle
                                 beside the read switches would understate what granting it means. --}}
                            <span class="rounded-[5px] border border-info-line bg-info-bg px-1.5 py-0.5 font-mono text-[11px] text-info">
                                {{ $capability['readOnly'] ? 'Not yet available' : 'Needs separate confirmation' }}
                            </span>
                        @endunless
                    </div>

                    <span class="text-[13px] text-text-2">{{ __('capabilities.'.$capability['name'].'.description') }}</span>
                </div>

                @if ($capability['granted'])
                    <form method="POST" action="{{ route('sites.capabilities.revoke', $site) }}"
                          onsubmit="return confirm('Revoke {{ $capability['name'] }}? The site can no longer perform it.');">
                        @csrf
                        <input type="hidden" name="capability" value="{{ $capability['name'] }}">
                        <button type="submit"
                                class="h-8 whitespace-nowrap rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
                            Revoke
                        </button>
                    </form>
                @elseif ($capability['grantable'] && $connector)
                    <form method="POST" action="{{ route('sites.capabilities.grant', $site) }}">
                        @csrf
                        <input type="hidden" name="capability" value="{{ $capability['name'] }}">
                        <button type="submit"
                                class="h-8 whitespace-nowrap rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Grant
                        </button>
                    </form>
                @endif
            </div>

            @if ($capability['confirmable'] && ! $capability['granted'] && $connector)
                {{-- Deliberately not a switch. Granting this authorises a copy of every user record on
                     the site, so it asks for the site's name, an acknowledgement and a reason - and
                     says what it will do before it asks. --}}
                <form method="POST" action="{{ route('capabilities.grant-confirmed', $site) }}"
                      class="flex flex-col gap-3 border-b border-border bg-surface-2 p-4">
                    @csrf
                    <input type="hidden" name="capability" value="{{ $capability['name'] }}">

                    <label class="flex items-start gap-2.5 text-[12.5px] leading-relaxed text-text-2">
                        <input type="checkbox" name="acknowledge" value="1" required
                               class="mt-0.5 flex-none accent-[var(--primary)]">
                        <span>{{ $capability['acknowledgement'] }}</span>
                    </label>

                    <div class="flex flex-wrap items-end gap-2">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Type the site's name</span>
                            <input type="text" name="confirm_site" required autocomplete="off"
                                   placeholder="{{ $site->name }}"
                                   class="h-[34px] w-[200px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] placeholder:text-text-3">
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px] font-medium">Why</span>
                            <input type="text" name="reason" required maxlength="255"
                                   placeholder="Nightly backups before the platform migration"
                                   class="h-[34px] w-[300px] max-w-full rounded-[7px] border border-border-2 bg-surface px-2.5 text-[12.5px] placeholder:text-text-3">
                        </label>

                        <button type="submit"
                                class="h-[34px] whitespace-nowrap rounded-[7px] border border-primary bg-primary px-3 text-[12.5px] font-medium text-primary-fg hover:bg-primary-hover">
                            Grant {{ $capability['name'] }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($capability['grant'])
                @php
                    $changedAt = $capability['granted']
                        ? $capability['grant']->granted_at
                        : $capability['grant']->revoked_at;
                @endphp

                {{-- One line. Empty values say nothing at all, which is what an empty value means. --}}
                @if ($changedAt || $capability['grant']->reason)
                    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 bg-surface-2 px-4 py-2.5 text-[12.5px] text-text-2">
                        @if ($changedAt)
                            <span>{{ $capability['granted'] ? 'Granted' : 'Revoked' }} {{ $changedAt->diffForHumans() }}</span>
                        @endif
                        @if ($changedAt && $capability['grant']->reason)
                            <span aria-hidden="true" class="text-text-3">·</span>
                        @endif
                        @if ($capability['grant']->reason)
                            <span>{{ $capability['grant']->reason }}</span>
                        @endif
                    </p>
                @endif
            @endif
        </div>
    @endforeach
</div>

<details class="group mt-3">
    <summary class="cursor-pointer list-none text-[12.5px] text-text-2 hover:text-text">
        <span class="group-open:hidden">Show permission history</span>
        <span class="hidden group-open:inline">Hide permission history</span>
    </summary>

    <div class="mt-2.5 overflow-hidden rounded-[10px] border border-border bg-surface">
        @forelse ($history as $event)
            <div class="grid grid-cols-[130px_1fr_150px] items-center gap-4 border-b border-border px-4 py-2.5 text-[12.5px] last:border-b-0">
                <span class="font-mono text-[11.5px] text-text-3">{{ $event->created_at->diffForHumans(short: true) }}</span>
                <span>
                    <code class="font-mono text-[12px]">{{ $event->capability }}</code>
                    {{ $event->previous_state ? Str::of($event->previous_state)->append(' → ') : '' }}{{ $event->new_state }}
                    @if ($event->reason)
                        <span class="text-text-2">— {{ $event->reason }}</span>
                    @endif
                </span>
                <span class="text-text-2">{{ $event->actor_label ?? 'System' }}</span>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-[13px] text-text-2">No capability changes recorded.</p>
        @endforelse
    </div>
</details>
