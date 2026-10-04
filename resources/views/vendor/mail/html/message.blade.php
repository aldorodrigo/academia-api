{{-- Correo con la marca Tuku: logo arriba, tarjeta blanca y pie con la presentación. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('tuku.url')">
Tuku
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**Tuku**: cuotas, asistencia y avisos de clase para academias, clubes y escuelas. Hecha en Paraguay.<br>
[{{ Str::after(config('tuku.url'), '://') }}]({{ config('tuku.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
