{{-- Informes de la comisión: balance del mes con descarga en PDF y Excel (como ReportsScreen de la app). --}}
@php
    $rows = [
        ['Saldo inicial', '₲ 4.350.000', ''],
        ['Ingresos', '₲ 18.450.000', 'text-verde'],
        ['Gastos', '₲ 6.200.000', ''],
    ];
@endphp
<x-site.phone label="Pantalla de la app para la comisión: el balance del mes con ingresos, gastos y saldo final, y descarga en PDF y Excel." title="Informes" back>
    <div class="grid grid-cols-2 overflow-hidden rounded-full border border-line-strong text-center text-xs font-bold">
        <span class="flex items-center justify-center gap-1 bg-brote py-1.5 text-verde"><span class="icon text-base">check</span>Balance</span>
        <span class="border-l border-line-strong py-1.5">Saldos</span>
    </div>

    <div class="flex items-center justify-between px-1">
        <span class="icon text-xl text-ink-muted">chevron_left</span>
        <span class="font-bold">Septiembre 2026</span>
        <span class="icon text-xl text-ink-muted">chevron_right</span>
    </div>

    <div class="rounded-lg bg-surface-raised p-4 shadow-card">
        <p class="mb-2 font-bold">Resumen</p>
        @foreach ($rows as [$label, $amount, $color])
            <div class="flex justify-between py-1">
                <span class="text-ink-muted">{{ $label }}</span>
                <span class="tabular font-semibold whitespace-nowrap {{ $color }}">{{ $amount }}</span>
            </div>
        @endforeach
        <div class="mt-1 flex justify-between border-t border-line pt-2">
            <span class="font-bold">Saldo final</span>
            <span class="tabular font-display text-lg leading-none font-extrabold">₲ 16.600.000</span>
        </div>
    </div>

    <div class="flex items-center justify-between gap-2 rounded-lg bg-aviso-soft px-4 py-3 text-aviso">
        <span class="font-semibold">Gastos pendientes de pago</span>
        <span class="tabular font-bold whitespace-nowrap">₲ 850.000</span>
    </div>

    <div class="rounded-lg bg-surface-raised p-4 shadow-card">
        <p class="mb-2 font-bold">Ingresos</p>
        @foreach ([['Cuotas', '₲ 17.250.000'], ['Inscripciones', '₲ 1.200.000']] as [$label, $amount])
            <div class="flex justify-between py-1">
                <span class="text-ink-muted">{{ $label }}</span>
                <span class="tabular">{{ $amount }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-auto grid grid-cols-2 gap-2">
        <span class="flex items-center justify-center gap-1 rounded-md bg-brote py-2.5 font-bold text-verde"><span class="icon text-lg">picture_as_pdf</span>PDF</span>
        <span class="flex items-center justify-center gap-1 rounded-md bg-brote py-2.5 font-bold text-verde"><span class="icon text-lg">table_view</span>Excel</span>
    </div>
</x-site.phone>
