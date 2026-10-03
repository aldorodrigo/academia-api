<?php

namespace App\Notifications;

use App\Models\ClassPack;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push al alumno o tutor: queda una clase del paquete.
 */
class PackLow extends Notification implements ShouldQueue
{
    use Queueable;

    public string $body;

    public function __construct(ClassPack $pack)
    {
        $pack->loadMissing(['student', 'teacher']);

        $this->body = "A {$pack->student->first_name} le queda 1 clase del paquete con {$pack->teacher->name}.";
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage('Te queda 1 clase', $this->body, ['type' => 'pack_low', 'route' => '/inicio']);
    }
}
