<?php

namespace App\Support\Push;

/**
 * Notificación push: título, texto y datos para la app (tipo y ruta a abrir).
 *
 * Con `withActions`, la app dibuja la notificación con botones: en Android llega
 * solo con datos (título y texto van en `data`) y en iOS con la categoría `category`.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public bool $withActions = false,
        public ?string $category = null,
    ) {}
}
