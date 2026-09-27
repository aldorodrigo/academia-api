{{-- Informes: pestañas, filtros y descarga en PDF / Excel. --}}
<x-filament-panels::page>
    @php($report = $this->report())
    @php($data = $report['data'])

    <x-filament::tabs>
        @foreach (['balance' => 'Balance', 'saldos' => 'Saldos por familia', 'morosos' => 'Morosos'] as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" wire:click="$set('tab', '{{ $key }}')">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div style="display: flex; gap: 1rem; align-items: end; flex-wrap: wrap;">
        @if ($tab === 'balance')
            <label>Desde<br><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
            <label>Hasta<br><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        @elseif ($tab === 'morosos')
            <label>Meses de atraso (mínimo)<br><x-filament::input.wrapper><x-filament::input type="number" min="1" wire:model.live="minMonths" /></x-filament::input.wrapper></label>
        @endif
        <x-filament::button tag="a" :href="$report['links']['pdf_url']" target="_blank" icon="heroicon-o-document-text" color="gray">PDF</x-filament::button>
        <x-filament::button tag="a" :href="$report['links']['xlsx_url']" icon="heroicon-o-table-cells" color="gray">Excel</x-filament::button>
    </div>

    @php($row = fn ($label, $amount, $bold = false) => '<tr><td style="padding:.4rem 0;'.($bold ? 'font-weight:600;' : '').'">'.e($label).'</td><td style="text-align:right;'.($bold ? 'font-weight:600;' : '').'">'.e($this->money($amount)).'</td></tr>')

    @if ($tab === 'balance')
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
            <x-filament::section heading="Resumen">
                <table style="width: 100%;">
                    {!! $row('Saldo inicial', $data['opening_balance']) !!}
                    {!! $row('Ingresos', $data['income']['total']) !!}
                    {!! $row('Gastos', -$data['expenses']['total']) !!}
                    @if ($data['other']) {!! $row('Otros movimientos', $data['other']) !!} @endif
                    {!! $row('Saldo final', $data['closing_balance'], true) !!}
                    @if ($data['pending_expenses']) {!! $row('Gastos pendientes de pago', $data['pending_expenses']) !!} @endif
                </table>
            </x-filament::section>
            <x-filament::section heading="Ingresos">
                <table style="width: 100%;">
                    @forelse ($data['income']['lines'] as $line) {!! $row($line['label'], $line['amount']) !!} @empty <tr><td>Sin ingresos.</td></tr> @endforelse
                </table>
            </x-filament::section>
            <x-filament::section heading="Gastos">
                <table style="width: 100%;">
                    @forelse ($data['expenses']['lines'] as $line) {!! $row($line['label'], $line['amount']) !!} @empty <tr><td>Sin gastos.</td></tr> @endforelse
                </table>
            </x-filament::section>
            <x-filament::section heading="Cuentas al cierre">
                <table style="width: 100%;">
                    @foreach ($data['accounts'] as $account) {!! $row($account['name'], $account['balance']) !!} @endforeach
                </table>
            </x-filament::section>
        </div>
    @else
        <x-filament::section :heading="$tab === 'saldos' ? 'Pendiente '.$this->money($data['totals']['pending']).' · Vencido '.$this->money($data['totals']['overdue']).' · Saldo a favor '.$this->money($data['totals']['credit']) : 'Total vencido '.$this->money($data['total']).' · '.count($data['families']).' familias'">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid rgba(0,0,0,.1);">
                        <th style="padding:.5rem 0;">Familia</th><th>Jugadores</th>
                        @if ($tab === 'saldos')
                            <th style="text-align:right;">Pendiente</th><th style="text-align:right;">Vencido</th><th style="text-align:right;">Saldo a favor</th>
                        @else
                            <th style="text-align:right;">Vencido</th><th style="text-align:right;">Meses</th><th>Desde</th><th>Contacto</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data['families'] as $family)
                        <tr style="border-bottom: 1px solid rgba(0,0,0,.05);">
                            <td style="padding:.5rem 0;">{{ $family['family'] }}</td>
                            <td>{{ implode(', ', $family['students']) }}</td>
                            @if ($tab === 'saldos')
                                <td style="text-align:right;">{{ $this->money($family['pending']) }}</td>
                                <td style="text-align:right;">{{ $this->money($family['overdue']) }}</td>
                                <td style="text-align:right;">{{ $this->money($family['credit']) }}</td>
                            @else
                                <td style="text-align:right;">{{ $this->money($family['overdue']) }}</td>
                                <td style="text-align:right;">{{ $family['months_overdue'] }}</td>
                                <td>{{ \Carbon\Carbon::parse($family['oldest_due_on'])->format('d/m/Y') }}</td>
                                <td>{{ $family['contact']['name'] ?? '' }} {{ $family['contact']['phone'] ?? '' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:.75rem 0;">{{ $tab === 'saldos' ? 'Todas las familias están al día.' : 'No hay morosos.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
