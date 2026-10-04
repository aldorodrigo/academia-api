{{-- Varios botones en una fila (ej. "Sí, va" / "No va"); en el celular se apilan. --}}
@props(['actions'])
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@foreach ($actions as $action)
<td class="action-cell">
<a href="{{ $action['url'] }}" class="button button-{{ $action['color'] ?? 'primary' }}" target="_blank" rel="noopener">{{ $action['label'] }}</a>
</td>
@endforeach
</tr>
</table>
</td>
</tr>
</table>
