<?php
/**
 * Bandeja de auditoría: lista habitaciones pendientes.
 * Spec: docs/auditoria.md
 *
 * Requiere: auditoria.ver_bandeja (validado en PaginasController).
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="auditoriaBandejaApp()" x-init="cargar(); iniciarRefresco()"
     @visibilitychange.window="alVolverVisible()">

    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto">
            <div>
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Inspección</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="subtitulo()"></p>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                        aria-label="Refrescar">
                    <span :class="cargando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                    </span>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet.
    </div>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto">

        <div class="mb-4" data-tour="aud.hotel">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">Hotel</p>
            <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                <template x-for="(h, idx) in hotelOpciones" :key="h.valor">
                    <span class="inline-flex items-center">
                        <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                        <button @click="setHotel(h.valor)"
                                :class="hotel === h.valor
                                    ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                class="py-1 text-xs sm:text-sm transition">
                            <span x-text="h.etiqueta"></span>
                        </button>
                    </span>
                </template>
            </div>
        </div>

        <template x-if="pendientes.length > 0">
            <div class="relative mb-3 max-w-2xl mx-auto">
                <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                <input type="search" x-model="busqueda" placeholder="Buscar habitación..."
                       aria-label="Buscar habitación"
                       class="w-full pl-9 pr-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
            </div>
        </template>

        <template x-if="pendientes.length > 0 && pendientesFiltrados.length === 0">
            <div class="max-w-2xl mx-auto bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                <i data-lucide="search-x" class="w-10 h-10 text-gray-400 mx-auto mb-2"></i>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Sin resultados para "<span x-text="busqueda"></span>".</p>
                <button @click="busqueda = ''" class="min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                    Limpiar búsqueda
                </button>
            </div>
        </template>

        <template x-if="cargando && pendientes.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
                </div>
            </div>
        </template>

        <template x-if="error && pendientes.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center px-4">
                <div class="text-center max-w-xs">
                    <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error"></p>
                    <button @click="cargar()"
                            class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Reintentar
                    </button>
                </div>
            </div>
        </template>

        <template x-if="!cargando && !error && pendientes.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center px-4">
                <div class="text-center max-w-xs">
                    <i data-lucide="check-circle" class="w-12 h-12 text-green-500 mx-auto mb-3"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Todo al día</h2>
                    <p class="text-gray-600 dark:text-gray-400">No hay piezas pendientes de inspeccionar.</p>
                </div>
            </div>
        </template>

        <!-- Lista vertical (no grilla): el motor de arrastre calcula el punto de
             inserción por posición vertical, así que necesita una sola columna para
             que "antes/después" tenga sentido. Mismo mecanismo que el tablero de
             Asignaciones — acá no hay "trabajadores", así que se usa una única zona
             de drop con workerId fijo "0" (una sola cola, no varias). -->
        <template x-if="pendientes.length > 0">
            <div data-tour="aud.lista" data-drop="worker" data-worker-id="0" class="max-w-2xl mx-auto">
                <ul data-cola class="space-y-2">
                    <template x-for="hab in pendientes" :key="hab.id">
                        <li data-room-slot data-drag-room :data-room-id="hab.id" data-room-origin="worker" data-worker-id="0"
                            x-show="coincideBusqueda(hab)"
                            @pointerdown="iniciarDrag($event)"
                            class="bg-white dark:bg-gray-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-900 p-4 hover:border-indigo-500 dark:hover:border-indigo-500 transition shadow-sm flex items-center gap-3 cursor-grab">
                            <i data-lucide="grip-vertical" class="w-4 h-4 text-gray-400 dark:text-gray-600 flex-shrink-0"></i>
                            <a :href="u('/auditoria/' + hab.id + '?ejecucion=' + (hab.ejecucion_id || ''))"
                               class="flex-1 min-w-0 flex items-center gap-3">
                                <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 truncate" x-text="hab.tipo_nombre || ''"></p>
                                    <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                        <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium"
                                              x-text="hotelCorto(hab.hotel_codigo)"></span>
                                        <template x-if="hab.es_nochero">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200">Nochero</span>
                                        </template>
                                        <!-- Ocupación y código de camas: los mismos badges que ve el
                                             trabajador en su ficha (htmlBadge* en app.js), en vivo desde
                                             Cloudbeds. Sin clases hidden/sm:/md: — el equipo trabaja en el
                                             celular; si no caben, la fila (flex-wrap) los baja de línea.
                                             El badge de ocupación ya cubre «Se va hoy» (check-out). -->
                                        <template x-if="hab.cb_frontdesk_status && hab.cb_frontdesk_status !== 'unused'">
                                            <span x-html="htmlBadgeOcupacion(hab.cb_frontdesk_status)"></span>
                                        </template>
                                        <template x-if="hab.cloudbeds_room_name">
                                            <span x-html="htmlBadgeCodigoRoomName(hab.cloudbeds_room_name)"></span>
                                        </template>
                                    </div>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium chip-estado-completada_pendiente_auditoria flex-shrink-0">
                                    Por inspeccionar
                                </span>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
        </template>

    </main>
</div>

<script src="<?= u('/assets/js/drag-asignaciones.js') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/js/drag-asignaciones.js') ?: '1' ?>"></script>
<script>
function auditoriaBandejaApp() {
    return {
        // Motor compartido de arrastrar-y-soltar (assets/js/drag-asignaciones.js):
        // expone iniciarDrag(); acá solo hay UNA cola (workerId fijo "0"), así que
        // _dropEnWorker/_dropEnPool nunca se disparan de verdad (no hay zona 'pool'
        // ni otro 'worker' al cual soltar) — quedan como no-ops defensivos.
        ...(window.dragAsignaciones || {}),
        _dropEnWorker() {},
        _dropEnPool() {},
        async _reordenarEnWorker(workerId, roomId, indice) {
            var from = this.pendientes.findIndex(function (h) { return h.id === roomId; });
            if (from === -1) return;
            var to = indice > from ? indice - 1 : indice;
            if (to === from) return; // sin cambio
            var item = this.pendientes.splice(from, 1)[0];
            this.pendientes.splice(to, 0, item);
            this.$nextTick(function () { lucide.createIcons(); });
            var orden = this.pendientes.map(function (h) { return h.id; });
            try {
                var r = await apiPut('/api/auditoria/orden', { orden: orden });
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos reordenar.';
                    this.cargar();
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
                this.cargar();
            }
        },

        pendientes: [],
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('auditoria_hotel') || 'ambos',
        busqueda: '',
        _intervalId: null,

        // Filtro solo visual (x-show en cada <li>, ver el HTML): el x-for sigue recorriendo
        // el array completo "pendientes" sin tocarlo, porque el motor de arrastre calcula
        // índices sobre ese array — filtrarlo de verdad le rompería el reordenamiento.
        coincideBusqueda(hab) {
            var q = this.busqueda.trim().toLowerCase();
            if (!q) return true;
            return (hab.numero || '').toLowerCase().includes(q);
        },
        get pendientesFiltrados() {
            var self = this;
            return this.pendientes.filter(function (h) { return self.coincideBusqueda(h); });
        },

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama INN' }
        ],

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/auditoria/bandeja';
                if (this.hotel && this.hotel !== 'ambos') {
                    url += '?hotel=' + encodeURIComponent(this.hotel);
                }
                var json = await apiFetch(url);
                if (json && json.ok) {
                    this.pendientes = json.data.pendientes || [];
                } else {
                    this.error = (json && json.error && json.error.mensaje) || 'Error al cargar.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            var self = this;
            this._intervalId = setInterval(function () { self.cargar(); }, 300000);
            window.addEventListener('online', function () { self.sinConexion = false; self.cargar(); });
            window.addEventListener('offline', function () { self.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) this.cargar();
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('auditoria_hotel', valor);
            this.cargar();
        },

        subtitulo() {
            var total = this.pendientes.length;
            if (this.cargando && total === 0) return '';
            return total === 1 ? '1 pieza pendiente' : total + ' piezas pendientes';
        },

        hotelCorto(codigo) {
            if (codigo === '1_sur') return 'Atankalama';
            if (codigo === 'inn') return 'Atankalama INN';
            return codigo || '';
        }
    };
}
</script>
