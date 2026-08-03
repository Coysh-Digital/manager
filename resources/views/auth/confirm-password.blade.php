@extends('layouts.auth')

@section('title', 'Confirm password · Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Confirm your password</h1>
    {{-- Naming the thing, where it is known. "You are about to do something" is true of every
         interruption and helps with none of them, and the sentence people actually want is the
         reassurance that what they typed is not gone. --}}
    @if (! empty($interrupted))
        <p class="mb-3 text-[13px] text-text-2">
            You were about to <span class="font-medium text-text">{{ $interrupted }}</span>. Nothing
            has been done yet, and what you had typed is kept — confirm below and you will be taken
            back to it.
        </p>
    @endif

    <p class="mb-5 text-[13px] text-text-2">
        This changes what Manager may do, or how your account is protected, so it asks that you are
        still the person at the keyboard.
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
