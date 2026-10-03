<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-rocket-launch" icon-color="primary">
        <x-slot name="heading">Configurá tu club · {{ $done }} de {{ $total }}</x-slot>
        @if ($next)
            <x-slot name="description">Sigue: {{ $next }}</x-slot>
        @endif
        <x-slot name="afterHeader">
            <x-filament::button tag="a" :href="$url">Seguir</x-filament::button>
        </x-slot>
    </x-filament::section>
</x-filament-widgets::widget>
