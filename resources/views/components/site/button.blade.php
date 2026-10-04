{{-- Botón (link) con las variantes de la marca: principal, secundario, tonal, sol y sobre la banda verde. --}}
@props(['href', 'variant' => 'primary', 'size' => 'md', 'icon' => null])
@php
    $variants = [
        'primary' => 'bg-verde text-on-verde hover:brightness-110',
        'secondary' => 'border border-line-strong text-ink hover:bg-brote',
        'tonal' => 'bg-brote text-verde hover:brightness-95',
        'sol' => 'bg-sol text-on-sol hover:brightness-105',
        'on-marca' => 'border border-on-marca/70 text-on-marca hover:bg-on-marca/10',
    ];
    $sizes = [
        'md' => 'min-h-12 px-5 text-base',
        'sm' => 'min-h-10 px-4 text-sm',
    ];
@endphp
<a href="{{ $href }}" {{ $attributes->class([
    'inline-flex items-center justify-center gap-2 rounded-md text-center font-bold transition',
    $variants[$variant],
    $sizes[$size],
]) }}>
    @if ($icon)
        <span class="icon text-xl" aria-hidden="true">{{ $icon }}</span>
    @endif
    {{ $slot }}
</a>
