<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" :icon-color="$paused ? 'danger' : 'success'">
        <x-slot name="heading">Códigos de verificación · hoy</x-slot>
        <x-slot name="description">
            @if ($paused)
                WhatsApp pausado: {{ $paused['reason'] }}
            @else
                Tope diario: {{ $limit }} por WhatsApp.
            @endif
        </x-slot>
        <x-slot name="afterHeader">
            {{ $this->toggleWhatsappAction }}
        </x-slot>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem;">
            <div>
                <div style="font-size: 0.875rem; opacity: 0.7;">Por WhatsApp</div>
                <div style="font-size: 1.5rem; font-weight: 600;">{{ $whatsapp }}</div>
            </div>
            <div>
                <div style="font-size: 0.875rem; opacity: 0.7;">Por correo</div>
                <div style="font-size: 1.5rem; font-weight: 600;">{{ $mail }}</div>
            </div>
            <div>
                <div style="font-size: 0.875rem; opacity: 0.7;">Verificados</div>
                <div style="font-size: 1.5rem; font-weight: 600;">{{ $verified_rate === null ? '—' : round($verified_rate * 100).' %' }}</div>
            </div>
            <div>
                <div style="font-size: 0.875rem; opacity: 0.7;">Gasto estimado</div>
                <div style="font-size: 1.5rem; font-weight: 600;">USD {{ number_format($cost_usd, 2, ',', '.') }}</div>
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
