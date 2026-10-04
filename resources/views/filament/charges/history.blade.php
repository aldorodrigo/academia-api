{{-- Historial de un cargo: emisión, pagos, anulación y reemplazo. --}}
<ol style="display: grid; gap: .75rem; list-style: none; padding: 0; margin: 0;">
    @foreach ($entries as $entry)
        <li>
            <div>{{ $entry['text'] }}</div>
            <div style="font-size: .875rem; opacity: .7;">
                {{ $entry['at']->timezone($timezone)->format('d/m/Y H:i') }}@if ($entry['by']) · {{ $entry['by'] }}@endif
            </div>
        </li>
    @endforeach
</ol>
