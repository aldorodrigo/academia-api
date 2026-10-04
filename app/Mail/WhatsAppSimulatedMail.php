<?php

namespace App\Mail;

use App\Actions\Auth\SendVerificationCode;
use App\Support\Phone;
use App\Support\WhatsApp\Branding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Desarrollo: el mensaje de WhatsApp con el código, como correo a `{número}@whatsapp.test` (Mailpit). Se ve
 * como el chat con Tuku: foto y nombre del perfil, y la plantilla de autenticación con su botón.
 */
class WhatsAppSimulatedMail extends Mailable
{
    public function __construct(public string $phone, public string $code) {}

    public function envelope(): Envelope
    {
        $display = Phone::display($this->phone);

        return new Envelope(
            to: [new Address(Phone::digits($this->phone).'@whatsapp.test', "WhatsApp {$display}")],
            subject: "WhatsApp al {$display}: tu código es {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.whatsapp-simulado', with: [
            // Con otro nombre que la propiedad pública `phone` (esa pisa los datos de la vista).
            'phoneDisplay' => Phone::display($this->phone),
            'minutes' => SendVerificationCode::VALID_MINUTES,
            'about' => Branding::ABOUT,
            'button' => Branding::COPY_BUTTON,
        ]);
    }
}
