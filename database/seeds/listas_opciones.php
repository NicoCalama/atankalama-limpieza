<?php

declare(strict_types=1);

/**
 * Opciones iniciales de las listas de selección (v7.3, pedido de gerencia del 10/10/2026: las acciones no
 * llevan cuadro de texto, solo se elige de una lista que se edita en Ajustes → Checklists y listas).
 *
 * DEFAULT APLICADO (aprobado por el usuario, 10/10/2026): se siembran para que el día del deploy cada
 * acción ya tenga de dónde elegir; después se renombran, ordenan o desactivan desde Ajustes. El rechazo
 * parte con las mismas 8 categorías del NO de Recepción, para que supervisora y Recepción hablen igual
 * (docs/home-supervisora.md). Las listas de Recepción y tickets se siembran en la v7.4.
 *
 * Los carga scripts/seed.php, scripts/migrate-add-listas-opciones.php (solo las listas vacías) y
 * TestDatabase. El orden del arreglo es el orden en pantalla.
 *
 * @return array<string, list<string>>
 */
return [
    'rechazo' => [
        'Baño sucio',
        'Cama mal hecha o sábanas sucias',
        'Piso o superficies sucias',
        'Basura sin retirar',
        'Faltan amenities o toallas',
        'Mal olor',
        'Algo roto o no funciona',
        'Otro',
    ],
    'saltar' => [
        'Huésped no ha salido',
        'Falta un insumo',
        'Requiere mantención',
        'Otro',
    ],
    'reasignar' => [
        'Sobrecarga de trabajo',
        'Ausencia o atraso',
        'Cambio de prioridad',
        'Otro',
    ],
];
