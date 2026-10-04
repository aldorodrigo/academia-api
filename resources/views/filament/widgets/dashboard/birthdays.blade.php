<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-cake">
        <x-slot name="heading">Cumpleaños</x-slot>
        <x-slot name="description">{{ $birthdays->isEmpty() ? 'Nadie cumple años esta semana.' : 'Esta semana.' }}</x-slot>

        @if ($birthdays->isNotEmpty())
            <div style="display: grid; gap: 0.45rem;">
                @foreach ($birthdays as $birthday)
                    <div style="display: flex; justify-content: space-between; gap: 0.75rem;">
                        <span>{{ $birthday['name'] }} <span style="opacity: .7; font-size: .85em;">· {{ $birthday['age'] }} años</span></span>
                        <span @style(['font-weight: 700' => $birthday['when'] === 'Hoy'])>{{ $birthday['when'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
