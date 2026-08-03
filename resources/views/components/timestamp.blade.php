{{--
    An absolute time, in the reader's zone.

    Every one of these used to render in the server's, which is UTC unless an operator changed it —
    so an audit entry written at half past four in the afternoon in Sydney was reported at half past
    five in the morning, with nothing on the screen saying which zone it meant.

    Relative times are not this component's business. `diffForHumans()` reads the same everywhere,
    and wrapping those would be work with no reader-visible effect.

    A <time> element, so the machine-readable value carries the offset even where the printed one
    does not have room to. The title is the zone: an hour with no zone beside it is a number
    somebody has to guess at, and the guess is usually wrong.
--}}
@props(['at', 'format' => 'j M Y, H:i'])

@if ($at === null)
    <span {{ $attributes }}>—</span>
@else
    @php($local = \App\Support\ViewerTimezone::apply($at))

    <time datetime="{{ $local->toIso8601String() }}"
          title="{{ $local->format('j M Y, H:i T') }}"
          {{ $attributes }}>{{ $local->format($format) }}</time>
@endif
