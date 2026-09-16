<?php
/**
 * Shell /ajustes — índice de secciones disponibles.
 * Spec: docs/ajustes.md §2
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

// letra_indice: posición (0-based) de la letra de acceso dentro de "label", ya
// resuelta a mano para que las 11 no choquen entre sí (accesibilidad-teclado.md).
// Evita D/E/F/V/H/B/T cuando hay alternativa (conocidas reservadas por el
// navegador en Windows); "Versiones" no tuvo alternativa y usa "V".
$secciones = [
    [
        'ruta' => '/ajustes/mi-cuenta',
        'icono' => 'user',
        'label' => 'Mi cuenta',
        'letra_indice' => 0, // M
        'descripcion' => 'Datos personales, tema y contraseña',
        'visible' => true,
    ],
    [
        'ruta' => '/ajustes/rbac',
        'icono' => 'shield',
        'label' => 'Roles y permisos',
        'letra_indice' => 0, // R
        'descripcion' => 'Matriz editable de permisos por rol',
        'visible' => $usuario->tienePermiso('permisos.asignar_a_rol'),
    ],
    [
        'ruta' => '/usuarios',
        'icono' => 'user-cog',
        'label' => 'Usuarios',
        'letra_indice' => 0, // U
        'descripcion' => 'Crear, editar y administrar usuarios',
        'visible' => $usuario->tienePermiso('usuarios.ver'),
    ],
    [
        'ruta' => '/ajustes/checklists',
        'icono' => 'list-checks',
        'label' => 'Checklists',
        'letra_indice' => 0, // C
        'descripcion' => 'Ítems del checklist por tipo y sus créditos',
        'visible' => $usuario->tienePermiso('checklists.editar'),
    ],
    [
        'ruta' => '/edificios',
        'icono' => 'layout-dashboard',
        'label' => 'Edificios',
        'letra_indice' => 2, // Ed[i]ficios
        'descripcion' => 'CRUD de edificios y asignación drag-and-drop',
        'visible' => $usuario->tienePermiso('habitaciones.ver_todas'),
    ],
    [
        'ruta' => '/ajustes/colores',
        'icono' => 'palette',
        'label' => 'Colores',
        'letra_indice' => 1, // C[o]lores
        'descripcion' => 'Colores de las tarjetas por estado y hotel',
        'visible' => $usuario->tienePermiso('apariencia.editar'),
    ],
    [
        'ruta' => '/ajustes/turnos',
        'icono' => 'calendar-clock',
        'label' => 'Turnos',
        'letra_indice' => 3, // Tur[n]os
        'descripcion' => 'Catálogo de turnos y asignación semanal',
        'visible' => $usuario->tienePermiso('turnos.ver'),
    ],
    [
        'ruta' => '/ajustes/importar-turnos',
        'icono' => 'file-up',
        'label' => 'Importar turnos',
        'letra_indice' => 2, // Im[p]ortar turnos
        'descripcion' => 'Carga masiva de turnos desde archivo',
        'visible' => $usuario->tienePermiso('turnos.importar'),
    ],
    [
        'ruta' => '/ajustes/alertas',
        'icono' => 'bell-ring',
        'label' => 'Alertas',
        'letra_indice' => 0, // A
        'descripcion' => 'Umbrales y recálculo de alertas predictivas',
        'visible' => $usuario->tienePermiso('alertas.configurar_umbrales'),
    ],
    [
        'ruta' => '/reportes',
        'icono' => 'bar-chart-3',
        'label' => 'Reportes',
        'letra_indice' => 7, // Reporte[s]
        'descripcion' => 'Indicadores y reportes operativos',
        'visible' => $usuario->tienePermiso('reportes.ver'),
    ],
    [
        'ruta' => '/ajustes/versiones',
        'icono' => 'git-branch',
        'label' => 'Versiones',
        'letra_indice' => 0, // V (sin alternativa libre entre las 11)
        'descripcion' => 'Qué cambió en cada versión de la app',
        'visible' => true,
    ],
];

$visibles = array_filter($secciones, fn($s) => $s['visible']);
?>

<div x-data="ajustesAccesoTeclado()"
     @keydown.window="if ($event.key === 'Alt') { altActivo = true; } else { manejarAccessKey($event); }"
     @keyup.window="altActivo = false">
    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-5xl mx-auto">
            <a href="<?= u('/home') ?>" class="md:hidden min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <i data-lucide="settings" class="w-6 h-6 text-gray-700 dark:text-gray-300 flex-shrink-0 hidden md:block"></i>
            <div class="min-w-0" data-tour="aj.contador">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Ajustes</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400"><?= count($visibles) ?> <?= count($visibles) === 1 ? 'sección disponible' : 'secciones disponibles' ?></p>
            </div>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 pb-24 md:pb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3" data-tour="aj.secciones">
            <?php foreach ($visibles as $s):
                $label = (string) $s['label'];
                $idx = (int) $s['letra_indice'];
                $antes = htmlspecialchars(mb_substr($label, 0, $idx, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                $letra = htmlspecialchars(mb_substr($label, $idx, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                $despues = htmlspecialchars(mb_substr($label, $idx + 1, null, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                $codigoTecla = 'Key' . strtoupper($letra);
            ?>
                <a href="<?= htmlspecialchars(u((string) $s['ruta']), ENT_QUOTES, 'UTF-8') ?>"
                   data-tecla-acceso="<?= $codigoTecla ?>"
                   class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-600 hover:shadow-sm transition min-h-[80px]">
                    <div class="w-11 h-11 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="<?= htmlspecialchars((string) $s['icono'], ENT_QUOTES, 'UTF-8') ?>" class="w-5 h-5 text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100"><?= $antes ?><span :class="altActivo ? 'underline' : ''"><?= $letra ?></span><?= $despues ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($s['descripcion']) ?></p>
                    </div>
                    <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 dark:text-gray-500 flex-shrink-0"></i>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Notificaciones push -->
        <div x-data="pushToggle()" x-init="init()" class="mt-4" data-tour="aj.push">
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
// Accesibilidad de teclado (rules/accesibilidad-teclado.md). Las letras de
// cada tarjeta ya vienen resueltas desde PHP (sin choques entre sí, ver
// $secciones arriba); acá solo se detecta Alt/Option y se activa la tarjeta
// que tenga el data-tecla-acceso correspondiente, igual que un clic.
function ajustesAccesoTeclado() {
    return {
        altActivo: false,
        manejarAccessKey(e) {
            if (!e.altKey) return;
            var el = document.querySelector('[data-tecla-acceso="' + e.code + '"]');
            if (el) { e.preventDefault(); el.click(); }
        }
    };
}

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
