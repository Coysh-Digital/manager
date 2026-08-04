@extends('layouts.app')

@section('title', 'Recovery keys · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('Recovery keys'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header />

        {{-- Recovery keys.

             The screen where an organisation decides who can read its backups. Two things it must
             never do: offer to generate a key here, and imply that we could help if one is lost. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                {{-- Every "Add one in Settings" link used to point at an id partway down a longer
                     screen, which needed a scroll-mt so the sticky topbar did not cover the header
                     it had just jumped to. They point at this screen now, so neither is needed. --}}
                <span class="text-[13.5px] font-medium">Recovery keys</span>
                <x-changelog-link href="https://managerforcraft.com/docs/recovery-keys" label="How recovery keys work" />
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
                            We cannot recover it - we have never held the other half, which is the whole
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
                                        Added {{ $key->created_at?->diffForHumans() }} - not yet used for anything.
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
                            {{-- The ceremony. Almost nothing can be checked about a public key - any 32
                                 bytes is a valid one - so the only real test is whether somebody can
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
                    {{-- The commands, not a description of them. "Generate the key with
                         manager-restore keygen" assumes the reader knows how to get manager-restore,
                         and until it was published on Packagist that was not a small assumption. --}}
                    <div class="flex flex-col gap-2">
                        <p class="text-[12px] font-medium text-text-2">On your own machine, not on this server:</p>

                        <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3"><code class="font-mono text-[11.5px] leading-relaxed">composer global require coysh-digital/manager-restore

manager-restore keygen --label="Ops laptop" --out=~/keys/recovery</code></pre>

                        <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-2">
                            Two files come out.
                            <code class="font-mono">recovery.pub</code> is safe to share and is what you
                            paste above. <code class="font-mono">recovery.secret</code> is the one that
                            matters: keep it somewhere other than the machine that made it, and never
                            here. If it is lost, every backup encrypted to it is permanently unreadable
                            - we have never held the other half, so there is nobody to ask.
                        </p>
                    </div>

                    <p class="max-w-[80ch] text-[12px] leading-relaxed text-text-3">
                        We never see the secret half and have nowhere to put one. After activating the
                        key you will be asked to prove you hold it, and then to add its fingerprint to
                        <code class="font-mono">config/manager-connector.php</code> on each site,
                        creating that file if the site does not have one - installing the connector
                        does not. That pin lives on your server, and it is what
                        stops us handing your sites a key of our own.
                        <x-changelog-link href="https://managerforcraft.com/docs/recovery-keys" label="Full instructions" />
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
    </div>
@endsection
