@extends('layouts.auth')

@section('title', 'Confirm password · Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Confirm your password</h1>
    <p class="mb-5 text-[13px] text-text-2">
        You are about to do something that changes what Manager may do, or how your account is
        protected. Confirm it is still you at the keyboard.
    </p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-3.5">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Password</span>
            <input type="password" name="password" required autofocus autocomplete="current-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>
        <button type="submit"
                class="h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover">
            Confirm
        </button>
    </form>
@endsection
