@props(['subtitle' => null])

{{--
    The chrome every Settings tab carries: the heading, the tab strip, and the error banner.

    One component rather than fifteen lines repeated seven times, and for the same reason
    <x-site-header> exists beside <x-site-tabs>: the heading is the thing that must not drift between
    screens that are meant to read as one.

    The heading stays "Settings" on all of them. Which tab somebody is on is the tab strip's job, and
    a heading that repeated it would say the same word twice a line apart.
--}}
<div class="mb-5 flex flex-col gap-1.5">
    <h1 class="text-[22px] font-semibold tracking-[-0.015em]">Settings</h1>

    @if ($subtitle)
        <p class="text-[13px] text-text-2">{{ $subtitle }}</p>
    @endif
</div>

<x-settings-tabs />

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-danger-line bg-danger-bg px-3.5 py-2.5 text-[12.5px] text-danger">
        {{ $errors->first() }}
    </div>
@endif
