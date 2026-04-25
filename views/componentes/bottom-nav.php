<?php
/**
 * Bottom tab bar para móvil (md:hidden).
 * Los items se filtran según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$items = [];

// Inicio — visible para todos los autenticados
$items[] = [
    'ruta' => '/home',
    'icono' => 'home',
    'label' => 'Inicio',
    'activo' => in_array($rutaActual, ['/home', '/home-trabajador', '/home-supervisora', '/home-recepcion', '/home-admin']),
];

// Habitaciones — visible si puede ver propias o todas
if ($usuario->tieneAlgunPermiso(['habitaciones.ver_asignadas_propias', 'habitaciones.ver_todas'])) {
    $items[] = [
        'ruta' => '/habitaciones',
        'icono' => 'clipboard-list',
        'label' => 'Habitaciones',
        'activo' => str_starts_with($rutaActual, '/habitaciones'),
    ];
}

// Alertas — solo supervisora/admin con permiso
if ($usuario->tienePermiso('alertas.recibir_predictivas')) {
    $items[] = [
        'ruta' => '/alertas',
        'icono' => 'bell-ring',
        'label' => 'Alertas',
        'activo' => str_starts_with($rutaActual, '/alertas'),
    ];
}

// Tickets — visible si puede ver propios o todos
if ($usuario->tieneAlgunPermiso(['tickets.ver_propios', 'tickets.ver_todos', 'tickets.crear'])) {
    $items[] = [
        'ruta' => '/tickets',
        'icono' => 'wrench',
        'label' => 'Tickets',
        'activo' => str_starts_with($rutaActual, '/tickets'),
    ];
}

// Reportes — visible con permiso reportes.ver
if ($usuario->tienePermiso('reportes.ver')) {
    $items[] = [
        'ruta' => '/reportes',
        'icono' => 'bar-chart-3',
        'label' => 'Reportes',
        'activo' => str_starts_with($rutaActual, '/reportes'),
    ];
}

// Ajustes — visible para todos
$items[] = [
    'ruta' => '/ajustes',
    'icono' => 'settings',
    'label' => 'Ajustes',
    'activo' => str_starts_with($rutaActual, '/ajustes'),
];

$totalCols = count($items);
?>

<nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 md:hidden z-30 safe-area-bottom">
    <div class="grid grid-cols-<?= $totalCols ?> max-w-lg mx-auto">
        <?php foreach ($items as $item): ?>
            <a href="<?= $item['ruta'] ?>"
               class="min-h-[60px] flex flex-col items-center justify-center transition-colors
                      <?= $item['activo']
                          ? 'text-blue-600 dark:text-blue-400'
                          : 'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                <i data-lucide="<?= $item['icono'] ?>" class="w-5 h-5"></i>
                <span class="text-xs mt-1"><?= $item['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
