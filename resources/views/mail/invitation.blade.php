<x-mail::message>
<x-mail::mascot pose="hola" />

# Te invitaron a {{ $organization }}

{{ $greeting }} {{ $organization }} te sumó a Tuku como **{{ $roles }}**. Tuku es la app de cuotas, asistencia y avisos de clase.

Tocá el botón y creá tu cuenta (o ingresá con la que ya tenés):

<x-mail::button :url="$url">
Aceptar invitación
</x-mail::button>

También podés escanear este código con la cámara del celular:

<p style="text-align: center;"><img src="{{ $message->embedData(\App\Support\Invitations\InvitationQr::png($url), 'invitacion.png', 'image/png') }}" alt="Código QR de la invitación" width="200" height="200"></p>

<x-slot:subcopy>
La invitación vence el {{ $expiresAt }} y sirve una sola vez. Si no esperabas este correo, podés ignorarlo.
</x-slot:subcopy>
</x-mail::message>
