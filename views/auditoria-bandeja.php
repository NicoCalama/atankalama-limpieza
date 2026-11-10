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
            <div class="flex items-center gap-2 mb-3 max-w-2xl mx-auto">
                <div class="relative flex-1 min-w-0">
                    <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    <input type="search" x-model="busqueda" placeholder="Buscar habitación..."
                           aria-label="Buscar habitación"
                           class="w-full pl-9 pr-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
                </div>
                <!-- v7.19: el orden es solo de esta pantalla y de este celular; no toca el orden manual. -->
                <div class="relative flex-shrink-0" @click.outside="menuOrden = false" @keydown.escape.window="menuOrden = false">
                    <button type="button" @click="menuOrden = !menuOrden" :aria-expanded="menuOrden ? 'true' : 'false'" aria-haspopup="true"
                            class="min-h-[44px] px-3 inline-flex items-center gap-1.5 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 rounded-lg text-sm font-medium"
                            :class="orden === 'prioridad' ? 'text-gray-700 dark:text-gray-200' : 'text-blue-600 dark:text-blue-400'">
                        <i data-lucide="arrow-up-down" class="w-4 h-4"></i>
                        <span x-text="etiquetaOrden()"></span>
                    </button>
                    <div x-show="menuOrden" x-cloak
                         class="absolute right-0 z-30 mt-1 w-48 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1">
                        <template x-for="o in opcionesOrden" :key="o.valor">
                            <button type="button" @click="setOrden(o.valor)" :aria-current="orden === o.valor ? 'true' : 'false'"
                                    class="w-full min-h-[44px] px-3 flex items-center justify-between gap-2 text-sm text-left hover:bg-gray-50 dark:hover:bg-gray-700"
                                    :class="orden === o.valor ? 'text-blue-600 dark:text-blue-400 font-semibold' : 'text-gray-700 dark:text-gray-200'">
                                <span x-text="o.etiqueta"></span>
                                <span x-show="orden === o.valor" aria-hidden="true">✓</span>
                            </button>
                        </template>
                    </div>
                </div>
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
            <div data-tour="aud.lista" data-drop="worker" data-worker-id="0" class="max-w-2xl mx-auto space-y-2">
                <!-- v7.19.1: «Por piso» muestra un grupo por piso, como carpetas que se abren al tocarlas.
                     En los otros órdenes hay un solo grupo sin título: la lista de siempre, con arrastre. -->
                <template x-for="grupo in gruposVisibles" :key="grupo.clave">
                    <div x-show="piezasQueCoinciden(grupo) > 0">
                        <template x-if="grupo.titulo">
                            <button type="button" @click="alternarCarpeta(grupo.clave)"
                                    :aria-expanded="carpetaAbierta(grupo) ? 'true' : 'false'"
                                    class="w-full min-h-[48px] px-4 flex items-center gap-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition text-left">
                                <span class="inline-flex flex-shrink-0 transition-transform" :class="carpetaAbierta(grupo) ? 'rotate-90' : ''">
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                                </span>
                                <span class="flex-1 min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="grupo.titulo"></span>
                                <span class="flex-shrink-0 min-w-[1.75rem] px-2 py-0.5 rounded-full text-center text-xs font-semibold bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200"
                                      x-text="piezasQueCoinciden(grupo)"></span>
                            </button>
                        </template>
                        <ul data-cola x-show="carpetaAbierta(grupo)" class="space-y-2" :class="grupo.titulo ? 'mt-2 pl-2' : ''">
                            <template x-for="hab in grupo.piezas" :key="hab.id">
                                <li data-room-slot data-drag-room :data-room-id="hab.id" data-room-origin="worker" data-worker-id="0"
                                    x-show="coincideBusqueda(hab)"
                                    @pointerdown="orden === 'prioridad' && iniciarDrag($event)"
                                    :style="orden === 'prioridad' ? '' : 'cursor: default'"
                                    class="bg-white dark:bg-gray-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-900 p-4 hover:border-indigo-500 dark:hover:border-indigo-500 transition shadow-sm flex items-center gap-3">
                                    <span x-show="orden === 'prioridad'" class="flex-shrink-0 inline-flex">
                                        <i data-lucide="grip-vertical" class="w-4 h-4 text-gray-400 dark:text-gray-600"></i>
                                    </span>
                                    <a :href="u('/auditoria/' + hab.id + '?ejecucion=' + (hab.ejecucion_id || ''))"
                                       class="flex-1 min-w-0 flex items-center gap-3">
                                        <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-600 dark:text-gray-400 truncate" x-text="hab.tipo_nombre || ''"></p>
                                            <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium"
                                                      x-text="hotelCorto(hab.hotel_codigo)"></span>
                                                <!-- v7.23: chapa Día, Noche o Día/Noche (antes solo «Nochero», que hoy es Día/Noche).
                                                     Se ve siempre, también la de Día; las áreas comunes no llevan. -->
                                                <template x-if="hab.chapa === 'dia'">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200" title="Un aseo en la mañana">Día</span>
                                                </template>
                                                <template x-if="hab.chapa === 'dia_noche'">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200" title="Dos aseos: vuelve a sucia en la tarde">Día/Noche</span>
                                                </template>
                                                <template x-if="hab.chapa === 'noche'">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200" title="Un aseo, en la tarde">Noche</span>
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
                                                <!-- Personas al lado del código de camas («2S · 2 personas»): cuántas
                                                     hay o salieron hoy, y cuántas llegan hoy (Cloudbeds). Así la
                                                     supervisora sabe cuántas camas revisar. -->
                                                <template x-if="hab.cb_huespedes">
                                                    <span x-html="htmlBadgePersonas(hab.cb_huespedes)"></span>
                                                </template>
                                                <template x-if="hab.cb_huespedes_llegan">
                                                    <span x-html="htmlBadgeLlegan(hab.cb_huespedes_llegan)"></span>
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

        // v7.19 (reunión del 08/11/2026): orden elegido por cada celular. «Prioridad» es el orden de
        // siempre (el del servidor, con el arrastre); los demás solo reordenan lo que se ve.
        orden: ['numero', 'piso'].includes(localStorage.getItem('auditoria_orden_vista')) ? localStorage.getItem('auditoria_orden_vista') : 'prioridad',
        menuOrden: false,
        opcionesOrden: [
            { valor: 'prioridad', etiqueta: 'Prioridad' },
            { valor: 'numero', etiqueta: 'Número ascendente' },
            { valor: 'piso', etiqueta: 'Por piso' }
        ],

        etiquetaOrden() {
            if (this.orden === 'numero') return 'Número';
            if (this.orden === 'piso') return 'Piso';
            return 'Ordenar';
        },

        setOrden(valor) {
            this.orden = valor;
            this.menuOrden = false;
            localStorage.setItem('auditoria_orden_vista', valor);
            this.$nextTick(function () { lucide.createIcons(); });
        },

        // En Prioridad es el mismo array del arrastre. En los otros órdenes es una copia, así que el
        // orden manual sigue intacto al volver. Primero un hotel y después el otro; áreas comunes al
        // final; numeric hace que la 201 vaya antes que la 1001.
        get listaVisible() {
            if (this.orden === 'prioridad') return this.pendientes;
            var porPiso = this.orden === 'piso';
            var pisoDe = function (h) { return h.piso === null || h.piso === undefined ? Number.MAX_SAFE_INTEGER : h.piso; };
            return this.pendientes.slice().sort(function (a, b) {
                return String(a.hotel_codigo || '').localeCompare(String(b.hotel_codigo || ''))
                    || (a.es_espacio_comun ? 1 : 0) - (b.es_espacio_comun ? 1 : 0)
                    || (porPiso ? pisoDe(a) - pisoDe(b) : 0)
                    || String(a.numero || '').localeCompare(String(b.numero || ''), 'es', { numeric: true, sensitivity: 'base' });
            });
        },

        // v7.19.1 (pedido de Nicolás, 09/11/2026): en «Por piso», cada piso de cada hotel es una carpeta.
        // Cada celular recuerda cuáles dejó abiertas, para que no se cierren al volver de inspeccionar.
        carpetasAbiertas: (function () {
            try {
                var guardadas = JSON.parse(localStorage.getItem('auditoria_carpetas_abiertas'));
                return Array.isArray(guardadas) ? guardadas : [];
            } catch (e) {
                return [];
            }
        })(),

        get gruposVisibles() {
            if (this.orden !== 'piso') return [{ clave: 'lista', titulo: null, piezas: this.listaVisible }];
            var self = this;
            var grupos = [];
            var porClave = {};
            this.listaVisible.forEach(function (h) {
                var sinPiso = h.piso === null || h.piso === undefined;
                var lugar = h.es_espacio_comun ? 'areas' : (sinPiso ? 'sin_piso' : String(h.piso));
                var clave = h.hotel_codigo + ':' + lugar;
                if (!porClave[clave]) {
                    var nombre = lugar === 'areas' ? 'Áreas comunes' : (lugar === 'sin_piso' ? 'Sin piso' : 'Piso ' + lugar);
                    porClave[clave] = { clave: clave, titulo: nombre + ' · ' + self.hotelCorto(h.hotel_codigo), piezas: [] };
                    grupos.push(porClave[clave]);
                }
                porClave[clave].piezas.push(h);
            });
            return grupos;
        },

        // Al buscar se abren solas las carpetas con coincidencias; las demás se ocultan.
        carpetaAbierta(grupo) {
            return grupo.titulo === null || this.busqueda.trim() !== '' || this.carpetasAbiertas.includes(grupo.clave);
        },

        alternarCarpeta(clave) {
            var i = this.carpetasAbiertas.indexOf(clave);
            if (i === -1) {
                this.carpetasAbiertas.push(clave);
            } else {
                this.carpetasAbiertas.splice(i, 1);
            }
            try {
                localStorage.setItem('auditoria_carpetas_abiertas', JSON.stringify(this.carpetasAbiertas));
            } catch (e) {
                // Sin almacenamiento: la carpeta se abre igual, solo que no se recuerda.
            }
        },

        piezasQueCoinciden(grupo) {
            var self = this;
            return grupo.piezas.filter(function (h) { return self.coincideBusqueda(h); }).length;
        },

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
