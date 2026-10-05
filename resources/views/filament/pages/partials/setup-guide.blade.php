{{-- Guía "Primeros pasos" en el Escritorio: la lista de pasos con su estado y el botón para hacer cada uno, o achicada a una barra. Estilos inline (el CSS del panel no tiene las clases propias). --}}
@php($checklist = static::checklist())
@php($steps = collect($checklist['steps'])->keyBy('key'))
@php($percent = $checklist['total'] === 0 ? 0 : (int) round($checklist['done'] * 100 / $checklist['total']))

{{-- La propuesta de vocabulario sin contestar se recuerda hasta que use las palabras o las deje como estaban. --}}
@php($vocabulary = $checklist['terminology_suggestion'])

@if ($vocabularyOnly ?? false)
    <x-filament::section icon="heroicon-o-language" icon-color="primary" compact>
        <x-slot name="heading">Elegí cómo les dicen</x-slot>
        <x-slot name="description">En {{ mb_strtolower(implode(' y ', $vocabulary['programs'])) }} se suele decir {{ mb_strtolower(\Illuminate\Support\Arr::join(array_values($vocabulary['suggested']), ', ', ' y ')) }}.</x-slot>
        <x-slot name="afterHeader">{{ $this->terminologyAction }}</x-slot>
    </x-filament::section>
@elseif ($compact)
    <x-filament::section icon="heroicon-o-rocket-launch" icon-color="primary" compact>
        <x-slot name="heading">Configurá tu {{ static::typeNoun() }} · {{ $checklist['done'] }} de {{ $checklist['total'] }}</x-slot>
        @if ($checklist['next'])
            <x-slot name="description">Sigue: {{ $steps[$checklist['next']]['title'] }}</x-slot>
        @endif
        <x-slot name="afterHeader">
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                @if ($vocabulary) {{ $this->terminologyAction }} @endif
                {{ $this->resumeGuideAction }}
            </div>
        </x-slot>
    </x-filament::section>
@else
    <x-filament::section icon="heroicon-o-rocket-launch" icon-color="primary">
        <x-slot name="heading">Configurá tu {{ static::typeNoun() }}</x-slot>
        <x-slot name="description">Te llevamos paso a paso. Se guarda solo: podés dejarlo y seguir después.</x-slot>
        <x-slot name="afterHeader">{{ $this->dismissGuideAction }}</x-slot>

        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
            <div style="flex: 1; height: 0.6rem; border-radius: 9999px; background: rgba(120, 120, 120, 0.2); overflow: hidden;">
                <div style="height: 100%; width: {{ $percent }}%; background: var(--primary-500); border-radius: 9999px;"></div>
            </div>
            <span style="font-weight: 600; white-space: nowrap;">{{ $checklist['done'] }} de {{ $checklist['total'] }}</span>
        </div>

        @if ($vocabulary)
            <div style="margin-bottom: 1rem;">
                <x-filament::section icon="heroicon-o-language" icon-color="warning" compact secondary>
                    <x-slot name="heading">Elegí cómo les dicen</x-slot>
                    <x-slot name="description">En {{ mb_strtolower(implode(' y ', $vocabulary['programs'])) }} se suele decir {{ mb_strtolower(\Illuminate\Support\Arr::join(array_values($vocabulary['suggested']), ', ', ' y ')) }}.</x-slot>
                    <x-slot name="afterHeader">{{ $this->terminologyAction }}</x-slot>
                </x-filament::section>
            </div>
        @endif

        <div style="display: grid; gap: 0.75rem;">
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

                <x-filament::section :icon="$icon" :icon-color="$color" compact secondary>
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
        </div>
    </x-filament::section>
@endif
