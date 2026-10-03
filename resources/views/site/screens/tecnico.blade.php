{{-- Planilla de asistencia del técnico, guardada sin conexión (como ClassAttendanceScreen de la app). --}}
@php
    $students = [
        ['AB', 'Axel Benítez', 'present'],
        ['DG', 'Diego González', 'present'],
        ['JM', 'Juan Martínez', 'absent'],
        ['LR', 'Lucas Ramírez', 'present'],
        ['NS', 'Nahuel Sosa', 'justified'],
        ['TV', 'Tiago Villalba', 'present'],
    ];
    $status = [
        'present' => ['check_circle', 'Presente', 'text-verde'],
        'absent' => ['cancel', 'Ausente', 'text-error'],
        'justified' => ['info', 'Justificado', 'text-aviso'],
    ];
@endphp
<x-site.phone label="Pantalla de la app para técnicos: la asistencia de la clase con presentes, ausentes y justificados, guardada sin conexión." title="Sub-10" back action="more_vert">
    <div>
        <p class="font-bold">Fútbol · Mar 6/10 17:00–18:30 · Cancha 2</p>
        <p class="text-xs text-ink-muted">Tocá a los que faltaron. Para justificar, usá ⋮.</p>
    </div>

    <div class="divide-y divide-line rounded-lg bg-surface-raised shadow-card">
        @foreach ($students as [$initials, $name, $mark])
            @php([$icon, $text, $color] = $status[$mark])
            <div class="flex items-center gap-3 px-3 py-2">
                <span class="grid size-8 place-items-center rounded-full bg-brote text-[11px] font-bold text-verde">{{ $initials }}</span>
                <span class="mr-auto">
                    <span class="block font-semibold">{{ $name }}</span>
                    @if ($mark === 'justified')
                        <span class="block text-[11px] text-ink-muted">El tutor avisó que no va</span>
                    @endif
                </span>
                <span class="flex items-center gap-1 text-xs font-bold {{ $color }}">
                    <span class="icon icon-fill text-lg">{{ $icon }}</span>{{ $text }}
                </span>
            </div>
        @endforeach
    </div>

    <div class="mt-auto rounded-md bg-ink px-3 py-2.5 text-xs text-surface">
        Sin conexión: quedó guardada en el celular y se envía sola al volver la señal.
    </div>
    <span class="rounded-md bg-verde py-2.5 text-center font-bold text-on-verde">Guardar asistencia (4 de 6)</span>
</x-site.phone>
