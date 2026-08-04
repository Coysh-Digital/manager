@props(['kind', 'points', 'label', 'height' => 180, 'summary' => null])

{{--
    A chart, and the same figures in text underneath it.

    The table is not a nicety. A canvas is a picture to a screen reader, to a printer that drops
    background graphics, and to anybody whose script blocked - and the numbers behind these charts
    are the sort somebody pastes into a ticket. It is visually hidden rather than absent, so the
    page has one source of figures rather than two that can disagree.
--}}
<div class="w-full">
    <div style="height: {{ $height }}px" class="relative w-full">
        <canvas data-chart="{{ json_encode(['kind' => $kind, 'points' => $points]) }}"
                role="img"
                aria-label="{{ $label }}"></canvas>
    </div>

    {{--
        The hiding goes on a wrapper, not on the table.

        `sr-only` clips with `overflow: hidden` on a one-pixel box, and a table's overflow does not
        clip its own `<caption>` - the caption is laid out outside the table box. So the summary
        sentence, which is a whole sentence naming the site, escaped and rendered at its full width
        while being invisible. Measured on a 375px viewport it ran to 475px, which is exactly the
        horizontal scrollbar somebody reported on every screen carrying a chart.
    --}}
    <div class="sr-only">
        <table>
            <caption>{{ $summary ?? $label }}</caption>
            <thead>
                <tr><th scope="col">Period</th><th scope="col">Value</th></tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr>
                        <th scope="row">{{ $point['label'] }}</th>
                        <td>{{ $point['text'] ?? $point['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
