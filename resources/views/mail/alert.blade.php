<x-mail::message>
# {{ $event->subject }}

{{ $event->summary }}

@if ($rows !== [])
{{--
    Written as HTML rather than as a markdown table through <x-mail::table>, and the reason is the
    delimiter. A markdown table separates cells with a pipe, and a site name is text an operator
    typed - "Acme | Production" is an ordinary thing to call a site and would split into two broken
    rows. The values are still escaped by Blade before they get here, so this is about the table
    surviving its own contents, not about markup reaching the inbox.

    The class is what CssToInlineStyles matches against in the Manager theme; see .table in
    resources/views/vendor/mail/html/themes/manager.css.
--}}
<div class="table">
<table width="100%" cellpadding="0" cellspacing="0">
@foreach ($rows as $label => $value)
<tr>
<td width="35%" style="color: #5d5854;">{{ $label }}</td>
<td>{{ $value }}</td>
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
