<?php
/**
 * Pantalla «Todas las alertas»: destino del enlace "Ver todas las alertas (N)" que los
 * dos Inicios muestran cuando hay más de 5 (home-admin.php y home-supervisora.php).
 * Spec: docs/home-admin.md (sección Alertas) y docs/home-supervisora.md (sección Alertas).
 *
 * Fuente de datos: GET /api/alertas, que devuelve TODAS las alertas activas ordenadas por
 * prioridad y después por fecha. Ojo: el Inicio del Admin solo cuenta las críticas
 * (prioridad 0 y 1), así que para un admin esta pantalla puede mostrar algunas más que el
 * número del enlace. El Inicio de la supervisora sí cuenta todas.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

// Los botones de cada alerta dependen de permisos. El Inicio los saca de su propio payload;
// acá se resuelven en el servidor y viajan al x-data.
$puedeAsignar = $usuario->tienePermiso('asignaciones.asignar_manual');
$puedeImportarInventario = $usuario->tienePermiso('habitaciones.importar_inventario');
$puedeSincronizarCloudbeds = $usuario->tienePermiso('cloudbeds.forzar_sincronizacion');
?>

<div x-data="alertasApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-3xl mx-auto">
            <a href="<?= u('/home') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver al inicio">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Alertas</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <span x-show="!cargando && alertas.length > 0" x-text="alertas.length + (alertas.length === 1 ? ' alerta activa' : ' alertas activas')"></span>
                    <span x-show="cargando">Cargando...</span>
                    <span x-show="!cargando && alertas.length === 0" x-cloak>Sin alertas activas</span>
                </p>
            </div>
            <button @click="cargar()" :disabled="cargando"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition disabled:opacity-50"
                    aria-label="Refrescar">
                <i data-lucide="refresh-cw" class="w-5 h-5" :class="cargando ? 'animate-spin' : ''"></i>
            </button>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4 pb-24 md:pb-6 space-y-3">

        <!-- Cargando -->
        <div x-show="cargando" class="flex flex-col items-center justify-center py-12">
            <svg class="animate-spin h-6 w-6 text-blue-600" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
            </svg>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Cargando...</p>
        </div>

        <!-- Error -->
        <template x-if="!cargando && error">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 text-center">
                <i data-lucide="alert-circle" class="w-8 h-8 text-red-500 mx-auto mb-2"></i>
                <p class="text-sm text-red-800 dark:text-red-300" x-text="error"></p>
                <button @click="cargar()" class="mt-3 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                    Reintentar
                </button>
            </div>
        </template>

        <!-- Vacío -->
        <template x-if="!cargando && !error && alertas.length === 0">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                <i data-lucide="check-circle-2" class="w-10 h-10 text-green-500 mx-auto mb-3"></i>
                <p class="text-sm text-gray-600 dark:text-gray-400">Todo tranquilo. No hay alertas activas.</p>
                <a href="<?= u('/home') ?>" class="inline-flex items-center justify-center gap-1 mt-4 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                    Volver al inicio
                </a>
            </div>
        </template>

        <!-- Listado -->
        <template x-if="!cargando && !error && alertas.length > 0">
            <div class="space-y-2">
                <template x-for="al in alertas" :key="al.id">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4"
                         :class="claseBordeAlerta(al.prioridad)">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                 :class="claseIconoAlerta(al.tipo)">
                                <i :data-lucide="iconoAlerta(al.tipo)" class="w-4 h-4"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-gray-100" x-text="al.titulo"></p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1" x-text="al.descripcion"></p>
                            </div>
                        </div>
                        <template x-if="botonesAlerta(al).length > 0">
                            <div class="flex flex-wrap gap-2 mt-3 pl-11">
                                <template x-for="btn in botonesAlerta(al)" :key="btn.accion">
                                    <button @click="accionAlerta(al, btn.accion)"
                                            class="min-h-[44px] px-3 py-1.5 text-sm font-medium rounded-lg transition"
                                            :class="btn.clase">
                                        <span x-text="btn.etiqueta"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>

    </main>

    <!-- Toast -->
    <div x-show="toast.visible" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg shadow-lg text-white text-sm font-medium max-w-sm w-[90%] text-center"
         :class="toast.tipo === 'exito' ? 'bg-green-600' : 'bg-red-600'"
         x-text="toast.mensaje"></div>


</div>

<script>
function alertasApp() {
    return {
        cargando: true,
        error: null,
        alertas: [],
        toast: { visible: false, tipo: 'exito', mensaje: '' },
        puedeAsignar: <?= $puedeAsignar ? 'true' : 'false' ?>,
        puedeImportarInventario: <?= $puedeImportarInventario ? 'true' : 'false' ?>,
        puedeSincronizarCloudbeds: <?= $puedeSincronizarCloudbeds ? 'true' : 'false' ?>,

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var r = await apiFetch('/api/alertas');
                if (r && r.ok) {
                    this.alertas = r.data.alertas || [];
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos cargar las alertas.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        // --- Presentación (mismos mapas que el Inicio) ---

        iconoAlerta(tipo) {
            var map = {
                'cloudbeds_sync_failed': 'refresh-cw-off',
                'trabajador_en_riesgo': 'alert-triangle',
                'habitacion_rechazada': 'x-circle',
                'fin_turno_pendientes': 'clock',
                'trabajador_disponible': 'user-check',
                'ticket_nuevo': 'wrench',
                'habitacion_saltada': 'skip-forward',
                'inventario_cambios_pendientes': 'refresh-cw',
                'aprobacion_deshecha': 'rotate-ccw'
            };
            return map[tipo] || 'bell';
        },

        claseIconoAlerta(tipo) {
            var map = {
                'cloudbeds_sync_failed': 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                'trabajador_en_riesgo': 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'habitacion_rechazada': 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                'fin_turno_pendientes': 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'trabajador_disponible': 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                'ticket_nuevo': 'bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400',
                'habitacion_saltada': 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'inventario_cambios_pendientes': 'bg-teal-100 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400',
                'aprobacion_deshecha': 'bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400'
            };
            return map[tipo] || 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        claseBordeAlerta(prioridad) {
            if (prioridad === 0) return 'border-l-4 border-l-red-600';
            if (prioridad === 1) return 'border-l-4 border-l-amber-500';
            return '';
        },

        botonesAlerta(al) {
            var btnPrimario = 'bg-blue-600 hover:bg-blue-700 text-white';
            var btnSecundario = 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100';

            // Cambios de inventario exigen una decisión explícita (Aceptar/Rechazar).
            if (al.tipo === 'inventario_cambios_pendientes') {
                if (!this.puedeImportarInventario) return [];
                return [
                    { accion: 'inventario_rechazar', etiqueta: 'Rechazar', clase: btnSecundario },
                    { accion: 'inventario_aceptar', etiqueta: 'Aceptar', clase: btnPrimario }
                ];
            }

            var botones = [];
            if (al.tipo === 'cloudbeds_sync_failed') {
                if (al.contexto && al.contexto.habitacion_id) {
                    // Escritura fallida de UNA pieza: se cierra cuando una escritura de esa pieza
                    // funciona (rehacer la acción desde su ficha), no con un sync de lectura.
                    botones.push({ accion: 'ir_habitacion', etiqueta: 'Ver habitación', clase: btnPrimario });
                } else if (this.puedeSincronizarCloudbeds) {
                    // Sin el permiso no hay botón: la alerta se cierra con el próximo sync que funcione.
                    botones.push({ accion: 'cloudbeds_retry', etiqueta: 'Reintentar ahora', clase: btnPrimario });
                }
            } else if (al.tipo === 'trabajador_en_riesgo' || al.tipo === 'fin_turno_pendientes') {
                // Un solo botón: los dos llevaban a /asignaciones con la misma key (rompía el x-for).
                botones.push({ accion: 'ir_asignaciones', etiqueta: this.puedeAsignar ? 'Reasignar' : 'Ver asignaciones', clase: btnPrimario });
            } else if (al.tipo === 'habitacion_rechazada' || al.tipo === 'aprobacion_deshecha' || al.tipo === 'habitacion_saltada') {
                if (al.contexto && al.contexto.habitacion_id) {
                    botones.push({ accion: 'ir_habitacion', etiqueta: 'Ver habitación', clase: btnPrimario });
                }
            } else if (al.tipo === 'trabajador_disponible') {
                if (this.puedeAsignar) botones.push({ accion: 'ir_asignaciones', etiqueta: 'Asignar', clase: btnPrimario });
            } else if (al.tipo === 'ticket_nuevo') {
                botones.push({ accion: 'marcar_atendido', etiqueta: 'Marcar atendido', clase: btnPrimario });
            }

            return botones;
        },

        // DEFAULT APLICADO: "Ver carga" y "Reasignar" del Inicio de la supervisora abren modales
        // que viven en esa pantalla (dependen de su payload de equipo). Acá no existen, así que
        // estas acciones navegan a /asignaciones, igual que en el Inicio del Admin.
        async accionAlerta(al, accion) {
            if (accion === 'cloudbeds_retry') {
                // Sync real (antes solo borraba la alerta). La P0 se cierra sola si funciona.
                try {
                    var rs = await apiPost('/api/cloudbeds/sync', {});
                    if (rs && rs.ok && rs.data && rs.data.sync && rs.data.sync.resultado === 'exito') {
                        this.mostrarToast('exito', 'Sincronización completa.');
                    } else if (rs && rs.ok) {
                        this.mostrarToast('error', 'La sincronización volvió a fallar. Revisa el detalle en Ajustes → Cloudbeds.');
                    } else {
                        this.mostrarToast('error', (rs && rs.error && rs.error.mensaje) || 'No pudimos sincronizar.');
                    }
                } catch (e) {
                    this.mostrarToast('error', 'No pudimos conectar con el servidor.');
                }
                this.cargar();
                return;
            }
            if (accion === 'ir_asignaciones') {
                window.location.href = u('/asignaciones');
                return;
            }
            if (accion === 'ir_habitacion') {
                var habId = al.contexto && al.contexto.habitacion_id;
                if (habId) window.location.href = u('/habitaciones/' + habId);
                return;
            }
            if (accion === 'inventario_aceptar' || accion === 'inventario_rechazar') {
                var ruta = accion === 'inventario_aceptar' ? '/api/inventario/aplicar' : '/api/inventario/rechazar';
                var exito = accion === 'inventario_aceptar' ? 'Inventario actualizado.' : 'Cambios descartados.';
                var falla = accion === 'inventario_aceptar' ? 'No pudimos actualizar el inventario.' : 'No pudimos descartar los cambios.';
                try {
                    var ri = await apiPost(ruta, { alerta_id: al.id });
                    if (ri && ri.ok) {
                        this.mostrarToast('exito', exito);
                        this.cargar();
                    } else {
                        this.mostrarToast('error', (ri && ri.error && ri.error.mensaje) || falla);
                    }
                } catch (e) {
                    this.mostrarToast('error', 'No pudimos conectar con el servidor.');
                }
                return;
            }
            // Acciones genéricas que resuelven la alerta en bitácora (hoy: «Marcar atendido» del ticket)
            try {
                var r = await apiPost('/api/alertas/' + al.id + '/accion', { accion: accion });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Acción registrada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos ejecutar la acción.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
