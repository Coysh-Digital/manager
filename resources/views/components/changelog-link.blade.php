@props(['href', 'label' => 'Changelog', 'mono' => false, 'title' => null])

{{--
    Off to the publisher's own notes.

    `noreferrer` is not decoration here: without it, the request tells a third party that an
    installation somewhere is behind on their package, which is a small part of exactly the thing
    this platform is otherwise careful not to leak.

    `mono` exists for the one caller that puts a version number in the label rather than the word
    "Changelog". A number set in the body face beside a nav full of them reads as a label; the mono
    face is how every other version string in this interface is set, and matching it is cheaper than
    a second anchor that would drift from the noreferrer reasoning above.
--}}
@if ($href)
    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer"
       @if ($title) title="{{ $title }}" @endif
       @class([
           'inline-flex items-center gap-1 whitespace-nowrap no-underline text-text-3 hover:text-primary',
           'font-mono text-[11px]' => $mono,
           'text-[12px]' => ! $mono,
       ])>
        {{ $label }}
        <span aria-hidden="true" class="text-[10px] leading-none">↗</span>
        <span class="sr-only">(opens in a new tab)</span>
    </a>
@endif
