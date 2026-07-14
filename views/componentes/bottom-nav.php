<?php
/**
 * Bottom tab bar para móvil (md:hidden).
 * Los items se filtran según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

// Path SIN el prefijo BASE_PATH (las comparaciones de abajo usan rutas de app).
$rutaActual = \Atankalama\Limpieza\Core\Url::rutaActual();

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

// Alertas: el panel ya aparece en la home (Supervisora/Admin); no hay página dedicada en MVP.

// Tickets — visible si puede ver propios o todos (NO solo crear — el trabajador no debe ver la lista)
if ($usuario->tieneAlgunPermiso(['tickets.ver_propios', 'tickets.ver_todos'])) {
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

?>

<nav class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 md:hidden z-30 safe-area-bottom">
    <div class="flex max-w-lg mx-auto">
        <?php foreach ($items as $item): ?>
            <a href="<?= htmlspecialchars(u((string) $item['ruta']), ENT_QUOTES, 'UTF-8') ?>"
               class="flex-1 min-w-0 min-h-[60px] flex flex-col items-center justify-center transition-colors
                      <?= $item['activo']
                          ? 'text-blue-600 dark:text-blue-400'
                          : 'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                <i data-lucide="<?= htmlspecialchars((string) $item['icono'], ENT_QUOTES, 'UTF-8') ?>" class="w-5 h-5"></i>
                <span class="text-xs mt-1 truncate max-w-full px-0.5"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>

        <!-- Cerrar sesión: siempre visible, en todas las páginas y roles -->
        <button type="button"
                onclick="if (confirm('¿Cerrar sesión?')) Alpine.store('auth').cerrarSesion();"
                aria-label="Cerrar sesión"
                class="w-14 flex-shrink-0 min-h-[60px] flex flex-col items-center justify-center transition-colors
                       text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700
                       border-l border-gray-200 dark:border-gray-700">
            <i data-lucide="log-out" class="w-5 h-5"></i>
            <span class="text-xs mt-1">Salir</span>
        </button>
    </div>
</nav>
