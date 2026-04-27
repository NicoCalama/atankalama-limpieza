<?php
/**
 * Home del Administrador (item 47).
 * Spec: docs/home-admin.md
 *
 * Estructura: 4 tabs (Inicio, Operativas, Técnicas, Ajustes) + header con indicador de estado.
 * Refresco: 60s + visibilitychange + manual.
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

<div x-data="homeAdmin()"
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
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400"><?= htmlspecialchars($saludo) ?></p>
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                              :class="claseIndicador()"
                              :title="'Sistema: ' + (data?.indicador_estado_sistema || 'OK')"></span>
                    </div>
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
                <button @click="$dispatch('toggle-notif')"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 relative"
                        aria-label="Notificaciones">
                    <i data-lucide="bell" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                    <template x-if="$store.notif && $store.notif.sinLeer > 0">
                        <span class="absolute top-1 right-1 min-w-[16px] h-4 bg-blue-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-0.5 leading-none"
                              x-text="$store.notif.sinLeer > 9 ? '9+' : $store.notif.sinLeer"></span>
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
                <p class="text-gray-600 dark:text-gray-400">Cargando dashboard...</p>
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
        <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto">

            <!-- Desktop: grid 2 columnas; móvil: tabs -->
            <div class="md:grid md:grid-cols-2 md:gap-6">

                <!-- ============ TAB INICIO (alertas) ============ -->
                <section x-show="tabActiva === 'inicio' || esDesktop" class="md:col-span-2 space-y-4 mb-4">
                    <template x-if="puedeVerAlertas">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                    <i data-lucide="bell-ring" class="w-4 h-4 text-amber-600 dark:text-amber-400"></i>
                                    Alertas
                                    <span x-show="alertasTotal > 0" class="text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 px-2 py-0.5 rounded-full" x-text="alertasTotal"></span>
                                </h2>
                            </div>

                            <template x-if="alertas.length === 0">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                                    <i data-lucide="check-circle-2" class="w-8 h-8 text-green-500 mx-auto mb-2"></i>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Todo tranquilo. No hay alertas críticas.</p>
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
                                                    <template x-if="al.hotel_codigo">
                                                        <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">Hotel: <span x-text="nombreHotelCorto(al.hotel_codigo)"></span></p>
                                                    </template>
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
                        </div>
                    </template>
                </section>

                <!-- ============ TAB OPERATIVAS ============ -->
                <section x-show="tabActiva === 'operativas' || esDesktop" class="space-y-4">
                    <template x-if="puedeVerKpis">
                        <div class="space-y-4">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                <i data-lucide="bar-chart-3" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                                Operativas del día
                            </h2>

                            <!-- 4 contadores -->
                            <div class="grid grid-cols-2 gap-3">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i data-lucide="bed" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Habitaciones</span>
                                    </div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="metricas.habitaciones.limpias + '/' + metricas.habitaciones.total"></p>
                                    <div class="flex flex-wrap gap-x-2 gap-y-0.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-text="'En progreso: ' + metricas.habitaciones.en_progreso"></span>
                                        <span x-text="'Pendientes: ' + metricas.habitaciones.pendientes"></span>
                                        <span x-show="metricas.habitaciones.no_asignadas > 0" class="text-amber-600 dark:text-amber-400" x-text="'Sin asignar: ' + metricas.habitaciones.no_asignadas"></span>
                                    </div>
                                </div>

                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i data-lucide="clipboard-check" class="w-4 h-4 text-emerald-600 dark:text-emerald-400"></i>
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Auditorías</span>
                                    </div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="metricas.auditorias.total"></p>
                                    <div class="flex flex-wrap gap-x-2 gap-y-0.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="text-green-600 dark:text-green-400" x-text="'OK: ' + metricas.auditorias.aprobadas"></span>
                                        <span class="text-amber-600 dark:text-amber-400" x-text="'Obs: ' + metricas.auditorias.con_observacion"></span>
                                        <span class="text-red-600 dark:text-red-400" x-text="'Rech: ' + metricas.auditorias.rechazadas"></span>
                                    </div>
                                </div>

                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i data-lucide="users" class="w-4 h-4 text-violet-600 dark:text-violet-400"></i>
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Trabajadores</span>
                                    </div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="metricas.trabajadores.en_turno"></p>
                                    <div class="flex flex-wrap gap-x-2 gap-y-0.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        <span>En turno</span>
                                        <span x-show="metricas.trabajadores.disponibles > 0" class="text-blue-600 dark:text-blue-400" x-text="'Disp: ' + metricas.trabajadores.disponibles"></span>
                                    </div>
                                </div>

                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <i data-lucide="wrench" class="w-4 h-4 text-rose-600 dark:text-rose-400"></i>
                                        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Tickets abiertos</span>
                                    </div>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="metricas.tickets_abiertos"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        <template x-if="metricas.tiempo_promedio_minutos !== null">
                                            <span x-text="'Tiempo prom: ' + metricas.tiempo_promedio_minutos + ' min'"></span>
                                        </template>
                                        <template x-if="metricas.tiempo_promedio_minutos === null">
                                            <span>Sin datos de tiempo</span>
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <!-- 3 KPIs con barras multicolor -->
                            <div class="space-y-3">
                                <template x-for="kpi in ['tiempo_promedio', 'tasa_rechazo', 'eficiencia_equipo']" :key="kpi">
                                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <i :data-lucide="iconoKpi(kpi)" class="w-4 h-4 flex-shrink-0" :class="claseIconoKpi(kpisActuales[kpi].estado)"></i>
                                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="etiquetaKpi(kpi)"></span>
                                            </div>
                                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full flex-shrink-0"
                                                  :class="claseBadgeKpi(kpisActuales[kpi].estado)"
                                                  x-text="etiquetaEstadoKpi(kpisActuales[kpi].estado)"></span>
                                        </div>
                                        <div class="flex items-baseline gap-2 mb-2">
                                            <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="valorKpi(kpi)"></span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="'meta ' + metaKpi(kpi)"></span>
                                        </div>
                                        <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                            <div class="h-full transition-all"
                                                 :class="claseBarraKpi(kpisActuales[kpi].estado)"
                                                 :style="'width: ' + porcentajeBarraKpi(kpi) + '%'"></div>
                                        </div>
                                        <template x-if="kpisActuales[kpi].contexto">
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2" x-text="kpisActuales[kpi].contexto"></p>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </section>

                <!-- ============ TAB TÉCNICAS ============ -->
                <section x-show="tabActiva === 'tecnicas' || esDesktop" class="space-y-4">
                    <template x-if="puedeVerSistema && data.sistema">
                        <div class="space-y-3">
                            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                <i data-lucide="activity" class="w-4 h-4 text-emerald-600 dark:text-emerald-400"></i>
                                Salud del sistema
                            </h2>

                            <!-- Cloudbeds -->
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4"
                                 :class="claseBordeSistema(data.sistema.cloudbeds.estado)">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="cloud" class="w-4 h-4" :class="claseIconoSistema(data.sistema.cloudbeds.estado)"></i>
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Cloudbeds</span>
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full" :class="claseBadgeSistema(data.sistema.cloudbeds.estado)" x-text="etiquetaEstadoSistema(data.sistema.cloudbeds.estado)"></span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'Última sync: ' + (data.sistema.cloudbeds.ultima_sync_relativa || 'Nunca')"></p>
                                <template x-if="data.sistema.cloudbeds.error_mensaje">
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1" x-text="data.sistema.cloudbeds.error_mensaje"></p>
                                </template>
                            </div>

                            <!-- Errores/Logs -->
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4"
                                 :class="claseBordeSistema(severidadAEstado(data.sistema.errores_logs.severidad))">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="alert-triangle" class="w-4 h-4" :class="claseIconoSistema(severidadAEstado(data.sistema.errores_logs.severidad))"></i>
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Errores / Warnings</span>
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full"
                                          :class="claseBadgeSistema(severidadAEstado(data.sistema.errores_logs.severidad))"
                                          x-text="etiquetaEstadoSistema(severidadAEstado(data.sistema.errores_logs.severidad))"></span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="data.sistema.errores_logs.errores"></span> errores · <span x-text="data.sistema.errores_logs.warnings"></span> warnings hoy
                                </p>
                                <a href="/ajustes#logs" class="text-xs text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1 mt-1" x-show="data.sistema.errores_logs.cantidad_hoy > 0">
                                    Ver logs <i data-lucide="arrow-right" class="w-3 h-3"></i>
                                </a>
                            </div>

                            <!-- Base de datos -->
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4"
                                 :class="claseBordeSistema(data.sistema.base_datos.estado)">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="database" class="w-4 h-4" :class="claseIconoSistema(data.sistema.base_datos.estado)"></i>
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Base de datos</span>
                                    </div>
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full" :class="claseBadgeSistema(data.sistema.base_datos.estado)" x-text="etiquetaEstadoSistema(data.sistema.base_datos.estado)"></span>
                                </div>
                                <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden mb-1">
                                    <div class="h-full transition-all"
                                         :class="claseBarraSistema(data.sistema.base_datos.estado)"
                                         :style="'width: ' + Math.min(100, data.sistema.base_datos.porcentaje_usado) + '%'"></div>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="(data.sistema.base_datos.tamano_mb ?? '?') + ' MB'"></span> de <span x-text="data.sistema.base_datos.limite_mb + ' MB'"></span>
                                    (<span x-text="data.sistema.base_datos.porcentaje_usado + '%'"></span>)
                                </p>
                            </div>

                            <!-- Usuarios activos -->
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="user-check" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Usuarios activos</span>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="data.sistema.usuarios_activos.ahora"></span>
                                </div>
                                <template x-if="data.sistema.usuarios_activos.listado.length === 0">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Solo tú estás conectado.</p>
                                </template>
                                <template x-if="data.sistema.usuarios_activos.listado.length > 0">
                                    <ul class="space-y-1 mt-1">
                                        <template x-for="u in data.sistema.usuarios_activos.listado.slice(0, 5)" :key="u.usuario_id">
                                            <li class="flex items-center justify-between gap-2 text-xs">
                                                <span class="truncate text-gray-700 dark:text-gray-300" x-text="u.nombre"></span>
                                                <span class="text-gray-500 dark:text-gray-400 flex-shrink-0" x-text="u.roles.join(', ')"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </template>
                            </div>

                            <!-- Versión app -->
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <i data-lucide="git-branch" class="w-4 h-4 text-gray-600 dark:text-gray-400"></i>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Versión</span>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="'v' + data.sistema.version_app.actual"></span>
                                    <template x-if="data.sistema.version_app.commit_hash">
                                        <span x-text="'#' + data.sistema.version_app.commit_hash"></span>
                                    </template>
                                    <span x-text="data.sistema.version_app.ambiente"></span>
                                    <template x-if="data.sistema.version_app.timestamp_deploy">
                                        <span x-text="'Deploy: ' + fechaCorta(data.sistema.version_app.timestamp_deploy)"></span>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="!puedeVerSistema">
                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                            <i data-lucide="lock" class="w-8 h-8 text-gray-400 mx-auto mb-2"></i>
                            <p class="text-sm text-gray-600 dark:text-gray-400">No tienes permiso para ver la salud del sistema.</p>
                        </div>
                    </template>
                </section>

                <!-- ============ TAB AJUSTES (solo móvil) ============ -->
                <section x-show="tabActiva === 'ajustes' && !esDesktop" class="md:col-span-2">
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                        <i data-lucide="settings" class="w-12 h-12 text-gray-400 mx-auto mb-3"></i>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Ajustes del sistema</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Gestiona usuarios, roles, turnos, checklists y más.</p>
                        <a href="/ajustes" class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                            Abrir Ajustes
                            <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </a>
                    </div>
                </section>
            </div>
        </main>
    </template>

    <!-- Bottom tab bar (solo móvil) -->
    <nav x-show="data && !esDesktop" x-cloak class="md:hidden fixed bottom-0 left-0 right-0 z-30 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 pb-[env(safe-area-inset-bottom)]">
        <div class="grid grid-cols-4 max-w-5xl mx-auto">
            <template x-for="t in tabs" :key="t.id">
                <button @click="setTab(t.id)"
                        class="min-h-[56px] flex flex-col items-center justify-center gap-0.5 transition"
                        :class="tabActiva === t.id ? 'text-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100'">
                    <i :data-lucide="t.icono" class="w-5 h-5"></i>
                    <span class="text-[10px] font-medium" x-text="t.etiqueta"></span>
                </button>
            </template>
        </div>
    </nav>

    <!-- FAB copilot lo inyecta layout.php vía views/componentes/fab-copilot.php -->
</div>

<script>
function homeAdmin() {
    return {
        data: null,
        alertas: [],
        alertasTotal: 0,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('admin_hotel') || 'ambos',
        tabActiva: localStorage.getItem('admin_tab') || 'inicio',
        esDesktop: window.matchMedia('(min-width: 768px)').matches,
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos hoteles' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama Inn' }
        ],

        tabs: [
            { id: 'inicio', etiqueta: 'Inicio', icono: 'home' },
            { id: 'operativas', etiqueta: 'Operativas', icono: 'bar-chart-3' },
            { id: 'tecnicas', etiqueta: 'Técnicas', icono: 'activity' },
            { id: 'ajustes', etiqueta: 'Ajustes', icono: 'settings' }
        ],

        get puedeVerAlertas() {
            return !!(this.data && this.data.permisos && this.data.permisos.alertas_recibir_predictivas);
        },
        get puedeVerKpis() {
            return !!(this.data && this.data.permisos && this.data.permisos.kpis_ver_operativas);
        },
        get puedeVerSistema() {
            return !!(this.data && this.data.permisos && this.data.permisos.sistema_ver_salud);
        },
        get metricas() {
            if (!this.data) return null;
            if (this.hotel === 'ambos') return this.data.metricas_operativas.consolidado;
            return this.data.metricas_operativas.por_hotel[this.hotel] || this.data.metricas_operativas.consolidado;
        },
        get kpisActuales() {
            if (!this.data) return null;
            if (this.hotel === 'ambos') return this.data.kpis.consolidado;
            return this.data.kpis.por_hotel[this.hotel] || this.data.kpis.consolidado;
        },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/home/admin';
                if (this.hotel && this.hotel !== 'ambos') url += '?hotel=' + encodeURIComponent(this.hotel);
                var r = await apiFetch(url);
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'Error al cargar.';
                    return;
                }
                this.data = r.data;
                this.alertas = this.data.alertas || [];
                this.alertasTotal = this.data.alertas_total || 0;
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
            window.addEventListener('resize', function () {
                self.esDesktop = window.matchMedia('(min-width: 768px)').matches;
            });
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
            localStorage.setItem('admin_hotel', valor);
            this.cargar();
        },

        setTab(id) {
            this.tabActiva = id;
            localStorage.setItem('admin_tab', id);
            this.$nextTick(function () { lucide.createIcons(); });
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

        fechaCorta(iso) {
            try {
                var d = new Date(iso);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                return dd + '/' + mm;
            } catch (e) { return iso; }
        },

        // --- Indicador global ---

        claseIndicador() {
            var e = this.data && this.data.indicador_estado_sistema;
            if (e === 'ERROR') return 'bg-red-500';
            if (e === 'ALERTA') return 'bg-amber-500';
            return 'bg-green-500';
        },

        // --- KPIs ---

        etiquetaKpi(kpi) {
            if (kpi === 'tiempo_promedio') return 'Tiempo promedio por habitación';
            if (kpi === 'tasa_rechazo') return 'Tasa de rechazo';
            if (kpi === 'eficiencia_equipo') return 'Eficiencia del equipo';
            return kpi;
        },

        iconoKpi(kpi) {
            if (kpi === 'tiempo_promedio') return 'clock';
            if (kpi === 'tasa_rechazo') return 'x-circle';
            if (kpi === 'eficiencia_equipo') return 'trending-up';
            return 'bar-chart-3';
        },

        claseIconoKpi(estado) {
            if (estado === 'OK') return 'text-green-600 dark:text-green-400';
            if (estado === 'ALERTA') return 'text-amber-600 dark:text-amber-400';
            if (estado === 'CRITICO') return 'text-red-600 dark:text-red-400';
            return 'text-gray-500 dark:text-gray-400';
        },

        claseBadgeKpi(estado) {
            if (estado === 'OK') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            if (estado === 'ALERTA') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (estado === 'CRITICO') return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        claseBarraKpi(estado) {
            if (estado === 'OK') return 'bg-green-500';
            if (estado === 'ALERTA') return 'bg-amber-500';
            if (estado === 'CRITICO') return 'bg-red-500';
            return 'bg-gray-400';
        },

        etiquetaEstadoKpi(estado) {
            if (estado === 'OK') return 'OK';
            if (estado === 'ALERTA') return 'Alerta';
            if (estado === 'CRITICO') return 'Crítico';
            return 'Sin datos';
        },

        valorKpi(kpi) {
            var k = this.kpisActuales && this.kpisActuales[kpi];
            if (!k || k.valor === null || k.valor === undefined) return '—';
            return k.valor + ' ' + (k.unidad || '');
        },

        metaKpi(kpi) {
            var k = this.kpisActuales && this.kpisActuales[kpi];
            if (!k) return '';
            if (kpi === 'tiempo_promedio') return '≤ ' + k.meta + ' ' + k.unidad;
            if (kpi === 'tasa_rechazo') return '≤ ' + k.meta + k.unidad;
            return '≥ ' + k.meta + k.unidad;
        },

        porcentajeBarraKpi(kpi) {
            var k = this.kpisActuales && this.kpisActuales[kpi];
            if (!k) return 0;
            if (kpi === 'tiempo_promedio') return k.porcentaje || 0;
            if (kpi === 'tasa_rechazo') {
                if (k.valor === null || k.valor === undefined) return 0;
                return Math.min(100, Math.round(k.valor * 10));
            }
            if (kpi === 'eficiencia_equipo') return Math.min(100, k.valor || 0);
            return 0;
        },

        // --- Sistema ---

        severidadAEstado(sev) {
            if (sev === 'alta') return 'ERROR';
            if (sev === 'media') return 'ALERTA';
            return 'OK';
        },

        claseIconoSistema(estado) {
            if (estado === 'ERROR' || estado === 'CRITICO') return 'text-red-600 dark:text-red-400';
            if (estado === 'ALERTA') return 'text-amber-600 dark:text-amber-400';
            return 'text-green-600 dark:text-green-400';
        },

        claseBadgeSistema(estado) {
            if (estado === 'ERROR' || estado === 'CRITICO') return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            if (estado === 'ALERTA') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
        },

        claseBordeSistema(estado) {
            if (estado === 'ERROR' || estado === 'CRITICO') return 'border-l-4 border-l-red-600';
            if (estado === 'ALERTA') return 'border-l-4 border-l-amber-500';
            return '';
        },

        claseBarraSistema(estado) {
            if (estado === 'ERROR' || estado === 'CRITICO') return 'bg-red-500';
            if (estado === 'ALERTA') return 'bg-amber-500';
            return 'bg-green-500';
        },

        etiquetaEstadoSistema(estado) {
            if (estado === 'OK') return 'OK';
            if (estado === 'ALERTA') return 'Alerta';
            if (estado === 'ERROR') return 'Error';
            if (estado === 'CRITICO') return 'Crítico';
            return estado;
        },

        // --- Alertas (reutilizadas de supervisora) ---

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

            if (al.tipo === 'cloudbeds_sync_failed') {
                return [{ accion: 'cloudbeds_retry', etiqueta: 'Reintentar ahora', clase: btnPrimario }];
            }
            if (al.tipo === 'trabajador_en_riesgo' || al.tipo === 'fin_turno_pendientes') {
                var botones = [{ accion: 'ir_asignaciones', etiqueta: 'Ver asignaciones', clase: btnSecundario }];
                if (puedeAsignar) botones.push({ accion: 'ir_asignaciones', etiqueta: 'Reasignar', clase: btnPrimario });
                return botones;
            }
            if (al.tipo === 'habitacion_rechazada') {
                var b = [];
                if (al.contexto && al.contexto.habitacion_id) {
                    b.push({ accion: 'ir_habitacion', etiqueta: 'Ver habitación', clase: btnPrimario });
                }
                return b;
            }
            if (al.tipo === 'trabajador_disponible') {
                if (puedeAsignar) return [{ accion: 'ir_asignaciones', etiqueta: 'Asignar', clase: btnPrimario }];
                return [];
            }
            if (al.tipo === 'ticket_nuevo') {
                return [{ accion: 'marcar_atendido', etiqueta: 'Marcar atendido', clase: btnPrimario }];
            }
            return [];
        },

        async accionAlerta(al, accion) {
            if (accion === 'ir_asignaciones') {
                window.location.href = '/asignaciones';
                return;
            }
            if (accion === 'ir_habitacion') {
                var habId = al.contexto && al.contexto.habitacion_id;
                if (habId) window.location.href = '/habitaciones/' + habId;
                return;
            }
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

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
