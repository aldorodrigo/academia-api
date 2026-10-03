<?php

namespace App\Support\WhatsApp;

interface WhatsAppSender
{
    /**
     * Manda el código de verificación al celular (E.164) con la plantilla de autenticación.
     */
    public function sendCode(string $phone, string $code): void;
}
