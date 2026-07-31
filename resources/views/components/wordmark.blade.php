{{--
    The mark, in one place.

    It was copy-pasted into the sidebar and the sign-in layout, which is how the two drifted apart by
    a few pixels and would have drifted further the moment the label stopped being a literal.

    The label comes from a bound contract rather than a config key. See App\Contracts\ProductLabel for
    why that distinction is worth an interface.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
    <div class="flex h-[22px] w-[22px] items-center justify-center rounded-md bg-primary text-[12px] font-semibold tracking-[-0.02em] text-primary-fg"
         aria-hidden="true">M</div>
    <div class="flex flex-col gap-px">
        <span class="text-sm font-semibold tracking-[-0.01em]">Manager for Craft</span>
        <span class="font-mono text-[9.5px] uppercase tracking-[0.04em] text-text-3">
            {{ app(App\Contracts\ProductLabel::class)->label() }}
        </span>
    </div>
</div>
