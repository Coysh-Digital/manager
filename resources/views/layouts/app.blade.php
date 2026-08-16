<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="palette-endpoint" content="{{ route('palette') }}">

    {{--
        Where the in-progress backup list is asked for on a screen that does not render one.

        The two backup screens carry their own URL on the region itself - the fleet's is
        organisation-wide and a site's is that site's - and this is the fallback for everywhere
        else, so that pressing Refresh on a site's Overview tab can still bring the sidebar count
        up to date without a navigation.
    --}}
    <meta name="backup-status-endpoint" content="{{ route('backups.status') }}">

    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Manager for Craft')</title>

    {{--
        The same file the marketing site serves, byte for byte, so a pinned tab looks the same
        whichever surface it came from. Its red is the light theme's --primary; a single static SVG
        cannot follow the dark theme's shift, and matching managerforcraft.com is worth more.
    --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

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
    {{--
        The navigation drawer's state, on narrow screens.

        A checkbox rather than a click handler, so the menu works with no JavaScript at all - which
        matters on a control plane somebody may be reaching for from a phone on a bad connection
        during an incident. app.js enhances it afterwards with Escape-to-close and an aria-expanded
        that tracks it; nothing depends on that arriving.
    --}}
    <input type="checkbox" id="nav-drawer" class="peer sr-only" aria-hidden="true" tabindex="-1">

    {{-- The scrim. A label, so tapping anywhere off the menu closes it. --}}
    <label for="nav-drawer"
           class="fixed inset-0 z-30 hidden bg-black/45 peer-checked:block lg:!hidden"
           aria-hidden="true"></label>

    @include('layouts.partials.sidebar')

    <div class="flex min-w-0 flex-1 flex-col">
        @include('layouts.partials.topbar')

        @if (session('status'))
            <div class="border-b border-ok-line bg-ok-bg px-4 py-3 text-[13px] text-ok sm:px-7">
                {{ session('status') }}
            </div>
        @endif

        @if (session('warning'))
            <div class="border-b border-amber-line bg-amber-bg px-4 py-3 text-[13px] text-amber sm:px-7">
                {{ session('warning') }}
            </div>
        @endif

        <main class="flex-1 px-4 pb-16 pt-5 sm:px-7 sm:pt-6">
            @yield('content')
        </main>
    </div>
</div>

{{--
    Where an action answered in place says what it did.

    Rendered here, empty, on every page rather than created by JavaScript when first needed: a live
    region has to already be in the document for what is put into it to be announced, and building
    the region and its contents in one tick is the usual reason a toast is silent to a screen reader.

    Two regions, because the two urgencies are genuinely different. "Backup requested" should wait
    for a gap in whatever is being read; "this site has no active connector" should not.

    Nothing renders here without JavaScript, and nothing needs to. The bands above <main> are the
    path this file does not touch, and they are still the only feedback an installation with scripts
    blocked gets - which is why they were left exactly as they were.
--}}
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex flex-col gap-2 p-3 sm:inset-x-auto sm:right-0 sm:w-[380px] sm:items-end">
    <div data-toasts="assertive" role="alert" aria-live="assertive" class="contents"></div>
    <div data-toasts="polite" role="status" aria-live="polite" class="contents"></div>
</div>
</body>
</html>
