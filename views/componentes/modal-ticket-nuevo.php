<?php
/**
 * Modal reutilizable "Nuevo ticket".
 * Incluir en layout.php (si el usuario tiene permiso tickets.crear).
 *
 * Para abrirlo desde cualquier parte, dispatch del evento:
 *   this.$dispatch('abrir-modal-ticket', { habitacionId: 42, hotelCodigo: '1_sur' })
 *
 * Al crearse exitosamente, emite 'ticket-creado' con el ticket como detail.
 * La página consumidora puede escuchar para refrescar listas.
 */
?>
<div x-data="modalTicketNuevo()"
     @abrir-modal-ticket.window="abrir($event.detail || {})">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportar problema</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Cuéntanos qué pasa y lo revisaremos.</p>
                </div>
                <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <form @submit.prevent="crear()" class="space-y-3">
                <!-- Hotel (requerido) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Hotel *</label>
                    <select x-model.number="form.hotel_id"
                            :disabled="form.habitacion_id !== null"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60">
                        <option :value="null">Selecciona un hotel</option>
                        <template x-for="h in hoteles" :key="h.id">
                            <option :value="h.id" x-text="h.nombre"></option>
                        </template>
                    </select>
                </div>

                <!-- Habitación (opcional) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Habitación (opcional)</label>
                    <template x-if="form.habitacion_id !== null">
                        <div class="flex items-center gap-2 px-3 py-2 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-sm">
                            <i data-lucide="bed" class="w-4 h-4"></i>
                            <span x-text="'Habitación ' + (habitacionSeleccionadaNumero || form.habitacion_id)"></span>
                            <button type="button" @click="quitarHabitacion()" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">Cambiar</button>
                        </div>
                    </template>
                    <template x-if="form.habitacion_id === null">
                        <input type="text"
                               x-model="busquedaHabitacion"
                               @focus="abrirBuscador = true"
                               @input="abrirBuscador = true"
                               placeholder="Buscar por número..."
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                    </template>
                    <template x-if="form.habitacion_id === null && abrirBuscador && habitacionesFiltradas().length > 0">
                        <ul class="mt-1 max-h-40 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="hab in habitacionesFiltradas().slice(0, 10)" :key="hab.id">
                                <li>
                                    <button type="button" @click="seleccionarHabitacion(hab)"
                                            class="w-full text-left px-3 py-2 text-sm text-gray-900 dark:text-gray-100 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                        <span class="font-semibold" x-text="hab.numero"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 ml-2" x-text="nombreHotelCorto(hab.hotel_codigo)"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Descripción del problema *</label>
                    <textarea x-model="form.descripcion" rows="4" maxlength="500" required
                              placeholder="Cuéntanos qué pasa..."
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                </div>

                <!-- Error inline -->
                <template x-if="error">
                    <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                </template>

                <!-- Acciones -->
                <div class="flex gap-2 pt-2">
                    <button type="button" @click="cerrar()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="enviando || !formValido()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                        <template x-if="enviando">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="enviando ? 'Enviando...' : 'Crear ticket'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function modalTicketNuevo() {
    return {
        abierto: false,
        enviando: false,
        error: null,
        hoteles: [],
        habitaciones: [],
        _datosCargados: false,
        busquedaHabitacion: '',
        abrirBuscador: false,
        habitacionSeleccionadaNumero: null,
        form: {
            hotel_id: null,
            habitacion_id: null,
            descripcion: '',
        },

        async abrir(detail) {
            this.reset();
            this.abierto = true;
            await this.asegurarDatos();
            if (detail && detail.habitacionId) {
                var hab = this.habitaciones.find(h => h.id === detail.habitacionId);
                if (hab) this.seleccionarHabitacion(hab);
                else this.form.habitacion_id = detail.habitacionId;
            }
            if (detail && detail.hotelCodigo) {
                var h = this.hoteles.find(x => x.codigo === detail.hotelCodigo);
                if (h) this.form.hotel_id = h.id;
            }
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrar() {
            this.abierto = false;
            this.abrirBuscador = false;
        },

        reset() {
            this.form = { hotel_id: null, habitacion_id: null, descripcion: '' };
            this.error = null;
            this.enviando = false;
            this.busquedaHabitacion = '';
            this.abrirBuscador = false;
            this.habitacionSeleccionadaNumero = null;
        },

        async asegurarDatos() {
            if (this._datosCargados) return;
            try {
                var rh = await apiFetch('/api/hoteles');
                if (rh && rh.ok) this.hoteles = rh.data.hoteles || [];
                var rhab = await apiFetch('/api/habitaciones');
                if (rhab && rhab.ok) this.habitaciones = rhab.data.habitaciones || [];
                this._datosCargados = true;
            } catch (e) {
                // Sin datos no bloqueamos: user puede elegir hotel manualmente si se cargó
            }
        },

        habitacionesFiltradas() {
            var q = (this.busquedaHabitacion || '').trim().toLowerCase();
            if (!q) return this.habitaciones;
            return this.habitaciones.filter(function (h) {
                return (h.numero || '').toString().toLowerCase().includes(q);
            });
        },

        seleccionarHabitacion(hab) {
            this.form.habitacion_id = hab.id;
            this.form.hotel_id = hab.hotel_id;
            this.habitacionSeleccionadaNumero = hab.numero;
            this.abrirBuscador = false;
            this.busquedaHabitacion = '';
        },

        quitarHabitacion() {
            this.form.habitacion_id = null;
            this.habitacionSeleccionadaNumero = null;
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Inn';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        formValido() {
            return this.form.hotel_id !== null
                && (this.form.descripcion || '').trim().length > 0;
        },

        async crear() {
            if (!this.formValido() || this.enviando) return;
            this.enviando = true;
            this.error = null;
            try {
                var descripcion = (this.form.descripcion || '').trim();
                // Título auto-generado desde la descripción (primeros 80 chars, sin saltos de línea)
                var titulo = descripcion.replace(/\s+/g, ' ').slice(0, 80);
                var payload = {
                    hotel_id: this.form.hotel_id,
                    titulo: titulo,
                    descripcion: descripcion,
                    prioridad: 'normal',
                };
                if (this.form.habitacion_id !== null) payload.habitacion_id = this.form.habitacion_id;
                var r = await apiPost('/api/tickets', payload);
                if (r && r.ok) {
                    this.$dispatch('ticket-creado', r.data.ticket);
                    this.cerrar();
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos crear el ticket.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.enviando = false;
            }
        }
    };
}
</script>
