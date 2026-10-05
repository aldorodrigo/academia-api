<?php

// Voseo (Filament trae "usted").
return [
    'title' => 'Confirmá tu correo',
    'heading' => 'Confirmá tu correo',
    'messages' => [
        'notification_not_received' => '¿No te llegó el correo?',
        'notification_sent' => 'Te mandamos un correo a :email para confirmarlo.',
    ],
    'notifications' => [
        'notification_resent' => [
            'title' => 'Te lo mandamos de nuevo.',
        ],
        'notification_resend_throttled' => [
            'body' => 'Probá de nuevo en :seconds segundos.',
        ],
    ],
];
