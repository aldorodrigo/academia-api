<?php

namespace App\Notifications;

use App\Models\ClassPack;
use App\Support\Push\PushMessage;

/**
 * Aviso al alumno o tutor: el paquete vence pronto y le quedan clases sin reservar.
 */
class PackExpiring extends PushNotification
{
    public string $body;

    public int $teacherId;

    public function __construct(ClassPack $pack, int $unreserved)
    {
        $pack->loadMissing(['student', 'teacher']);
        $this->teacherId = $pack->user_id;
        $day = $pack->expires_on->locale('es')->translatedFormat('l j/n');
        $classes = $unreserved === 1 ? '1 clase' : "{$unreserved} clases";

        $this->body = "El paquete de {$pack->student->first_name} con {$pack->teacher->name} vence el {$day} y le quedan {$classes} sin reservar. ¡Reservalas!";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Tu paquete vence pronto', $this->body, [
            'type' => 'pack_expiring',
            'route' => "/particulares/{$this->teacherId}/reservar",
        ]);
    }
}
