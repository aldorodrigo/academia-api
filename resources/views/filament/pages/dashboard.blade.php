<x-filament-panels::page>
    @if ($guide = static::guideMode())
        @include('filament.pages.partials.setup-guide', ['compact' => $guide === 'compact'])
    @endif

    {{ $this->content }}
</x-filament-panels::page>
