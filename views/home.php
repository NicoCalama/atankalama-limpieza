<?php
/**
 * Home shell — muestra el dashboard correcto según permisos del usuario.
 * Los home específicos por rol se implementan en items 44-47.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

use Atankalama\Limpieza\Services\AuthService;

$authSvc = new AuthService();
$homeTarget = $authSvc->calcularHomeTarget($usuario);
$primerNombre = explode(' ', $usuario->nombre)[0];

// Saludo contextual por hora
$hora = (int) date('H');
if ($hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}

// Color determinístico para avatar
$colores = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-500', 'bg-indigo-500', 'bg-teal-500'];
$colorIdx = abs(crc32($usuario->rut)) % count($colores);
$avatarColor = $colores[$colorIdx];
$inicial = mb_strtoupper(mb_substr($primerNombre, 0, 1));
?>

<!-- Header -->
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
    <div class="flex items-center justify-between max-w-5xl mx-auto">
        <div class="flex items-center gap-3">
            <!-- Avatar -->
            <a href="/ajustes" class="w-12 h-12 rounded-full <?= $avatarColor ?> flex items-center justify-center text-white text-lg font-bold flex-shrink-0">
                <?= $inicial ?>
            </a>
            <!-- Saludo -->
            <div>
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100"><?= $saludo ?>, <?= htmlspecialchars($primerNombre) ?></p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    <?= htmlspecialchars(implode(', ', $usuario->roles)) ?>
                </p>
            </div>
        </div>
        <!-- Campana -->
        <button class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative">
            <i data-lucide="bell" class="w-6 h-6 text-gray-600 dark:text-gray-400"></i>
        </button>
    </div>
</header>

<!-- Contenido principal -->
<main class="pb-24 md:pb-8 px-4 py-6 max-w-5xl mx-auto" x-data="homeApp()">

    <!-- Placeholder según rol — se reemplaza por vistas completas en items 44-47 -->
    <?php if ($homeTarget === '/home-admin'): ?>
        <!-- Home Admin -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center">
                        <i data-lucide="shield" class="w-5 h-5 text-violet-600 dark:text-violet-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Panel de Administración</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista de administrador con KPIs, alertas y gestión del equipo. Se implementa en item 47.</p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <a href="/habitaciones" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="clipboard-list" class="w-6 h-6 mx-auto mb-2 text-blue-600 dark:text-blue-400"></i>
                    <span class="text-sm font-medium">Habitaciones</span>
                </a>
                <a href="/usuarios" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="users" class="w-6 h-6 mx-auto mb-2 text-emerald-600 dark:text-emerald-400"></i>
                    <span class="text-sm font-medium">Usuarios</span>
                </a>
                <a href="/alertas" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="bell-ring" class="w-6 h-6 mx-auto mb-2 text-amber-600 dark:text-amber-400"></i>
                    <span class="text-sm font-medium">Alertas</span>
                </a>
                <a href="/ajustes" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="settings" class="w-6 h-6 mx-auto mb-2 text-gray-600 dark:text-gray-400"></i>
                    <span class="text-sm font-medium">Ajustes</span>
                </a>
            </div>
        </div>

    <?php elseif ($homeTarget === '/home-supervisora'): ?>
        <!-- Home Supervisora -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                        <i data-lucide="eye" class="w-5 h-5 text-amber-600 dark:text-amber-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Panel de Supervisión</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista de supervisora con alertas predictivas, asignaciones y estado del equipo. Se implementa en item 45.</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <a href="/habitaciones" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="clipboard-list" class="w-6 h-6 mx-auto mb-2 text-blue-600 dark:text-blue-400"></i>
                    <span class="text-sm font-medium">Habitaciones</span>
                </a>
                <a href="/alertas" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="bell-ring" class="w-6 h-6 mx-auto mb-2 text-amber-600 dark:text-amber-400"></i>
                    <span class="text-sm font-medium">Alertas</span>
                </a>
                <a href="/asignaciones" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="users" class="w-6 h-6 mx-auto mb-2 text-emerald-600 dark:text-emerald-400"></i>
                    <span class="text-sm font-medium">Asignaciones</span>
                </a>
                <a href="/tickets" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="wrench" class="w-6 h-6 mx-auto mb-2 text-rose-600 dark:text-rose-400"></i>
                    <span class="text-sm font-medium">Tickets</span>
                </a>
            </div>
        </div>

    <?php elseif ($homeTarget === '/home-recepcion'): ?>
        <!-- Home Recepción -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-cyan-100 dark:bg-cyan-900/30 flex items-center justify-center">
                        <i data-lucide="concierge-bell" class="w-5 h-5 text-cyan-600 dark:text-cyan-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Recepción</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista de recepción con estado de habitaciones y auditoría. Se implementa en item 46.</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <a href="/habitaciones" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="clipboard-list" class="w-6 h-6 mx-auto mb-2 text-blue-600 dark:text-blue-400"></i>
                    <span class="text-sm font-medium">Habitaciones</span>
                </a>
                <a href="/auditoria" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-center hover:border-blue-300 dark:hover:border-blue-700 transition-colors">
                    <i data-lucide="shield-check" class="w-6 h-6 mx-auto mb-2 text-emerald-600 dark:text-emerald-400"></i>
                    <span class="text-sm font-medium">Auditoría</span>
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- Home Trabajador (default) -->
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <i data-lucide="spray-can" class="w-5 h-5 text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Mis habitaciones</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Tu cola de habitaciones asignadas para hoy. Se implementa en item 44.</p>
            </div>

            <!-- Estado vacío placeholder -->
            <div class="text-center py-12">
                <i data-lucide="check-circle" class="w-16 h-16 mx-auto mb-4 text-gray-300 dark:text-gray-600"></i>
                <p class="text-gray-500 dark:text-gray-400 text-sm">No tienes habitaciones asignadas todavía.</p>
                <p class="text-gray-400 dark:text-gray-500 text-xs mt-1">Tu supervisora las asignará pronto.</p>
            </div>
        </div>
    <?php endif; ?>

</main>
