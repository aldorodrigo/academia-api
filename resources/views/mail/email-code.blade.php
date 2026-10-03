<x-mail::message>
# Hola, {{ $name }}

@if ($reset)
Tu código para elegir una contraseña nueva es:
@else
Tu código para confirmar tu cuenta es:
@endif

<x-mail::panel>
<span style="font-size: 28px; letter-spacing: 8px; font-weight: bold;">{{ $code }}</span>
</x-mail::panel>

@if ($reset)
Ingresalo en la app o en el panel. Vence en 15 minutos. Si no lo pediste, podés ignorar este correo: tu contraseña no cambia.
@else
Ingresalo en la app o en el panel. Vence en 15 minutos. Si no creaste una cuenta, podés ignorar este correo.
@endif
</x-mail::message>
