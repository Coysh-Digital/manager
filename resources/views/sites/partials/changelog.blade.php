{{--
    The fragment behind the Notes panel.

    Rendered on its own, without a layout, because it is fetched into a page that already exists.
    When there is nothing to show — the fetch is off, the network refused, or the versions did not
    line up — this says so plainly and hands back the link, which is what the screen offered before
    any of this existed.

    Serves both sources. Craft's notes are fetched from GitHub once per installation; a plugin's were
    forwarded by connectors and are stored against the release rather than against any site. Neither
    involves sending anything about this site anywhere, but they are not the same claim, so the
    footnote says which one applies.
--}}
@php($source = $source ?? 'craft')

@if ($notes)
    {{-- Already rendered through league/commonmark with HTML stripped and unsafe links refused.
         See ChangelogMarkdown::render(). --}}
    <div class="changelog flex flex-col gap-2 text-[12.5px] leading-relaxed text-text-2">
        {!! $notes !!}
    </div>

    <p class="mt-3 text-[11.5px] text-text-3">
        @if ($source === 'craft')
            Published by Craft, read here once for this installation. Nothing about this site was sent
            to fetch it.
        @else
            Published by the plugin and reported by the sites running it. Held against the release
            rather than against any site, and nothing was fetched to show it.
        @endif

        @if ($link)
            <x-changelog-link :href="$link" label="Read the full changelog" />
        @endif
    </p>
@else
    <p class="text-[12.5px] text-text-3">
        @if ($source === 'craft')
            The release notes could not be read here.
        @else
            No release notes have been reported for these versions yet. They arrive with the next
            update check from a site running this plugin.
        @endif

        @if ($link)
            <x-changelog-link :href="$link" :label="$source === 'craft' ? 'Read them on GitHub' : 'Open the Plugin Store listing'" />
        @endif
    </p>
@endif
