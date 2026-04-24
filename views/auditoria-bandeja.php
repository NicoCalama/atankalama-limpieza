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
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Auditoría</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="subtitulo()"></p>
            </div>
            <button @click="cargar()" :disabled="cargando"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                    aria-label="Refrescar">
                <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"
                   :class="cargando ? 'animate-spin' : ''"></i>
            </button>
        </div>
    </header>

    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet.
    </div>

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto">

        <div class="mb-4">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Hotel</p>
            <div class="flex gap-2 flex-wrap">
                <template x-for="h in hotelOpciones" :key="h.valor">
                    <button @click="setHotel(h.valor)"
                            :class="hotel === h.valor
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-blue-400'"
                            class="min-h-[40px] px-4 py-1.5 rounded-full border text-sm font-medium transition">
                        <span x-text="h.etiqueta"></span>
                    </button>
                </template>
            </div>
        </div>

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
                    <p class="text-gray-600 dark:text-gray-400">No hay habitaciones pendientes de auditar.</p>
                </div>
            </div>
        </template>

        <template x-if="pendientes.length > 0">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <template x-for="hab in pendientes" :key="hab.id">
                    <a :href="'/auditoria/' + hab.id + '?ejecucion=' + (hab.ejecucion_id || '')"
                       class="bg-white dark:bg-gray-800 rounded-xl border-2 border-indigo-200 dark:border-indigo-900 p-4 hover:border-indigo-500 dark:hover:border-indigo-500 transition shadow-sm flex flex-col gap-2">
                        <div class="flex items-start justify-between">
                            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium"
                                  x-text="hotelCorto(hab.hotel_codigo)"></span>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400" x-text="hab.tipo_nombre || ''"></p>
                        <div class="mt-auto pt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">
                                Por auditar
                            </span>
                        </div>
                    </a>
                </template>
            </div>
        </template>

    </main>
</div>

<script>
function auditoriaBandejaApp() {
    return {
        pendientes: [],
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('auditoria_hotel') || 'ambos',
        _intervalId: null,

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
            return total === 1 ? '1 habitación pendiente' : total + ' habitaciones pendientes';
        },

        hotelCorto(codigo) {
            if (codigo === '1_sur') return 'Atankalama';
            if (codigo === 'inn') return 'Atankalama INN';
            return codigo || '';
        }
    };
}
</script>
