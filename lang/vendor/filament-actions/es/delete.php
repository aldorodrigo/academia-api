<?php

// Voseo (Filament trae "usted").
return [
    'multiple' => [
        // Sin concordar con la palabra del club ("categorías seleccionados"): ver docs/PLAN_GENERO.md.
        'modal' => ['heading' => 'Borrar la selección (:label)'],
        'notifications' => [
            'deleted_partial' => [
                'missing_authorization_failure_message' => 'No tenés permiso para borrar :count.',
            ],
            'deleted_none' => [
                'missing_authorization_failure_message' => 'No tenés permiso para borrar :count.',
            ],
        ],
    ],
];
