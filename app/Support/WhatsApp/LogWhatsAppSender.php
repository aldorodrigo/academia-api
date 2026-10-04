<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Sin la WhatsApp Cloud API configurada (desarrollo): deja el código en el log.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function sendCode(string $phone, string $code): void
    {
        Log::info('WhatsApp (sin Meta)', ['phone' => $phone, 'code' => $code]);
    }
}
