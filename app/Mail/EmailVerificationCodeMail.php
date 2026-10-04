<?php

namespace App\Mail;

use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Código de 6 dígitos por correo. Con `whatsapp`, es la copia del que fue por WhatsApp a ese número (tiene su
 * propio código); con `confirmUrl`, trae el botón "Confirmar mi correo".
 */
class EmailVerificationCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $code,
        public string $purpose = 'verify',
        public ?string $whatsapp = null,
        public ?string $confirmUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'reset'
            ? "Tu código para cambiar la contraseña: {$this->code}"
            : "Tu código de Tuku: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-code',
            with: [
                // Con otros nombres que las propiedades públicas (esas pisan los datos de la vista).
                'firstName' => strtok($this->name, ' ') ?: $this->name,
                'code' => $this->code,
                'reset' => $this->purpose === 'reset',
                'whatsappDisplay' => Phone::display($this->whatsapp),
            ],
        );
    }
}
