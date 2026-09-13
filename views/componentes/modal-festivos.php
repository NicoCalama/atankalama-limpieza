<?php
/**
 * Modal de gestión de festivos (informativo: se muestran resaltados en el
 * calendario de asignación de turnos, no bloquean nada).
 * Se abre con: window.dispatchEvent(new CustomEvent('abrir-modal-festivos'))
 * Al agregar/eliminar emite 'festivos-actualizados' para que la vista padre
 * refresque los festivos de la semana visible.
 */
?>

<div x-data="modalFestivosApp()"
     @abrir-modal-festivos.window="abrir()"
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
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Festivos</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Solo informativo: se resaltan en el calendario.</p>
            </div>
            <button type="button" @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2 flex-shrink-0" aria-label="Cerrar">
                <i data-lucide="x" class="w-5 h-5 text-gray-500 dark:text-gray-400"></i>
            </button>
        </header>

        <div class="p-4 space-y-4">
            <div class="grid grid-cols-[auto_1fr_auto] gap-2 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha</label>
                    <input type="date" x-model="form.fecha"
                           class="min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre</label>
                    <input type="text" x-model="form.nombre" maxlength="100" placeholder="ej. Fiestas Patrias"
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="button" @click="agregar()" :disabled="!puedeAgregar() || guardando"
                        class="min-h-[44px] px-3 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition"
                        aria-label="Agregar festivo">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                </button>
            </div>

            <div x-show="error" x-cloak
                 class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 text-sm rounded-lg px-3 py-2"
                 x-text="error"></div>

            <div x-show="cargando" x-cloak class="flex items-center justify-center py-6">
                <svg class="animate-spin h-5 w-5 text-blue-600" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                    <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
                </svg>
            </div>

            <div x-show="!cargando && festivos.length === 0" x-cloak class="text-center py-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay festivos registrados.</p>
            </div>

            <ul x-show="!cargando && festivos.length > 0" x-cloak class="space-y-1.5 max-h-64 overflow-y-auto">
                <template x-for="f in festivos" :key="f.id">
                    <li class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="f.nombre"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="fechaLarga(f.fecha)"></p>
                        </div>
                        <button type="button" @click="eliminar(f)"
                                class="min-h-[36px] min-w-[36px] flex-shrink-0 flex items-center justify-center rounded-lg hover:bg-rose-50 dark:hover:bg-rose-900/20"
                                aria-label="Eliminar festivo">
                            <i data-lucide="trash-2" class="w-4 h-4 text-rose-500"></i>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        <footer class="sticky bottom-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center justify-end">
            <button type="button" @click="cerrar()"
                    class="min-h-[44px] px-4 py-2 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium transition">
                Cerrar
            </button>
        </footer>
    </div>
</div>

<script>
function modalFestivosApp() {
    return {
        abierto: false,
        cargando: false,
        guardando: false,
        festivos: [],
        form: { fecha: '', nombre: '' },
        error: '',
        cambiosPendientes: false,

        abrir() {
            this.form = { fecha: '', nombre: '' };
            this.error = '';
            this.cambiosPendientes = false;
            this.abierto = true;
            this.cargarFestivos();
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
            if (this.cambiosPendientes) {
                window.dispatchEvent(new CustomEvent('festivos-actualizados'));
            }
        },

        async cargarFestivos() {
            this.cargando = true;
            try {
                const hoy = new Date();
                const desde = this.sumarDias(hoy, -365);
                const hasta = this.sumarDias(hoy, 365);
                const res = await apiFetch('/api/festivos?desde=' + desde + '&hasta=' + hasta);
                if (!res.ok) { this.error = res.error?.mensaje || 'No se pudieron cargar los festivos.'; return; }
                this.festivos = res.data?.festivos || [];
            } catch (e) {
                this.error = 'Error de red.';
            } finally {
                this.cargando = false;
                this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
            }
        },

        puedeAgregar() {
            return this.form.fecha !== '' && this.form.nombre.trim().length > 0;
        },

        async agregar() {
            if (!this.puedeAgregar() || this.guardando) return;
            this.error = '';
            this.guardando = true;
            try {
                const res = await apiPost('/api/festivos', { fecha: this.form.fecha, nombre: this.form.nombre.trim() });
                if (!res.ok) { this.error = res.error?.mensaje || 'No se pudo agregar el festivo.'; return; }
                this.form = { fecha: '', nombre: '' };
                this.cambiosPendientes = true;
                await this.cargarFestivos();
            } catch (e) {
                this.error = 'Error de red.';
            } finally {
                this.guardando = false;
            }
        },

        async eliminar(f) {
            this.error = '';
            try {
                const res = await apiFetch('/api/festivos/' + f.id, { method: 'DELETE' });
                if (!res.ok) { this.error = res.error?.mensaje || 'No se pudo eliminar.'; return; }
                this.cambiosPendientes = true;
                this.festivos = this.festivos.filter(x => x.id !== f.id);
            } catch (e) {
                this.error = 'Error de red.';
            }
        },

        sumarDias(fecha, dias) {
            const d = new Date(fecha);
            d.setDate(d.getDate() + dias);
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const da = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${da}`;
        },

        fechaLarga(fecha) {
            const d = new Date(fecha + 'T12:00:00');
            const dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            return `${dias[d.getDay()]} ${d.getDate()} de ${meses[d.getMonth()]} ${d.getFullYear()}`;
        },
    };
}
</script>
