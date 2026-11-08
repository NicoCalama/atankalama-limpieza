<?php
/**
 * «Cambio Tarea» (v7.16, pedido de gerencia del 06/11/2026; plan aprobado por Nicolás el 07/11/2026).
 * La supervisora anota cuántas horas estuvo HOY alguien de Aseo en otra área (Recepción, Lavandería…),
 * para que en la v7.18 se descuenten de sus horas en Aseo y no le bajen el bono. Solo el mismo día.
 * Spec: docs/cambio-tarea.md.
 *
 * Fuente de datos: GET /api/cambio-tarea?hotel=&fecha= · guardar: PUT /api/cambio-tarea/{usuario_id}
 * (el juego completo de áreas de esa persona, a cada cambio). Al que trabaja sin turno se le puede cargar
 * el turno acá mismo con POST /api/usuarios/{id}/turno, si quien mira tiene turnos.asignar_a_usuario.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */
?>

<div x-data="cambioTareaApp()" x-init="cargar()">

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center gap-3 max-w-3xl mx-auto">
            <a href="<?= u('/home') ?>" class="min-h-[44px] min-w-[44px] flex items-center justify-center -ml-2" aria-label="Volver al inicio">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Cambio Tarea</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="fechaTexto()"></p>
            </div>
            <button @click="cargar()" :disabled="cargando"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition disabled:opacity-50"
                    aria-label="Refrescar">
                <span class="inline-flex" :class="cargando ? 'animate-spin' : ''">
                    <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                </span>
            </button>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4 pb-24 md:pb-6 space-y-3">

        <p class="text-sm text-gray-600 dark:text-gray-400" data-tour="ct.intro">
            Anota cuántas horas estuvo alguien de Aseo en otra área, como Recepción o Lavandería. Se guarda solo
            y se puede anotar solo el mismo día.
        </p>

        <!-- Buscar por nombre: filtra la lista mientras se escribe -->
        <div x-show="!error" data-tour="ct.buscar">
            <label class="sr-only" for="ct-buscar">Buscar persona</label>
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                <input id="ct-buscar" x-model="busqueda" type="search" autocomplete="off"
                       @input="$nextTick(function () { lucide.createIcons(); })"
                       placeholder="Buscar por nombre..."
                       class="w-full pl-9 pr-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
            </div>
        </div>

        <!-- Hotel y, si está habilitado, moverse entre días -->
        <div class="flex flex-wrap items-center gap-2" data-tour="ct.filtros">
            <template x-for="op in hotelOpciones" :key="op.valor">
                <button type="button" @click="elegirHotel(op.valor)"
                        class="min-h-[44px] px-4 rounded-full text-sm font-medium border transition"
                        :class="hotel === op.valor
                            ? 'bg-blue-600 border-blue-600 text-white'
                            : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                        :aria-pressed="hotel === op.valor ? 'true' : 'false'"
                        x-text="op.etiqueta"></button>
            </template>
            <!-- Navegar días: programado, oculto mientras CAMBIO_TAREA_NAVEGAR_DIAS esté apagado. -->
            <template x-if="navegarDias">
                <div class="flex items-center gap-1 ml-auto">
                    <button type="button" @click="moverDia(-1)" :disabled="cargando"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                            aria-label="Día anterior">
                        <i data-lucide="chevron-left" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                    </button>
                    <button type="button" @click="moverDia(1)" :disabled="cargando || fecha >= hoy"
                            class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40"
                            aria-label="Día siguiente">
                        <i data-lucide="chevron-right" class="w-5 h-5 text-gray-700 dark:text-gray-300"></i>
                    </button>
                </div>
            </template>
        </div>

        <!-- Otro día: solo lectura -->
        <div x-show="!cargando && !error && !editable" x-cloak
             class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
            Estás viendo otro día. Las horas solo se anotan el mismo día, así que aquí no se pueden cambiar.
        </div>

        <!-- Resumen del día -->
        <div x-show="!cargando && !error && personas.length > 0" x-cloak data-tour="ct.resumen"
             class="bg-gray-50 dark:bg-gray-800/60 rounded-lg px-4 py-3 text-sm text-gray-700 dark:text-gray-300"
             x-text="resumenDia()"></div>

        <!-- Cargando -->
        <div x-show="cargando" class="flex flex-col items-center justify-center py-12">
            <svg class="animate-spin h-6 w-6 text-blue-600" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle>
                <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" class="opacity-75"></path>
            </svg>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Cargando...</p>
        </div>

        <!-- Error -->
        <template x-if="!cargando && error">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 text-center">
                <i data-lucide="alert-circle" class="w-8 h-8 text-red-500 mx-auto mb-2"></i>
                <p class="text-sm text-red-800 dark:text-red-300" x-text="error"></p>
                <button @click="cargar()" class="mt-3 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                    Reintentar
                </button>
            </div>
        </template>

        <!-- Vacío -->
        <template x-if="!cargando && !error && personas.length === 0">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                <i data-lucide="users" class="w-10 h-10 text-gray-400 mx-auto mb-3"></i>
                <p class="text-sm text-gray-600 dark:text-gray-400">No hay personal de Aseo con turno este día en este hotel.</p>
                <a href="<?= u('/ajustes/turnos') ?>" x-show="puedeCargarTurno"
                   class="inline-flex items-center justify-center gap-1 mt-4 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">
                    Ver turnos
                </a>
            </div>
        </template>

        <!-- Personas -->
        <div class="space-y-2" data-tour="ct.lista" x-show="!cargando && !error && personas.length > 0" x-cloak>
            <p x-show="personasVisibles().length === 0"
               class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 text-sm text-center text-gray-600 dark:text-gray-400">
                Nadie de Aseo se llama así. Revisa el nombre o cambia el hotel.
            </p>
            <template x-for="p in personasVisibles()" :key="p.id">
                <div class="bg-white dark:bg-gray-800 border rounded-xl transition"
                     :class="abierta === p.id ? 'border-blue-500 dark:border-blue-400' : (p.turno ? 'border-gray-200 dark:border-gray-700' : 'border-amber-300 dark:border-amber-700')">

                    <!-- Fila compacta -->
                    <button type="button" @click="alternar(p.id)"
                            class="w-full min-h-[56px] px-4 py-3 flex items-center justify-between gap-3 text-left"
                            :aria-expanded="abierta === p.id ? 'true' : 'false'">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate" x-text="p.nombre"></p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span x-show="!p.turno" class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200">Sin turno</span>
                            <span class="text-sm text-right"
                                  :class="totalMinutos(p) > 0 ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400 dark:text-gray-500'"
                                  x-text="resumenPersona(p)"></span>
                            <!-- El giro va en el span: lucide reemplaza el <i> por un <svg> y el :class se perdería. -->
                            <span class="inline-flex transition-transform" :class="abierta === p.id ? 'rotate-180' : ''">
                                <i data-lucide="chevron-down" class="w-4 h-4 text-gray-500"></i>
                            </span>
                        </div>
                    </button>

                    <!-- Ficha -->
                    <div x-show="abierta === p.id" x-cloak class="px-4 pb-4 pt-3 space-y-3 border-t border-gray-100 dark:border-gray-700">

                        <!-- Sin turno cargado -->
                        <template x-if="!p.turno">
                            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-200 space-y-2">
                                <p>No tiene turno cargado este día, así que no se sabe cuántas horas trabajó.</p>
                                <template x-if="puedeCargarTurno && editable && turnos.length > 0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label class="sr-only" :for="'turno-' + p.id">Turno que hizo</label>
                                        <select :id="'turno-' + p.id" x-model.number="p.turnoElegido"
                                                class="min-h-[44px] flex-1 min-w-[160px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-3 text-sm">
                                            <option value="">¿Qué turno hizo?</option>
                                            <template x-for="t in turnos" :key="t.id">
                                                <option :value="t.id" x-text="etiquetaTurno(t)"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="cargarTurno(p)" :disabled="!p.turnoElegido || p.cargandoTurno"
                                                class="min-h-[44px] px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium disabled:opacity-50">
                                            Cargar turno
                                        </button>
                                    </div>
                                </template>
                                <template x-if="!puedeCargarTurno">
                                    <p>Pídele al Admin que se lo cargue en Ajustes → Turnos.</p>
                                </template>
                            </div>
                        </template>

                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Horas en otra área</p>

                        <template x-for="(f, i) in p.filas" :key="f.key">
                            <div class="grid grid-cols-[minmax(0,1fr)_96px_44px] gap-2 items-center">
                                <select x-model.number="f.area_id" @change="cambio(p)" :disabled="!editable"
                                        :aria-label="'Área ' + (i + 1)"
                                        class="min-h-[44px] w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-3 text-sm disabled:opacity-60">
                                    <option value="">Elige el área</option>
                                    <template x-for="a in areasPara(p, f)" :key="a.id">
                                        <option :value="a.id" :selected="a.id === f.area_id" x-text="a.nombre"></option>
                                    </template>
                                </select>
                                <select x-model.number="f.minutos" @change="cambio(p)" :disabled="!editable"
                                        :aria-label="'Horas en el área ' + (i + 1)"
                                        class="min-h-[44px] w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 px-2 text-sm disabled:opacity-60">
                                    <template x-for="m in opcionesMinutos" :key="m">
                                        <option :value="m" :selected="m === f.minutos" x-text="fmtHoras(m)"></option>
                                    </template>
                                </select>
                                <button type="button" x-show="editable" @click="quitarFila(p, i)"
                                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                        aria-label="Quitar esta área">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </template>

                        <p x-show="p.filas.length === 0" class="text-sm text-gray-500 dark:text-gray-400">No estuvo en otra área.</p>

                        <button type="button" x-show="editable && p.filas.length < maxAreas && quedanAreas(p)" @click="agregarFila(p)"
                                class="w-full min-h-[44px] flex items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 text-sm font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20">
                            <i data-lucide="plus" class="w-4 h-4"></i> Otra área
                        </button>

                        <!-- Solo el estado del guardado: la cuenta de horas en Aseo no se muestra (Nicolás, 08/11). -->
                        <p class="min-h-[1rem] text-right text-xs"
                           :class="p.estado === 'error' ? 'text-red-600 dark:text-red-400' : (p.estado === 'guardado' ? 'text-green-600 dark:text-green-400' : 'text-gray-500')"
                           x-text="textoEstado(p)"></p>
                    </div>
                </div>
            </template>
        </div>
    </main>

    <!-- Toast -->
    <div x-show="toast.visible" x-transition x-cloak
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg shadow-lg text-white text-sm font-medium max-w-sm w-[90%] text-center"
         :class="toast.tipo === 'exito' ? 'bg-green-600' : 'bg-red-600'"
         x-text="toast.mensaje"></div>
</div>

<script>
function cambioTareaApp() {
    var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    var hotelGuardado = 'ambos';
    try { hotelGuardado = localStorage.getItem('cambio_tarea_hotel') || 'ambos'; } catch (e) { /* sin almacenamiento */ }

    return {
        cargando: true,
        error: '',
        fecha: '',
        hoy: '',
        editable: false,
        navegarDias: false,
        puedeCargarTurno: false,
        personas: [],
        areas: [],
        turnos: [],
        maxAreas: 6,
        opcionesMinutos: [],
        abierta: null,
        busqueda: '',
        hotel: hotelGuardado,
        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Inn' }
        ],
        toast: { visible: false, tipo: 'exito', mensaje: '' },
        seq: 0,

        async cargar() {
            this.cargando = true;
            this.error = '';
            var url = '/api/cambio-tarea?hotel=' + encodeURIComponent(this.hotel);
            if (this.navegarDias && this.fecha && this.fecha !== this.hoy) url += '&fecha=' + encodeURIComponent(this.fecha);
            try {
                var r = await apiFetch(url);
                if (r && r.ok) {
                    var d = r.data;
                    this.fecha = d.fecha;
                    this.hoy = d.hoy;
                    this.editable = d.editable;
                    this.navegarDias = d.navegar_dias;
                    this.puedeCargarTurno = d.puede_cargar_turno;
                    this.areas = d.areas || [];
                    this.turnos = d.turnos || [];
                    this.maxAreas = d.max_areas || 6;
                    this.opcionesMinutos = [];
                    for (var m = d.minutos_paso || 30; m <= (d.minutos_max_area || 720); m += (d.minutos_paso || 30)) {
                        this.opcionesMinutos.push(m);
                    }
                    var self = this;
                    this.personas = (d.personas || []).map(function (p) { return self.prepararPersona(p); });
                    if (this.abierta !== null && !this.personas.some(function (p) { return p.id === self.abierta; })) {
                        this.abierta = null;
                    }
                } else {
                    this.error = (r && r.error && r.error.mensaje) || 'No pudimos cargar el día.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        prepararPersona(p) {
            var self = this;
            p.filas = (p.otras_areas || []).map(function (a) {
                return { key: ++self.seq, area_id: a.area_id, nombre: a.nombre, minutos: a.minutos };
            });
            p.estado = '';
            p.timer = null;
            p.guardando = false;
            p.pendiente = false;
            p.turnoElegido = '';
            p.cargandoTurno = false;
            return p;
        },

        elegirHotel(valor) {
            this.hotel = valor;
            try { localStorage.setItem('cambio_tarea_hotel', valor); } catch (e) { /* sin almacenamiento */ }
            this.cargar();
        },

        moverDia(delta) {
            var d = this.aFecha(this.fecha);
            d.setDate(d.getDate() + delta);
            var nueva = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            if (nueva > this.hoy) return;
            this.fecha = nueva;
            this.abierta = null;
            this.cargar();
        },

        alternar(id) {
            this.abierta = this.abierta === id ? null : id;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        // --- Filas ---

        agregarFila(p) {
            p.filas.push({ key: ++this.seq, area_id: '', nombre: '', minutos: 60 });
            this.$nextTick(function () { lucide.createIcons(); });
        },

        quitarFila(p, i) {
            p.filas.splice(i, 1);
            this.cambio(p);
        },

        // Áreas para elegir en una fila: las activas que no están en otra fila de la misma persona, más la
        // que ya tiene (aunque la hayan desactivado en Ajustes).
        areasPara(p, f) {
            var usadas = p.filas.filter(function (o) { return o !== f && o.area_id; }).map(function (o) { return o.area_id; });
            var lista = this.areas.filter(function (a) { return usadas.indexOf(a.id) === -1; });
            if (f.area_id && !lista.some(function (a) { return a.id === f.area_id; })) {
                lista = lista.concat([{ id: f.area_id, nombre: f.nombre || 'Área' }]);
            }
            return lista;
        },

        quedanAreas(p) {
            var usadas = p.filas.map(function (o) { return o.area_id; });
            return this.areas.some(function (a) { return usadas.indexOf(a.id) === -1; });
        },

        // --- Guardado: a cada cambio, el juego completo de la persona ---

        cambio(p) {
            if (!this.editable) return;
            var self = this;
            clearTimeout(p.timer);
            p.timer = setTimeout(function () { self.guardar(p); }, 400);
        },

        async guardar(p) {
            if (p.guardando) { p.pendiente = true; return; }
            var completas = p.filas.filter(function (f) { return f.area_id; });
            p.guardando = true;
            p.estado = 'guardando';
            try {
                var r = await apiPut('/api/cambio-tarea/' + p.id, {
                    fecha: this.fecha,
                    areas: completas.map(function (f) { return { area_id: f.area_id, minutos: f.minutos }; })
                });
                if (r && r.ok) {
                    // Se actualizan los nombres en las filas que ya están (no se rearman: así un select
                    // abierto no pierde el foco a mitad de un cambio).
                    p.otras_areas = r.data.otras_areas || [];
                    p.filas.forEach(function (f) {
                        var g = p.otras_areas.find(function (a) { return a.area_id === f.area_id; });
                        if (g) f.nombre = g.nombre;
                    });
                    p.estado = 'guardado';
                } else {
                    p.estado = 'error';
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos guardar el cambio. Intenta de nuevo en un momento.');
                    if (r && r.error && r.error.codigo === 'SOLO_MISMO_DIA') {
                        p.pendiente = false;
                        this.fecha = '';
                        this.cargar();
                    }
                }
            } catch (e) {
                p.estado = 'error';
                this.mostrarToast('error', 'No pudimos conectar con el servidor. Intenta de nuevo en un momento.');
            } finally {
                p.guardando = false;
                this.$nextTick(function () { lucide.createIcons(); });
                if (p.pendiente) {
                    p.pendiente = false;
                    this.guardar(p);
                }
            }
        },

        async cargarTurno(p) {
            if (!p.turnoElegido) return;
            p.cargandoTurno = true;
            try {
                var r = await apiPost('/api/usuarios/' + p.id + '/turno', { turno_id: p.turnoElegido, fecha: this.fecha });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Turno cargado.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cargar el turno.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                p.cargandoTurno = false;
            }
        },

        // --- Textos ---

        aFecha(iso) {
            var partes = (iso || '').split('-');
            return new Date(Number(partes[0]), Number(partes[1]) - 1, Number(partes[2]));
        },

        fechaTexto() {
            if (!this.fecha) return 'Hoy';
            var d = this.aFecha(this.fecha);
            var dd = String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
            return (this.fecha === this.hoy ? 'Hoy · ' : '') + DIAS[d.getDay()] + ' ' + dd;
        },

        fmtHoras(minutos) {
            var h = Math.round((minutos / 60) * 10) / 10;
            return String(h).replace('.', ',') + ' h';
        },

        etiquetaTurno(t) {
            var nombre = t.nombre ? t.nombre.charAt(0).toUpperCase() + t.nombre.slice(1) : 'Turno';
            return nombre + ' ' + t.hora_inicio + '–' + t.hora_fin;
        },

        totalMinutos(p) {
            return (p.otras_areas || []).reduce(function (s, a) { return s + a.minutos; }, 0);
        },

        resumenPersona(p) {
            var guardadas = p.otras_areas || [];
            if (guardadas.length === 0) return 'Sin novedades';
            if (guardadas.length === 1) return guardadas[0].nombre + ' ' + this.fmtHoras(guardadas[0].minutos);
            return guardadas.length + ' áreas · ' + this.fmtHoras(this.totalMinutos(p));
        },

        textoEstado(p) {
            if (p.estado === 'guardando') return 'Guardando...';
            if (p.estado === 'guardado') return '✓ Guardado';
            if (p.estado === 'error') return 'No se guardó';
            return '';
        },

        // Búsqueda mientras se escribe (Nicolás, 08/11): sin tildes ni mayúsculas, y cada palabra
        // escrita tiene que estar en el nombre («ana roj» encuentra a Ana Rojas, «munoz» a Muñoz).
        normalizar(texto) {
            return String(texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        },

        personasVisibles() {
            var palabras = this.normalizar(this.busqueda).split(/\s+/).filter(Boolean);
            if (palabras.length === 0) return this.personas;
            var self = this;
            return this.personas.filter(function (p) {
                var nombre = self.normalizar(p.nombre);
                return palabras.every(function (w) { return nombre.indexOf(w) !== -1; });
            });
        },

        resumenDia() {
            // Solo el conteo (pedido de Nicolás 08/11): las horas en otras áreas ya salen en cada fila.
            return this.personas.length + (this.personas.length === 1 ? ' persona de Aseo' : ' personas de Aseo');
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 3000);
        }
    };
}
</script>
