{{-- Cloudflare Turnstile: el token queda en el estado del campo (lo valida la API en el servidor).
     Cada token sirve una sola vez: después de cada envío el servidor manda `turnstile-reset` y se pide otro. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{ state: $wire.$entangle(@js($getStatePath())), widgetId: null }"
        x-init="
            const render = () => widgetId = window.turnstile.render($refs.widget, {
                sitekey: @js(config('services.turnstile.site_key')),
                language: 'es',
                callback: (token) => state = token,
                'expired-callback': () => state = null,
                'error-callback': () => state = null,
            });
            if (window.turnstile) { render(); } else {
                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.onload = render;
                document.head.appendChild(script);
            }
        "
        x-on:turnstile-reset.window="state = null; if (window.turnstile && widgetId !== null) window.turnstile.reset(widgetId)"
    >
        <div x-ref="widget"></div>
    </div>
</x-dynamic-component>
