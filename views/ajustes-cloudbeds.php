<?php
/**
 * Ajustes → Cloudbeds (v7.8): qué hace la app cuando una habitación sale de mantención o de cerrada en
 * Cloudbeds. Un interruptor por tipo (decisión de Nicolás, 15/10/2026): prendido = al terminar el bloqueo
 * la pieza pasa a sucia, queda sin asignar con aviso a la supervisora y su limpieza es completa, con
 * cambio de sábanas. Prendidos si nadie los tocó. Spec: docs/cloudbeds.md §4.5.
 * Permiso: cloudbeds.configurar_credenciales (lo chequea PaginasController).
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="ajustesCloudbedsApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between gap-3 max-w-3xl mx-auto">
            <div class="flex items-center gap-3 min-w-0">
                <a href="<?= u('/ajustes') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Cloudbeds</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Habitaciones en mantención o cerradas</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-6">

        <!-- Cargando -->
        <div x-show="cargando && !listo" class="flex flex-col items-center justify-center py-16">
            <div class="w-8 h-8 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-3">Cargando...</p>
        </div>

        <div x-show="errorCarga && !cargando" x-cloak class="text-center py-12">
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">No pudimos cargar la configuración.</p>
            <button @click="cargar()" class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">Reintentar</button>
        </div>

        <template x-if="listo">
            <div class="space-y-6">
                <section>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">Cuando una habitación vuelve a estar disponible</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        Mientras está en mantención o cerrada en Cloudbeds, la habitación no se asigna y sale de las colas.
                        Con el interruptor prendido, al terminar se genera su limpieza: pasa a sucia, queda sin asignar
                        con un aviso a la supervisora, y el checklist es completo, con cambio de sábanas.
                    </p>
                    <div class="space-y-2">
                        <template x-for="op in opciones" :key="op.clave">
                            <button type="button" role="switch" :aria-checked="config[op.clave] ? 'true' : 'false'"
                                    @click="alternar(op.clave)" :disabled="guardando === op.clave"
                                    class="w-full min-h-[56px] text-left rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 flex items-center gap-3 transition disabled:opacity-60">
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="op.titulo"></span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400"
                                          x-text="config[op.clave] ? 'Se genera la limpieza completa.' : 'Solo se quita la etiqueta; no se genera limpieza.'"></span>
                                </span>
                                <span :class="config[op.clave] ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                                      class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full transition" aria-hidden="true">
                                    <span :class="config[op.clave] ? 'translate-x-5' : 'translate-x-0'"
                                          class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition"></span>
                                </span>
                            </button>
                        </template>
                    </div>
                </section>
            </div>
        </template>
        <!-- Toast -->
        <div x-show="toast.visible" x-cloak x-transition
             :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50"
             x-text="toast.mensaje"></div>
    </main>
</div>

<script>
function ajustesCloudbedsApp() {
    return {
        cargando: false,
        errorCarga: false,
        listo: false,
        config: { mantencion: true, cerrada: true },
        opciones: [
            { clave: 'mantencion', titulo: 'Limpieza al salir de mantención' },
            { clave: 'cerrada', titulo: 'Limpieza al dejar de estar cerrada' }
        ],
        guardando: null,
        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        async cargar() {
            this.cargando = true;
            this.errorCarga = false;
            try {
                var r = await apiFetch('/api/cloudbeds/bloqueos-config');
                if (!r || !r.ok) {
                    this.errorCarga = true;
                    return;
                }
                this.config = { mantencion: !!r.data.mantencion, cerrada: !!r.data.cerrada };
                this.listo = true;
            } catch (e) {
                this.errorCarga = true;
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        mostrarToast(mensaje, tipo) {
            var self = this;
            this.toast = { visible: true, mensaje: mensaje, tipo: tipo || 'ok' };
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        async alternar(clave) {
            if (this.guardando) return;
            var anterior = this.config[clave];
            this.config[clave] = !anterior;
            this.guardando = clave;
            try {
                var cuerpo = {};
                cuerpo[clave] = this.config[clave];
                var r = await apiPut('/api/cloudbeds/bloqueos-config', cuerpo);
                if (!r || !r.ok) throw new Error((r && r.error && r.error.mensaje) || '');
                this.mostrarToast('Guardado');
            } catch (e) {
                this.config[clave] = anterior;
                this.mostrarToast(e.message || 'No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            } finally {
                this.guardando = null;
            }
        }
    };
}
</script>
