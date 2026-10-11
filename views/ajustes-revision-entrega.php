<?php
/**
 * Ajustes → Inspección pre-entrega (v7; en código «revision_entrega»): qué hace un NO de Recepción (interruptor). Los
 * motivos de un NO se editan desde la v7.3 en Ajustes → Checklists y listas (permiso listas.editar).
 * Spec: docs/revision-entrega.md. Permiso: revision_entrega.configurar (lo chequea
 * PaginasController).
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="ajustesRevisionEntregaApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between gap-3 max-w-3xl mx-auto">
            <div class="flex items-center gap-3 min-w-0">
                <a href="<?= u('/ajustes') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                </a>
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Inspección pre-entrega</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Qué pasa cuando Recepción marca NO</p>
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
                <!-- Bloque 1: qué hace un NO -->
                <section data-tour="rev.interruptor">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Cuando Recepción marca NO</h2>
                    <div class="space-y-2" role="radiogroup" aria-label="Cuando Recepción marca NO">
                        <button type="button" role="radio" :aria-checked="!noEnsucia ? 'true' : 'false'"
                                @click="guardarInterruptor(false)" :disabled="guardandoInterruptor"
                                :class="!noEnsucia ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800'"
                                class="w-full min-h-[56px] text-left rounded-xl border-2 p-3 flex items-start gap-3 transition disabled:opacity-60">
                            <span :class="!noEnsucia ? 'border-blue-600' : 'border-gray-300 dark:border-gray-600'"
                                  class="mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0">
                                <span x-show="!noEnsucia" class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">Solo avisar a las supervisoras</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">La pieza no cambia de estado. La supervisora decide qué hacer.</span>
                            </span>
                        </button>
                        <button type="button" role="radio" :aria-checked="noEnsucia ? 'true' : 'false'"
                                @click="guardarInterruptor(true)" :disabled="guardandoInterruptor"
                                :class="noEnsucia ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800'"
                                class="w-full min-h-[56px] text-left rounded-xl border-2 p-3 flex items-start gap-3 transition disabled:opacity-60">
                            <span :class="noEnsucia ? 'border-blue-600' : 'border-gray-300 dark:border-gray-600'"
                                  class="mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0">
                                <span x-show="noEnsucia" class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">Avisar y devolver la pieza a limpieza</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Si estaba aprobada, vuelve a sucia y se avisa a Cloudbeds. La re-limpieza no suma en los KPIs.</span>
                            </span>
                        </button>
                    </div>
                </section>

                <!-- Bloque 2: los motivos de un NO se editan desde la v7.3 con las demás listas de selección -->
                <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">Motivos de un NO</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Ahora se editan junto con las demás listas para elegir (rechazo, saltar, reasignar) en <strong>Ajustes → Checklists y listas</strong>, pestaña «Listas».</p>
                    <?php if ($usuario->tienePermiso('listas.editar')): ?>
                    <a href="<?= u('/ajustes/checklists#listas') ?>"
                       class="mt-3 inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition">
                        <i data-lucide="list-checks" class="w-4 h-4"></i>
                        Ir a las listas
                    </a>
                    <?php endif; ?>
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
function ajustesRevisionEntregaApp() {
    return {
        cargando: false,
        errorCarga: false,
        listo: false,
        noEnsucia: false,
        guardandoInterruptor: false,
        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        async cargar() {
            this.cargando = true;
            this.errorCarga = false;
            try {
                var rCfg = await apiFetch('/api/revision-entrega/config');
                if (!rCfg || !rCfg.ok) {
                    this.errorCarga = true;
                    return;
                }
                this.noEnsucia = !!rCfg.data.no_ensucia;
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

        async guardarInterruptor(valor) {
            if (this.noEnsucia === valor || this.guardandoInterruptor) return;
            var anterior = this.noEnsucia;
            this.noEnsucia = valor;
            this.guardandoInterruptor = true;
            try {
                var r = await apiPut('/api/revision-entrega/config', { no_ensucia: valor });
                if (!r || !r.ok) throw new Error((r && r.error && r.error.mensaje) || '');
                this.mostrarToast('Guardado');
            } catch (e) {
                this.noEnsucia = anterior;
                this.mostrarToast(e.message || 'No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            } finally {
                this.guardandoInterruptor = false;
            }
        }
    };
}
</script>
