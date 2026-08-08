<x-mail::message>
# {{ $event->subject }}

{{ $event->summary }}

@if ($rows !== [])
{{--
    The detail box: everything that varies between one alert and the next, in one place.

    Written as HTML rather than as a markdown table through <x-mail::table>, and the reason is the
    delimiter. A markdown table separates cells with a pipe, and a site name is text an operator
    typed - "Acme | Production" is an ordinary thing to call a site and would split into two broken
    rows. The values are still escaped by Blade before they get here, so this is about the table
    surviving its own contents, not about markup reaching the inbox.

    The classes are what CssToInlineStyles matches against in the Manager theme; see .detail in
    resources/views/vendor/mail/html/themes/manager.css. The tone comes from the event's type rather
    than from anything this template decides, so a red box always means the same thing.

    `border="0" cellpadding="0" cellspacing="0"` as attributes as well as CSS: Outlook's Word engine
    draws its own cell borders otherwise, over the top of a box that already has one.
--}}
<div class="detail detail-{{ $event->tone() }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0">
@foreach ($rows as $label => $value)
<tr>
<td class="detail-label">{{ $label }}</td>
<td @class(['detail-cause-'.$event->tone() => $label === $causeLabel])>{{ $value }}</td>
</tr>
@endforeach
</table>
</div>
@endif

<x-mail::button :url="$link->url">
{{ $link->label }}
</x-mail::button>

<x-slot:subcopy>
You are receiving this because a notification destination in Manager subscribes to `{{ $event->type }}`. [Change what you are sent]({{ $preferences }}).
</x-slot:subcopy>
</x-mail::message>
