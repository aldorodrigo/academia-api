<?php

namespace App\Mail;

use App\Support\Phone;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Desarrollo: el mensaje de WhatsApp con el código, como correo a `{número}@whatsapp.test` (Mailpit).
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
        return new Content(htmlString: '<p><strong>'.e($this->code).'</strong> es tu código de verificación. Por tu seguridad, no lo compartas.</p>'
            .'<p style="color:#888">(WhatsApp simulado al '.e(Phone::display($this->phone)).'.)</p>');
    }
}
