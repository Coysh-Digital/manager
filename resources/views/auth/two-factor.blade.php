@extends('layouts.auth')

@section('title', 'Two-factor · Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Two-factor authentication</h1>
    <p class="mb-5 text-[13px] text-text-2">
        @if ($hasPasskeys && $hasTotp)
            Use your passkey, or enter a code from your authenticator app.
        @elseif ($hasPasskeys)
            Use your passkey to continue.
        @else
            Enter the six-digit code from your authenticator app, or one of your recovery codes.
        @endif
    </p>

    @if ($hasPasskeys)
        {{-- Offered first: it is one press, and phishing-resistant in a way a typed code is not. --}}
        <button type="button"
                data-passkey-assert
                data-options-url="{{ route('two-factor.passkey.options') }}"
                data-assert-url="{{ route('two-factor.passkey.store') }}"
                class="mb-4 h-[38px] w-full rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover disabled:opacity-60">
            Use a passkey
        </button>

        <p data-passkey-message
           class="mb-4 text-[12.5px] text-text-2 data-[state=error]:text-danger"></p>

        @if ($hasTotp)
            <div class="mb-4 flex items-center gap-3">
                <span class="h-px flex-1 bg-border"></span>
                <span class="font-mono text-[10.5px] uppercase tracking-[0.08em] text-text-3">or</span>
                <span class="h-px flex-1 bg-border"></span>
            </div>
        @endif
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    {{--
        One field takes either kind of code. Asking somebody whose phone has just died to first
        find the right form is a bad moment to add a step.

        Always rendered, even for a passkey holder: a recovery code has to work when the passkey is
        on a phone that is not to hand.
    --}}
    <form method="POST" action="{{ route('two-factor.store') }}" class="flex flex-col gap-3.5">
        @csrf

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Code</span>
            <input type="text" name="code" required autofocus autocomplete="one-time-code"
                   inputmode="text" spellcheck="false"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 font-mono text-[13px] tracking-[0.08em] text-text">
        </label>

        <button type="submit"
                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:border-primary-hover hover:bg-primary-hover">
            Continue
        </button>
    </form>

    <p class="mt-4 text-[12.5px]">
        <a href="{{ route('login') }}" class="text-primary hover:text-primary-hover">Back to sign in</a>
    </p>
@endsection

@section('footnote')
    Each recovery code works once. If you use one, generate a fresh set from your account page.
@endsection
