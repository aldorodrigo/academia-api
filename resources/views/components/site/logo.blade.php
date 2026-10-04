{{-- Logo de Tuku: verde en el tema claro, blanco en el oscuro o sobre la banda verde (`light`). --}}
@props(['light' => false])
@if ($light)
    <img src="{{ asset('brand/tuku-logo-blanco.svg') }}" alt="Tuku" {{ $attributes->class('h-9 w-auto') }}>
@else
    <picture>
        <source srcset="{{ asset('brand/tuku-logo-blanco.svg') }}" media="(prefers-color-scheme: dark)">
        <img src="{{ asset('brand/tuku-logo.svg') }}" alt="Tuku" {{ $attributes->class('h-9 w-auto') }}>
    </picture>
@endif
