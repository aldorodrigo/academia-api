<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Canal "inbox": la copia de cada aviso en la bandeja de la app (tabla `notifications`, `GET me/notifications`),
 * con la organización en la que se mandó. Le llega a toda cuenta, tenga o no la app instalada o un correo.
 */
class InboxChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'organization_id' => property_exists($notification, 'organizationId') ? $notification->organizationId : null,
        ];
    }
}
