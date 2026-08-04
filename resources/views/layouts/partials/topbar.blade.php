{{-- The restrained utility bar: where you are, how the platform is doing, and who you are. --}}
<header class="sticky top-0 z-20 flex h-14 flex-none items-center gap-2 border-b border-border bg-surface px-3 sm:gap-4 sm:px-5">
    {{-- The drawer handle. A label rather than a button so the menu works without JavaScript; app.js
         gives it aria-expanded once it loads. --}}
    <label for="nav-drawer"
           data-nav-toggle
           role="button"
           tabindex="0"
           aria-controls="sidebar"
           class="-ml-1 flex h-9 w-9 flex-none cursor-pointer items-center justify-center rounded-[7px] text-text-2 hover:bg-row-hover hover:text-text lg:hidden">
        <span aria-hidden="true" class="flex flex-col gap-[3px]">
            <span class="block h-px w-4 bg-current"></span>
            <span class="block h-px w-4 bg-current"></span>
            <span class="block h-px w-4 bg-current"></span>
        </span>
        <span class="sr-only">Menu</span>
    </label>

    {{--
        The trail, not just the leaf.

        It was one static segment, so a site's screens gave no way back to the fleet except the
        sidebar — which on a phone is behind the drawer. Each level is now a link, and the host is
        dropped below the small breakpoint: it is the least useful segment and the widest.
    --}}
    <nav aria-label="Breadcrumb" class="flex min-w-0 flex-1 items-center overflow-hidden">
        <ol class="flex min-w-0 items-center gap-1.5 whitespace-nowrap text-[13px] text-text-2">
            <li class="hidden flex-none items-center gap-1.5 sm:flex">
                <span class="font-mono text-[11.5px] text-text-3">{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}</span>
                <span aria-hidden="true" class="text-border-2">/</span>
            </li>

            @php
                // Views yield a JSON trail when they have one; anything that has not been converted
                // still yields a plain string, and reads as a single unlinked segment.
                //
                // Decoded first because `@section('crumb', $value)` puts the value through e() — the
                // inline-value form of the directive escapes, unlike the block form — so the JSON
                // arrives here with its quotes as entities. Labels are re-escaped on output below.
                $raw = html_entity_decode(trim($__env->yieldContent('crumb', 'Sites')), ENT_QUOTES);
                $trail = json_decode($raw, true);
                $trail = is_array($trail) ? $trail : [['label' => $raw]];
            @endphp

            @foreach ($trail as $segment)
                @php $isLast = $loop->last; @endphp

                {{-- The last segment never truncates and everything before it does. Where you are is
                     the part worth the room; "Sites / Manager Testbed / S…" spends it on the part you
                     already know. Flexbox takes the space from the longest shrinkable segment, which
                     is the site name. --}}
                <li @class(['flex items-center gap-1.5', 'min-w-0' => ! $isLast, 'flex-none' => $isLast])>
                    @if (! $isLast && ($segment['href'] ?? null))
                        <a href="{{ $segment['href'] }}"
                           class="truncate text-text-2 no-underline hover:text-text">{{ $segment['label'] }}</a>
                    @else
                        <span @class([
                            'truncate' => ! $isLast,
                            'whitespace-nowrap font-medium text-text' => $isLast,
                        ]) @if ($isLast) aria-current="page" @endif>{{ $segment['label'] }}</span>
                    @endif

                    @unless ($isLast)
                        <span aria-hidden="true" class="flex-none text-border-2">/</span>
                    @endunless
                </li>
            @endforeach
        </ol>
    </nav>

    @isset($fleetSummary)
        {{-- Hidden until there is room: the breadcrumb and the account menu matter more. --}}
        <div class="hidden flex-none items-center gap-[7px] overflow-hidden xl:flex">
            <span @class([
                'h-[7px] w-[7px] flex-none rounded-full',
                'bg-ok' => $fleetSummary['needingAttention'] === 0,
                'bg-amber' => $fleetSummary['needingAttention'] > 0,
            ])></span>
            <span class="truncate text-[12.5px] text-text-2">
                {{ $fleetSummary['total'] }} {{ Str::plural('site', $fleetSummary['total']) }},
                @if ($fleetSummary['needingAttention'] === 0)
                    all reporting
                @else
                    {{ $fleetSummary['needingAttention'] }} need attention
                @endif
            </span>
        </div>
    @endisset

    <div class="ml-auto flex flex-none items-center gap-2 whitespace-nowrap sm:gap-3">
        {{--
            The palette, with its shortcut printed on it.

            A keyboard shortcut nobody is told about is a feature for the person who wrote it. This
            is a real button — it works by clicking, which is the only way it exists at all on a
            phone — and the ⌘K badge is there to teach the faster route to anybody who uses this
            screen daily.
        --}}
        <button type="button" data-palette-open
                class="flex h-[30px] items-center gap-2 rounded-[7px] border border-border bg-surface-2 px-2.5 text-[12.5px] text-text-3 hover:bg-row-hover hover:text-text">
            <span aria-hidden="true">⌕</span>
            <span class="hidden sm:inline">Jump to…</span>
            <kbd class="hidden rounded-[4px] border border-border-2 px-1 font-mono text-[10px] lg:inline">⌘K</kbd>
            <span class="sr-only">Search sites and screens</span>
        </button>

        {{-- Light / dark / system, persisted in localStorage. Below the small breakpoint it collapses
             to the two real choices: "System" is a preference, not a view, and a phone has less room
             than it has opinions. --}}
        <div class="flex h-[30px] overflow-hidden rounded-[7px] border border-border">
            @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                <button type="button"
                        data-theme-option="{{ $value }}"
                        aria-pressed="false"
                        @class([
                            'border-0 px-2.5 text-[12px] text-text-2 aria-pressed:bg-pale aria-pressed:text-primary',
                            'border-l border-border' => ! $loop->first,
                            'hidden sm:block' => $value === 'system',
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="hidden h-[22px] w-px bg-border sm:block"></div>

        <a href="{{ route('settings.account') }}" class="flex items-center gap-2 no-underline">
            <div class="flex h-6 w-6 flex-none items-center justify-center rounded-full border border-pale-line bg-pale text-[10.5px] font-semibold text-primary">
                {{ Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($part) => Str::substr($part, 0, 1))->implode('') }}
            </div>
            {{-- The name is the first thing to go: the initials already identify the account. --}}
            <span class="hidden text-[13px] font-medium text-text md:inline">{{ auth()->user()->name }}</span>
        </a>
    </div>
</header>
