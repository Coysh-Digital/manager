@extends('layouts.app')

@section('title', 'Notifications · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('Notifications'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header />

        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="border-b border-border px-4 py-3 text-[13.5px] font-medium">Notifications</div>

            <div class="p-4">
                @if (session('freshSigningSecret'))
                    {{-- Shown once. A receiver needs it to verify deliveries; we have no reason to be
                         able to show it again. --}}
                    <div class="mb-4 rounded-lg border border-amber-line bg-amber-bg p-3">
                        <p class="mb-1.5 text-[13px] font-medium">Signing secret - save this now</p>
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

                                {{-- Which sites, stated on every destination rather than only on the
                                     narrowed ones. "All sites" is a decision somebody made and the
                                     one worth seeing beside a target that goes to a client. --}}
                                <span class="text-[11.5px] text-text-3">
                                    @if ($destination->sites->isEmpty())
                                        All sites, including any added later
                                    @else
                                        {{ $destination->sites->pluck('name')->join(', ') }}
                                    @endif
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

                        {{--
                            Which sites this destination is for.

                            "All sites" is the default and writes nothing, so a destination created
                            without touching this behaves exactly as every destination did before
                            scoping existed - including for sites added afterwards, which is the
                            behaviour somebody who never opened this control expects to keep.

                            A radio pair rather than "leave empty for all". An empty checkbox list
                            reading as "everything" is the kind of control people get wrong in the
                            direction that tells one client about another.
                        --}}
                        <fieldset class="flex flex-col gap-1.5 border-t border-border pt-3">
                            <legend class="mb-1 text-[12.5px] font-medium">For which sites</legend>

                            <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                                <input type="radio" name="scope" value="all"
                                       @checked(old('scope', 'all') === 'all')
                                       class="accent-[var(--primary)]">
                                All sites, including any added later
                            </label>

                            <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                                <input type="radio" name="scope" value="some"
                                       @checked(old('scope') === 'some')
                                       class="accent-[var(--primary)]">
                                Only the sites ticked below
                            </label>

                            @if ($sites->isEmpty())
                                <p class="mt-1 text-[11.5px] text-text-3">
                                    No sites yet, so there is nothing to narrow this to. A destination
                                    added now covers whatever is added later.
                                </p>
                            @else
                                <div class="mt-1 flex max-h-[220px] flex-col gap-1.5 overflow-y-auto rounded-[7px] border border-border bg-surface-2 p-2.5">
                                    @foreach ($sites as $site)
                                        <label class="flex items-center gap-2 text-[12.5px] text-text-2">
                                            <input type="checkbox" name="sites[]" value="{{ $site->external_id }}"
                                                   @checked(in_array($site->external_id, (array) old('sites', []), true))
                                                   class="flex-none accent-[var(--primary)]">
                                            <span class="truncate">{{ $site->name }}</span>
                                            <span class="truncate font-mono text-[11px] text-text-3">{{ $site->expected_domain }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif

                            <p class="text-[11.5px] text-text-3">
                                Narrowing a destination does not narrow what it is subscribed to. It
                                still hears about everything ticked above - just not about sites it is
                                not responsible for.
                            </p>
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
    </div>
@endsection
