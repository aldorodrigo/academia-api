{{-- Inicio del tutor: próxima clase con "¿Lo llevás?" (como NextClassCard de la app). --}}
<x-site.phone label="Pantalla de la app para familias: la próxima clase de cada hijo con los botones Sí, va y No va." title="Academia Ritmo" action="account_circle">
    <p class="font-display text-xl font-bold">Hola, Laura.</p>

    <div class="rounded-lg bg-surface-raised p-4 shadow-card">
        <p class="font-bold">Mateo tiene Natación el martes 6/10 a las 17:00</p>
        <p class="text-xs text-ink-muted">Nivel 2 · Pileta cubierta</p>
        <div class="mt-3 flex items-center gap-2">
            <span class="mr-auto font-bold">¿Lo llevás?</span>
            <span class="rounded-full border border-line-strong px-4 py-1.5 font-bold">No va</span>
            <span class="rounded-full bg-verde px-4 py-1.5 font-bold text-on-verde">Sí, va</span>
        </div>
    </div>

    <div class="rounded-lg bg-surface-raised p-4 shadow-card">
        <p class="font-bold">Sofía tiene Danza el jueves 8/10 a las 18:00</p>
        <p class="text-xs text-ink-muted">Infantil · Salón 1</p>
        <div class="mt-3 flex items-center gap-2">
            <span class="icon icon-fill text-xl text-verde">check_circle</span>
            <span class="mr-auto">Avisaste que va.</span>
            <span class="font-bold text-verde">Cambiar</span>
        </div>
    </div>

    <p class="mt-2 font-bold">Mis hijos</p>
    @foreach ([['MR', 'Mateo Ruiz', '9 años · Natación'], ['SR', 'Sofía Ruiz', '6 años · Danza']] as [$initials, $name, $detail])
        <div class="flex items-center gap-3 rounded-lg bg-surface-raised p-3 shadow-card">
            <span class="grid size-9 place-items-center rounded-full bg-brote text-xs font-bold text-verde">{{ $initials }}</span>
            <span class="mr-auto">
                <span class="block font-semibold">{{ $name }}</span>
                <span class="block text-xs text-ink-muted">{{ $detail }}</span>
            </span>
            <span class="rounded-sm bg-brote px-2 py-1 text-[11px] font-bold text-verde">Activo</span>
        </div>
    @endforeach
</x-site.phone>
