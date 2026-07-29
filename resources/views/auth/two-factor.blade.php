@extends('layouts.auth')

@section('title', 'Two-factor · Manager')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Two-factor authentication</h1>
    <p class="mb-5 text-[13px] text-text-2">
        Enter the six-digit code from your authenticator app, or one of your recovery codes.
    </p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    {{--
        One field takes either kind of code. Asking somebody whose phone has just died to first
        find the right form is a bad moment to add a step.
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
