<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Phone;
use App\Support\Verification\EmailConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Confirmá tu correo": a la cuenta con celular que tiene además un correo sin verificar. Con el link
 * confirmado le llegan por correo las copias de los avisos y de los códigos.
 */
class ConfirmEmailMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmá tu correo en Tuku');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.confirm-email',
            with: [
                'name' => strtok($this->user->name, ' ') ?: $this->user->name,
                'phone' => Phone::display($this->user->phone),
                'url' => EmailConfirmation::url($this->user),
                'days' => EmailConfirmation::VALID_DAYS,
            ],
        );
    }
}
