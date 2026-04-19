<?php
/**
 * Ajustes → Alertas (config de umbrales predictivos).
 * Spec: docs/ajustes.md §4 + docs/alertas-predictivas.md
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="alertasConfigApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-3xl mx-auto">
            <a href="/ajustes" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Alertas</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400">Umbrales de alertas predictivas</p>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4 pb-24 md:pb-6 space-y-4">

        <!-- Explicación -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4">
            <div class="flex gap-2">
                <i data-lucide="info" class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5"></i>
                <div class="text-sm text-blue-900 dark:text-blue-200 space-y-1">
                    <p>Los valores controlan cuándo se disparan las alertas predictivas (ej. "trabajador en riesgo").</p>
                    <p class="text-xs">Al guardar, el sistema recalcula inmediatamente las alertas activas.</p>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div x-show="cargando" x-cloak class="flex items-center justify-center py-10">
            <svg class="animate-spin h-6 w-6 text-blue-600" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
            </svg>
        </div>

        <!-- Form -->
        <section x-show="!cargando" x-cloak class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-6 space-y-5">

            <template x-for="campo in campos" :key="campo.clave">
                <div>
                    <div class="flex items-baseline justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-900 dark:text-gray-100" x-text="campo.label"></label>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="'default: ' + campo.default"></span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2" x-text="campo.descripcion"></p>
                    <div class="relative">
                        <input type="number" min="1" step="1" x-model="form[campo.clave]"
                               :class="modificado(campo.clave) ? 'ring-2 ring-amber-400 border-amber-400' : 'border-gray-300 dark:border-gray-600'"
                               class="w-full min-h-[44px] px-3 py-2 pr-16 rounded-lg bg-white dark:bg-gray-900 border text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500 dark:text-gray-400 pointer-events-none">minutos</span>
                    </div>
                </div>
            </template>

            <!-- Banner cambios -->
            <div x-show="totalCambios() > 0" x-cloak
                 class="flex items-center gap-2 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
                <i data-lucide="alert-circle" class="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0"></i>
                <p class="text-xs text-amber-900 dark:text-amber-100">
                    <strong x-text="totalCambios()"></strong>
                    <span x-text="totalCambios() === 1 ? ' cambio sin guardar' : ' cambios sin guardar'"></span>
                </p>
            </div>

            <!-- Acciones -->
            <div class="flex items-center justify-between gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                <button type="button" @click="recalcularAhora()" :disabled="recalculando"
                        class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 text-sm font-medium transition">
                    <i data-lucide="refresh-cw" class="w-4 h-4" :class="recalculando ? 'animate-spin' : ''"></i>
                    <span x-text="recalculando ? 'Recalculando...' : 'Recalcular ahora'"></span>
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" @click="descartar()" x-show="totalCambios() > 0" x-cloak
                            class="min-h-[44px] px-3 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                        Descartar
                    </button>
                    <button type="button" @click="guardar()" :disabled="totalCambios() === 0 || guardando"
                            class="min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition">
                        <template x-if="guardando">
                            <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                            </svg>
                        </template>
                        <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Toast -->
        <div x-show="toast.visible" x-cloak x-transition
             :class="toast.tipo === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-50"
             x-text="toast.mensaje"></div>
    </main>
</div>

<script>
function alertasConfigApp() {
    return {
        cargando: true,
        guardando: false,
        recalculando: false,
        campos: [
            {
                clave: 'margen_seguridad_minutos',
                label: 'Margen de seguridad',
                descripcion: 'Minutos de colchón al calcular si un trabajador alcanza a terminar su turno.',
                default: '15',
            },
            {
                clave: 'fin_turno_anticipo_minutos',
                label: 'Anticipo fin de turno',
                descripcion: 'Minutos antes del fin de turno que se lanzan alertas "fin_turno_pendientes".',
                default: '30',
            },
            {
                clave: 'recalculo_intervalo_minutos',
                label: 'Intervalo de recálculo',
                descripcion: 'Cada cuántos minutos el sistema recalcula las alertas predictivas automáticamente.',
                default: '15',
            },
            {
                clave: 'tiempo_fallback_nueva_habitacion',
                label: 'Tiempo por defecto (habitación nueva)',
                descripcion: 'Tiempo estimado de limpieza cuando no hay histórico para un trabajador.',
                default: '30',
            },
        ],
        form: {},
        original: {},
        toast: { visible: false, mensaje: '', tipo: 'ok' },

        async cargar() {
            this.cargando = true;
            try {
                const res = await apiFetch('/api/alertas/config');
                if (!res.ok) {
                    this.mostrarToast(res.error?.mensaje || 'No se pudo cargar.', 'error');
                    return;
                }
                const cfg = res.data?.config || {};
                for (const c of this.campos) {
                    this.form[c.clave] = String(cfg[c.clave] ?? c.default);
                }
                this.original = JSON.parse(JSON.stringify(this.form));
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.cargando = false;
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            }
        },

        modificado(clave) {
            return String(this.form[clave]) !== String(this.original[clave]);
        },

        totalCambios() {
            return this.campos.filter(c => this.modificado(c.clave)).length;
        },

        descartar() {
            this.form = JSON.parse(JSON.stringify(this.original));
        },

        async guardar() {
            if (this.totalCambios() === 0 || this.guardando) return;
            const payload = {};
            for (const c of this.campos) {
                if (this.modificado(c.clave)) {
                    const n = parseInt(this.form[c.clave], 10);
                    if (isNaN(n) || n < 1) {
                        this.mostrarToast(c.label + ' debe ser un número ≥ 1.', 'error');
                        return;
                    }
                    payload[c.clave] = String(n);
                }
            }
            this.guardando = true;
            try {
                const res = await apiPut('/api/alertas/config', { config: payload });
                if (!res.ok) {
                    this.mostrarToast(res.error?.mensaje || 'No se pudo guardar.', 'error');
                    return;
                }
                const cfg = res.data?.config || {};
                for (const c of this.campos) {
                    this.form[c.clave] = String(cfg[c.clave] ?? c.default);
                }
                this.original = JSON.parse(JSON.stringify(this.form));
                this.mostrarToast('Configuración guardada. Recalculando alertas...');
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.guardando = false;
            }
        },

        async recalcularAhora() {
            if (this.recalculando) return;
            this.recalculando = true;
            try {
                const res = await apiPost('/api/alertas/recalcular', {});
                if (!res.ok) {
                    this.mostrarToast(res.error?.mensaje || 'No se pudo recalcular.', 'error');
                    return;
                }
                this.mostrarToast('Alertas recalculadas.');
            } catch (e) {
                this.mostrarToast('Error de red.', 'error');
            } finally {
                this.recalculando = false;
            }
        },

        mostrarToast(mensaje, tipo = 'ok') {
            this.toast = { visible: true, mensaje, tipo };
            setTimeout(() => { this.toast.visible = false; }, 2500);
        },
    };
}
</script>
