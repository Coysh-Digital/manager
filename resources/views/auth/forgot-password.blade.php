@extends('layouts.auth')

@section('title', 'Reset password · Manager')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Reset your password</h1>
    <p class="mb-5 text-[13px] text-text-2">We will email you a link if that address has an account.</p>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-ok-line bg-ok-bg px-3.5 py-2.5 text-[12.5px] text-ok">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-3.5">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>
        <button type="submit"
                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover">
            Email a reset link
        </button>
    </form>

    <p class="mt-4 text-[12.5px]">
        <a href="{{ route('login') }}" class="text-primary hover:text-primary-hover">Back to sign in</a>
    </p>
@endsection

@section('footnote')
    Resetting a password does not bypass two-factor authentication.
@endsection
