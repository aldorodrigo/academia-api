<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-exclamation-triangle">
        <x-slot name="heading">Morosos</x-slot>
        <x-slot name="description">{{ $families->isEmpty() ? 'Nadie tiene cuotas vencidas.' : 'Las familias con más deuda vencida.' }}</x-slot>
        @if ($url)
            <x-slot name="afterHeader">
                <x-filament::link :href="$url" size="sm">Ver todos</x-filament::link>
            </x-slot>
        @endif

        @if ($families->isNotEmpty())
            <div style="display: grid; gap: 0.6rem;">
                @foreach ($families as $family)
                    <div style="display: flex; gap: 0.75rem; align-items: baseline; justify-content: space-between;">
                        <span>
                            {{ $family['family'] }}
                            <br><span style="opacity: .7; font-size: .85em;">Desde el {{ $family['since'] }}</span>
                        </span>
                        <span style="display: flex; gap: 0.5rem; align-items: center;">
                            <strong>{{ $this->money($family['amount']) }}</strong>
                            @if ($family['whatsapp'])
                                <x-filament::icon-button tag="a" :href="$family['whatsapp']" target="_blank" icon="heroicon-o-chat-bubble-left-ellipsis" label="Escribir por WhatsApp" size="sm" />
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
