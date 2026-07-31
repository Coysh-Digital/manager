@extends('layouts.auth')

@section('title', 'Choose a new password · Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Choose a new password</h1>
    <p class="mb-5 text-[13px] text-text-2">Every other session will be signed out.</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-3.5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">New password</span>
            <input type="password" name="password" required autofocus autocomplete="new-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
            <span class="text-[11.5px] text-text-3">At least 12 characters, and checked against known breached passwords.</span>
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Confirm new password</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <button type="submit"
                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover">
            Change password
        </button>
    </form>
@endsection
