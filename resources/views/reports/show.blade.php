{{-- Informe genérico (balance, saldos, morosos): secciones con tabla. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 13px; margin: 18px 0 6px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 4px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f3f3f3; }
        td.amount, th.amount { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $organization->name }}</h1>
    <div class="muted">{{ $title }} · generado el {{ $organization->today()->format('d/m/Y') }}</div>

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @if (count($section['rows']) === 0)
            <p class="muted">Sin datos.</p>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach ($section['headers'] as $i => $header)
                            <th class="{{ in_array($i, $section['money'], true) ? 'amount' : '' }}">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $value)
                                <td class="{{ in_array($i, $section['money'], true) ? 'amount' : '' }}">{{ $value }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
