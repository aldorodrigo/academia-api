<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Al super admin: se pausaron los códigos por WhatsApp (tope diario o posible abuso).
 */
class WhatsAppPaused extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $reason) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return filled($notifiable->email) ? ['push', 'mail'] : ['push'];
    }

    public function toPush(User $notifiable): PushMessage
    {
        return new PushMessage(
            title: 'WhatsApp pausado',
            body: "{$this->reason} Los códigos van por correo hasta que lo reanudes desde el panel de plataforma.",
            data: ['type' => 'whatsapp_paused'],
        );
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Se pausaron los códigos por WhatsApp')
            ->line($this->reason)
            ->line('Mientras tanto, los códigos van por correo a quien lo tiene. Revisá los envíos y reanudalo desde el panel de plataforma.')
            ->action('Panel de plataforma', url('/plataforma'));
    }
}
