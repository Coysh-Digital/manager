@extends('layouts.auth')

@section('title', 'Sign in · Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Sign in</h1>
    <p class="mb-5 text-[13px] text-text-2">This installation runs on your own infrastructure.</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-3.5">
        @csrf

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px] text-text">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Password</span>
            <input type="password" name="password" required autocomplete="current-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px] text-text">
        </label>

        <label class="flex items-center gap-2 text-[12.5px] text-text-2">
            <input type="checkbox" name="remember" value="1" class="accent-[var(--primary)]">
            Stay signed in on this device
        </label>

        <button type="submit"
                class="mt-1 h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:border-primary-hover hover:bg-primary-hover">
            Sign in
        </button>
    </form>

    {{--
        Said here because this screen is where somebody decides whether their passkey is any use.

        A passkey is a *second* factor in Manager, never a way in on its own - the reasoning is in
        AppServiceProvider, and PasskeyAndMfaEnforcementTest holds it. That decision is sound and
        this changes none of it. What it left behind was a login screen with no mention of passkeys
        at all, which reads as "not supported" rather than "not yet": somebody who registered one
        gets to the password field, sees nothing, and has no way to know the button is one step
        further on.

        A sentence, not a button. A button here would have to either sign somebody in on a passkey
        alone - which is the thing that must not happen - or collect a password first and be a
        button that does nothing, which is worse than the silence it replaced.
    --}}
    <p class="mt-4 text-[12.5px] text-text-2">
        Registered a passkey? Sign in here first, and you will be offered it instead of a code.
    </p>

    <p class="mt-2 text-[12.5px]">
        <a href="{{ route('password.request') }}" class="text-primary hover:text-primary-hover">Forgotten your password?</a>
    </p>
@endsection

@section('footnote')
    Manager never holds an administrator password, an SSH credential or a database password for any
    site it monitors.
@endsection
