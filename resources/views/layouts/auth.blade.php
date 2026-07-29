<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Manager')</title>

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
        <div class="mb-6 flex items-center gap-2.5">
            <div class="flex h-[22px] w-[22px] items-center justify-center rounded-md bg-primary text-[12px] font-semibold text-primary-fg">M</div>
            <div class="flex flex-col gap-px">
                <span class="text-sm font-semibold tracking-[-0.01em]">Manager</span>
                <span class="font-mono text-[9.5px] uppercase tracking-[0.04em] text-text-3">
                    {{ config('manager.edition') === 'cloud' ? 'Cloud' : 'Self-hosted' }}
                </span>
            </div>
        </div>

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
