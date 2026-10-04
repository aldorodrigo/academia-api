<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-clipboard-document-check">
        <x-slot name="heading">Para hacer</x-slot>

        <div style="display: grid; gap: 0.5rem;">
            @foreach ($items as $item)
                <a href="{{ $item['url'] }}" style="display: flex; gap: 0.5rem; align-items: center;">
                    <x-filament::icon icon="heroicon-m-chevron-right" @class(['h-4 w-4']) style="color: {{ $item['color'] === 'warning' ? 'var(--warning-500)' : 'inherit' }}; width: 1rem; height: 1rem;" />
                    <span>{{ $item['text'] }}</span>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
