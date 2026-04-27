<?php
/**
 * Sidebar lateral para desktop (hidden en móvil, visible md: y arriba).
 * Los items se filtran según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$rutaActual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$items = [];

$items[] = [
    'ruta' => '/home',
    'icono' => 'home',
    'label' => 'Inicio',
    'activo' => in_array($rutaActual, ['/home', '/home-trabajador', '/home-supervisora', '/home-recepcion', '/home-admin']),
];

if ($usuario->tieneAlgunPermiso(['habitaciones.ver_asignadas_propias', 'habitaciones.ver_todas'])) {
    $items[] = [
        'ruta' => '/habitaciones',
        'icono' => 'clipboard-list',
        'label' => 'Habitaciones',
        'activo' => str_starts_with($rutaActual, '/habitaciones'),
    ];
}

if ($usuario->tienePermiso('asignaciones.asignar_manual')) {
    $items[] = [
        'ruta' => '/asignaciones',
        'icono' => 'users',
        'label' => 'Asignaciones',
        'activo' => str_starts_with($rutaActual, '/asignaciones'),
    ];
}

if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
    $items[] = [
        'ruta' => '/auditoria',
        'icono' => 'shield-check',
        'label' => 'Auditoría',
        'activo' => str_starts_with($rutaActual, '/auditoria'),
    ];
}

// Alertas: el panel ya aparece en la home (Supervisora/Admin); no hay página dedicada en MVP.

if ($usuario->tieneAlgunPermiso(['tickets.ver_propios', 'tickets.ver_todos'])) {
    $items[] = [
        'ruta' => '/tickets',
        'icono' => 'wrench',
        'label' => 'Tickets',
        'activo' => str_starts_with($rutaActual, '/tickets'),
    ];
}

if ($usuario->tienePermiso('reportes.ver')) {
    $items[] = [
        'ruta' => '/reportes',
        'icono' => 'bar-chart-3',
        'label' => 'Reportes',
        'activo' => str_starts_with($rutaActual, '/reportes'),
    ];
}

if ($usuario->tienePermiso('usuarios.ver')) {
    $items[] = [
        'ruta' => '/usuarios',
        'icono' => 'user-cog',
        'label' => 'Usuarios',
        'activo' => str_starts_with($rutaActual, '/usuarios'),
    ];
}

if ($usuario->tienePermiso('permisos.asignar_a_rol')) {
    $items[] = [
        'ruta' => '/ajustes/rbac',
        'icono' => 'shield',
        'label' => 'Roles y permisos',
        'activo' => $rutaActual === '/ajustes/rbac',
    ];
}

if ($usuario->tienePermiso('turnos.importar')) {
    $items[] = [
        'ruta' => '/ajustes/importar-turnos',
        'icono' => 'file-up',
        'label' => 'Importar turnos',
        'activo' => $rutaActual === '/ajustes/importar-turnos',
    ];
}

$items[] = [
    'ruta' => '/ajustes',
    'icono' => 'settings',
    'label' => 'Ajustes',
    'activo' => str_starts_with($rutaActual, '/ajustes'),
];

// Nombre corto para el sidebar
$primerNombre = explode(' ', $usuario->nombre)[0];
// Color determinístico basado en RUT
$colores = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-500', 'bg-indigo-500', 'bg-teal-500'];
$colorIdx = crc32($usuario->rut) % count($colores);
$avatarColor = $colores[abs($colorIdx)];
$inicial = mb_strtoupper(mb_substr($primerNombre, 0, 1));
?>

<aside class="hidden md:flex md:flex-col md:fixed md:inset-y-0 md:left-0 md:w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 z-40">
    <!-- Logo / Branding -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-gray-200 dark:border-gray-700">
        <div class="w-10 h-10 rounded-lg bg-blue-600 flex items-center justify-center">
            <i data-lucide="sparkles" class="w-5 h-5 text-white"></i>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Atankalama</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Limpieza</p>
        </div>
    </div>

    <!-- Navegación -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        <?php foreach ($items as $item): ?>
            <a href="<?= htmlspecialchars((string) $item['ruta'], ENT_QUOTES, 'UTF-8') ?>"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors
                      <?= $item['activo']
                          ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400'
                          : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' ?>">
                <i data-lucide="<?= htmlspecialchars((string) $item['icono'], ENT_QUOTES, 'UTF-8') ?>" class="w-5 h-5 flex-shrink-0"></i>
                <?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Usuario -->
    <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-4">
        <a href="/ajustes" class="flex items-center gap-3 group">
            <div class="w-9 h-9 rounded-full <?= htmlspecialchars((string) $avatarColor, ENT_QUOTES, 'UTF-8') ?> flex items-center justify-center text-white text-sm font-bold flex-shrink-0">
                <?= htmlspecialchars((string) $inicial, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"><?= htmlspecialchars($primerNombre) ?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= htmlspecialchars(implode(', ', $usuario->roles)) ?></p>
            </div>
        </a>
    </div>
</aside>
