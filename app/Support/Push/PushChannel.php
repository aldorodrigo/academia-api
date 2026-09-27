<?php

namespace App\Support\Push;

use App\Models\DeviceToken;
use Illuminate\Notifications\Notification;

/**
 * Canal "push" para las notificaciones: manda a los dispositivos del usuario y
 * borra los tokens que FCM informa como inválidos.
 */
class PushChannel
{
    public function __construct(private PushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $tokens = $notifiable->routeNotificationFor('push', $notification);

        if (empty($tokens) || ! method_exists($notification, 'toPush')) {
            return;
        }

        $invalid = $this->sender->send(array_values($tokens), $notification->toPush($notifiable));

        if ($invalid !== []) {
            DeviceToken::query()->whereIn('token', $invalid)->delete();
        }
    }
}
