@extends('layouts.auth')

@section('title', 'Set up Manager for Craft')

@section('content')
    <h1 class="mb-1 text-[17px] font-semibold tracking-[-0.01em]">Set up Manager</h1>
    <p class="mb-5 text-[13px] text-text-2">
        Create the first account. This page closes permanently once you have.
    </p>

    @if ($insecureConnection)
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            <strong>This connection is not encrypted.</strong>
            The password you are about to set will cross the network in the clear. Put HTTPS in front of
            Manager before continuing.
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('setup.store') }}" class="flex flex-col gap-3.5">
        @csrf

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Organisation name</span>
            <input type="text" name="organisation" value="{{ old('organisation') }}" required autofocus
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Your name</span>
            <input type="text" name="name" value="{{ old('name') }}" required
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Password</span>
            <input type="password" name="password" required autocomplete="new-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
            <span class="text-[11.5px] text-text-3">
                At least 12 characters, and checked against known breached passwords.
            </span>
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-[12.5px] font-medium">Confirm password</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="h-[34px] rounded-[7px] border border-border-2 bg-surface-2 px-2.5 text-[13px]">
        </label>

        <button type="submit"
                class="mt-1 h-[34px] rounded-[7px] border border-primary bg-primary px-3.5 text-[13px] font-medium text-primary-fg hover:bg-primary-hover">
            Create account
        </button>
    </form>
@endsection

@section('footnote')
    You will be asked to set up two-factor authentication straight afterwards.
@endsection
