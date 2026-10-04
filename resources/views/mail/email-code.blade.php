<x-mail::message>
@unless ($reset)
<x-mail::mascot pose="hola" />
@endunless

# Hola, {{ $firstName }}

@if ($reset)
Tu código para elegir una contraseña nueva es:
@else
Tu código para confirmar tu cuenta de Tuku es:
@endif

<x-mail::panel>
<span class="code">{{ $code }}</span>
</x-mail::panel>

Ingresalo en la app o en el panel. Vence en 15 minutos y es solo para vos: no lo compartas.

@if ($whatsappDisplay)
También te mandamos un código por WhatsApp al {{ $whatsappDisplay }}. Podés usar cualquiera de los dos.
@endif

@if ($confirmUrl)
<x-mail::button :url="$confirmUrl" color="secondary">
Confirmar mi correo
</x-mail::button>

Confirmalo y te mandamos a este correo una copia de los avisos y los códigos que te lleguen por WhatsApp.
@endif

<x-slot:subcopy>
@if ($reset)
Si no lo pediste, podés ignorar este correo: tu contraseña no cambia.
@else
Si no creaste una cuenta en Tuku, podés ignorar este correo.
@endif
</x-slot:subcopy>
</x-mail::message>
