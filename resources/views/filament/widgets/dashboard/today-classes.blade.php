<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-calendar-days">
        <x-slot name="heading">Hoy</x-slot>
        <x-slot name="description">
            @if ($classes->isEmpty())
                No hay clases hoy.
            @else
                {{ $classes->count() }} {{ $classes->count() === 1 ? 'clase' : 'clases' }}@if ($missing) · {{ $missing }} sin asistencia @endif
            @endif
        </x-slot>

        @if ($classes->isNotEmpty())
            <div style="display: grid; gap: 0.6rem;">
                @foreach ($classes as $class)
                    <a href="{{ $class['url'] }}" style="display: flex; gap: 0.75rem; align-items: baseline; justify-content: space-between;">
                        <span>
                            <strong>{{ $class['time'] }}</strong> · {{ $class['group'] }}@if ($class['makeup']) (recuperación)@endif
                            @if ($class['venue'])<br><span style="opacity: .7; font-size: .85em;">{{ $class['venue'] }}</span>@endif
                        </span>
                        <x-filament::badge :color="$class['color']">{{ $class['status'] }}</x-filament::badge>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
