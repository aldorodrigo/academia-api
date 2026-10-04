<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">{{ $title }}</x-slot>
        <x-slot name="description">{{ $active }} {{ $active === 1 ? 'activo' : 'activos' }} · {{ $new }} {{ $new === 1 ? 'alta' : 'altas' }} y {{ $withdrawn }} {{ $withdrawn === 1 ? 'baja' : 'bajas' }} este mes</x-slot>
        <x-slot name="afterHeader">
            <x-filament::link :href="$url" size="sm">Ver</x-filament::link>
        </x-slot>

        @if ($groups->isNotEmpty())
            <div style="display: grid; gap: 0.45rem;">
                @foreach ($groups as $group)
                    @php($full = $group['capacity'] !== null && $group['count'] >= $group['capacity'])
                    <a href="{{ $group['url'] }}" style="display: flex; gap: 0.75rem; align-items: center; justify-content: space-between;">
                        <span>{{ $group['name'] }}@if ($multiplePrograms) <span style="opacity: .7; font-size: .85em;">· {{ $group['program'] }}</span>@endif</span>
                        <span style="display: flex; gap: 0.5rem; align-items: center;">
                            @if ($full)<x-filament::badge color="warning" size="sm">Lleno</x-filament::badge>@endif
                            <span style="font-variant-numeric: tabular-nums;">{{ $group['count'] }}{{ $group['capacity'] !== null ? ' / '.$group['capacity'] : '' }}</span>
                        </span>
                    </a>
                @endforeach
                @if ($more > 0)
                    <x-filament::link :href="$groupsUrl" size="sm">y {{ $more }} más</x-filament::link>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
