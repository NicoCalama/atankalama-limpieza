<?php
/**
 * Bottom tab bar para móvil (md:hidden).
 * Los items se filtran según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 *
 * Ícono + texto. El texto va más chico (10px) y con espaciado apretado que la
 * versión original (12px) para que quepan los ítems + "Salir" sin cortarse en
 * celulares angostos. Se probó reemplazar el texto por un popover (mouse-over /
 * mantener presionado), pero el celular no tiene un equivalente confiable a
 * "mouse por encima sin tocar" — se volvió al texto siempre visible.
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

// Auditoría — visible con permiso auditoria.ver_bandeja (faltaba: el sidebar de
// escritorio ya lo tenía, pero en móvil no había forma de llegar a /auditoria).
if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
    $items[] = [
        'ruta' => '/auditoria',
        'icono' => 'shield-check',
        'label' => 'Inspección',
        'activo' => str_starts_with($rutaActual, '/auditoria'),
    ];
}

// Tickets — si puede ver la lista (ver_propios/ver_todos), enlaza a /tickets. Si solo
// puede crear (ej. Trabajador), el botón abre directo el modal de reporte: no tiene
// lista que ver, pero sí debe poder reportar un problema desde cualquier pantalla.
$puedeVerTickets = $usuario->tieneAlgunPermiso(['tickets.ver_propios', 'tickets.ver_todos']);
$puedeCrearTickets = $usuario->tienePermiso('tickets.crear');
if ($puedeVerTickets || $puedeCrearTickets) {
    $items[] = [
        'tipo' => $puedeVerTickets ? 'link' : 'accion',
        'ruta' => '/tickets',
        'icono' => 'wrench',
        'label' => $puedeVerTickets ? 'Tickets' : 'Reportar',
        'activo' => str_starts_with($rutaActual, '/tickets'),
    ];
}

// Asignaciones — visible con permiso asignaciones.asignar_manual
if ($usuario->tienePermiso('asignaciones.asignar_manual')) {
    $items[] = [
        'ruta' => '/asignaciones',
        'icono' => 'users',
        'label' => 'Asignaciones',
        'activo' => str_starts_with($rutaActual, '/asignaciones'),
    ];
}

// Áreas comunes — visible con permiso espacios.ver (faltaba: el sidebar de
// escritorio ya lo tenía, pero en móvil no había forma de llegar a /espacios).
if ($usuario->tienePermiso('espacios.ver')) {
    $items[] = [
        'ruta' => '/espacios',
        'icono' => 'building-2',
        'label' => 'Áreas comunes',
        'activo' => str_starts_with($rutaActual, '/espacios'),
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
            <?php
            $clases = 'flex-1 min-w-0 min-h-[52px] flex flex-col items-center justify-center gap-0.5 transition-colors '
                . ($item['activo']
                    ? 'text-blue-600 dark:text-blue-400'
                    : 'text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700');
            $contenido = '<i data-lucide="' . htmlspecialchars((string) $item['icono'], ENT_QUOTES, 'UTF-8') . '" class="w-5 h-5"></i>'
                . '<span class="text-[10px] leading-none truncate max-w-full px-0.5">' . htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') . '</span>';
            ?>
            <?php if (($item['tipo'] ?? 'link') === 'accion'): ?>
                <button type="button" class="<?= $clases ?>"
                        onclick="window.dispatchEvent(new CustomEvent('abrir-modal-ticket', { detail: {} }))">
                    <?= $contenido ?>
                </button>
            <?php else: ?>
                <a href="<?= htmlspecialchars(u((string) $item['ruta']), ENT_QUOTES, 'UTF-8') ?>" class="<?= $clases ?>">
                    <?= $contenido ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Cerrar sesión: siempre visible, en todas las páginas y roles -->
        <button type="button"
                onclick="if (confirm('¿Cerrar sesión?')) Alpine.store('auth').cerrarSesion();"
                aria-label="Cerrar sesión"
                class="w-12 flex-shrink-0 min-h-[52px] flex flex-col items-center justify-center gap-0.5 transition-colors
                       text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700
                       border-l border-gray-200 dark:border-gray-700">
            <i data-lucide="log-out" class="w-5 h-5"></i>
            <span class="text-[10px] leading-none">Salir</span>
        </button>
    </div>
</nav>
