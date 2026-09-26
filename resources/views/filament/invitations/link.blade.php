{{-- Estilos inline / componentes de Filament: las clases de Tailwind propias no están en el CSS del panel. --}}
<div x-data="{ copied: false }" style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
    <img src="{{ $qr }}" alt="Código QR de la invitación" width="220" height="220">

    <div style="display: flex; width: 100%; align-items: center; gap: 0.5rem;">
        <div style="flex: 1; min-width: 0;">
            <x-filament::input.wrapper>
                <x-filament::input type="text" readonly :value="$url" x-ref="link" x-on:focus="$el.select()" />
            </x-filament::input.wrapper>
        </div>
        <x-filament::button
            icon="heroicon-o-clipboard-document"
            x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
        >
            <span x-show="! copied">Copiar</span>
            <span x-show="copied" x-cloak>Copiado</span>
        </x-filament::button>
    </div>
</div>
