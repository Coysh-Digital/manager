@props(['href', 'label' => 'Changelog'])

{{--
    Off to the publisher's own notes.

    `noreferrer` is not decoration here: without it, the request tells a third party that an
    installation somewhere is behind on their package, which is a small part of exactly the thing
    this platform is otherwise careful not to leak.
--}}
@if ($href)
    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer"
       class="inline-flex items-center gap-1 whitespace-nowrap text-[12px] text-text-3 no-underline hover:text-primary">
        {{ $label }}
        <span aria-hidden="true" class="text-[10px] leading-none">↗</span>
        <span class="sr-only">(opens in a new tab)</span>
    </a>
@endif
