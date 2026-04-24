<?php
/**
 * Listado de habitaciones.
 * Spec: docs/habitaciones.md
 *
 * Role-aware:
 *  - Trabajador (sin habitaciones.ver_todas) → sus asignadas del día (/api/usuarios/{id}/cola)
 *  - Supervisora / Recepción / Admin → lista completa con filtros hotel + estado (/api/habitaciones)
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

$puedeVerTodas = $usuario->tienePermiso('habitaciones.ver_todas');
?>

<div x-data="habitacionesApp(<?= $puedeVerTodas ? 'true' : 'false' ?>, <?= (int) $usuario->id ?>)"
     x-init="cargar()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Habitaciones</h1>
            <button @click="cargar()" :disabled="cargando"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                    aria-label="Refrescar">
                <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"
                   :class="cargando ? 'animate-spin' : ''"></i>
            </button>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet.
    </div>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto">

        <?php if ($puedeVerTodas): ?>
        <!-- Filtros (solo roles con habitaciones.ver_todas) -->
        <div class="mb-4 space-y-3">
            <!-- Hotel -->
            <div>
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Hotel</p>
                <div class="flex gap-2 flex-wrap">
                    <template x-for="h in hotelesOpciones" :key="h.codigo">
                        <button @click="setHotel(h.codigo)"
                                :class="hotel === h.codigo
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-blue-400'"
                                class="min-h-[40px] px-4 py-1.5 rounded-full border text-sm font-medium transition">
                            <span x-text="h.etiqueta"></span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Estado -->
            <div>
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Estado</p>
                <div class="flex gap-2 flex-wrap">
                    <template x-for="e in estadosOpciones" :key="e.valor || 'todos'">
                        <button @click="setEstado(e.valor)"
                                :class="estado === e.valor
                                    ? 'bg-blue-600 text-white border-blue-600'
                                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-blue-400'"
                                class="min-h-[40px] px-4 py-1.5 rounded-full border text-sm font-medium transition">
                            <span x-text="e.etiqueta"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Estado de carga inicial -->
        <template x-if="cargando && habitaciones.length === 0">
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

        <!-- Error -->
        <template x-if="error && habitaciones.length === 0">
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

        <!-- Estado vacío -->
        <template x-if="!cargando && !error && habitaciones.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center px-4">
                <div class="text-center max-w-xs">
                    <i data-lucide="inbox" class="w-12 h-12 text-gray-400 dark:text-gray-500 mx-auto mb-3"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Sin habitaciones</h2>
                    <p class="text-gray-600 dark:text-gray-400">
                        <?= $puedeVerTodas ? 'No hay habitaciones con los filtros seleccionados.' : 'No tienes habitaciones asignadas para hoy.' ?>
                    </p>
                </div>
            </div>
        </template>

        <!-- Grid de tarjetas -->
        <template x-if="habitaciones.length > 0">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <template x-for="hab in habitaciones" :key="hab.id">
                    <a :href="'/habitaciones/' + hab.id"
                       class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:border-blue-400 dark:hover:border-blue-500 transition shadow-sm flex flex-col gap-2"
                       :class="estadoAuditado(hab.estado) ? 'opacity-60' : ''">
                        <div class="flex items-start justify-between">
                            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium"
                                  x-text="hotelCorto(hab.hotel_codigo)"></span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400" x-text="hab.tipo_nombre || hab.tipo"></p>
                        <div class="mt-auto pt-1" x-html="badgeEstado(hab.estado)"></div>
                    </a>
                </template>
            </div>
        </template>

    </main>
</div>

<script>
function habitacionesApp(puedeVerTodas, usuarioId) {
    return {
        puedeVerTodas: puedeVerTodas,
        usuarioId: usuarioId,
        habitaciones: [],
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,

        hotel: localStorage.getItem('habitaciones_hotel') || 'ambos',
        estado: localStorage.getItem('habitaciones_estado') || '',

        hotelesOpciones: [
            { codigo: 'ambos', etiqueta: 'Ambos' },
            { codigo: '1_sur', etiqueta: 'Atankalama' },
            { codigo: 'inn', etiqueta: 'Atankalama INN' }
        ],

        estadosOpciones: [
            { valor: '', etiqueta: 'Todos' },
            { valor: 'sucia', etiqueta: 'Sucias' },
            { valor: 'en_progreso', etiqueta: 'En progreso' },
            { valor: 'completada_pendiente_auditoria', etiqueta: 'Por auditar' },
            { valor: 'aprobada', etiqueta: 'Aprobadas' },
            { valor: 'rechazada', etiqueta: 'Rechazadas' }
        ],

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url;
                if (this.puedeVerTodas) {
                    var params = [];
                    if (this.hotel && this.hotel !== 'ambos') params.push('hotel=' + encodeURIComponent(this.hotel));
                    if (this.estado) params.push('estado=' + encodeURIComponent(this.estado));
                    url = '/api/habitaciones' + (params.length ? '?' + params.join('&') : '');
                } else {
                    url = '/api/usuarios/' + this.usuarioId + '/cola';
                }

                var json = await apiFetch(url);
                if (json && json.ok) {
                    if (this.puedeVerTodas) {
                        this.habitaciones = json.data.habitaciones || [];
                    } else {
                        var cola = json.data.cola || [];
                        this.habitaciones = cola.map(function (a) {
                            return {
                                id: a.habitacion_id,
                                numero: a.numero,
                                estado: a.estado,
                                hotel_codigo: a.hotel_codigo,
                                tipo_nombre: a.tipo_nombre
                            };
                        });
                    }
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

        setHotel(codigo) {
            this.hotel = codigo;
            localStorage.setItem('habitaciones_hotel', codigo);
            this.cargar();
        },

        setEstado(valor) {
            this.estado = valor;
            localStorage.setItem('habitaciones_estado', valor);
            this.cargar();
        },

        hotelCorto(codigo) {
            if (codigo === '1_sur') return 'Atankalama';
            if (codigo === 'inn') return 'Atankalama INN';
            return codigo || '';
        },

        estadoAuditado(estado) {
            return estado === 'aprobada' || estado === 'aprobada_con_observacion' || estado === 'rechazada';
        },

        badgeEstado(estado) {
            var configs = {
                'sucia': { texto: 'Pendiente', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200' },
                'en_progreso': { texto: 'En progreso', clase: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' },
                'completada_pendiente_auditoria': { texto: 'Por auditar', clase: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200' },
                'aprobada': { texto: 'Aprobada', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'aprobada_con_observacion': { texto: 'Aprobada c/obs.', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'rechazada': { texto: 'Rechazada', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' }
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        }
    };
}
</script>
