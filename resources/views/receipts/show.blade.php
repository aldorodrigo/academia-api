{{-- Recibo de pago (comprobante interno del club, no es factura). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo N° {{ $payment->receiptLabel() }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .header { width: 100%; margin: 0 0 16px; }
        .header td { border: none; padding: 0; vertical-align: top; }
        .right { text-align: right; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 16px; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; }
        td.amount, th.amount { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; }
        .muted { color: #666; }
        .void { position: fixed; top: 35%; left: 10%; font-size: 90px; color: rgba(200, 0, 0, .18); transform: rotate(-25deg); }
    </style>
</head>
<body>
    @if ($payment->isVoided())
        <div class="void">ANULADO</div>
    @endif

    <table class="header">
        <tr>
            <td>
                <h1>{{ $organization->name }}</h1>
                <div class="muted">Comprobante interno de pago</div>
            </td>
            <td class="right">
                <h2>Recibo N° {{ $payment->receiptLabel() }}</h2>
                <div>{{ $payment->received_on->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <p>
        Recibimos de <strong>{{ $payment->guardian?->full_name ?? $payment->family->name }}</strong>
        la suma de <strong>{{ $money($payment->amount)->inWords() }}</strong>
        ({{ $money($payment->amount)->format() }}) en concepto de:
    </p>

    <table>
        <thead>
            <tr><th>{{ $studentTerm ?? $organization->term('student') }}</th><th>Concepto</th><th class="amount">Monto</th></tr>
        </thead>
        <tbody>
            @foreach ($allocations as $allocation)
                <tr>
                    <td>{{ $allocation->charge->student->full_name }}</td>
                    <td>
                        {{ $allocation->charge->description }}
                        @if ($allocation->early_payment_discount > 0)
                            <br><span class="muted">{{ $allocation->early_payment_label }}: {{ $money(-$allocation->early_payment_discount)->format() }}</span>
                        @endif
                    </td>
                    <td class="amount">{{ $money($allocation->amount)->format() }}</td>
                </tr>
            @endforeach
            @if ($credit > 0)
                <tr><td></td><td>Saldo a favor</td><td class="amount">{{ $money($credit)->format() }}</td></tr>
            @endif
            <tr class="total"><td></td><td>Total</td><td class="amount">{{ $money($payment->amount)->format() }}</td></tr>
        </tbody>
    </table>

    <p class="muted">
        Forma de pago: {{ $payment->method->label() }}{{ $payment->reference ? ' · Ref. '.$payment->reference : '' }}
        · Cuenta: {{ $payment->moneyAccount->name }}
        @if ($payment->creator)
            · Cobró: {{ $payment->creator->name }}
        @endif
    </p>
    @if ($payment->isVoided())
        <p><strong>Anulado:</strong> {{ $payment->void_reason }}</p>
    @endif
</body>
</html>
