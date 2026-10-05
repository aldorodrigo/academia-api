<?php

// Voseo (Filament trae "usted"); se importa Excel (.xlsx) o CSV.
return [
    'modal' => [
        'form' => [
            'file' => [
                'placeholder' => 'Elegí el archivo Excel (.xlsx) o CSV',
            ],
        ],
    ],
    'notifications' => [
        'max_rows' => [
            'title' => 'El archivo es demasiado grande',
        ],
        'started' => [
            'title' => 'Importación en curso',
            'body' => 'Estamos cargando 1 fila; te avisamos al terminar.|Estamos cargando :count filas; te avisamos al terminar.',
        ],
    ],
];
