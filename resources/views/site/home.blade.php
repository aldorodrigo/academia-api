{{-- Landing de Tuku (tukuha.app). Textos: presentación de la marca; precios: config/tuku.php. --}}
@php
    use App\Support\Money;

    // El panel vive en APP_URL (api.tukuha.app) aunque la landing se sirva desde tukuha.app.
    $panelUrl = fn (string $route) => rtrim(config('app.url'), '/').route($route, absolute: false);
    $register = $panelUrl('filament.admin.auth.register');
    $panel = $panelUrl('filament.admin.auth.login');
    $app = config('app.frontend_url');
    $whatsapp = config('tuku.whatsapp')
        ? 'https://wa.me/'.config('tuku.whatsapp').'?text='.rawurlencode('Hola, quiero saber más de Tuku.')
        : null;
    $email = config('tuku.email');
    $gratisHasta = $freeUntil->translatedFormat('j \d\e F \d\e Y');
    $cobraDesde = $freeUntil->copy()->addDay()->translatedFormat('F \d\e Y');

    $highlights = [
        ['wifi_off', 'Funciona sin señal', 'La asistencia queda guardada en el celular y se envía sola cuando vuelve la conexión.'],
        ['notifications', 'Avisos antes de cada clase', 'Hasta tres avisos por clase. La familia responde "Sí, va" o "No va" desde la notificación.'],
        ['receipt_long', 'Recibo de cada pago', 'Cada pago tiene su recibo en PDF, y la familia lo ve en la app.'],
        ['event_repeat', 'Temporadas a tu medida', 'Varias a la vez, por disciplina, y colonias de vacaciones. Cuota mensual o por clase.'],
        ['event_busy', 'Clases suspendidas o reprogramadas', 'Cancelás o cambiás el horario y los tutores se enteran al momento.'],
        ['calendar_month', 'Clases particulares', 'Agenda, reservas desde la app y paquetes de clases con vencimiento.'],
    ];

    $steps = [
        ['person_add', 'Creá tu cuenta', 'Con tu celular o tu correo, en un minuto.'],
        ['checklist', 'Cargá tu organización', 'La guía "Primeros pasos" te lleva por las disciplinas, las categorías con sus horarios y la temporada con sus cuotas.'],
        ['qr_code_2', 'Invitá a tu equipo', 'Profes y familias entran con un link o un código QR.'],
    ];

    $faqs = [
        ['¿Tengo que pagar algo ahora?', "No. Tuku es gratis hasta el {$gratisHasta} y no te pedimos tarjeta para crear la cuenta."],
        ["¿Qué pasa después del {$gratisHasta}?", "Desde {$cobraDesde} elegís el plan que va con tu organización: Instructores, Academias o Clubes, organizaciones y escuelas. Los precios ya están en esta página."],
        ['¿Las familias y los profes pagan?', 'No. El plan lo paga la organización. Familias, técnicos y profesores usan Tuku sin costo.'],
        ['¿Hay que instalar algo?', 'Hoy Tuku se usa desde el navegador del celular o de la computadora. Las apps para Android y iPhone están en camino.'],
        ['¿Sirve para mi organización?', 'Sí, si tenés alumnos, grupos y cuotas: academias de deporte, danza, música o idiomas, escuelas de formación, clubes, colonias de vacaciones, profesores particulares y comisiones de padres. Las palabras se adaptan: "categoría" o "grupo", "técnico" o "profesora".'],
        ['¿Qué pasa si en la cancha no hay señal?', 'El técnico toma la asistencia igual. Queda guardada en el celular y se envía sola cuando vuelve la conexión.'],
    ];

    $icons = [
        'account_circle', 'arrow_back', 'arrow_forward', 'battery_full', 'calendar_month', 'cancel', 'chat', 'check',
        'check_circle', 'checklist', 'chevron_left', 'chevron_right', 'event_busy', 'event_repeat', 'expand_more', 'info',
        'login', 'mail', 'more_vert', 'notifications', 'person_add', 'picture_as_pdf', 'qr_code_2', 'receipt_long',
        'signal_cellular_alt', 'table_view', 'wifi_off',
    ];
@endphp

<x-site.layout
    title="Tuku: cuotas, asistencia y avisos de clase para academias y clubes"
    description="Tuku: cuotas, asistencia y avisos de clase para academias, clubes y escuelas. Todo en una app."
    :icons="$icons"
>
    <x-site.header>
        <x-slot:nav>
            <a href="#funciones" class="hover:text-ink">Funciones</a>
            <a href="#empezar" class="hover:text-ink">Cómo empezar</a>
            <a href="#precios" class="hover:text-ink">Precios</a>
            <a href="#preguntas" class="hover:text-ink">Preguntas</a>
        </x-slot:nav>
        <x-slot:actions>
            <details class="group relative">
                <summary class="flex min-h-10 cursor-pointer list-none items-center gap-1 rounded-md px-3 text-sm font-bold text-ink hover:bg-brote [&::-webkit-details-marker]:hidden">
                    Ingresar
                    <span class="icon text-xl transition group-open:rotate-180" aria-hidden="true">expand_more</span>
                </summary>
                <div class="absolute right-0 mt-2 w-72 rounded-lg border border-line bg-surface-raised p-2 shadow-card">
                    <a href="{{ $panel }}" class="block rounded-md px-3 py-2.5 hover:bg-brote">
                        <span class="block font-bold">Panel de tu organización</span>
                        <span class="block text-sm text-ink-muted">Para la dirección, la comisión y la secretaría.</span>
                    </a>
                    <a href="{{ $app }}" class="block rounded-md px-3 py-2.5 hover:bg-brote">
                        <span class="block font-bold">App de Tuku</span>
                        <span class="block text-sm text-ink-muted">Para familias, técnicos y profesores.</span>
                    </a>
                </div>
            </details>
            <x-site.button :href="$register" size="sm" class="hidden sm:inline-flex">Creá tu cuenta</x-site.button>
        </x-slot:actions>
    </x-site.header>

    <main id="contenido">
        {{-- Portada --}}
        <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 pt-12 pb-16 sm:px-6 md:grid-cols-[1.1fr_1fr] md:pt-20 md:pb-24">
            <div>
                <p class="inline-flex rounded-full bg-sol px-3 py-1 text-sm font-bold text-on-sol">
                    Gratis hasta el {{ $gratisHasta }}
                </p>
                <h1 class="mt-5 font-display text-[2.5rem] leading-[1.1] font-extrabold tracking-tight text-balance sm:text-5xl lg:text-[3.5rem]">
                    Cuotas, asistencia y avisos de clase en una sola app
                </h1>
                <p class="mt-5 max-w-xl text-lg text-ink-muted">
                    Tuku es la app para academias, clubes, escuelas de formación y profesores particulares.
                    Las familias saben qué pagar y avisan si van, los profes toman asistencia desde la cancha
                    y la comisión tiene las cuentas claras.
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <x-site.button :href="$register" icon="arrow_forward" class="flex-row-reverse">Creá tu cuenta gratis</x-site.button>
                    @if ($whatsapp)
                        <x-site.button :href="$whatsapp" variant="secondary" icon="chat">Escribinos por WhatsApp</x-site.button>
                    @else
                        <x-site.button href="#precios" variant="secondary">Ver precios</x-site.button>
                    @endif
                </div>
                <p class="mt-4 text-sm text-ink-muted">Sin tarjeta. La guía "Primeros pasos" te ayuda a cargar todo.</p>
            </div>
            <div class="relative">
                @include('site.screens.familia')
                <img src="{{ asset('brand/tuku-hola.svg') }}" alt="" class="absolute -bottom-6 -left-2 hidden w-36 sm:block md:-left-10 lg:-left-16 lg:w-44">
            </div>
        </section>

        {{-- Para quién --}}
        <section aria-label="Para quién es Tuku" class="border-y border-line bg-surface-raised">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-6 text-center sm:px-6 md:flex-row md:items-center md:justify-between md:text-left">
                <p class="font-semibold text-ink-muted">
                    Para academias · clubes · escuelas de formación · colonias · profesores particulares · comisiones de padres
                </p>
                <p class="shrink-0 font-bold">Club Jakare es el club piloto de Tuku.</p>
            </div>
        </section>

        {{-- Un bloque por público --}}
        <section id="funciones" class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <h2 class="max-w-2xl font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">
                Cada uno ve lo suyo, todos en la misma app
            </h2>

            <div class="mt-16 grid items-center gap-12 md:grid-cols-2">
                <div class="md:order-2">
                    <p class="text-sm font-bold tracking-wide text-verde uppercase">Para técnicos y profesores</p>
                    <h3 class="mt-2 font-display text-2xl leading-tight font-bold text-balance sm:text-3xl">Tomás asistencia aunque no haya señal</h3>
                    <ul class="mt-6 space-y-4 text-lg">
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Todos arrancan presentes: tocás solo a los que faltaron.</li>
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Ves quiénes avisaron que no van, ya justificados.</li>
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Si hace falta, cancelás o reprogramás la clase y todos se enteran.</li>
                    </ul>
                </div>
                <div class="md:order-1">@include('site.screens.tecnico')</div>
            </div>

            <div class="mt-24 grid items-center gap-12 md:grid-cols-2">
                <div>
                    <p class="text-sm font-bold tracking-wide text-verde uppercase">Para la comisión o la dirección</p>
                    <h3 class="mt-2 font-display text-2xl leading-tight font-bold text-balance sm:text-3xl">Las cuentas claras, sin planillas sueltas</h3>
                    <ul class="mt-6 space-y-4 text-lg">
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Cuotas por mes o por clase, becas y descuentos por hermano.</li>
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Saldo de cada familia y cuotas vencidas, en un vistazo.</li>
                        <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Balance del mes en PDF y Excel, listo para la reunión.</li>
                    </ul>
                </div>
                <div>@include('site.screens.comision')</div>
            </div>

            <div class="mt-24 rounded-xl bg-brote p-8 sm:p-12">
                <div class="grid gap-8 md:grid-cols-[1.4fr_1fr] md:items-center">
                    <div>
                        <p class="text-sm font-bold tracking-wide text-verde uppercase">Para las familias</p>
                        <h3 class="mt-2 font-display text-2xl leading-tight font-bold text-balance sm:text-3xl">Todo lo de tus hijos, en el celular</h3>
                        <ul class="mt-6 space-y-3 text-lg">
                            <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Ves lo que tenés que pagar y lo que ya pagaste, con tus recibos.</li>
                            <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Te avisamos antes de cada clase y respondés con un toque.</li>
                            <li class="flex gap-3"><span class="icon icon-fill mt-0.5 text-verde" aria-hidden="true">check_circle</span>Ves la asistencia del mes de cada hijo.</li>
                        </ul>
                    </div>
                    <div class="rounded-lg bg-surface-raised p-6 shadow-card">
                        <p class="font-bold">¿Tu club o tu academia ya usa Tuku?</p>
                        <p class="mt-1 text-ink-muted">Entrá con el celular o el correo que les diste.</p>
                        <x-site.button :href="$app" variant="primary" icon="login" class="mt-4 w-full">Abrir la app</x-site.button>
                    </div>
                </div>
            </div>
        </section>

        {{-- Lo que hace distinto a Tuku --}}
        <section class="border-t border-line bg-surface-raised">
            <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <h2 class="max-w-2xl font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">Pensada para la cancha, el salón y la pileta</h2>
                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($highlights as [$icon, $title, $text])
                        <div class="rounded-lg border border-line bg-surface p-6">
                            <span class="icon grid size-11 place-items-center rounded-md bg-brote text-verde" aria-hidden="true">{{ $icon }}</span>
                            <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                            <p class="mt-1 text-ink-muted">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Cómo empezar: el salto une los tres pasos --}}
        <section id="empezar" class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <h2 class="font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">Empezá hoy, en tres pasos</h2>
            <p class="mt-3 max-w-xl text-lg text-ink-muted">No hace falta que nadie te lo instale: la guía te acompaña desde el primer día.</p>
            <ol class="mt-12 grid gap-10 md:grid-cols-3">
                @foreach ($steps as $i => [$icon, $title, $text])
                    <li class="relative">
                        @unless ($loop->last)
                            {{-- El salto: un arco punteado hasta el paso siguiente. --}}
                            <svg aria-hidden="true" class="pointer-events-none absolute top-0 left-17 hidden h-7 w-[calc(100%-2.25rem)] text-verde md:block" viewBox="0 0 200 28" preserveAspectRatio="none" fill="none">
                                <path d="M2 26 Q100 -18 198 26" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="0.1 10" vector-effect="non-scaling-stroke"/>
                            </svg>
                        @endunless
                        <span class="grid size-14 place-items-center rounded-full bg-verde font-display text-2xl font-extrabold text-on-verde">{{ $i + 1 }}</span>
                        <h3 class="mt-4 flex items-center gap-2 text-xl font-bold">
                            <span class="icon text-verde" aria-hidden="true">{{ $icon }}</span>{{ $title }}
                        </h3>
                        <p class="mt-1 text-ink-muted">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Precios --}}
        <section id="precios" class="border-t border-line bg-surface-raised">
            <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="inline-flex rounded-full bg-sol px-3 py-1 text-sm font-bold text-on-sol">Sin tarjeta</p>
                    <h2 class="mt-4 font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">Gratis hasta el {{ $gratisHasta }}</h2>
                    <p class="mt-3 text-lg text-ink-muted">
                        Usá Tuku completo sin pagar nada hasta esa fecha.
                        Desde {{ $cobraDesde }} elegís el plan que va con tu organización.
                    </p>
                </div>

                <div class="mt-12 grid gap-6 lg:grid-cols-3">
                    @foreach ($plans as $plan)
                        <div @class([
                            'grid gap-6 rounded-lg p-6 sm:p-8 lg:row-span-4 lg:grid-rows-subgrid',
                            'border-2 border-verde bg-surface shadow-card' => $plan['key'] === 'academias',
                            'border border-line bg-surface' => $plan['key'] !== 'academias',
                        ])>
                            <div>
                                <h3 class="font-display text-2xl leading-tight font-bold">{{ $plan['name'] }}</h3>
                                <p class="mt-1 text-ink-muted">{{ $plan['for'] }}</p>
                            </div>
                            <div>
                                <p>
                                    <span class="tabular font-display text-4xl font-extrabold">{{ Money::pyg($plan['price'])->format() }}</span>
                                    <span class="text-ink-muted">por mes</span>
                                </p>
                                <p class="font-bold">Hasta {{ $plan['students'] }} alumnos</p>
                                <p class="text-sm text-ink-muted">Desde {{ $cobraDesde }}</p>
                            </div>
                            <ul class="space-y-3">
                                @foreach ($plan['features'] as $feature)
                                    <li class="flex gap-2"><span class="icon text-xl text-verde" aria-hidden="true">check</span>{{ $feature }}</li>
                                @endforeach
                            </ul>
                            <x-site.button :href="$register" :variant="$plan['key'] === 'academias' ? 'primary' : 'secondary'" class="w-full self-end">
                                Empezá gratis
                            </x-site.button>
                        </div>
                    @endforeach
                </div>
                <p class="mt-8 text-center text-ink-muted">
                    Precios mensuales en guaraníes. ¿Más de {{ collect($plans)->max('students') }} alumnos?
                    <a href="{{ $whatsapp ?? 'mailto:'.$email }}" class="font-bold text-verde underline underline-offset-4">Escribinos</a> y lo armamos juntos.
                </p>
            </div>
        </section>

        {{-- Preguntas --}}
        <section id="preguntas" class="mx-auto max-w-3xl px-4 py-20 sm:px-6">
            <h2 class="font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">Preguntas frecuentes</h2>
            <div class="mt-8 divide-y divide-line border-y border-line">
                @foreach ($faqs as [$question, $answer])
                    <details class="group py-2">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-sm py-3 text-lg font-bold [&::-webkit-details-marker]:hidden">
                            {{ $question }}
                            <span class="icon text-ink-muted transition group-open:rotate-180" aria-hidden="true">expand_more</span>
                        </summary>
                        <p class="pb-4 text-ink-muted">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        {{-- Cierre --}}
        <section class="bg-marca text-on-marca">
            <div class="mx-auto grid max-w-6xl items-center gap-8 px-4 py-16 sm:px-6 md:grid-cols-[auto_1fr]">
                <img src="{{ asset('brand/tuku-salta.svg') }}" alt="" class="mx-auto w-32 md:w-40">
                <div class="text-center md:text-left">
                    <h2 class="font-display text-3xl leading-tight font-extrabold text-balance sm:text-4xl">¡Jaha! Tu organización, en orden desde hoy</h2>
                    <p class="mt-2 text-lg opacity-90">Gratis hasta el {{ $gratisHasta }}. Sin tarjeta.</p>
                    <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row md:justify-start">
                        <x-site.button :href="$register" variant="sol">Creá tu cuenta gratis</x-site.button>
                        @if ($whatsapp)
                            <x-site.button :href="$whatsapp" variant="on-marca" icon="chat">Escribinos por WhatsApp</x-site.button>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-line">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.5fr_1fr_1fr]">
            <div>
                <x-site.logo />
                <p class="mt-4 font-semibold">Las actividades de tus hijos, en un solo lugar.</p>
                <p class="mt-2 text-ink-muted">Hecha en Paraguay. Tuku es un saltamontes: <em>tuku</em> en guaraní.</p>
            </div>
            <div>
                <h2 class="font-bold">Contacto</h2>
                <ul class="mt-3 space-y-2 text-ink-muted">
                    @if ($whatsapp)
                        <li><a href="{{ $whatsapp }}" class="flex items-center gap-2 hover:text-ink"><span class="icon text-xl" aria-hidden="true">chat</span>WhatsApp</a></li>
                    @endif
                    <li><a href="mailto:{{ $email }}" class="flex items-center gap-2 hover:text-ink"><span class="icon text-xl" aria-hidden="true">mail</span>{{ $email }}</a></li>
                </ul>
            </div>
            <div>
                <h2 class="font-bold">Seguinos</h2>
                <ul class="mt-3 space-y-2 text-ink-muted">
                    @foreach (config('tuku.social') as $network => $url)
                        <li><a href="{{ $url }}" class="hover:text-ink" rel="me">{{ $network }} · {{ $network === 'X' ? '@tukuhaapp' : '@tukuha.app' }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="border-t border-line">
            <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-ink-muted sm:flex-row sm:justify-between sm:px-6">
                <p>© {{ now()->year }} Tuku · tukuha.app</p>
                <p><a href="{{ $panel }}" class="hover:text-ink">Panel</a> · <a href="{{ $app }}" class="hover:text-ink">App</a></p>
            </div>
        </div>
    </footer>
</x-site.layout>
