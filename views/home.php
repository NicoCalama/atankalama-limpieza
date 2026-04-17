<?php
/**
 * Home shell — carga el dashboard correcto según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

use Atankalama\Limpieza\Services\AuthService;

$authSvc = new AuthService();
$homeTarget = $authSvc->calcularHomeTarget($usuario);

// El Home del Trabajador tiene su propia vista completa (item 44).
// Los demás roles mantienen placeholders hasta sus items respectivos.
if ($homeTarget === '/home-trabajador') {
    include __DIR__ . '/home-trabajador.php';
    return;
}

// --- Placeholders para roles que se implementan en items 45-47 ---

require_once __DIR__ . '/componentes/avatar.php';

$primerNombre = explode(' ', $usuario->nombre)[0];

$hora = (int) date('H');
if ($hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}
?>

<!-- Header -->
<header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
    <div class="flex items-center justify-between max-w-5xl mx-auto">
        <div class="flex items-center gap-3">
            <a href="/ajustes" aria-label="Mi perfil">
                <?= avatarHtml($usuario->nombre, $usuario->rut) ?>
            </a>
            <div>
                <p class="text-lg font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($saludo) ?>, <?= htmlspecialchars($primerNombre) ?></p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    <?= htmlspecialchars(implode(', ', $usuario->roles)) ?>
                </p>
            </div>
        </div>
        <button class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative"
                aria-label="Notificaciones">
            <i data-lucide="bell" class="w-6 h-6 text-gray-600 dark:text-gray-400"></i>
        </button>
    </div>
</header>

<main class="pb-24 md:pb-8 px-4 py-6 max-w-5xl mx-auto">

    <?php if ($homeTarget === '/home-admin'): ?>
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center">
                        <i data-lucide="shield" class="w-5 h-5 text-violet-600 dark:text-violet-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Panel de Administración</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista completa en item 47.</p>
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
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                        <i data-lucide="eye" class="w-5 h-5 text-amber-600 dark:text-amber-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Panel de Supervisión</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista completa en item 45.</p>
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
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-cyan-100 dark:bg-cyan-900/30 flex items-center justify-center">
                        <i data-lucide="concierge-bell" class="w-5 h-5 text-cyan-600 dark:text-cyan-400"></i>
                    </div>
                    <h2 class="text-lg font-semibold">Recepción</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Vista completa en item 46.</p>
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
    <?php endif; ?>

</main>
