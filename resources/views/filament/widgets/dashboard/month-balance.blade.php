<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-scale">
        <x-slot name="heading">Balance de {{ $month }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::link :href="$url" size="sm">Informes</x-filament::link>
        </x-slot>

        <div style="display: grid; gap: 0.45rem;">
            <div style="display: flex; justify-content: space-between;"><span>Ingresos</span><strong>{{ $this->money($income) }}</strong></div>
            <div style="display: flex; justify-content: space-between;"><span>Gastos</span><strong>{{ $this->money($expenses) }}</strong></div>
            <div style="display: flex; justify-content: space-between; border-top: 1px solid rgba(120,120,120,.25); padding-top: .45rem;">
                <span>Resultado del mes</span><strong>{{ $this->money($income - $expenses) }}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; opacity: .75;"><span>Saldo en cuentas</span><span>{{ $this->money($closing) }}</span></div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
