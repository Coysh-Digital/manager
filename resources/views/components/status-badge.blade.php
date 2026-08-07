@props(['tone' => 'grey', 'label', 'glyph' => null])

@php
    // Every badge carries a glyph as well as a colour. Status must never depend on hue alone —
    // for colour vision, for greyscale printouts, and for the screenshot somebody pastes into a
    // ticket.
    //
    // The `glyph` prop overrides which one, and cannot omit it: a caller passing an empty string
    // still falls back to the tone's own. It exists for the small number of badges where the tone
    // is right but its default mark is not - a fleet's update count is `info`, and "i 3 updates"
    // says less than "↑ 3 updates" for the same width.
    $glyphs = ['ok' => '✓', 'warn' => '!', 'bad' => '✕', 'info' => 'i', 'grey' => '—'];

    $classes = [
        'ok' => 'bg-ok-bg text-ok border-ok-line',
        'warn' => 'bg-amber-bg text-amber border-amber-line',
        'bad' => 'bg-danger-bg text-danger border-danger-line',
        'info' => 'bg-info-bg text-info border-info-line',
        'grey' => 'bg-grey-bg text-grey border-border-2',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border py-0.5 pl-1.5 pr-2 text-[12px] font-medium '.($classes[$tone] ?? $classes['grey'])]) }}>
    <span class="font-mono text-[11px] leading-none">{{ $glyph ?: ($glyphs[$tone] ?? $glyphs['grey']) }}</span>
    {{-- Hooked so a screen that updates a badge in place has something stable to write to, rather
         than reaching for whichever span happens to be last. --}}
    <span data-status-badge-label>{{ $label }}</span>
</span>
