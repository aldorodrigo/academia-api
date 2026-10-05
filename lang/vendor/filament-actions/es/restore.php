<?php

// Voseo (Filament trae "usted").
return [
    'multiple' => [
        // Sin concordar con la palabra del club ("categorías seleccionados"): ver docs/PLAN_GENERO.md.
        'modal' => ['heading' => 'Restaurar la selección (:label)'],
        'notifications' => [
            'restored_partial' => [
                'missing_authorization_failure_message' => 'No tenés permiso para restaurar :count.',
            ],
            'restored_none' => [
                'missing_authorization_failure_message' => 'No tenés permiso para restaurar :count.',
            ],
        ],
    ],
];
