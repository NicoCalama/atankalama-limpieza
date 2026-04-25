<?php
/**
 * Home de la Supervisora.
 * Spec: docs/home-supervisora.md
 *
 * Secciones:
 *  1. Header sticky (avatar + saludo + selector hotel + campana)
 *  2. Alertas urgentes (top 5, con enlace a "Ver todas")
 *  3. Estado del equipo (barra progreso global + lista trabajadores)
 *
 * Filtrado:
 *  - Selector hotel: 1_sur / inn / ambos (persistido en localStorage)
 *  - Equipo ordenado: en_riesgo → en_tiempo → disponible (subfiltro por atraso)
 *
 * Refresco: auto 60s + visibilitychange + tras cada acción.
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

<div x-data="homeSupervisora()"
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
                <button class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative"
                        aria-label="Notificaciones">
                    <i data-lucide="bell" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                    <template x-if="alertas.length > 0">
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full"></span>
                    </template>
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

    <!-- Carga inicial -->
    <template x-if="cargando && !data">
        <div class="min-h-[60vh] flex items-center justify-center">
            <div class="flex flex-col items-center gap-3">
                <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <p class="text-gray-600 dark:text-gray-400">Cargando Home...</p>
            </div>
        </div>
    </template>

    <!-- Error -->
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Error al cargar</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4">Verifica tu conexión e intenta de nuevo.</p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido -->
    <template x-if="data">
        <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-6">

            <!-- Sección Alertas -->
            <template x-if="puedeVerAlertas">
                <section>
                    <div class="flex items-center justify-between mb-2">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                            <i data-lucide="bell-ring" class="w-4 h-4 text-amber-600 dark:text-amber-400"></i>
                            Alertas urgentes
                            <span x-show="alertasTotal > 0" class="text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 px-2 py-0.5 rounded-full" x-text="alertasTotal"></span>
                        </h2>
                    </div>

                    <template x-if="alertas.length === 0">
                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                            <i data-lucide="check-circle-2" class="w-8 h-8 text-green-500 mx-auto mb-2"></i>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Todo tranquilo. No hay alertas activas.</p>
                        </div>
                    </template>

                    <template x-if="alertas.length > 0">
                        <div class="space-y-2">
                            <template x-for="al in alertas" :key="al.id">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4"
                                     :class="claseBordeAlerta(al.prioridad)">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                             :class="claseIconoAlerta(al.tipo)">
                                            <i :data-lucide="iconoAlerta(al.tipo)" class="w-4 h-4"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-gray-900 dark:text-gray-100" x-text="al.titulo"></p>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1" x-text="al.descripcion"></p>
                                        </div>
                                    </div>
                                    <template x-if="botonesAlerta(al).length > 0">
                                        <div class="flex gap-2 mt-3 pl-11">
                                            <template x-for="btn in botonesAlerta(al)" :key="btn.accion">
                                                <button @click="accionAlerta(al, btn.accion)"
                                                        class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg transition"
                                                        :class="btn.clase">
                                                    <span x-text="btn.etiqueta"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="alertasTotal > alertas.length">
                                <div class="text-center pt-1">
                                    <a href="/alertas" class="inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline">
                                        Ver todas las alertas (<span x-text="alertasTotal"></span>)
                                        <i data-lucide="arrow-right" class="w-3 h-3"></i>
                                    </a>
                                </div>
                            </template>
                        </div>
                    </template>
                </section>
            </template>

            <!-- Sección Estado del equipo -->
            <section>
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                        <i data-lucide="users" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                        Estado del equipo
                    </h2>
                    <template x-if="data.permisos.asignaciones_asignar_manual">
                        <a href="/asignaciones" class="text-sm text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                            Asignaciones <i data-lucide="arrow-right" class="w-3 h-3"></i>
                        </a>
                    </template>
                </div>

                <!-- Progreso global -->
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 mb-3">
                    <div class="flex items-baseline justify-between mb-2">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Habitaciones completadas:
                            <span class="text-gray-900 dark:text-gray-100 font-semibold">
                                <span x-text="data.progreso_global.completadas"></span> / <span x-text="data.progreso_global.total"></span>
                            </span>
                        </p>
                        <span class="text-lg font-bold text-gray-900 dark:text-gray-100"
                              x-text="data.progreso_global.porcentaje + '%'"></span>
                    </div>
                    <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex">
                        <div class="bg-green-500 h-full transition-all"
                             :style="'width: ' + porcentajeGlobal('completadas') + '%'"></div>
                        <div class="bg-blue-400 h-full transition-all"
                             :style="'width: ' + porcentajeGlobal('en_progreso') + '%'"></div>
                        <div class="bg-red-500 h-full transition-all"
                             :style="'width: ' + porcentajeGlobal('rechazadas') + '%'"></div>
                    </div>
                    <div class="flex flex-wrap gap-3 mt-2 text-xs text-gray-600 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 bg-green-500 rounded-full"></span> Completadas</span>
                        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 bg-blue-400 rounded-full"></span> En progreso <span x-text="data.progreso_global.en_progreso"></span></span>
                        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 bg-red-500 rounded-full"></span> Rechazadas <span x-text="data.progreso_global.rechazadas"></span></span>
                        <span class="inline-flex items-center gap-1"><span class="w-2 h-2 bg-gray-400 rounded-full"></span> Pendientes <span x-text="data.progreso_global.pendientes"></span></span>
                    </div>
                </div>

                <!-- Lista trabajadores -->
                <template x-if="data.equipo.length === 0">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                        <i data-lucide="user-x" class="w-8 h-8 text-gray-400 mx-auto mb-2"></i>
                        <p class="text-sm text-gray-600 dark:text-gray-400">No hay trabajadores con turno hoy en este hotel.</p>
                    </div>
                </template>

                <template x-if="data.equipo.length > 0">
                    <div class="space-y-2">
                        <template x-for="tr in data.equipo" :key="tr.usuario.id">
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                <div class="flex items-start gap-3">
                                    <span x-html="avatarUsuario(tr.usuario)"></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="tr.usuario.nombre"></p>
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0"
                                                  :class="claseBadgeEstado(tr.estado)"
                                                  x-text="etiquetaEstado(tr.estado)"></span>
                                        </div>
                                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                            <span x-text="tr.progreso.completadas"></span> / <span x-text="tr.progreso.total"></span> habitaciones
                                            <template x-if="tr.estado !== 'disponible' && tr.tiempo_restante_min > 0">
                                                <span> · <span x-text="tr.tiempo_restante_min"></span> min restantes</span>
                                            </template>
                                            <template x-if="tr.hotel_codigo">
                                                <span class="text-gray-500 dark:text-gray-500"> · <span x-text="nombreHotelCorto(tr.hotel_codigo)"></span></span>
                                            </template>
                                        </p>
                                        <div class="h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden flex mt-2">
                                            <div class="bg-green-500 h-full transition-all"
                                                 :style="'width: ' + porcentajeTr(tr, 'completadas') + '%'"></div>
                                            <div class="bg-blue-400 h-full transition-all"
                                                 :style="'width: ' + porcentajeTr(tr, 'en_progreso') + '%'"></div>
                                            <div class="bg-red-500 h-full transition-all"
                                                 :style="'width: ' + porcentajeTr(tr, 'rechazadas') + '%'"></div>
                                        </div>
                                    </div>
                                </div>
                                <template x-if="data.permisos.asignaciones_asignar_manual">
                                    <div class="flex gap-2 mt-3">
                                        <button @click="abrirVerCarga(tr)"
                                                class="flex-1 min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                            Ver carga
                                        </button>
                                        <button @click="abrirReasignar(tr)"
                                                :disabled="tr.progreso.pendientes === 0"
                                                class="flex-1 min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition">
                                            Reasignar
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </section>

        </main>
    </template>

    <!-- Modal: Ver carga -->
    <div x-show="modalVerCarga.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarVerCarga()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Carga de <span x-text="modalVerCarga.trabajador?.usuario?.nombre"></span></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="modalVerCarga.cola.length + ' habitaciones'"></p>
                </div>
                <button @click="cerrarVerCarga()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <template x-if="modalVerCarga.cargando">
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Cargando...</p>
            </template>

            <template x-if="!modalVerCarga.cargando && modalVerCarga.cola.length === 0">
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Sin habitaciones asignadas.</p>
            </template>

            <template x-if="!modalVerCarga.cargando && modalVerCarga.cola.length > 0">
                <ul class="space-y-2">
                    <template x-for="hab in modalVerCarga.cola" :key="hab.habitacion_id">
                        <li class="flex items-center justify-between gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">
                                    <span x-text="hab.numero"></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-1" x-text="'· ' + (hab.tipo_nombre || '')"></span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="etiquetaEstadoHab(hab.estado)"></p>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
        </div>
    </div>

    <!-- Modal: Reasignar -->
    <div x-show="modalReasignar.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarReasignar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reasignar carga de <span x-text="modalReasignar.origen?.usuario?.nombre"></span></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Elige una habitación y un destinatario.</p>
                </div>
                <button @click="cerrarReasignar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <template x-if="modalReasignar.cargando">
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Cargando...</p>
            </template>

            <template x-if="!modalReasignar.cargando">
                <div class="space-y-4">
                    <!-- Paso 1: elegir habitación -->
                    <div>
                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">Habitación a reasignar</p>
                        <template x-if="modalReasignar.habitacionesPendientes.length === 0">
                            <p class="text-sm text-gray-500 dark:text-gray-400">No tiene habitaciones pendientes de reasignar.</p>
                        </template>
                        <div class="flex flex-wrap gap-2" x-show="modalReasignar.habitacionesPendientes.length > 0">
                            <template x-for="hab in modalReasignar.habitacionesPendientes" :key="hab.habitacion_id">
                                <button @click="modalReasignar.habSeleccionada = hab.habitacion_id"
                                        :class="modalReasignar.habSeleccionada === hab.habitacion_id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600'"
                                        class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg border transition">
                                    <span x-text="hab.numero"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Paso 2: elegir destino -->
                    <div x-show="modalReasignar.habSeleccionada">
                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">Enviar a (ordenado por menor carga)</p>
                        <ul class="space-y-1.5 max-h-60 overflow-y-auto">
                            <template x-for="dest in trabajadoresDestino()" :key="dest.usuario.id">
                                <li>
                                    <button @click="confirmarReasignar(dest)"
                                            :disabled="modalReasignar.enviando"
                                            class="w-full flex items-center justify-between gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition disabled:opacity-50">
                                        <span class="flex items-center gap-2 min-w-0">
                                            <span x-html="avatarUsuario(dest.usuario)"></span>
                                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="dest.usuario.nombre"></span>
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">
                                            <span x-text="dest.progreso.pendientes + dest.progreso.en_progreso"></span> pendientes
                                        </span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <!-- Textarea motivo -->
                    <div x-show="modalReasignar.habSeleccionada">
                        <label class="block text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Motivo (opcional)</label>
                        <input x-model="modalReasignar.motivo" type="text" maxlength="200"
                               placeholder="Ej: sobrecarga, llegó tarde, etc."
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function homeSupervisora() {
    return {
        data: null,
        alertas: [],
        alertasTotal: 0,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('supervisora_hotel') || 'ambos',
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        modalVerCarga: { abierto: false, trabajador: null, cola: [], cargando: false },
        modalReasignar: { abierto: false, origen: null, habitacionesPendientes: [], habSeleccionada: null, motivo: '', cargando: false, enviando: false },

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos hoteles' },
            { valor: '1_sur', etiqueta: 'Atankalama 1 Sur' },
            { valor: 'inn', etiqueta: 'Atankalama Inn' }
        ],

        get puedeVerAlertas() {
            return !!(this.data && this.data.permisos && this.data.permisos.alertas_recibir_predictivas);
        },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/home/supervisora';
                if (this.hotel && this.hotel !== 'ambos') url += '?hotel=' + encodeURIComponent(this.hotel);
                var rHome = await apiFetch(url);
                if (!rHome || !rHome.ok) {
                    this.error = (rHome && rHome.error && rHome.error.mensaje) || 'Error al cargar.';
                    return;
                }
                this.data = rHome.data;

                if (this.puedeVerAlertas) {
                    var urlA = '/api/alertas/activas';
                    if (this.hotel && this.hotel !== 'ambos') urlA += '?hotel=' + encodeURIComponent(this.hotel);
                    var rA = await apiFetch(urlA);
                    if (rA && rA.ok) {
                        this.alertas = rA.data.top || [];
                        this.alertasTotal = rA.data.total || 0;
                    }
                } else {
                    this.alertas = [];
                    this.alertasTotal = 0;
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
            this._intervalId = setInterval(function () { self.cargar(); }, 60000);
            window.addEventListener('online', function () { self.sinConexion = false; self.cargar(); });
            window.addEventListener('offline', function () { self.sinConexion = true; });
        },

        alVolverVisible() {
            if (!document.hidden) this.cargar();
        },

        async cerrarSesion() {
            try { await fetch('/api/auth/logout', { method: 'POST' }); } catch (e) {}
            window.location.href = '/login';
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('supervisora_hotel', valor);
            this.cargar();
        },

        etiquetaHotel() {
            var op = this.hotelOpciones.find(o => o.valor === this.hotel);
            return op ? op.etiqueta : 'Ambos hoteles';
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Atankalama INN';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        porcentajeGlobal(campo) {
            if (!this.data || !this.data.progreso_global || this.data.progreso_global.total === 0) return 0;
            return Math.round(this.data.progreso_global[campo] * 100 / this.data.progreso_global.total);
        },

        porcentajeTr(tr, campo) {
            if (!tr.progreso || tr.progreso.total === 0) return 0;
            return Math.round(tr.progreso[campo] * 100 / tr.progreso.total);
        },

        claseBadgeEstado(estado) {
            if (estado === 'en_riesgo') return 'bg-red-500 text-white';
            if (estado === 'disponible') return 'bg-blue-400 text-white';
            return 'bg-green-500 text-white';
        },

        etiquetaEstado(estado) {
            if (estado === 'en_riesgo') return 'En riesgo';
            if (estado === 'disponible') return 'Disponible';
            return 'En tiempo';
        },

        etiquetaEstadoHab(estado) {
            var map = {
                'sucia': 'Pendiente',
                'en_progreso': 'En progreso',
                'completada_pendiente_auditoria': 'Por auditar',
                'aprobada': 'Aprobada',
                'aprobada_con_observacion': 'Aprobada c/obs.',
                'rechazada': 'Rechazada'
            };
            return map[estado] || estado;
        },

        avatarUsuario(u) {
            if (!u) return '';
            var nombre = u.nombre || '';
            var inicial = nombre.trim().charAt(0).toUpperCase() || '?';
            var seed = (u.rut || nombre).toString();
            var hash = 0;
            for (var i = 0; i < seed.length; i++) {
                hash = ((hash << 5) - hash) + seed.charCodeAt(i);
                hash |= 0;
            }
            var colores = ['bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-violet-500', 'bg-cyan-500', 'bg-orange-500', 'bg-pink-500'];
            var color = colores[Math.abs(hash) % colores.length];
            return '<span class="w-10 h-10 rounded-full ' + color + ' text-white font-bold flex items-center justify-center flex-shrink-0">' + escapeHtml(inicial) + '</span>';
        },

        // --- Alertas ---

        iconoAlerta(tipo) {
            var map = {
                'cloudbeds_sync_failed': 'refresh-cw-off',
                'trabajador_en_riesgo': 'alert-triangle',
                'habitacion_rechazada': 'x-circle',
                'fin_turno_pendientes': 'clock',
                'trabajador_disponible': 'user-check',
                'ticket_nuevo': 'wrench'
            };
            return map[tipo] || 'bell';
        },

        claseIconoAlerta(tipo) {
            var map = {
                'cloudbeds_sync_failed': 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                'trabajador_en_riesgo': 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'habitacion_rechazada': 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                'fin_turno_pendientes': 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'trabajador_disponible': 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                'ticket_nuevo': 'bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400'
            };
            return map[tipo] || 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        claseBordeAlerta(prioridad) {
            if (prioridad === 0) return 'border-l-4 border-l-red-600';
            if (prioridad === 1) return 'border-l-4 border-l-amber-500';
            return '';
        },

        botonesAlerta(al) {
            var btnPrimario = 'bg-blue-600 hover:bg-blue-700 text-white';
            var btnSecundario = 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100';
            var puedeAsignar = this.data && this.data.permisos && this.data.permisos.asignaciones_asignar_manual;

            if (al.tipo === 'trabajador_en_riesgo' || al.tipo === 'fin_turno_pendientes') {
                var botones = [{ accion: 'ver_carga', etiqueta: 'Ver carga', clase: btnSecundario }];
                if (puedeAsignar) botones.push({ accion: 'reasignar', etiqueta: 'Reasignar', clase: btnPrimario });
                return botones;
            }
            if (al.tipo === 'habitacion_rechazada') {
                var b = [];
                if (puedeAsignar) b.push({ accion: 'reasignar_hab', etiqueta: 'Reasignar', clase: btnPrimario });
                // "Resolver ahora" no implementado en H2d — se hará cuando exista la auditoría express.
                return b;
            }
            if (al.tipo === 'trabajador_disponible') {
                if (puedeAsignar) return [{ accion: 'asignar', etiqueta: 'Asignar habitaciones', clase: btnPrimario }];
                return [];
            }
            if (al.tipo === 'cloudbeds_sync_failed') {
                return [{ accion: 'cloudbeds_retry', etiqueta: 'Reintentar ahora', clase: btnPrimario }];
            }
            if (al.tipo === 'ticket_nuevo') {
                return [{ accion: 'marcar_atendido', etiqueta: 'Marcar atendido', clase: btnPrimario }];
            }
            return [];
        },

        async accionAlerta(al, accion) {
            if (accion === 'ver_carga') {
                var usuarioId = al.contexto && al.contexto.usuario_id;
                var tr = this.data.equipo.find(function (t) { return t.usuario.id === usuarioId; });
                if (tr) this.abrirVerCarga(tr);
                return;
            }
            if (accion === 'reasignar') {
                var usuarioId2 = al.contexto && al.contexto.usuario_id;
                var tr2 = this.data.equipo.find(function (t) { return t.usuario.id === usuarioId2; });
                if (tr2) this.abrirReasignar(tr2);
                return;
            }
            if (accion === 'reasignar_hab') {
                var habId = al.contexto && al.contexto.habitacion_id;
                if (habId) window.location.href = '/habitaciones/' + habId;
                return;
            }
            if (accion === 'asignar') {
                window.location.href = '/asignaciones';
                return;
            }
            // Acciones "genéricas" que resuelven la alerta en bitácora (cloudbeds retry, marcar atendido)
            try {
                var r = await apiPost('/api/alertas/' + al.id + '/accion', { accion: accion });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Acción registrada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos ejecutar la acción.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
        },

        // --- Ver carga ---

        async abrirVerCarga(tr) {
            this.modalVerCarga = { abierto: true, trabajador: tr, cola: [], cargando: true };
            this.$nextTick(function () { lucide.createIcons(); });
            try {
                var r = await apiFetch('/api/usuarios/' + tr.usuario.id + '/cola');
                if (r && r.ok) {
                    this.modalVerCarga.cola = r.data.cola || [];
                }
            } catch (e) {
                // Silencioso — modal ya muestra estado vacío
            } finally {
                this.modalVerCarga.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        cerrarVerCarga() {
            this.modalVerCarga = { abierto: false, trabajador: null, cola: [], cargando: false };
        },

        // --- Reasignar ---

        async abrirReasignar(tr) {
            this.modalReasignar = {
                abierto: true, origen: tr,
                habitacionesPendientes: [], habSeleccionada: null,
                motivo: '', cargando: true, enviando: false
            };
            this.$nextTick(function () { lucide.createIcons(); });
            try {
                var r = await apiFetch('/api/usuarios/' + tr.usuario.id + '/cola');
                if (r && r.ok) {
                    var cola = r.data.cola || [];
                    this.modalReasignar.habitacionesPendientes = cola.filter(function (h) {
                        return h.estado === 'sucia' || h.estado === 'en_progreso' || h.estado === 'rechazada';
                    });
                }
            } catch (e) {
                // ignorar
            } finally {
                this.modalReasignar.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        cerrarReasignar() {
            this.modalReasignar = { abierto: false, origen: null, habitacionesPendientes: [], habSeleccionada: null, motivo: '', cargando: false, enviando: false };
        },

        trabajadoresDestino() {
            if (!this.data || !this.data.equipo) return [];
            var origenId = this.modalReasignar.origen ? this.modalReasignar.origen.usuario.id : null;
            return this.data.equipo
                .filter(function (t) { return t.usuario.id !== origenId; })
                .slice()
                .sort(function (a, b) {
                    var cargaA = a.progreso.pendientes + a.progreso.en_progreso;
                    var cargaB = b.progreso.pendientes + b.progreso.en_progreso;
                    return cargaA - cargaB;
                });
        },

        async confirmarReasignar(dest) {
            if (this.modalReasignar.enviando) return;
            if (!this.modalReasignar.habSeleccionada) return;
            this.modalReasignar.enviando = true;
            try {
                var payload = {
                    habitacion_id: this.modalReasignar.habSeleccionada,
                    usuario_id: dest.usuario.id,
                    fecha: new Date().toISOString().slice(0, 10),
                    motivo: this.modalReasignar.motivo || 'Reasignación desde Home Supervisora'
                };
                var r = await apiPost('/api/asignaciones/reasignar', payload);
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Habitación reasignada a ' + dest.usuario.nombre + '.');
                    this.cerrarReasignar();
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos reasignar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.modalReasignar.enviando = false;
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
