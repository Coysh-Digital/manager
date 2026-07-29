{{-- The restrained utility bar: where you are, how the platform is doing, and who you are. --}}
<header class="sticky top-0 z-20 flex h-14 flex-none items-center gap-4 border-b border-border bg-surface px-5">
    <div class="flex min-w-0 flex-none items-center gap-2 overflow-hidden whitespace-nowrap text-[13px] text-text-2">
        <span class="font-mono text-[11.5px] text-text-3">{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}</span>
        <span class="text-border-2">/</span>
        <span class="font-medium text-text">@yield('crumb', 'Sites')</span>
    </div>

    @isset($fleetSummary)
        {{-- Hidden on narrow viewports: the breadcrumb and the account menu matter more. --}}
        <div class="hidden flex-auto items-center gap-3.5 overflow-hidden pl-1.5 xl:flex">
            <div class="flex min-w-0 items-center gap-[7px] overflow-hidden">
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
        </div>
    @endisset

    <div class="ml-auto flex flex-none items-center gap-3 whitespace-nowrap">
        {{-- Light / dark / system, persisted in localStorage. --}}
        <div class="flex h-[30px] overflow-hidden rounded-[7px] border border-border">
            @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                <button type="button"
                        data-theme-option="{{ $value }}"
                        aria-pressed="false"
                        class="border-0 px-2.5 text-[12px] text-text-2 aria-pressed:bg-pale aria-pressed:text-primary {{ $loop->first ? '' : 'border-l border-border' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="h-[22px] w-px bg-border"></div>

        <div class="flex items-center gap-2">
            <div class="flex h-6 w-6 items-center justify-center rounded-full border border-pale-line bg-pale text-[10.5px] font-semibold text-primary">
                {{ Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($part) => Str::substr($part, 0, 1))->implode('') }}
            </div>
            <span class="text-[13px] font-medium">{{ auth()->user()->name }}</span>
        </div>
    </div>
</header>
