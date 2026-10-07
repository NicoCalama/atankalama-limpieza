<?php

declare(strict_types=1);

/**
 * Motivos iniciales de un NO en la inspección pre-entrega (Ajustes → Inspección pre-entrega).
 *
 * DEFAULT APLICADO (aprobado por el usuario, 04/10/2026): se siembran estos 8 para que el día
 * del deploy Recepción ya pueda registrar un NO. Después se renombran o desactivan desde Ajustes.
 * Los carga scripts/seed.php y scripts/migrate-add-revision-entrega.php (solo si la tabla está vacía).
 */
return [
    'Baño sucio',
    'Cama mal hecha o sábanas sucias',
    'Piso o superficies sucias',
    'Basura sin retirar',
    'Faltan amenities o toallas',
    'Mal olor',
    'Algo roto o no funciona',
    'Otro',
];
