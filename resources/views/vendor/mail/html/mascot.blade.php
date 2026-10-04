{{-- Tuku en una pose del sistema de marca: hola (bienvenida), salta (confirmado), descansa (no hay clase). --}}
@props(['pose'])
<table class="mascot" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<img src="{{ asset("brand/correo/tuku-{$pose}.png") }}" width="120" height="144" alt="">
</td>
</tr>
</table>
