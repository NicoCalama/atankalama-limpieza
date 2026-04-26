<?php
/**
 * Shell /ajustes — índice de secciones disponibles.
 * Spec: docs/ajustes.md §2
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$secciones = [
    [
        'ruta' => '/ajustes/mi-cuenta',
        'icono' => 'user',
        'label' => 'Mi cuenta',
        'descripcion' => 'Datos personales, tema y contraseña',
        'visible' => true,
    ],
    [
        'ruta' => '/ajustes/rbac',
        'icono' => 'shield',
        'label' => 'Roles y permisos',
        'descripcion' => 'Matriz editable de permisos por rol',
        'visible' => $usuario->tienePermiso('permisos.asignar_a_rol'),
    ],
    [
        'ruta' => '/usuarios',
        'icono' => 'user-cog',
        'label' => 'Usuarios',
        'descripcion' => 'Crear, editar y administrar usuarios',
        'visible' => $usuario->tienePermiso('usuarios.ver'),
    ],
    [
        'ruta' => '/ajustes/turnos',
        'icono' => 'calendar-clock',
        'label' => 'Turnos',
        'descripcion' => 'Catálogo de turnos y asignación semanal',
        'visible' => $usuario->tienePermiso('turnos.ver'),
    ],
    [
        'ruta' => '/ajustes/alertas',
        'icono' => 'bell-ring',
        'label' => 'Alertas',
        'descripcion' => 'Umbrales y recálculo de alertas predictivas',
        'visible' => $usuario->tienePermiso('alertas.configurar_umbrales'),
    ],
];

$visibles = array_filter($secciones, fn($s) => $s['visible']);
?>

<div>
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-5xl mx-auto">
            <a href="/home" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <i data-lucide="settings" class="w-6 h-6 text-gray-700 dark:text-gray-300 flex-shrink-0 hidden md:block"></i>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Ajustes</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400"><?= count($visibles) ?> <?= count($visibles) === 1 ? 'sección disponible' : 'secciones disponibles' ?></p>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($visibles as $s): ?>
                <a href="<?= htmlspecialchars((string) $s['ruta'], ENT_QUOTES, 'UTF-8') ?>"
                   class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-600 hover:shadow-sm transition min-h-[80px]">
                    <div class="w-11 h-11 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="<?= htmlspecialchars((string) $s['icono'], ENT_QUOTES, 'UTF-8') ?>" class="w-5 h-5 text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($s['label']) ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($s['descripcion']) ?></p>
                    </div>
                    <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 dark:text-gray-500 flex-shrink-0"></i>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Notificaciones push -->
        <div x-data="pushToggle()" x-init="init()" class="mt-4">
            <template x-if="soportado">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex items-center gap-4">
                    <div class="w-11 h-11 rounded-lg bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="bell" class="w-5 h-5 text-amber-600 dark:text-amber-400"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Notificaciones push</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"
                           x-text="suscrito ? 'Activadas en este dispositivo' : (denegado ? 'Bloqueadas en este navegador' : 'Recibe alertas aunque la app esté cerrada')"></p>
                    </div>
                    <template x-if="!denegado">
                        <button @click="toggle()" :disabled="cargando"
                                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
                                :class="suscrito ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200"
                                  :class="suscrito ? 'translate-x-5' : 'translate-x-0'"></span>
                        </button>
                    </template>
                    <template x-if="denegado">
                        <span class="text-xs text-red-500 font-medium">Bloqueado</span>
                    </template>
                </div>
            </template>
        </div>
    </main>
</div>

<script>
function pushToggle() {
    return {
        soportado: false,
        suscrito: false,
        denegado: false,
        cargando: false,

        async init() {
            this.soportado = PushManager.soportado();
            if (!this.soportado) return;
            this.denegado  = Notification.permission === 'denied';
            this.suscrito  = await PushManager.estaSuscrito();
        },

        async toggle() {
            if (this.cargando) return;
            this.cargando = true;
            if (this.suscrito) {
                await PushManager.desuscribir();
                this.suscrito = false;
            } else {
                this.suscrito = await PushManager.suscribir();
                this.denegado = Notification.permission === 'denied';
            }
            this.cargando = false;
        }
    };
}
</script>
