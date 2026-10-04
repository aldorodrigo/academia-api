<x-filament-widgets::widget>
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
        @foreach ($buttons as $button)
            <x-filament::button tag="a" :href="$button['url']" :icon="$button['icon']" :color="$loop->first ? 'primary' : 'gray'">
                {{ $button['label'] }}
            </x-filament::button>
        @endforeach
    </div>
</x-filament-widgets::widget>
