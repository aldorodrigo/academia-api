<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de la app: va por push y, si la cuenta tiene un correo para copias (`User::mailableEmail()`), también
 * por correo con la marca Tuku. El correo sale del mismo `toPush()`: título, texto y un botón que abre la app
 * en la pantalla del aviso.
 */
abstract class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function toPush(object $notifiable): PushMessage;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->mailableEmail() !== null ? ['push', 'mail'] : ['push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $push = $this->toPush($notifiable);

        return (new MailMessage)
            ->subject($push->title)
            ->markdown('mail.notification', [
                'name' => strtok((string) $notifiable->name, ' ') ?: null,
                'title' => $push->title,
                'body' => $push->body,
                'actions' => $this->mailActions($push),
                'pose' => $this->mailPose(),
            ]);
    }

    /**
     * Botones del correo. Por defecto, abrir la app web en la pantalla del aviso.
     *
     * @return list<array{label: string, url: string, color?: string}>
     */
    protected function mailActions(PushMessage $push): array
    {
        return [[
            'label' => 'Ver en Tuku',
            'url' => rtrim(config('app.frontend_url'), '/').($push->data['route'] ?? '/inicio'),
        ]];
    }

    /**
     * Pose de Tuku arriba del correo (`hola`, `salta`, `descansa`) o ninguna.
     */
    protected function mailPose(): ?string
    {
        return null;
    }
}
