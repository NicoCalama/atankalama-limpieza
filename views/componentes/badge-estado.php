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
    // Colores vía clases semánticas .chip-estado-* (custom.css + variables que
    // inyecta el layout desde ui_config — editables en Ajustes → Colores).
    $config = match ($estado) {
        'pendiente', 'sucia' => ['texto' => 'Pendiente', 'clase' => 'chip-estado-sucia'],
        'en_progreso' => ['texto' => 'En progreso', 'clase' => 'chip-estado-en_progreso'],
        'completada', 'completada_pendiente_auditoria' => ['texto' => 'Completada', 'clase' => 'chip-estado-completada_pendiente_auditoria'],
        'aprobada' => ['texto' => 'Aprobada', 'clase' => 'chip-estado-aprobada'],
        'aprobada_con_observacion' => ['texto' => 'Aprobada', 'clase' => 'chip-estado-aprobada_con_observacion'],
        'rechazada' => ['texto' => 'Rechazada', 'clase' => 'chip-estado-rechazada'],
        default => ['texto' => ucfirst($estado), 'clase' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'],
    };

    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' .
        $config['clase'] . '">' . htmlspecialchars($config['texto']) . '</span>';
}
