<?php

// Voseo (Filament trae "usted").
return [
    'form' => [
        'current_password' => [
            'below_content' => 'Por seguridad, confirmá tu contraseña para seguir.',
        ],
    ],
    'notifications' => [
        'email_change_verification_sent' => [
            'body' => 'Te mandamos un correo a :email para confirmar el cambio. Revisalo para terminar.',
        ],
        'throttled' => [
            'title' => 'Demasiados intentos. Probá de nuevo en :seconds segundos.',
            'body' => 'Probá de nuevo en :seconds segundos.',
        ],
    ],
];
