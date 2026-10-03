<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $code, public string $purpose = 'verify') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu código: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-code',
            with: [
                'name' => strtok($this->name, ' ') ?: $this->name,
                'code' => $this->code,
                'reset' => $this->purpose === 'reset',
            ],
        );
    }
}
