<?php

namespace App\Support\WhatsApp;

use App\Mail\WhatsAppSimulatedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Desarrollo: cada mensaje de WhatsApp llega a Mailpit como un correo a `{número}@whatsapp.test`
 * (http://localhost:8025), igual que los correos. Los e2e lo leen con la API de Mailpit.
 */
class MailWhatsAppSender implements WhatsAppSender
{
    public function sendCode(string $phone, string $code): void
    {
        Mail::send(new WhatsAppSimulatedMail($phone, $code));
    }
}
