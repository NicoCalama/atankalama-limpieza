<?php

declare(strict_types=1);

/**
 * Templates de checklist por tipo de habitación.
 * Se crea un template por cada tipo con los 10 items del MVP.
 * Ver docs/checklist.md §2.2.
 */
return [
    'template_default_items' => [
        ['orden' => 1,  'descripcion' => 'Retirar ropa de cama usada',                        'obligatorio' => 1],
        ['orden' => 2,  'descripcion' => 'Limpiar y desinfectar baño completo',               'obligatorio' => 1],
        ['orden' => 3,  'descripcion' => 'Reponer toallas',                                   'obligatorio' => 1],
        ['orden' => 4,  'descripcion' => 'Hacer cama con sábanas limpias',                    'obligatorio' => 1],
        ['orden' => 5,  'descripcion' => 'Aspirar alfombra y pisos',                          'obligatorio' => 1],
        ['orden' => 6,  'descripcion' => 'Limpiar superficies (mesa de noche, escritorio)',   'obligatorio' => 1],
        ['orden' => 7,  'descripcion' => 'Reponer amenities (shampoo, jabón)',                'obligatorio' => 1],
        ['orden' => 8,  'descripcion' => 'Vaciar basureros',                                  'obligatorio' => 1],
        ['orden' => 9,  'descripcion' => 'Revisar iluminación y aire',                        'obligatorio' => 0],
        ['orden' => 10, 'descripcion' => 'Inspección final',                                  'obligatorio' => 1],
    ],
];
