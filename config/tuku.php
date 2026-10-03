<?php

/*
|--------------------------------------------------------------------------
| Sitio público de Tuku (tukuha.app)
|--------------------------------------------------------------------------
|
| Datos de la landing: contacto, redes y precios. Los precios son mensuales,
| en guaraníes enteros, y rigen después de `free_until`.
|
*/

return [

    // Hasta esta fecha (inclusive) Tuku es gratis para todas las organizaciones.
    'free_until' => env('TUKU_FREE_UNTIL', '2027-10-31'),

    // Número de WhatsApp en formato internacional sin "+" (595981123456).
    // Sin número no se muestra el botón "Escribinos por WhatsApp".
    'whatsapp' => env('TUKU_WHATSAPP'),

    'email' => env('TUKU_EMAIL', 'hola@tukuha.app'),

    'social' => [
        'Instagram' => 'https://www.instagram.com/tukuha.app',
        'Facebook' => 'https://www.facebook.com/tukuha.app',
        'TikTok' => 'https://www.tiktok.com/@tukuha.app',
        'X' => 'https://x.com/tukuhaapp',
    ],

    // Planes por tipo de uso y cantidad de alumnos.
    'plans' => [
        [
            'key' => 'instructores',
            'name' => 'Instructores',
            'for' => 'Profesores particulares y entrenadores personales.',
            'price' => 50_000,
            'students' => 40,
            'features' => [
                'Agenda y horarios disponibles',
                'Reservas que tus alumnos hacen desde la app',
                'Paquetes de clases con vencimiento y clase suelta',
                'Cobros y saldo de cada alumno',
            ],
        ],
        [
            'key' => 'academias',
            'name' => 'Academias',
            'for' => 'Academias de deporte, danza, música o idiomas.',
            'price' => 150_000,
            'students' => 150,
            'features' => [
                'Alumnos, familias y categorías con sus horarios',
                'Temporadas con cuota mensual o por clase',
                'Becas, descuentos y recibos de cada pago',
                'Asistencia desde el celular, aunque no haya señal',
                'Avisos de clase con "Sí, va" o "No va"',
            ],
        ],
        [
            'key' => 'clubes',
            'name' => 'Clubes, organizaciones y escuelas',
            'for' => 'Clubes, escuelas de formación y comisiones de padres.',
            'price' => 350_000,
            'students' => 500,
            'features' => [
                'Todo lo del plan Academias',
                'Varias disciplinas y sedes',
                'Comisión con cargos y mandatos',
                'Gastos, cuentas y transferencias',
                'Informes del mes en PDF y Excel',
            ],
        ],
    ],

];
