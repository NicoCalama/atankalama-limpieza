<?php
/**
 * Badge de estado de habitación (reutilizable).
 * Uso: <?php include __DIR__ . '/badge-estado.php'; ?> con $__badgeEstado definido.
 *
 * O llamar como función:
 *   <?= badgeEstadoHtml('pendiente') ?>
 */

function badgeEstadoHtml(string $estado): string
{
    $config = match ($estado) {
        'pendiente' => ['texto' => 'Pendiente', 'clase' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200'],
        'en_progreso' => ['texto' => 'En progreso', 'clase' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200'],
        'completada', 'completada_pendiente_auditoria' => ['texto' => 'Completada', 'clase' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'],
        'aprobada', 'aprobada_con_observacion' => ['texto' => 'Aprobada', 'clase' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'],
        'rechazada' => ['texto' => 'Rechazada', 'clase' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200'],
        'sucia' => ['texto' => 'Pendiente', 'clase' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200'],
        default => ['texto' => ucfirst($estado), 'clase' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'],
    };

    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' .
        $config['clase'] . '">' . htmlspecialchars($config['texto']) . '</span>';
}
