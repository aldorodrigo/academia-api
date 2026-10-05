<?php

// Voseo (Filament trae "usted").
return [
    'columns' => [
        'select' => [
            'no_search_results_message' => 'No hay opciones para tu búsqueda.',
            'placeholder' => 'Elegí una opción',
            'search_prompt' => 'Escribí para buscar…',
        ],
    ],
    // "Todos :label" no concuerda con "categorías" (ver docs/PLAN_GENERO.md).
    'summary' => [
        'subheadings' => [
            'all' => 'En total (:label)',
        ],
    ],
    'empty' => [
        'description' => 'Todavía no hay nada cargado.',
    ],
    'selection_indicator' => [
        'actions' => [
            'select_all' => [
                'label' => 'Seleccionar los :count',
            ],
            'deselect_all' => [
                'label' => 'Quitar la selección',
            ],
        ],
    ],
];
