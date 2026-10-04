{{--
    Página corta de Tuku a la que llevan los links de los correos (confirmar el correo, "¿Lo llevás?"):
    Tuku en una pose, título, texto y, si hace falta, botones. Cada botón manda un formulario: los links
    de los correos no cambian nada solos (los antivirus de correo los abren).
    `actions`: [['label' => …, 'url' => … (firmada), 'primary' => bool]]
--}}
<x-site.layout :title="$title.' · Tuku'" :description="$message">
    <x-slot:head>
        <meta name="robots" content="noindex">
    </x-slot:head>

    <main id="contenido" class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center px-4 py-12 text-center">
        <a href="{{ config('tuku.url') }}" class="rounded-md">
            <x-site.logo />
        </a>

        <div class="mt-8 w-full rounded-lg border border-line bg-surface-raised p-8 shadow-card">
            <img src="{{ asset("brand/tuku-{$pose}.svg") }}" alt="" class="mx-auto w-32">
            <h1 class="mt-4 font-display text-3xl leading-tight font-extrabold text-balance">{{ $title }}</h1>
            <p class="mt-3 text-lg text-ink-muted">{{ $message }}</p>

            @if (! empty($actions))
                <div class="mt-8 flex flex-col gap-3">
                    @foreach ($actions as $action)
                        <form method="POST" action="{{ $action['url'] }}">
                            @csrf
                            <button type="submit" @class([
                                'inline-flex min-h-12 w-full items-center justify-center rounded-md px-5 text-base font-bold transition',
                                'bg-verde text-on-verde hover:brightness-110' => $action['primary'] ?? false,
                                'border border-line-strong text-ink hover:bg-brote' => ! ($action['primary'] ?? false),
                            ])>
                                {{ $action['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</x-site.layout>
