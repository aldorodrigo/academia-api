{{--
    Celular de muestra con una pantalla de la app dibujada en HTML (mismos textos y componentes que la app).
    Para lectores de pantalla es una sola imagen descrita por `label`.
--}}
@props(['label', 'title', 'back' => false, 'action' => null])
<div role="img" aria-label="{{ $label }}" {{ $attributes->class('relative mx-auto w-[300px] max-w-full select-none') }}>
    <div aria-hidden="true" class="overflow-hidden rounded-[2.6rem] border-[9px] border-marco bg-surface shadow-phone">
        <div class="flex h-8 items-center justify-between px-6 pt-1 text-[11px] font-bold text-ink">
            <span class="tabular">17:05</span>
            <span class="h-4 w-16 rounded-full bg-marco"></span>
            <span class="flex items-center gap-0.5">
                <span class="icon icon-fill text-sm">signal_cellular_alt</span>
                <span class="icon icon-fill text-sm">battery_full</span>
            </span>
        </div>
        <div class="flex h-12 items-center gap-3 px-4">
            @if ($back)
                <span class="icon text-xl text-ink">arrow_back</span>
            @endif
            <span class="font-display text-lg leading-none font-bold text-ink">{{ $title }}</span>
            @if ($action)
                <span class="icon ml-auto text-xl text-ink">{{ $action }}</span>
            @endif
        </div>
        <div class="flex h-[520px] flex-col gap-3 overflow-hidden px-4 pb-4 text-[13px] leading-snug text-ink *:shrink-0">
            {{ $slot }}
        </div>
    </div>
</div>
