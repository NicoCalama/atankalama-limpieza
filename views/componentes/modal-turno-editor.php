<?php
/**
 * Modal crear/editar turno del catálogo.
 * Se abre con: window.dispatchEvent(new CustomEvent('abrir-modal-turno-editor', {detail:{turno: null|{...}}}))
 * Al guardar emite 'turno-guardado' para que la página refresque.
 */
?>

<div x-data="modalTurnoEditorApp()"
     @abrir-modal-turno-editor.window="abrir($event.detail?.turno || null)"
     @keydown.escape.window="cerrar()"
     x-show="abierto" x-cloak
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4"
     x-transition.opacity>

    <div @click.away="cerrar()"
         class="w-full sm:max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-xl shadow-xl max-h-[90vh] overflow-y-auto"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-0 sm:opacity-0"
         x-transition:enter-end="translate-y-0 sm:opacity-100">

        <header class="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100" x-text="modo === 'crear' ? 'Nuevo turno' : 'Editar turno'"></h2>
            <button type="button" @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2" aria-label="Cerrar">
                <i data-lucide="x" class="w-5 h-5 text-gray-500 dark:text-gray-400"></i>
            </button>
        </header>

        <div class="p-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre</label>
                <input type="text" x-model="form.nombre" maxlength="50" placeholder="ej. mañana, tarde, noche"
                       class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Hora inicio</label>
                    <input type="time" x-model="form.hora_inicio"
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Hora fin</label>
                    <input type="time" x-model="form.hora_fin"
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <div x-show="modo === 'editar'" x-cloak>
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" x-model="form.activo" class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Turno activo</span>
                </label>
            </div>

            <div x-show="error" x-cloak
                 class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 text-sm rounded-lg px-3 py-2"
                 x-text="error"></div>
        </div>

        <footer class="sticky bottom-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-end gap-2">
            <button type="button" @click="cerrar()"
                    class="min-h-[44px] px-4 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                Cancelar
            </button>
            <button type="button" @click="guardar()" :disabled="!puedeGuardar() || guardando"
                    class="min-h-[44px] inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition">
                <template x-if="guardando">
                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                    </svg>
                </template>
                <span x-text="guardando ? 'Guardando...' : 'Guardar'"></span>
            </button>
        </footer>
    </div>
</div>

<script>
function modalTurnoEditorApp() {
    return {
        abierto: false,
        modo: 'crear',
        turnoId: null,
        form: { nombre: '', hora_inicio: '08:00', hora_fin: '16:00', activo: true },
        guardando: false,
        error: '',

        abrir(turno) {
            if (turno) {
                this.modo = 'editar';
                this.turnoId = turno.id;
                this.form = {
                    nombre: turno.nombre,
                    hora_inicio: turno.hora_inicio,
                    hora_fin: turno.hora_fin,
                    activo: !!turno.activo,
                };
            } else {
                this.modo = 'crear';
                this.turnoId = null;
                this.form = { nombre: '', hora_inicio: '08:00', hora_fin: '16:00', activo: true };
            }
            this.error = '';
            this.abierto = true;
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        puedeGuardar() {
            return this.form.nombre.trim().length > 0
                && /^([01]\d|2[0-3]):[0-5]\d$/.test(this.form.hora_inicio)
                && /^([01]\d|2[0-3]):[0-5]\d$/.test(this.form.hora_fin);
        },

        async guardar() {
            if (!this.puedeGuardar() || this.guardando) return;
            this.error = '';
            this.guardando = true;
            try {
                const payload = {
                    nombre: this.form.nombre.trim(),
                    hora_inicio: this.form.hora_inicio,
                    hora_fin: this.form.hora_fin,
                };
                let res;
                if (this.modo === 'crear') {
                    res = await apiPost('/api/turnos', payload);
                } else {
                    payload.activo = this.form.activo;
                    res = await apiPut('/api/turnos/' + this.turnoId, payload);
                }
                if (!res.ok) {
                    this.error = res.error?.mensaje || 'No se pudo guardar el turno.';
                    return;
                }
                window.dispatchEvent(new CustomEvent('turno-guardado'));
                this.cerrar();
            } catch (e) {
                this.error = 'Error de red.';
            } finally {
                this.guardando = false;
            }
        },
    };
}
</script>
