<x-mail::message>
<x-mail::mascot pose="hola" />

# Hola, {{ $name }}

Tu cuenta de Tuku tiene este correo además de tu celular {{ $phone }}. Confirmalo y te mandamos acá una copia de los avisos y de los códigos que te lleguen por WhatsApp.

<x-mail::button :url="$url">
Confirmar mi correo
</x-mail::button>

<x-slot:subcopy>
El link vence en {{ $days }} días. Si no tenés una cuenta en Tuku, podés ignorar este correo.
</x-slot:subcopy>
</x-mail::message>
