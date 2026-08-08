@props(['site', 'updateCount' => 0, 'findingCount' => 0])

@php
    // Six screens, in the order somebody works through them: what it is, whether it is alive, what
    // it needs, what is wrong with it, how it is configured, what has been done to it.
    $tabs = [
        ['route' => 'sites.show', 'label' => 'Overview', 'count' => null],
        ['route' => 'sites.health', 'label' => 'Health', 'count' => null],
        ['route' => 'sites.updates', 'label' => 'Updates', 'count' => $updateCount],
        ['route' => 'sites.security', 'label' => 'Security', 'count' => $findingCount],
        ['route' => 'sites.backups', 'label' => 'Backups', 'count' => null],
        ['route' => 'sites.settings', 'label' => 'Settings', 'count' => null],
        ['route' => 'sites.audit', 'label' => 'Audit', 'count' => null],
    ];
@endphp

{{--
    A count appears only when it is non-zero. A badge reading "0" is a reassurance nobody asked for,
    and it costs the eye the same glance as a real number.
--}}
{{-- Scrolls sideways rather than wrapping to two rows on a phone. Seven tabs wrapped is a block of
     navigation taller than the content under it, and the order carries meaning.

     `overflow-y-hidden` is not belt-and-braces, it is the fix for a real bug. CSS says that when one
     axis is not `visible` the other resolves to `auto`, so `overflow-x-auto` alone silently makes
     this scrollable vertically too - and the `-mb-px` below deliberately puts one pixel of the list
     outside the box, which is exactly enough to make a phone offer a vertical drag on the tab strip.
     Reported from use: the tabs moved up and down as well as sideways. --}}
<nav aria-label="Site sections" class="relative -mx-4 mb-5 overflow-x-auto overflow-y-hidden border-b border-border px-4 sm:mx-0 sm:mb-6 sm:px-0">
    <ul class="-mb-px flex w-max min-w-full items-end gap-x-1">
        @foreach ($tabs as $tab)
            @php $active = request()->routeIs($tab['route']); @endphp

            <li>
                <a href="{{ route($tab['route'], $site) }}"
                   @if ($active) aria-current="page" @endif
                   class="inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-[13px] no-underline
                          {{ $active
                              ? 'border-primary font-medium text-text'
                              : 'border-transparent text-text-2 hover:border-border-2 hover:text-text' }}">
                    {{ $tab['label'] }}

                    @if ($tab['count'] > 0)
                        <span class="rounded-full bg-surface-2 px-1.5 py-px font-mono text-[10.5px] tabular text-text-2">
                            {{ $tab['count'] }}
                        </span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
