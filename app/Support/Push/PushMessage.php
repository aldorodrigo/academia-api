<?php

namespace App\Support\Push;

/**
 * Notificación push: título, texto y datos para la app (tipo y ruta a abrir).
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
    ) {}
}
