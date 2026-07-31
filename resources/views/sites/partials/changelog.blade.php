{{--
    The fragment behind the Notes panel.

    Rendered on its own, without a layout, because it is fetched into a page that already exists.
    When there is nothing to show — the fetch is off, the network refused, or the versions did not
    line up — this says so plainly and hands back the link, which is what the screen offered before
    any of this existed.
--}}
@if ($notes)
    {{-- Already rendered through league/commonmark with HTML stripped and unsafe links refused.
         See ChangelogFetcher::between(). --}}
    <div class="changelog flex flex-col gap-2 text-[12.5px] leading-relaxed text-text-2">
        {!! $notes !!}
    </div>

    <p class="mt-3 text-[11.5px] text-text-3">
        Published by Craft, read here once for this installation. Nothing about this site was sent to
        fetch it.
        <x-changelog-link :href="$link" label="Read the full changelog" />
    </p>
@else
    <p class="text-[12.5px] text-text-3">
        The release notes could not be read here.
        <x-changelog-link :href="$link" label="Read them on GitHub" />
    </p>
@endif
