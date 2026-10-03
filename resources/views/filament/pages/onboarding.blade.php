{{-- Primeros pasos: lista de pasos con su estado y el botón para hacer cada uno. Estilos inline (el CSS del panel no tiene las clases propias). --}}
<x-filament-panels::page>
    @php($checklist = static::checklist())
    @php($steps = collect($checklist['steps'])->keyBy('key'))
    @php($percent = $checklist['total'] === 0 ? 0 : (int) round($checklist['done'] * 100 / $checklist['total']))

    <div style="display: flex; align-items: center; gap: 1rem;">
        <div style="flex: 1; height: 0.6rem; border-radius: 9999px; background: rgba(120, 120, 120, 0.2); overflow: hidden;">
            <div style="height: 100%; width: {{ $percent }}%; background: rgb(var(--primary-500)); border-radius: 9999px;"></div>
        </div>
        <span style="font-weight: 600; white-space: nowrap;">{{ $checklist['done'] }} de {{ $checklist['total'] }}</span>
    </div>

    @if ($checklist['completed'])
        <x-filament::section icon="heroicon-o-sparkles" icon-color="success">
            <x-slot name="heading">¡Todo listo!</x-slot>
            {{ filament()->getTenant()->name }} ya tiene lo básico para empezar. Todo lo que configuraste se puede cambiar cuando quieras.
        </x-filament::section>
    @endif

    @foreach ($steps as $key => $step)
        @php($blocker = $step['blocked_by'] ? $steps[$step['blocked_by']]['title'] : null)
        @php($icon = match ($step['status']) {
            'done' => 'heroicon-s-check-circle',
            'skipped' => 'heroicon-o-minus-circle',
            'locked' => 'heroicon-o-lock-closed',
            default => 'heroicon-o-arrow-right-circle',
        })
        @php($color = match ($step['status']) {
            'done' => 'success',
            'pending' => $checklist['next'] === $key ? 'primary' : 'gray',
            default => 'gray',
        })

        <x-filament::section :icon="$icon" :icon-color="$color" compact>
            <x-slot name="heading">{{ $loop->iteration }}. {{ $step['title'] }}</x-slot>
            <x-slot name="description">
                @switch($step['status'])
                    @case('done') {{ $step['summary'] ?? $step['description'] }} @break
                    @case('skipped') Lo dejaste para después. @break
                    @case('locked') Primero: {{ $blocker }} @break
                    @default {{ $step['description'] }} · {{ $step['minutes'] }} min
                @endswitch
            </x-slot>

            @if ($step['status'] !== 'locked')
                <x-slot name="afterHeader">
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        @switch($key)
                            @case('programs') {{ $this->programsAction }} @break
                            @case('groups') {{ $this->groupsAction }} @break
                            @case('season') {{ $this->seasonAction }} @break
                            @case('instructors')
                                {{ $this->teachingAction }}
                                {{ $this->inviteInstructorAction }}
                                @if ($step['status'] === 'pending') {{ $this->skipInstructorsAction }} @endif
                                @break
                        @endswitch
                    </div>
                </x-slot>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
