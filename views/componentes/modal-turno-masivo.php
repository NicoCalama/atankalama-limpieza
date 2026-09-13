<?php
/**
 * Modal de asignación masiva de turno a un trabajador, desde hoy hasta una fecha
 * elegida con calendario, excluyendo los días de la semana que correspondan a su descanso.
 * Se abre con: window.dispatchEvent(new CustomEvent('abrir-modal-turno-masivo',
 *   {detail: {usuarioId, usuarioNombre, catalogoActivos: [...]}}))
 * Al guardar emite 'turno-masivo-guardado' con {detail: {cantidadAsignada, cantidadExcluida}}.
 */
?>

<div x-data="modalTurnoMasivoApp()"
     @abrir-modal-turno-masivo.window="abrir($event.detail)"
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
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Asignar turno en bloque</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="usuarioNombre"></p>
            </div>
            <button type="button" @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center -mr-2 flex-shrink-0" aria-label="Cerrar">
                <i data-lucide="x" class="w-5 h-5 text-gray-500 dark:text-gray-400"></i>
            </button>
        </header>

        <div class="p-4 space-y-4">
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Turno</label>
                <div class="space-y-2">
                    <template x-for="t in catalogoActivos" :key="t.id">
                        <button type="button" @click="form.turno_id = t.id"
                                :class="form.turno_id === t.id
                                    ? 'bg-blue-50 dark:bg-blue-900/30 border-blue-400 ring-2 ring-blue-400'
                                    : 'bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                class="w-full min-h-[48px] flex items-center justify-between px-4 py-2 rounded-lg border text-sm transition">
                            <span class="text-gray-900 dark:text-gray-100 font-medium" x-text="t.nombre"></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="t.hora_inicio"></span>–<span x-text="t.hora_fin"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Desde</label>
                    <input type="text" :value="fechaLarga(desde)" disabled
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-500 dark:text-gray-400 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Hasta</label>
                    <input type="date" x-model="form.hasta" :min="desde"
                           class="w-full min-h-[44px] px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Días de descanso a excluir</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="d in diasSemana" :key="d.valor">
                        <button type="button" @click="alternarDiaExcluido(d.valor)"
                                :class="form.dias_excluidos.includes(d.valor)
                                    ? 'bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border-rose-300 dark:border-rose-700'
                                    : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                class="min-h-[36px] min-w-[44px] px-2 py-1 text-xs font-medium rounded-lg border transition"
                                x-text="d.label"></button>
                    </template>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">Los días marcados no reciben este turno dentro del rango.</p>
            </div>

            <div x-show="resumenPreview" x-cloak
                 class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 text-xs rounded-lg px-3 py-2"
                 x-text="resumenPreview"></div>

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
                <span x-text="guardando ? 'Guardando...' : 'Asignar'"></span>
            </button>
        </footer>
    </div>
</div>

<script>
function modalTurnoMasivoApp() {
    return {
        abierto: false,
        usuarioId: null,
        usuarioNombre: '',
        catalogoActivos: [],
        desde: '',
        diasSemana: [
            { valor: 1, label: 'Lun' },
            { valor: 2, label: 'Mar' },
            { valor: 3, label: 'Mié' },
            { valor: 4, label: 'Jue' },
            { valor: 5, label: 'Vie' },
            { valor: 6, label: 'Sáb' },
            { valor: 0, label: 'Dom' },
        ],
        form: { turno_id: null, hasta: '', dias_excluidos: [] },
        guardando: false,
        error: '',

        abrir(detalle) {
            this.usuarioId = detalle?.usuarioId ?? null;
            this.usuarioNombre = detalle?.usuarioNombre ?? '';
            this.catalogoActivos = detalle?.catalogoActivos ?? [];
            this.desde = this.hoyYmd();
            this.form = { turno_id: null, hasta: this.desde, dias_excluidos: [] };
            this.error = '';
            this.abierto = true;
            this.$nextTick(() => { if (window.lucide) lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
        },

        alternarDiaExcluido(valor) {
            const i = this.form.dias_excluidos.indexOf(valor);
            if (i === -1) {
                this.form.dias_excluidos.push(valor);
            } else {
                this.form.dias_excluidos.splice(i, 1);
            }
        },

        get resumenPreview() {
            if (!this.form.hasta || this.form.hasta < this.desde) return '';
            const total = this.diferenciaDias(this.desde, this.form.hasta) + 1;
            return `Rango de ${total} día${total === 1 ? '' : 's'}.`;
        },

        puedeGuardar() {
            return this.form.turno_id !== null
                && this.form.hasta !== ''
                && this.form.hasta >= this.desde;
        },

        async guardar() {
            if (!this.puedeGuardar() || this.guardando) return;
            this.error = '';
            this.guardando = true;
            try {
                const res = await apiPost('/api/usuarios/' + this.usuarioId + '/turno/rango', {
                    turno_id: this.form.turno_id,
                    desde: this.desde,
                    hasta: this.form.hasta,
                    dias_excluidos: this.form.dias_excluidos,
                });
                if (!res.ok) {
                    this.error = res.error?.mensaje || 'No se pudo asignar el turno.';
                    return;
                }
                const cantidadAsignada = res.data?.fechas_asignadas?.length ?? 0;
                const cantidadExcluida = res.data?.fechas_excluidas?.length ?? 0;
                window.dispatchEvent(new CustomEvent('turno-masivo-guardado', { detail: { cantidadAsignada, cantidadExcluida } }));
                this.cerrar();
            } catch (e) {
                this.error = 'Error de red.';
            } finally {
                this.guardando = false;
            }
        },

        hoyYmd() {
            const d = new Date();
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const da = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${da}`;
        },

        diferenciaDias(desde, hasta) {
            const a = new Date(desde + 'T12:00:00');
            const b = new Date(hasta + 'T12:00:00');
            return Math.round((b - a) / 86400000);
        },

        fechaLarga(fecha) {
            if (!fecha) return '';
            const d = new Date(fecha + 'T12:00:00');
            const dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
            const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            return `${dias[d.getDay()]} ${d.getDate()} de ${meses[d.getMonth()]}`;
        },
    };
}
</script>
