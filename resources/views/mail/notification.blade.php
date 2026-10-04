{{-- Copia por correo de un aviso push (App\Notifications\PushNotification). --}}
<x-mail::message>
@if ($pose)
<x-mail::mascot :pose="$pose" />
@endif

# {{ $title }}

@if ($name)
Hola, {{ $name }}:
@endif

{{ $body }}

<x-mail::buttons :actions="$actions" />

<x-slot:subcopy>
Te llega este correo porque es el de tu cuenta de Tuku: es una copia de los avisos que te llegan al celular.
</x-slot:subcopy>
</x-mail::message>
