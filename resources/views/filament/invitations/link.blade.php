<div class="flex flex-col items-center gap-4" x-data="{ copied: false }">
    <img src="{{ $qr }}" alt="Código QR de la invitación" width="220" height="220">

    <div class="flex w-full items-center gap-2">
        <input type="text" readonly value="{{ $url }}" class="fi-input block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5" x-ref="link">
        <x-filament::button size="sm" x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true">
            <span x-show="! copied">Copiar</span>
            <span x-show="copied">Copiado</span>
        </x-filament::button>
    </div>
</div>
