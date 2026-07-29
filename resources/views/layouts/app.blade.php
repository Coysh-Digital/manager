<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Manager')</title>

    {{--
        Applied before first paint, so the page never flashes the wrong theme on the way to the
        right one. Duplicating the resolve logic from app.js is the price of that, and it is worth
        paying: the alternative is a white flash on every navigation for anyone using dark mode.
    --}}
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
<div class="flex min-h-screen">
    @include('layouts.partials.sidebar')

    <div class="flex min-w-0 flex-1 flex-col">
        @include('layouts.partials.topbar')

        @if (session('status'))
            <div class="border-b border-ok-line bg-ok-bg px-7 py-3 text-[13px] text-ok">
                {{ session('status') }}
            </div>
        @endif

        @if (session('warning'))
            <div class="border-b border-amber-line bg-amber-bg px-7 py-3 text-[13px] text-amber">
                {{ session('warning') }}
            </div>
        @endif

        <main class="flex-1 px-7 pb-16 pt-6">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
