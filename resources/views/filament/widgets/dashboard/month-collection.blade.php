<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-banknotes">
        <x-slot name="heading">Cobranza de {{ $month }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::link :href="$url" size="sm">Cuotas</x-filament::link>
        </x-slot>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1rem;">
            <div>
                <div style="opacity: .7; font-size: .85em;">Cobrado</div>
                <div style="font-size: 1.35em; font-weight: 700;">{{ $this->money($collected) }}</div>
            </div>
            <div>
                <div style="opacity: .7; font-size: .85em;">Falta cobrar del mes</div>
                <div style="font-size: 1.35em; font-weight: 700;">{{ $this->money($due_pending) }}</div>
                @if ($due > 0)<div style="opacity: .7; font-size: .8em;">de {{ $this->money($due) }}</div>@endif
            </div>
            <div>
                <div style="opacity: .7; font-size: .85em;">Vencido</div>
                <div style="font-size: 1.35em; font-weight: 700; {{ $overdue > 0 ? 'color: var(--danger-600);' : '' }}">{{ $this->money($overdue) }}</div>
                @if ($overdue_families > 0)<div style="opacity: .7; font-size: .8em;">{{ $overdue_families }} {{ $overdue_families === 1 ? 'familia' : 'familias' }}</div>@endif
            </div>
            <div>
                <div style="opacity: .7; font-size: .85em;">Vence esta semana</div>
                <div style="font-size: 1.35em; font-weight: 700;">{{ $this->money($week['amount']) }}</div>
                @if ($week['count'] > 0)<div style="opacity: .7; font-size: .8em;">{{ $week['count'] }} {{ $week['count'] === 1 ? 'cuota' : 'cuotas' }}</div>@endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
