@extends('layouts.app')

@section('title', 'People · Settings · Manager for Craft')
@section('crumb', App\Support\Crumbs::settings('People'))

@section('content')
    <div class="mx-auto max-w-[900px]">
        <x-settings-header />

        {{--
            People.

            There was no way to add a second person to an installation before this: accounts came
            from the one-time setup flow or from a shell command, so every installation was either
            one account or an SSH session away from one.
        --}}
        {{-- The id and scroll-mt are gone with the anchor that needed them: this is a URL now, and a
             link into it lands at the top of a screen rather than partway down a longer one. --}}
        <div class="mb-3.5 overflow-hidden rounded-[10px] border border-border bg-surface shadow-[var(--shadow)]">
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
                        expires - the same mechanism as a forgotten password. Until they do, the
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
    </div>
@endsection
