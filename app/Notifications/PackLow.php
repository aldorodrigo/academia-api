<?php

namespace App\Notifications;

use App\Models\ClassPack;
use App\Support\Push\PushMessage;

/**
 * Aviso al alumno o tutor: queda una clase del paquete.
 */
class PackLow extends PushNotification
{
    public string $body;

    public function __construct(ClassPack $pack)
    {
        $pack->loadMissing(['student', 'teacher']);

        $this->body = "A {$pack->student->first_name} le queda 1 clase del paquete con {$pack->teacher->name}.";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Te queda 1 clase', $this->body, ['type' => 'pack_low', 'route' => '/inicio']);
    }
}
