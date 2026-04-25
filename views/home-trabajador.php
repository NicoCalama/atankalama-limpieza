<?php
/**
 * Home del Trabajador de Limpieza.
 * Spec: docs/home-trabajador.md
 *
 * Filosofía: "¿Qué tengo que hacer ahora?"
 * NO mostrar números, contadores ni tiempos — solo barra de progreso visual.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';

$primerNombre = explode(' ', $usuario->nombre)[0];

// Saludo contextual por hora
$hora = (int) date('H');
if ($hora < 12) {
    $saludo = 'Buenos días';
} elseif ($hora < 19) {
    $saludo = 'Buenas tardes';
} else {
    $saludo = 'Buenas noches';
}
?>

<div x-data="homeTrabajador()"
     x-init="cargar(); iniciarRefresco();"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-2xl mx-auto">
            <div class="flex items-center gap-3">
                <a href="/ajustes" aria-label="Mi perfil">
                    <?= avatarHtml($usuario->nombre, $usuario->rut) ?>
                </a>
                <div>
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($saludo) ?>, <?= htmlspecialchars($primerNombre) ?></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="data?.hotel_actual?.nombre || '<?= htmlspecialchars($usuario->hotelDefault === 'inn' ? 'Atankalama INN' : 'Atankalama') ?>'"></p>
                </div>
            </div>
            <!-- Campana -->
            <button class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative"
                    aria-label="Notificaciones">
                <i data-lucide="bell" class="w-6 h-6 text-gray-600 dark:text-gray-400"></i>
            </button>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Tus cambios se sincronizarán cuando vuelva.
    </div>

    <!-- Estado de carga -->
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

    <!-- Error al cargar -->
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar tu día</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión a internet e intenta de nuevo.</p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido principal -->
    <template x-if="data">
        <main class="pb-24 md:pb-8 max-w-2xl mx-auto">

            <!-- Sin asignaciones -->
            <template x-if="!data.tiene_asignaciones_hoy">
                <div class="px-4 py-12 text-center">
                    <i data-lucide="coffee" class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No tienes habitaciones asignadas todavía</h2>
                    <p class="text-base text-gray-600 dark:text-gray-400 max-w-xs mx-auto mb-6">Espera a que tu supervisora te asigne, o avísale que estás disponible.</p>
                    <button @click="avisarDisponibilidad()"
                            :disabled="data.aviso_disponibilidad_enviado_hoy || enviandoAviso"
                            class="min-h-[44px] px-6 py-2 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                                   border border-gray-300 dark:border-gray-600 rounded-lg font-medium transition
                                   hover:bg-gray-200 dark:hover:bg-gray-600
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <span x-text="data.aviso_disponibilidad_enviado_hoy ? '✓ Aviso enviado' : (enviandoAviso ? 'Enviando...' : 'Avisar que estoy disponible')"></span>
                    </button>
                </div>
            </template>

            <!-- Con asignaciones -->
            <template x-if="data.tiene_asignaciones_hoy">
                <div>
                    <!-- Sección 2: Tarjeta de progreso -->
                    <div class="px-4 mt-4">
                        <!-- Día completado -->
                        <template x-if="data.progreso.todas_completadas">
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800 p-6 text-center">
                                <i data-lucide="party-popper" class="w-12 h-12 text-green-500 mx-auto mb-3"></i>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">¡Día completado!</h2>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Excelente trabajo</p>
                            </div>
                        </template>

                        <!-- Progreso en curso -->
                        <template x-if="!data.progreso.todas_completadas">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Tu día de hoy</p>
                                <div class="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
                                    <div class="bg-green-500 h-full transition-all duration-500"
                                         :style="'width:' + porcentaje(data.progreso.completadas) + '%'"></div>
                                    <div class="bg-blue-500 h-full transition-all duration-500"
                                         :style="'width:' + porcentaje(data.progreso.en_progreso) + '%'"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Grid responsive: en desktop, 2 columnas para actual + próximas -->
                    <div class="md:grid md:grid-cols-2 md:gap-4 md:px-4 md:mt-4">

                        <!-- Sección 3: Habitación actual -->
                        <template x-if="data.habitacion_actual">
                            <div class="px-4 md:px-0 mt-4 md:mt-0">
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Habitación actual</p>
                                    <div class="mb-4">
                                        <p class="text-4xl font-bold text-gray-900 dark:text-gray-100" x-text="data.habitacion_actual.numero"></p>
                                        <p class="text-base text-gray-600 dark:text-gray-400 mt-1" x-text="data.habitacion_actual.tipo"></p>
                                        <div class="mt-2" x-html="badgeEstado(data.habitacion_actual.estado)"></div>
                                    </div>
                                    <a :href="'/habitaciones/' + data.habitacion_actual.id"
                                       class="block w-full min-h-[56px] bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center">
                                        <span x-text="data.habitacion_actual.estado === 'en_progreso' ? 'Continuar' : 'Comenzar limpieza'"></span>
                                    </a>
                                </div>
                            </div>
                        </template>

                        <!-- Sección 4: Próximas habitaciones -->
                        <template x-if="data.proximas && data.proximas.length > 0">
                            <div class="px-4 md:px-0 mt-4 md:mt-0">
                                <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-2">Próximas</p>
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                                    <template x-for="hab in data.proximas" :key="hab.id">
                                        <a :href="'/habitaciones/' + hab.id"
                                           class="min-h-[60px] flex items-center px-4 py-3 border-b border-gray-200 dark:border-gray-700 last:border-b-0
                                                  hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                            <span class="text-lg font-bold text-gray-900 dark:text-gray-100 w-12" x-text="hab.numero"></span>
                                            <span class="text-base text-gray-600 dark:text-gray-400 flex-1 ml-4" x-text="hab.tipo"></span>
                                            <span x-html="badgeEstado(hab.estado)"></span>
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </template>

                    </div>
                </div>
            </template>

            <?php if ($usuario->tienePermiso('tickets.crear')): ?>
            <!-- Reportar problema -->
            <div class="px-4 mt-6">
                <button type="button"
                        @click="reportarProblema()"
                        class="w-full min-h-[52px] inline-flex items-center justify-center gap-2 px-4 py-2
                               bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                               text-gray-700 dark:text-gray-200 rounded-xl font-medium
                               hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500"></i>
                    Reportar un problema
                </button>
            </div>
            <?php endif; ?>

            <!-- Cerrar turno -->
            <div class="px-4 mt-6 pb-2">
                <button type="button"
                        @click="cerrarSesion()"
                        :disabled="cerrando"
                        class="w-full min-h-[56px] inline-flex items-center justify-center gap-2.5 px-4 py-3
                               bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800
                               text-red-700 dark:text-red-300 rounded-xl font-semibold text-base
                               hover:bg-red-100 dark:hover:bg-red-900/40 active:bg-red-200 transition
                               disabled:opacity-60 disabled:cursor-not-allowed">
                    <i data-lucide="log-out" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-text="cerrando ? 'Cerrando sesión...' : 'Terminar turno y cerrar sesión'"></span>
                </button>
            </div>

        </main>
    </template>
</div>

<script>
function homeTrabajador() {
    return {
        data: null,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        enviandoAviso: false,
        cerrando: false,
        _intervalId: null,

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var resp = await fetch('/api/home/trabajador');
                var json = await resp.json();
                if (json.ok) {
                    this.data = json.data;
                } else {
                    this.error = json.error?.mensaje || 'Error al cargar.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function() { lucide.createIcons(); });
            }
        },

        iniciarRefresco() {
            // Refresco cada 2 minutos
            this._intervalId = setInterval(() => this.cargar(), 120000);

            // Detectar conexión
            window.addEventListener('online', () => { this.sinConexion = false; this.cargar(); });
            window.addEventListener('offline', () => { this.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) {
                this.cargar();
            }
        },

        porcentaje(valor) {
            if (!this.data || this.data.progreso.total === 0) return 0;
            return Math.round((valor / this.data.progreso.total) * 100);
        },

        badgeEstado(estado) {
            var configs = {
                'pendiente': { texto: 'Pendiente', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200' },
                'en_progreso': { texto: 'En progreso', clase: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' },
                'completada': { texto: 'Completada', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'aprobada': { texto: 'Aprobada', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'rechazada': { texto: 'Rechazada', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' },
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        },

        reportarProblema() {
            var detail = {};
            if (this.data && this.data.habitacion_actual) {
                detail.habitacionId = this.data.habitacion_actual.id;
            }
            window.dispatchEvent(new CustomEvent('abrir-modal-ticket', { detail: detail }));
        },

        async cerrarSesion() {
            if (this.cerrando) return;
            this.cerrando = true;
            try {
                await fetch('/api/auth/logout', { method: 'POST' });
            } catch (e) { /* continuar igual */ }
            window.location.href = '/login';
        },

        async avisarDisponibilidad() {
            if (this.enviandoAviso || this.data.aviso_disponibilidad_enviado_hoy) return;
            this.enviandoAviso = true;
            try {
                var resp = await fetch('/api/disponibilidad/avisar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                var json = await resp.json();
                if (json.ok) {
                    this.data.aviso_disponibilidad_enviado_hoy = true;
                }
            } catch (e) {
                // Silencioso — el usuario puede reintentar
            } finally {
                this.enviandoAviso = false;
            }
        }
    };
}
</script>
