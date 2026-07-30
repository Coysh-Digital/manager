@props(['kind', 'points', 'label', 'height' => 180, 'summary' => null])

{{--
    A chart, and the same figures in text underneath it.

    The table is not a nicety. A canvas is a picture to a screen reader, to a printer that drops
    background graphics, and to anybody whose script blocked — and the numbers behind these charts
    are the sort somebody pastes into a ticket. It is visually hidden rather than absent, so the
    page has one source of figures rather than two that can disagree.
--}}
<div class="w-full">
    <div style="height: {{ $height }}px" class="relative w-full">
        <canvas data-chart="{{ json_encode(['kind' => $kind, 'points' => $points]) }}"
                role="img"
                aria-label="{{ $label }}"></canvas>
    </div>

    <table class="sr-only">
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
