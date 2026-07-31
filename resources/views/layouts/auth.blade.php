<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Manager for Craft')</title>

    {{-- Duplicated from the app layout: these two share no head partial. --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <script>
        (function () {
            try {
                var preference = localStorage.getItem('manager.theme') || 'system';
                var dark = preference === 'dark'
                    || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-bg text-text">
<div class="flex min-h-screen items-center justify-center px-4 py-12">
    <div class="w-full max-w-[400px]">
        <x-wordmark class="mb-6" />

        <div class="rounded-[10px] border border-border bg-surface p-6 shadow-[var(--shadow)]">
            @yield('content')
        </div>

        @hasSection('footnote')
            <p class="mt-4 text-[12.5px] text-text-3">@yield('footnote')</p>
        @endif
    </div>
</div>
</body>
</html>
