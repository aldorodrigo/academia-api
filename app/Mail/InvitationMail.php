<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Te invitaron a '.$this->invitation->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'organization' => $this->invitation->organization->name,
                'roles' => implode(', ', $this->invitation->roleLabels()),
                'url' => Invitation::urlFor($this->token),
                'expiresAt' => $this->invitation->expires_at
                    ->timezone($this->invitation->organization->timezone)
                    ->format('d/m/Y'),
            ],
        );
    }
}
