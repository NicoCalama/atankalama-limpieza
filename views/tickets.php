<?php
/**
 * Página de Tickets de mantenimiento.
 * Spec: docs/tickets.md
 *
 * Listado con filtros (hotel, estado, prioridad), detalle en modal.
 * Acciones según permisos: tomar/asignar/marcar resuelto/cerrar/reabrir.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';
?>

<div x-data="ticketsApp()"
     x-init="cargar(); iniciarRefresco()"
     @ticket-creado.window="onTicketCreado($event.detail)"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <i data-lucide="wrench" class="w-6 h-6 text-rose-600 dark:text-rose-400 flex-shrink-0"></i>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">Tickets</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="total"></span> en total
                        <template x-if="!puedeVerTodos">
                            <span> · Tus reportes</span>
                        </template>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <template x-if="puedeCrear">
                    <button @click="$dispatch('abrir-modal-ticket', {})"
                            class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span class="hidden sm:inline">Nuevo</span>
                    </button>
                </template>
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Refrescar">
                    <i data-lucide="rotate-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400" :class="cargando ? 'animate-spin' : ''"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Los datos se actualizarán cuando vuelva.
    </div>

    <!-- Toast -->
    <div x-show="toast.visible" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg shadow-lg text-white text-sm font-medium max-w-sm w-[90%] text-center"
         :class="toast.tipo === 'exito' ? 'bg-green-600' : 'bg-red-600'"
         x-text="toast.mensaje"></div>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-4">

        <!-- Filtros -->
        <section class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
            <div class="flex flex-wrap gap-2 items-center">
                <div class="flex items-center gap-1 flex-wrap">
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Estado:</span>
                    <template x-for="e in estadosFiltro" :key="e.valor">
                        <button @click="setEstado(e.valor)"
                                :class="estado === e.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                class="min-h-[36px] px-3 py-1 text-xs font-medium rounded-lg border transition"
                                x-text="e.etiqueta"></button>
                    </template>
                </div>
                <template x-if="puedeVerTodos">
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1 ml-2">Hotel:</span>
                        <template x-for="h in hotelesFiltro" :key="h.valor">
                            <button @click="setHotel(h.valor)"
                                    :class="hotel === h.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                    class="min-h-[36px] px-3 py-1 text-xs font-medium rounded-lg border transition"
                                    x-text="h.etiqueta"></button>
                        </template>
                    </div>
                </template>
            </div>
        </section>

        <!-- Carga inicial -->
        <template x-if="cargando && tickets.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-gray-600 dark:text-gray-400">Cargando tickets...</p>
                </div>
            </div>
        </template>

        <!-- Vacío -->
        <template x-if="!cargando && tickets.length === 0">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                <i data-lucide="wrench" class="w-12 h-12 text-gray-400 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">Sin tickets</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <template x-if="estado === ''">
                        <span>Todavía no hay tickets con estos filtros.</span>
                    </template>
                    <template x-if="estado !== ''">
                        <span>No hay tickets en este estado.</span>
                    </template>
                </p>
                <template x-if="puedeCrear">
                    <button @click="$dispatch('abrir-modal-ticket', {})"
                            class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Reportar problema
                    </button>
                </template>
            </div>
        </template>

        <!-- Listado -->
        <template x-if="tickets.length > 0">
            <ul class="space-y-2">
                <template x-for="t in tickets" :key="t.id">
                    <li class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 cursor-pointer hover:border-blue-300 dark:hover:border-blue-700 transition"
                        :class="claseBordePrioridad(t.prioridad)"
                        @click="abrirDetalle(t)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0"
                                          :class="claseBadgePrioridad(t.prioridad)"
                                          x-text="etiquetaPrioridad(t.prioridad)"></span>
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0"
                                          :class="claseBadgeEstado(t.estado)"
                                          x-text="etiquetaEstado(t.estado)"></span>
                                    <template x-if="t.habitacion_numero">
                                        <span class="text-xs text-gray-500 dark:text-gray-400 inline-flex items-center gap-1">
                                            <i data-lucide="bed" class="w-3 h-3"></i>
                                            <span x-text="t.habitacion_numero"></span>
                                        </span>
                                    </template>
                                    <span class="text-xs text-gray-500 dark:text-gray-400" x-text="nombreHotelCorto(t.hotel_codigo)"></span>
                                </div>
                                <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="t.titulo"></p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-2 mt-0.5" x-text="t.descripcion"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                    <span x-text="'Por ' + (t.levantado_por_nombre || 'usuario')"></span>
                                    · <span x-text="fechaRelativa(t.created_at)"></span>
                                </p>
                            </div>
                            <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 flex-shrink-0 mt-1"></i>
                        </div>
                    </li>
                </template>
            </ul>
        </template>
    </main>

    <!-- Modal detalle -->
    <div x-show="detalle.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarDetalle()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto"
             x-show="detalle.ticket">
            <template x-if="detalle.ticket">
                <div>
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                      :class="claseBadgePrioridad(detalle.ticket.prioridad)"
                                      x-text="etiquetaPrioridad(detalle.ticket.prioridad)"></span>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                      :class="claseBadgeEstado(detalle.ticket.estado)"
                                      x-text="etiquetaEstado(detalle.ticket.estado)"></span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="detalle.ticket.titulo"></h3>
                        </div>
                        <button @click="cerrarDetalle()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div>
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Descripción</p>
                            <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap" x-text="detalle.ticket.descripcion"></p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Reportado por</p>
                                <p class="text-gray-900 dark:text-gray-100" x-text="detalle.ticket.levantado_por_nombre || '—'"></p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Fecha</p>
                                <p class="text-gray-900 dark:text-gray-100" x-text="fechaCorta(detalle.ticket.created_at)"></p>
                            </div>
                            <template x-if="detalle.ticket.habitacion_numero">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Habitación</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="detalle.ticket.habitacion_numero + ' · ' + nombreHotelCorto(detalle.ticket.hotel_codigo)"></p>
                                </div>
                            </template>
                            <template x-if="detalle.ticket.asignado_a">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Asignado a</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="'#' + detalle.ticket.asignado_a"></p>
                                </div>
                            </template>
                            <template x-if="detalle.ticket.resuelto_at">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Resuelto</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="fechaCorta(detalle.ticket.resuelto_at)"></p>
                                </div>
                            </template>
                        </div>

                        <!-- Acciones -->
                        <template x-if="puedeGestionar && detalle.ticket.estado !== 'cerrado'">
                            <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">Acciones</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-if="detalle.ticket.estado === 'abierto'">
                                        <button @click="tomar()" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                            Tomar
                                        </button>
                                    </template>
                                    <template x-if="detalle.ticket.estado === 'abierto' || detalle.ticket.estado === 'en_progreso'">
                                        <button @click="cambiarEstado('resuelto')" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white transition">
                                            Marcar resuelto
                                        </button>
                                    </template>
                                    <template x-if="detalle.ticket.estado === 'resuelto'">
                                        <button @click="cambiarEstado('cerrado')" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-600 hover:bg-gray-700 disabled:opacity-50 text-white transition">
                                            Cerrar
                                        </button>
                                    </template>
                                    <template x-if="detalle.ticket.estado === 'resuelto' || detalle.ticket.estado === 'en_progreso'">
                                        <button @click="cambiarEstado('abierto')" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-amber-100 dark:bg-amber-900/30 hover:bg-amber-200 dark:hover:bg-amber-900/50 text-amber-800 dark:text-amber-300 transition">
                                            Reabrir
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="detalle.ticket.estado === 'cerrado'">
                            <p class="text-xs text-gray-500 dark:text-gray-400 italic pt-3 border-t border-gray-200 dark:border-gray-700">
                                Este ticket está cerrado y no puede modificarse.
                            </p>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function ticketsApp() {
    return {
        tickets: [],
        total: 0,
        cargando: false,
        sinConexion: !navigator.onLine,
        estado: localStorage.getItem('tickets_estado') || '',
        hotel: localStorage.getItem('tickets_hotel') || 'ambos',
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        detalle: { abierto: false, ticket: null, enviando: false },

        estadosFiltro: [
            { valor: '', etiqueta: 'Todos' },
            { valor: 'abierto', etiqueta: 'Abiertos' },
            { valor: 'en_progreso', etiqueta: 'En progreso' },
            { valor: 'resuelto', etiqueta: 'Resueltos' },
            { valor: 'cerrado', etiqueta: 'Cerrados' }
        ],

        hotelesFiltro: [
            { valor: 'ambos', etiqueta: 'Ambos' },
            { valor: '1_sur', etiqueta: '1 Sur' },
            { valor: 'inn', etiqueta: 'Inn' }
        ],

        get puedeCrear() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.crear'));
        },
        get puedeVerTodos() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.ver_todos'));
        },
        get puedeGestionar() {
            return this.puedeVerTodos;
        },

        async cargar() {
            this.cargando = true;
            try {
                var params = [];
                if (this.estado) params.push('estado=' + encodeURIComponent(this.estado));
                if (this.puedeVerTodos && this.hotel && this.hotel !== 'ambos') params.push('hotel=' + encodeURIComponent(this.hotel));
                var url = '/api/tickets' + (params.length ? '?' + params.join('&') : '');
                var r = await apiFetch(url);
                if (r && r.ok) {
                    this.tickets = r.data.tickets || [];
                    this.total = r.data.total || 0;
                }
            } catch (e) {
                // Silencioso — estado de error por toast si falla al actuar
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            var self = this;
            this._intervalId = setInterval(function () { self.cargar(); }, 60000);
            window.addEventListener('online', function () { self.sinConexion = false; self.cargar(); });
            window.addEventListener('offline', function () { self.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) this.cargar();
        },

        setEstado(valor) {
            this.estado = valor;
            localStorage.setItem('tickets_estado', valor);
            this.cargar();
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('tickets_hotel', valor);
            this.cargar();
        },

        onTicketCreado(ticket) {
            this.mostrarToast('exito', 'Ticket creado. Gracias por reportar.');
            this.cargar();
        },

        abrirDetalle(t) {
            this.detalle = { abierto: true, ticket: t, enviando: false };
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrarDetalle() {
            this.detalle = { abierto: false, ticket: null, enviando: false };
        },

        async tomar() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            var usuarioId = Alpine.store('auth') && Alpine.store('auth').usuario && Alpine.store('auth').usuario.id;
            if (!usuarioId) return;
            this.detalle.enviando = true;
            try {
                var rA = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/asignar', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario_id: usuarioId })
                });
                if (!rA || !rA.ok) {
                    this.mostrarToast('error', (rA && rA.error && rA.error.mensaje) || 'No pudimos tomar el ticket.');
                    return;
                }
                await this.cambiarEstado('en_progreso');
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        async cambiarEstado(nuevo) {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/estado', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ estado: nuevo })
                });
                if (r && r.ok) {
                    this.detalle.ticket = { ...this.detalle.ticket, ...r.data.ticket };
                    this.mostrarToast('exito', 'Ticket actualizado.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos actualizar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        // --- Helpers visuales ---

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Inn';
            if (codigo === '1_sur') return '1 Sur';
            return codigo || '';
        },

        etiquetaPrioridad(p) {
            if (p === 'urgente') return 'Urgente';
            if (p === 'alta') return 'Alta';
            if (p === 'normal') return 'Normal';
            return 'Baja';
        },

        etiquetaEstado(e) {
            if (e === 'abierto') return 'Abierto';
            if (e === 'en_progreso') return 'En progreso';
            if (e === 'resuelto') return 'Resuelto';
            return 'Cerrado';
        },

        claseBordePrioridad(p) {
            if (p === 'urgente') return 'border-l-4 border-l-red-600';
            if (p === 'alta') return 'border-l-4 border-l-amber-500';
            if (p === 'normal') return 'border-l-4 border-l-blue-500';
            return '';
        },

        claseBadgePrioridad(p) {
            if (p === 'urgente') return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            if (p === 'alta') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (p === 'normal') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
        },

        claseBadgeEstado(e) {
            if (e === 'abierto') return 'bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-300';
            if (e === 'en_progreso') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
            if (e === 'resuelto') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        fechaCorta(iso) {
            try {
                var d = new Date(iso);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var mi = String(d.getMinutes()).padStart(2, '0');
                return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi;
            } catch (e) { return iso; }
        },

        fechaRelativa(iso) {
            try {
                var d = new Date(iso);
                var diffMs = Date.now() - d.getTime();
                var diffMin = Math.floor(diffMs / 60000);
                if (diffMin < 1) return 'ahora';
                if (diffMin < 60) return 'hace ' + diffMin + ' min';
                var diffHr = Math.floor(diffMin / 60);
                if (diffHr < 24) return 'hace ' + diffHr + ' h';
                var diffD = Math.floor(diffHr / 24);
                if (diffD < 7) return 'hace ' + diffD + ' d';
                return this.fechaCorta(iso).slice(0, 10);
            } catch (e) { return ''; }
        }
    };
}
</script>
