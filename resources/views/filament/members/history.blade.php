<div class="divide-y divide-gray-200 dark:divide-white/10">
    @forelse ($assignments as $assignment)
        <div class="flex items-center justify-between gap-4 py-2 text-sm">
            <div>
                <div class="font-medium">{{ $assignment->label() }}</div>
                <div class="text-gray-500 dark:text-gray-400">
                    {{ $assignment->starts_on?->format('d/m/Y') ?? 'Desde el alta' }}
                    →
                    {{ $assignment->ends_on?->format('d/m/Y') ?? 'sin vencimiento' }}
                    @if ($assignment->assignedBy)
                        · asignó {{ $assignment->assignedBy->name }}
                    @endif
                </div>
            </div>
            @if ($assignment->ended_at)
                <x-filament::badge color="gray">Terminado {{ $assignment->ended_at->format('d/m/Y') }}</x-filament::badge>
            @else
                <x-filament::badge color="success">Vigente</x-filament::badge>
            @endif
        </div>
    @empty
        <p class="py-2 text-sm text-gray-500">Sin roles asignados.</p>
    @endforelse
</div>
