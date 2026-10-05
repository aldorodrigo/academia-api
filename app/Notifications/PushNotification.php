<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Push\PushMessage;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de la app: queda en la bandeja "Avisos" de la cuenta (canal `inbox`, siempre), va por push a sus
 * dispositivos y, si la cuenta tiene un correo para copias (`User::mailableEmail()`), también por correo con la
 * marca Tuku. La bandeja y el correo salen del mismo `toPush()`: título, texto y la pantalla del aviso.
 */
abstract class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Organización activa al mandarlo (la bandeja de cada organización muestra los suyos). */
    public ?int $organizationId = null;

    abstract public function toPush(object $notifiable): PushMessage;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $this->organizationId ??= app(CurrentOrganization::class)->id();

        if (! $notifiable instanceof User) {
            return ['push'];
        }

        return $notifiable->mailableEmail() !== null ? ['inbox', 'push', 'mail'] : ['inbox', 'push'];
    }

    /**
     * Copia para la bandeja de la app (`GET me/notifications`).
     *
     * @return array{format: string, type: ?string, title: string, body: string, route: ?string}
     */
    public function toDatabase(object $notifiable): array
    {
        $push = $this->toPush($notifiable);

        return [
            'format' => 'tuku',
            'type' => $push->data['type'] ?? null,
            'title' => $push->title,
            'body' => $push->body,
            'route' => $push->data['route'] ?? null,
        ];
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
