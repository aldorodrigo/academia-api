<?php

namespace App\Notifications;

use App\Support\Push\PushMessage;

/**
 * Aviso a la familia al dar de baja (si quien la da lo elige): el mensaje viene prellenado, amable
 * y con las puertas abiertas (WithdrawEnrollment::defaultNotice), y se puede cambiar antes de mandarlo.
 * Sin mascota en el correo: puede ir junto a una deuda.
 */
class StudentWithdrawn extends PushNotification
{
    public function __construct(public string $title, public string $body) {}

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, ['type' => 'student_withdrawn', 'route' => '/inicio']);
    }
}
