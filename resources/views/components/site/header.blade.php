{{-- Encabezado fijo: logo, navegación (`nav`) y acciones (`actions`). --}}
@props(['home' => url('/')])
<header class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
        <a href="{{ $home }}" class="shrink-0 rounded-sm">
            <x-site.logo class="h-8" />
        </a>
        @isset($nav)
            <nav aria-label="Secciones" class="hidden items-center gap-6 text-sm font-semibold text-ink-muted lg:flex">
                {{ $nav }}
            </nav>
        @endisset
        <div class="ml-auto flex items-center gap-2">
            {{ $actions ?? '' }}
        </div>
    </div>
</header>
