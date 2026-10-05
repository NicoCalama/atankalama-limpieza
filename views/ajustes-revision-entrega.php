<?php
/**
 * Ajustes → Inspección pre-entrega (v6.18; en código «revision_entrega»): qué hace un NO de Recepción (interruptor) y el catálogo
 * de motivos. Spec: docs/revision-entrega.md. Permiso: revision_entrega.configurar (lo chequea
 * PaginasController). Motivos: crear, renombrar, activar/desactivar. No se borran ni se reordenan.
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
                    <p class="text-xs text-gray-500 dark:text-gray-400">Qué pasa cuando Recepción marca NO y los motivos que puede elegir</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-6">

        <!-- Cargando -->
        <div x-show="cargando && motivos.length === 0" class="flex flex-col items-center justify-center py-16">
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

                <!-- Bloque 2: motivos -->
                <section>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Motivos de un NO</h2>

                    <form class="flex gap-2 mb-1" data-tour="mot.nuevo" @submit.prevent="crear()">
                        <input type="text" maxlength="60" x-model="nuevoNombre" placeholder="Ej: Baño sucio" aria-label="Nuevo motivo"
                               class="flex-1 min-h-[44px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-3 text-base focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="submit" :disabled="nuevoNombre.trim() === '' || creando"
                                class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold disabled:opacity-50">
                            Agregar
                        </button>
                    </form>
                    <p x-show="errorNuevo" x-cloak class="text-sm text-red-600 dark:text-red-400 mb-2" x-text="errorNuevo"></p>

                    <div class="mt-3 space-y-2" data-tour="mot.lista">
                        <template x-if="motivos.length === 0">
                            <div class="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
                                <i data-lucide="list-x" class="w-8 h-8 mx-auto mb-2 text-gray-400"></i>
                                Todavía no hay motivos. Crea el primero con «Agregar».
                            </div>
                        </template>
                        <template x-for="m in motivos" :key="m.id">
                            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-3 flex items-center gap-2">
                                <template x-if="editandoId !== m.id">
                                    <div class="flex-1 min-w-0 flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100 break-words min-w-0" x-text="m.nombre"></span>
                                        <span :class="m.activo ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                                                               : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-600'"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border flex-shrink-0"
                                              x-text="m.activo ? 'Activo' : 'Inactivo'"></span>
                                    </div>
                                </template>
                                <template x-if="editandoId === m.id">
                                    <form class="flex-1 flex gap-2" @submit.prevent="guardarNombre(m)">
                                        <input type="text" maxlength="60" x-model="editandoNombre" aria-label="Nombre del motivo"
                                               class="flex-1 min-w-0 min-h-[40px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-2 text-base">
                                        <button type="submit" class="min-h-[40px] px-3 rounded-lg bg-blue-600 text-white text-sm font-semibold">Guardar</button>
                                        <button type="button" @click="editandoId = null" class="min-h-[40px] px-3 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm">Cancelar</button>
                                    </form>
                                </template>
                                <button type="button" x-show="editandoId !== m.id" @click="editar(m)" aria-label="Editar"
                                        class="min-h-[40px] min-w-[40px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                                    <i data-lucide="pencil" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                                </button>
                                <button type="button" x-show="editandoId !== m.id" @click="alternarActivo(m)" role="switch"
                                        :aria-checked="m.activo ? 'true' : 'false'" :aria-label="m.activo ? 'Desactivar motivo' : 'Activar motivo'"
                                        :class="m.activo ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"
                                        class="relative w-11 h-6 rounded-full transition flex-shrink-0">
                                    <span :class="m.activo ? 'translate-x-5' : 'translate-x-0.5'"
                                          class="absolute top-0.5 left-0 w-5 h-5 rounded-full bg-white shadow transition-transform"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Un motivo inactivo deja de aparecer en el botón NO, pero el historial lo conserva.</p>
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
        motivos: [],
        nuevoNombre: '',
        creando: false,
        errorNuevo: '',
        editandoId: null,
        editandoNombre: '',
        toast: { visible: false, mensaje: '', tipo: 'ok' },
        toastTimer: null,

        async cargar() {
            this.cargando = true;
            this.errorCarga = false;
            try {
                var [rCfg, rMot] = await Promise.all([
                    apiFetch('/api/revision-entrega/config'),
                    apiFetch('/api/revision-entrega/motivos?todos=1')
                ]);
                if (!rCfg || !rCfg.ok || !rMot || !rMot.ok) {
                    this.errorCarga = true;
                    return;
                }
                this.noEnsucia = !!rCfg.data.no_ensucia;
                this.motivos = rMot.data.motivos || [];
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
        },

        async recargarMotivos() {
            var r = await apiFetch('/api/revision-entrega/motivos?todos=1');
            if (r && r.ok) this.motivos = r.data.motivos || [];
            this.$nextTick(function () { lucide.createIcons(); });
        },

        async crear() {
            var nombre = this.nuevoNombre.trim();
            if (nombre === '' || this.creando) return;
            this.creando = true;
            this.errorNuevo = '';
            try {
                var r = await apiPost('/api/revision-entrega/motivos', { nombre: nombre });
                if (r && r.ok) {
                    this.nuevoNombre = '';
                    await this.recargarMotivos();
                    this.mostrarToast('Motivo agregado');
                } else {
                    this.errorNuevo = (r && r.error && r.error.mensaje) || 'No pudimos guardar el motivo.';
                }
            } catch (e) {
                this.errorNuevo = 'No pudimos guardar el motivo, intenta de nuevo en un momento.';
            } finally {
                this.creando = false;
            }
        },

        editar(m) {
            this.editandoId = m.id;
            this.editandoNombre = m.nombre;
        },

        async guardarNombre(m) {
            var nombre = this.editandoNombre.trim();
            if (nombre === '' || nombre === m.nombre) {
                this.editandoId = null;
                return;
            }
            try {
                var r = await apiPut('/api/revision-entrega/motivos/' + m.id, { nombre: nombre });
                if (r && r.ok) {
                    this.editandoId = null;
                    await this.recargarMotivos();
                    this.mostrarToast('Guardado');
                } else {
                    this.mostrarToast((r && r.error && r.error.mensaje) || 'No pudimos guardar tu cambio.', 'error');
                }
            } catch (e) {
                this.mostrarToast('No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            }
        },

        async alternarActivo(m) {
            var nuevo = !m.activo;
            m.activo = nuevo;
            try {
                var r = await apiPut('/api/revision-entrega/motivos/' + m.id, { activo: nuevo });
                if (!r || !r.ok) throw new Error((r && r.error && r.error.mensaje) || '');
                this.mostrarToast(nuevo ? 'Motivo activado' : 'Motivo desactivado');
            } catch (e) {
                m.activo = !nuevo;
                this.mostrarToast(e.message || 'No pudimos guardar tu cambio, intenta de nuevo en un momento.', 'error');
            }
        }
    };
}
</script>
