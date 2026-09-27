{{-- Detalle de un cargo: monto base, ajustes y total. --}}
<div style="display: grid; gap: .5rem;">
    @foreach ($lines as [$label, $amount])
        <div style="display: flex; justify-content: space-between;">
            <span>{{ $label }}</span>
            <span>{{ $amount }}</span>
        </div>
    @endforeach
    <hr>
    <div style="display: flex; justify-content: space-between; font-weight: 600;">
        <span>Total</span>
        <span>{{ $total }}</span>
    </div>
    <div style="font-size: .875rem; opacity: .7;">
        Vence {{ $charge->due_on->format('d/m/Y') }} · Estado: {{ $charge->status()->label() }}
        @if ($charge->isVoided())
            <br>Anulado: {{ $charge->void_reason }}
        @endif
    </div>
</div>
