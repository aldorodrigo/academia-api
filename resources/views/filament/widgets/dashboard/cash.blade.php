<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-building-library">
        <x-slot name="heading">Caja</x-slot>
        <x-slot name="description">Total {{ $this->money($total) }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::link :href="$url" size="sm">Cuentas</x-filament::link>
        </x-slot>

        <div style="display: grid; gap: 0.45rem;">
            @foreach ($accounts as $account)
                <div style="display: flex; justify-content: space-between; gap: 0.75rem;">
                    <span>{{ $account['name'] }}</span>
                    <strong style="font-variant-numeric: tabular-nums;">{{ $this->money($account['balance']) }}</strong>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
