<?php

// Voseo (Filament trae "usted") y alta autoservicio: el link de registro lleva a registrar un club.
return [
    'heading' => 'Entrá a tu cuenta',
    'actions' => [
        'register' => [
            'before' => '¿Primera vez?',
            'label' => 'Registrá tu club',
        ],
        'request_password_reset' => [
            'label' => '¿Olvidaste tu contraseña?',
        ],
    ],
    'multi_factor' => [
        'heading' => 'Confirmá que sos vos',
        'subheading' => 'Para entrar, confirmá que sos vos.',
        'form' => [
            'provider' => [
                'label' => '¿Cómo querés confirmarlo?',
            ],
        ],
    ],
    'notifications' => [
        'throttled' => [
            'title' => 'Demasiados intentos. Probá de nuevo en :seconds segundos.',
            'body' => 'Probá de nuevo en :seconds segundos.',
        ],
    ],
];
