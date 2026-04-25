<?php
/**
 * Home de Recepción.
 * Spec: docs/home-recepcion.md
 *
 * Filosofía: "¿Qué habitaciones necesito auditar ahora?"
 * Bandeja visual de auditoría pendiente. Tap → auditoría.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';

$primerNombre = explode(' ', $usuario->nombre)[0];

$hora = (int) date('H');
if ($hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}
?>

<div x-data="homeRecepcion()"
     x-init="cargar(); iniciarRefresco();"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <a href="/ajustes" aria-label="Mi perfil">
                    <?= avatarHtml($usuario->nombre, $usuario->rut) ?>
                </a>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400"><?= htmlspecialchars($saludo) ?></p>
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate"><?= htmlspecialchars($usuario->nombre) ?></p>
                    <!-- Selector hotel -->
                    <div class="relative" x-data="{ abierto: false }" @click.outside="abierto = false">
                        <button @click="abierto = !abierto"
                                class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 inline-flex items-center gap-1">
                            <span x-text="etiquetaHotel()"></span>
                            <i data-lucide="chevron-down" class="w-3 h-3"></i>
                        </button>
                        <div x-show="abierto" x-cloak
                             class="absolute left-0 top-full mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg overflow-hidden min-w-[180px]">
                            <template x-for="op in hotelOpciones" :key="op.valor">
                                <button @click="setHotel(op.valor); abierto = false"
                                        :class="hotel === op.valor ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-200'"
                                        class="block w-full text-left px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                                    <span x-text="op.etiqueta"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Refrescar">
                    <i data-lucide="rotate-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"
                       :class="cargando ? 'animate-spin' : ''"></i>
                </button>
                <button @click="cerrarSesion()"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400"
                        aria-label="Cerrar sesión">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Los cambios se sincronizarán cuando vuelva.
    </div>

    <!-- Carga inicial -->
    <template x-if="cargando && !data">
        <div class="min-h-[60vh] flex items-center justify-center">
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
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar las habitaciones</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión a internet e intenta de nuevo.</p>
                <button @click="cargar()"
                        class="min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido -->
    <template x-if="data">
        <main class="pb-24 md:pb-8 px-3 py-3 max-w-5xl mx-auto">

            <!-- Sin pendientes -->
            <template x-if="data.total_pendientes === 0">
                <div class="min-h-[50vh] flex items-center justify-center px-4">
                    <div class="text-center max-w-xs">
                        <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3 text-gray-400 dark:text-gray-500"></i>
                        <p class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">No hay habitaciones</p>
                        <p class="text-gray-600 dark:text-gray-400">Pendientes de auditar</p>
                    </div>
                </div>
            </template>

            <!-- Grid de pendientes -->
            <template x-if="data.total_pendientes > 0">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="hab in data.habitaciones_pendientes" :key="hab.id">
                        <a :href="'/auditoria/' + hab.id + '?ejecucion=' + (hab.ejecucion_id || '')"
                           class="min-h-[80px] bg-white dark:bg-gray-800 border-2 border-gray-300 dark:border-gray-600 rounded-lg p-4 flex items-center justify-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 active:ring-2 active:ring-blue-500 transition">
                            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100"
                                  x-text="etiquetaTarjeta(hab)"></span>
                        </a>
                    </template>
                </div>
            </template>

        </main>
    </template>
</div>

<script>
function homeRecepcion() {
    return {
        data: null,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('recepcion_hotel') || 'ambos',
        _intervalId: null,

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos hoteles' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama INN' }
        ],

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/home/recepcion';
                if (this.hotel && this.hotel !== 'ambos') {
                    url += '?hotel=' + encodeURIComponent(this.hotel);
                }
                var json = await apiFetch(url);
                if (json && json.ok) {
                    this.data = json.data;
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
            this._intervalId = setInterval(function () { self.cargar(); }, 300000); // 5 min
            window.addEventListener('online', function () { self.sinConexion = false; self.cargar(); });
            window.addEventListener('offline', function () { self.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) {
                this.cargar();
            }
        },

        async cerrarSesion() {
            try { await fetch('/api/auth/logout', { method: 'POST' }); } catch (e) {}
            window.location.href = '/login';
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('recepcion_hotel', valor);
            this.cargar();
        },

        etiquetaHotel() {
            var op = this.hotelOpciones.find(o => o.valor === this.hotel);
            return op ? op.etiqueta : 'Ambos hoteles';
        },

        etiquetaTarjeta(hab) {
            if (this.hotel !== 'ambos') return String(hab.numero);
            var prefijo = hab.hotel_codigo === '1_sur' ? 'ATAN' : (hab.hotel_codigo === 'inn' ? 'ATAN-INN' : hab.hotel_codigo);
            return prefijo + '-' + hab.numero;
        }
    };
}
</script>
