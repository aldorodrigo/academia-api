<x-mail::message>
# Te invitaron a {{ $organization }}

Te sumaron como **{{ $roles }}**. Para entrar, tocá el botón y creá tu cuenta (o ingresá con la que ya tenés).

<x-mail::button :url="$url">
Aceptar invitación
</x-mail::button>

También podés escanear este código con la cámara del celular:

<img src="{{ $message->embedData(\App\Support\Invitations\InvitationQr::png($url), 'invitacion.png', 'image/png') }}" alt="Código QR de la invitación" width="200" height="200">

La invitación vence el {{ $expiresAt }} y sirve una sola vez. Si no esperabas este correo, podés ignorarlo.
</x-mail::message>
