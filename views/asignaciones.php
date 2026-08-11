<?php
/**
 * Vista de Asignaciones.
 * Spec: docs/home-supervisora.md (flujo de asignación y reasignación)
 *
 * DOS MODOS de asignación, elegibles con un interruptor (recordado por dispositivo
 * en localStorage['asignaciones_modo'], default 'tablero'):
 *
 *  · TABLERO (default) — arrastrar-y-soltar. Dos columnas: izquierda = Equipo
 *    (trabajadores, drop zones), derecha = Sin asignar (pool). Se arrastran las
 *    piezas (una o varias) al trabajador para asignar, de un trabajador a otro para
 *    reasignar, de vuelta al pool o con la X para quitar, y dentro del cuadro para
 *    reordenar. Motor con pointer events (mouse + dedo): public/assets/js/drag-asignaciones.js.
 *
 *  · CLÁSICO — el flujo de siempre (chips + modal). Es el respaldo (sobre todo en
 *    teléfono) y queda intacto. No lleva anclas data-tour (las lleva el tablero).
 *
 * Endpoints (ninguno escribe a Cloudbeds):
 *  - GET  /api/asignaciones/vista?hotel=&fecha=
 *  - POST /api/asignaciones            { habitacion_ids, usuario_id, fecha }
 *  - POST /api/asignaciones/auto       { hotel, fecha }
 *  - POST /api/asignaciones/reasignar  { habitacion_id, usuario_id, fecha, motivo }
 *  - POST /api/asignaciones/desasignar { habitacion_id, fecha }
 *  - PUT  /api/asignaciones/orden      { usuario_id, fecha, orden: [habitacion_id...] }
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';
?>

<div x-data="asignacionesApp()"
     x-init="cargar(); iniciarRefresco();"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-7xl mx-auto gap-3">
            <div class="flex items-center gap-2 min-w-0">
                <a href="<?= u('/home') ?>" aria-label="Volver"
                   class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </a>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">Asignaciones</p>
                    <div class="relative" data-tour="asig.hotel" x-data="{ abierto: false }" @click.outside="abierto = false">
                        <button @click="abierto = !abierto"
                                class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 inline-flex items-center gap-1">
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
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
            </div>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet.
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
                <p class="text-gray-600 dark:text-gray-400">Cargando...</p>
            </div>
        </div>
    </template>

    <!-- Error -->
    <template x-if="error && !data">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Error al cargar</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error"></p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <template x-if="data">
        <div>

            <!-- Barra de modo (compartida por ambos modos) -->
            <div class="px-3 md:px-4 pt-3 max-w-7xl mx-auto flex items-center justify-between gap-2">
                <div class="inline-flex items-center rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-0.5">
                    <button @click="setModo('tablero')"
                            :class="modo === 'tablero' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' : 'text-gray-500 dark:text-gray-400'"
                            class="min-h-[36px] px-3 text-sm font-medium rounded-md inline-flex items-center gap-1.5 transition">
                        <i data-lucide="layout-grid" class="w-4 h-4"></i> Tablero
                    </button>
                    <button @click="setModo('clasico')"
                            :class="modo === 'clasico' ? 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm' : 'text-gray-500 dark:text-gray-400'"
                            class="min-h-[36px] px-3 text-sm font-medium rounded-md inline-flex items-center gap-1.5 transition">
                        <i data-lucide="list" class="w-4 h-4"></i> Clásico
                    </button>
                </div>
                <p x-show="esTactil && modo === 'tablero'" class="text-[11px] text-gray-500 dark:text-gray-400 text-right">
                    ¿Te cuesta arrastrar? Usa «Clásico».
                </p>
            </div>

            <!-- ═══════════════════════════ MODO TABLERO ═══════════════════════════ -->
            <template x-if="modo === 'tablero'">
                <main class="px-3 md:px-4 py-4 max-w-7xl mx-auto pb-24 md:pb-8">
                    <div class="grid grid-cols-1 md:grid-cols-[1fr_20rem] lg:grid-cols-[1fr_24rem] gap-4 items-start">

                        <!-- IZQUIERDA: Equipo (drop zones). En móvil va debajo del pool. -->
                        <section data-tour="asig.equipo" class="order-2 md:order-1">
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                    <i data-lucide="users" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                                    Equipo del día
                                    <span class="text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200 px-2 py-0.5 rounded-full"
                                          x-text="data.trabajadores.length"></span>
                                </h2>
                            </div>

                            <template x-if="data.trabajadores.length === 0">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                                    <i data-lucide="user-x" class="w-8 h-8 text-gray-400 mx-auto mb-2"></i>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">No hay trabajadores con turno hoy en este hotel.</p>
                                </div>
                            </template>

                            <template x-if="data.trabajadores.length > 0">
                                <div class="space-y-2">
                                    <template x-for="tr in data.trabajadores" :key="tr.usuario.id">
                                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 transition"
                                             data-drop="worker" :data-worker-id="tr.usuario.id">
                                            <div class="flex items-start gap-3 mb-3">
                                                <span x-html="avatarUsuario(tr.usuario)"></span>
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="tr.usuario.nombre"></p>
                                                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                                        <span x-text="tr.progreso.completadas"></span>/<span x-text="tr.progreso.total"></span> completadas ·
                                                        <span x-text="tr.progreso.en_progreso + tr.progreso.pendientes"></span> pendientes
                                                        <template x-if="tr.progreso.rechazadas > 0">
                                                            <span class="text-red-600 dark:text-red-400"> · <span x-text="tr.progreso.rechazadas"></span> rechazadas</span>
                                                        </template>
                                                    </p>
                                                </div>
                                            </div>

                                            <template x-if="tr.cola.length === 0">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 italic border border-dashed border-gray-300 dark:border-gray-600 rounded-lg py-3 text-center">
                                                    Arrastra piezas acá.
                                                </p>
                                            </template>

                                            <ul data-cola class="space-y-1.5" x-show="tr.cola.length > 0">
                                                <template x-for="hab in tr.cola" :key="hab.habitacion_id">
                                                    <li data-room-slot class="flex items-center gap-1.5">
                                                        <div class="flex-1 flex items-center gap-2 min-w-0 px-2.5 py-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg select-none"
                                                             :class="esReasignable(hab.estado) ? '' : 'opacity-60'"
                                                             x-bind:data-drag-room="esReasignable(hab.estado) ? '' : null"
                                                             :data-room-id="hab.habitacion_id"
                                                             :data-room-estado="hab.estado"
                                                             data-room-origin="worker"
                                                             :data-worker-id="tr.usuario.id"
                                                             @pointerdown="iniciarDrag($event)">
                                                            <span class="font-semibold text-sm text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                                                            <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="hab.tipo_nombre"></span>
                                                            <span class="text-[10px] px-1.5 py-0.5 rounded"
                                                                  :class="claseBadgeHab(hab.estado)"
                                                                  x-text="etiquetaEstadoHab(hab.estado)"></span>
                                                            <template x-if="hab.franja">
                                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 capitalize"
                                                                      x-text="hab.franja"></span>
                                                            </template>
                                                        </div>
                                                        <template x-if="esReasignable(hab.estado)">
                                                            <button @click="_dropEnPool([hab.habitacion_id], 'worker')"
                                                                    title="Quitar (dejar sin asignar)" aria-label="Quitar asignación"
                                                                    class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg
                                                                           border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400
                                                                           hover:bg-red-50 dark:hover:bg-red-900/20 transition flex-shrink-0">
                                                                <i data-lucide="x" class="w-4 h-4"></i>
                                                            </button>
                                                        </template>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </section>

                        <!-- DERECHA: Sin asignar (pool). Sticky en desktop, arriba en móvil. -->
                        <section data-tour="asig.sin-asignar" class="order-1 md:order-2 md:sticky md:top-[76px]">
                            <div class="flex items-center justify-between mb-2 gap-2">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                    <i data-lucide="clipboard-list" class="w-4 h-4 text-rose-600 dark:text-rose-400"></i>
                                    Sin asignar
                                    <span class="text-xs bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-200 px-2 py-0.5 rounded-full"
                                          x-text="data.sin_asignar.length + ((data.re_limpiar && data.re_limpiar.length) ? data.re_limpiar.length : 0)"></span>
                                </h2>
                                <template x-if="puedeAutoAsignar && data.sin_asignar.length > 0 && data.trabajadores.length > 0">
                                    <button @click="autoAsignar()" :disabled="autoEjecutando" data-tour="asig.auto"
                                            class="min-h-[36px] px-3 py-1.5 text-sm font-medium rounded-lg bg-violet-600 hover:bg-violet-700 text-white transition inline-flex items-center gap-1.5 disabled:opacity-50 flex-shrink-0">
                                        <i data-lucide="shuffle" class="w-4 h-4"></i>
                                        <span x-text="autoEjecutando ? 'Asignando...' : 'Auto'"></span>
                                    </button>
                                </template>
                            </div>

                            <div data-drop="pool"
                                 class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 space-y-3 min-h-[80px] md:max-h-[calc(100vh-8rem)] md:overflow-y-auto scrollbar-thin">

                                <template x-if="data.sin_asignar.length === 0 && (!data.re_limpiar || data.re_limpiar.length === 0)">
                                    <div class="text-center py-4">
                                        <i data-lucide="check-circle-2" class="w-8 h-8 text-green-500 mx-auto mb-2"></i>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Todo asignado. Arrastra acá para quitar.</p>
                                    </div>
                                </template>

                                <!-- Sin asignar, agrupadas por hotel -->
                                <template x-for="grupo in sinAsignarPorHotel()" :key="grupo.hotel_codigo">
                                    <div>
                                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2"
                                           x-text="grupo.hotel_nombre + ' · ' + grupo.habitaciones.length"></p>
                                        <div class="flex flex-wrap gap-2">
                                            <template x-for="hab in grupo.habitaciones" :key="hab.id">
                                                <div class="min-h-[40px] px-3 py-1.5 text-sm font-semibold rounded-lg border inline-flex items-center gap-1.5 select-none transition"
                                                     :class="seleccionadas.includes(hab.id) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600'"
                                                     data-drag-room :data-room-id="hab.id" data-room-estado="sucia" data-room-origin="pool"
                                                     @pointerdown="iniciarDrag($event)">
                                                    <span x-text="hab.numero"></span>
                                                    <span class="text-[10px] font-normal opacity-75" x-text="hab.tipo_nombre"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <!-- Volver a limpiar (2ª limpieza del día) -->
                                <template x-if="data.re_limpiar && data.re_limpiar.length > 0">
                                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                                        <p class="text-xs font-semibold text-teal-700 dark:text-teal-300 inline-flex items-center gap-1 mb-1">
                                            <i data-lucide="rotate-cw" class="w-3.5 h-3.5"></i> Volver a limpiar
                                        </p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-2">Al asignarlas se re-abren y la limpieza arranca de cero.</p>
                                        <template x-for="grupo in reLimpiarPorHotel()" :key="grupo.hotel_codigo">
                                            <div class="mb-2">
                                                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2"
                                                   x-text="grupo.hotel_nombre + ' · ' + grupo.habitaciones.length"></p>
                                                <div class="flex flex-wrap gap-2">
                                                    <template x-for="hab in grupo.habitaciones" :key="hab.id">
                                                        <div class="min-h-[40px] px-3 py-1.5 text-sm font-semibold rounded-lg border border-teal-300 dark:border-teal-700 inline-flex items-center gap-1.5 select-none transition"
                                                             :class="seleccionadas.includes(hab.id) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100'"
                                                             data-drag-room :data-room-id="hab.id" data-room-estado="sucia" data-room-origin="pool"
                                                             @pointerdown="iniciarDrag($event)">
                                                            <span x-text="hab.numero"></span>
                                                            <span class="text-[10px] font-normal opacity-75" x-text="hab.tipo_nombre"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-2 px-1">
                                Toca para elegir varias; arrastra al trabajador para asignar. Arrastra de vuelta acá (o la X) para quitar.
                            </p>
                        </section>

                    </div>
                </main>
            </template>

            <!-- ═══════════════════════════ MODO CLÁSICO (respaldo) ═══════════════════════════ -->
            <!-- Flujo de siempre (chips + modal). Sin anclas data-tour (las lleva el tablero). -->
            <template x-if="modo === 'clasico'">
                <div>
                    <main class="pb-32 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-6">

                        <!-- Sección: Sin asignar -->
                        <section>
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                    <i data-lucide="clipboard-list" class="w-4 h-4 text-rose-600 dark:text-rose-400"></i>
                                    Sin asignar
                                    <span class="text-xs bg-rose-100 dark:bg-rose-900/40 text-rose-800 dark:text-rose-200 px-2 py-0.5 rounded-full"
                                          x-text="data.sin_asignar.length"></span>
                                </h2>
                                <template x-if="puedeAutoAsignar && data.sin_asignar.length > 0 && data.trabajadores.length > 0">
                                    <button @click="autoAsignar()" :disabled="autoEjecutando"
                                            class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-violet-600 hover:bg-violet-700 text-white transition inline-flex items-center gap-1.5 disabled:opacity-50">
                                        <i data-lucide="shuffle" class="w-4 h-4"></i>
                                        <span x-text="autoEjecutando ? 'Asignando...' : 'Auto-asignar'"></span>
                                    </button>
                                </template>
                            </div>

                            <template x-if="data.sin_asignar.length === 0">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                                    <i data-lucide="check-circle-2" class="w-8 h-8 text-green-500 mx-auto mb-2"></i>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Todas las habitaciones sucias están asignadas.</p>
                                </div>
                            </template>

                            <template x-if="data.sin_asignar.length > 0">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 space-y-3">
                                    <template x-for="grupo in sinAsignarPorHotel()" :key="grupo.hotel_codigo">
                                        <div>
                                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2"
                                               x-text="grupo.hotel_nombre + ' · ' + grupo.habitaciones.length"></p>
                                            <div class="flex flex-wrap gap-2">
                                                <template x-for="hab in grupo.habitaciones" :key="hab.id">
                                                    <button @click="toggleSeleccion(hab.id)"
                                                            :class="seleccionadas.includes(hab.id) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600'"
                                                            class="min-h-[40px] px-3 py-1.5 text-sm font-semibold rounded-lg border transition inline-flex items-center gap-1.5">
                                                        <span x-text="hab.numero"></span>
                                                        <span class="text-[10px] font-normal opacity-75" x-text="hab.tipo_nombre"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </section>

                        <!-- Sección: Volver a limpiar (2ª limpieza del día — ocupación día/noche) -->
                        <template x-if="data.re_limpiar && data.re_limpiar.length > 0">
                            <section>
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2 mb-1">
                                    <i data-lucide="rotate-cw" class="w-4 h-4 text-teal-600 dark:text-teal-400"></i>
                                    Volver a limpiar
                                    <span class="text-xs bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 px-2 py-0.5 rounded-full"
                                          x-text="data.re_limpiar.length"></span>
                                </h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Piezas ya limpias hoy que necesitan otra pasada (ocupación de día/noche). Al asignarlas se re-abren y la limpieza arranca de cero.</p>
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 space-y-3">
                                    <template x-for="grupo in reLimpiarPorHotel()" :key="grupo.hotel_codigo">
                                        <div>
                                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2"
                                               x-text="grupo.hotel_nombre + ' · ' + grupo.habitaciones.length"></p>
                                            <div class="flex flex-wrap gap-2">
                                                <template x-for="hab in grupo.habitaciones" :key="hab.id">
                                                    <button @click="toggleSeleccion(hab.id)"
                                                            :class="seleccionadas.includes(hab.id) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border-gray-300 dark:border-gray-600'"
                                                            class="min-h-[40px] px-3 py-1.5 text-sm font-semibold rounded-lg border transition inline-flex items-center gap-1.5">
                                                        <span x-text="hab.numero"></span>
                                                        <span class="text-[10px] font-normal opacity-75" x-text="hab.tipo_nombre"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </section>
                        </template>

                        <!-- Sección: Equipo -->
                        <section>
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 inline-flex items-center gap-2">
                                    <i data-lucide="users" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
                                    Equipo del día
                                    <span class="text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200 px-2 py-0.5 rounded-full"
                                          x-text="data.trabajadores.length"></span>
                                </h2>
                            </div>

                            <template x-if="data.trabajadores.length === 0">
                                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-5 text-center">
                                    <i data-lucide="user-x" class="w-8 h-8 text-gray-400 mx-auto mb-2"></i>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">No hay trabajadores con turno hoy en este hotel.</p>
                                </div>
                            </template>

                            <template x-if="data.trabajadores.length > 0">
                                <div class="space-y-2">
                                    <template x-for="tr in data.trabajadores" :key="tr.usuario.id">
                                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                                            <div class="flex items-start gap-3 mb-3">
                                                <span x-html="avatarUsuario(tr.usuario)"></span>
                                                <div class="flex-1 min-w-0">
                                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="tr.usuario.nombre"></p>
                                                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                                        <span x-text="tr.progreso.completadas"></span>/<span x-text="tr.progreso.total"></span> completadas ·
                                                        <span x-text="tr.progreso.en_progreso + tr.progreso.pendientes"></span> pendientes
                                                        <template x-if="tr.progreso.rechazadas > 0">
                                                            <span class="text-red-600 dark:text-red-400"> · <span x-text="tr.progreso.rechazadas"></span> rechazadas</span>
                                                        </template>
                                                    </p>
                                                </div>
                                            </div>

                                            <template x-if="tr.cola.length === 0">
                                                <p class="text-xs text-gray-500 dark:text-gray-400 italic">Sin habitaciones asignadas.</p>
                                            </template>

                                            <template x-if="tr.cola.length > 0">
                                                <ul class="space-y-1.5">
                                                    <template x-for="hab in tr.cola" :key="hab.habitacion_id">
                                                        <li class="flex items-center justify-between gap-2 px-2.5 py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                                            <div class="flex items-center gap-2 min-w-0">
                                                                <span class="font-semibold text-sm text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                                                                <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="hab.tipo_nombre"></span>
                                                                <span class="text-[10px] px-1.5 py-0.5 rounded"
                                                                      :class="claseBadgeHab(hab.estado)"
                                                                      x-text="etiquetaEstadoHab(hab.estado)"></span>
                                                                <template x-if="hab.franja">
                                                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 capitalize"
                                                                          x-text="hab.franja"></span>
                                                                </template>
                                                            </div>
                                                            <template x-if="esReasignable(hab.estado)">
                                                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                                                    <button @click="abrirReasignar(tr, hab)"
                                                                            class="min-h-[36px] px-2.5 py-1 text-xs font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition">
                                                                        Reasignar
                                                                    </button>
                                                                    <button @click="desasignar(tr, hab)"
                                                                            title="Desasignar"
                                                                            aria-label="Desasignar"
                                                                            class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg
                                                                                   border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400
                                                                                   hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                                                        <i data-lucide="user-minus" class="w-4 h-4"></i>
                                                                    </button>
                                                                </div>
                                                            </template>
                                                        </li>
                                                    </template>
                                                </ul>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                        </section>
                    </main>

                    <!-- Barra flotante inferior: asignar seleccionadas -->
                    <div x-show="seleccionadas.length > 0" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-4"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 px-4 py-3 shadow-lg">
                        <div class="max-w-5xl mx-auto flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    <span x-text="seleccionadas.length"></span> <span x-text="seleccionadas.length === 1 ? 'habitación' : 'habitaciones'"></span> seleccionada<span x-show="seleccionadas.length !== 1">s</span>
                                </p>
                                <button @click="limpiarSeleccion()" class="text-xs text-gray-500 dark:text-gray-400 hover:underline">Limpiar</button>
                            </div>
                            <button @click="abrirAsignar()"
                                    class="min-h-[44px] px-4 py-2 text-sm font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition inline-flex items-center gap-2">
                                <i data-lucide="user-plus" class="w-4 h-4"></i>
                                Asignar a...
                            </button>
                        </div>
                    </div>

                    <!-- Modal: elegir trabajador para asignar seleccionadas -->
                    <div x-show="modalAsignar.abierto" x-cloak
                         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
                         @click.self="cerrarAsignar()">
                        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Asignar <span x-text="seleccionadas.length"></span> <span x-text="seleccionadas.length === 1 ? 'habitación' : 'habitaciones'"></span></h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Ordenado por menor carga.</p>
                                </div>
                                <button @click="cerrarAsignar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                                </button>
                            </div>
                            <template x-if="data && data.trabajadores.length === 0">
                                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No hay trabajadores con turno hoy.</p>
                            </template>
                            <template x-if="data && data.trabajadores.length > 0">
                                <div>
                                    <div class="mb-3">
                                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1.5">Franja (opcional)</p>
                                        <div class="flex gap-2">
                                            <template x-for="f in ['mañana','tarde','noche']" :key="f">
                                                <button @click="modalAsignar.franja = (modalAsignar.franja === f ? null : f)"
                                                        :class="modalAsignar.franja === f ? 'bg-teal-600 text-white border-teal-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                                        class="flex-1 min-h-[40px] px-2 py-1.5 text-sm font-medium rounded-lg border capitalize transition"
                                                        x-text="f"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <input x-model="modalAsignar.busqueda" type="text"
                                           placeholder="Buscar trabajador por nombre..."
                                           class="w-full mb-3 px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                                    <template x-if="trabajadoresFiltrados().length === 0">
                                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Sin coincidencias.</p>
                                    </template>
                                    <ul class="space-y-1.5 max-h-[55vh] overflow-y-auto" x-show="trabajadoresFiltrados().length > 0">
                                        <template x-for="tr in trabajadoresFiltrados()" :key="tr.usuario.id">
                                            <li>
                                                <button @click="confirmarAsignar(tr)"
                                                        :disabled="modalAsignar.enviando"
                                                        class="w-full flex items-center justify-between gap-3 px-3 py-2 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition disabled:opacity-50">
                                                    <span class="flex items-center gap-2 min-w-0">
                                                        <span x-html="avatarUsuario(tr.usuario)"></span>
                                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="tr.usuario.nombre"></span>
                                                    </span>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">
                                                        <span x-text="tr.progreso.pendientes + tr.progreso.en_progreso"></span> pendientes
                                                    </span>
                                                </button>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Modal: reasignar individual -->
                    <div x-show="modalReasignar.abierto" x-cloak
                         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
                         @click.self="cerrarReasignar()">
                        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[85vh] overflow-y-auto">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        Reasignar hab. <span x-text="modalReasignar.habitacion?.numero"></span>
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Actualmente: <span x-text="modalReasignar.origen?.usuario?.nombre"></span>
                                    </p>
                                </div>
                                <button @click="cerrarReasignar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                                </button>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">Enviar a (ordenado por menor carga)</p>
                                    <ul class="space-y-1.5 max-h-60 overflow-y-auto">
                                        <template x-for="dest in destinosReasignar()" :key="dest.usuario.id">
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
                                <div>
                                    <label class="block text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Motivo (opcional)</label>
                                    <input x-model="modalReasignar.motivo" type="text" maxlength="200"
                                           placeholder="Ej: sobrecarga, llegó tarde, etc."
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </template>
</div>

<!-- Motor de arrastre del tablero (pointer events). Solo se carga en esta pantalla. -->
<script src="<?= u('/assets/js/drag-asignaciones.js') ?>?v=<?= @filemtime(__DIR__ . '/../public/assets/js/drag-asignaciones.js') ?: '1' ?>"></script>
<script>
function asignacionesApp() {
    return {
        // Mixin del motor de arrastre (aporta iniciarDrag; usa this._dropEnWorker/…).
        ...(window.dragAsignaciones || {}),

        data: null,
        cargando: false,
        error: null,
        sinConexion: !navigator.onLine,
        hotel: localStorage.getItem('asignaciones_hotel') || 'ambos',
        modo: localStorage.getItem('asignaciones_modo') || 'tablero',
        esTactil: !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches),
        seleccionadas: [],
        autoEjecutando: false,
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        modalAsignar: { abierto: false, enviando: false, busqueda: '', franja: null },
        modalReasignar: { abierto: false, origen: null, habitacion: null, motivo: '', enviando: false },

        hotelOpciones: [
            { valor: 'ambos', etiqueta: 'Ambos hoteles' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama Inn' }
        ],

        get puedeAutoAsignar() {
            var a = Alpine.store('auth');
            return !!(a && typeof a.tienePermiso === 'function' && a.tienePermiso('asignaciones.auto_asignar'));
        },

        get fechaHoy() {
            return window.hoyServidor();
        },

        async cargar() {
            this.cargando = true;
            this.error = null;
            try {
                var url = '/api/asignaciones/vista?fecha=' + encodeURIComponent(this.fechaHoy);
                if (this.hotel && this.hotel !== 'ambos') url += '&hotel=' + encodeURIComponent(this.hotel);
                var r = await apiFetch(url);
                if (!r || !r.ok) {
                    this.error = (r && r.error && r.error.mensaje) || 'Error al cargar.';
                    return;
                }
                this.data = r.data;
                // Limpiar seleccionadas que ya no existan en sin_asignar o volver-a-limpiar
                var idsDisponibles = this.data.sin_asignar.map(function (h) { return h.id; })
                    .concat((this.data.re_limpiar || []).map(function (h) { return h.id; }));
                this.seleccionadas = this.seleccionadas.filter(function (id) { return idsDisponibles.includes(id); });
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

        setModo(m) {
            this.modo = m;
            localStorage.setItem('asignaciones_modo', m);
            this.seleccionadas = [];
            this.$nextTick(function () { lucide.createIcons(); });
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('asignaciones_hotel', valor);
            this.seleccionadas = [];
            this.cargar();
        },

        etiquetaHotel() {
            var op = this.hotelOpciones.find(o => o.valor === this.hotel);
            return op ? op.etiqueta : 'Ambos hoteles';
        },

        sinAsignarPorHotel() {
            if (!this.data) return [];
            var grupos = {};
            this.data.sin_asignar.forEach(function (h) {
                var key = h.hotel_codigo;
                if (!grupos[key]) {
                    grupos[key] = { hotel_codigo: key, hotel_nombre: h.hotel_nombre, habitaciones: [] };
                }
                grupos[key].habitaciones.push(h);
            });
            return Object.values(grupos);
        },

        reLimpiarPorHotel() {
            if (!this.data || !this.data.re_limpiar) return [];
            var grupos = {};
            this.data.re_limpiar.forEach(function (h) {
                var key = h.hotel_codigo;
                if (!grupos[key]) {
                    grupos[key] = { hotel_codigo: key, hotel_nombre: h.hotel_nombre, habitaciones: [] };
                }
                grupos[key].habitaciones.push(h);
            });
            return Object.values(grupos);
        },

        // --- Selección (chips clásicos y toque de tarjeta en el tablero) ---

        toggleSeleccion(id) {
            var idx = this.seleccionadas.indexOf(id);
            if (idx === -1) this.seleccionadas.push(id);
            else this.seleccionadas.splice(idx, 1);
        },

        limpiarSeleccion() {
            this.seleccionadas = [];
        },

        // ═══════════════ TABLERO: gestos → endpoints (optimista + reconcilia) ═══════════════

        // Soltar en un trabajador: desde el pool = asignar en lote; desde otro
        // trabajador = reasignar la pieza arrastrada.
        async _dropEnWorker(ids, workerId, origen) {
            var self = this;
            var destino = this.data.trabajadores.find(function (t) { return t.usuario.id === workerId; });
            if (!destino) return;

            if (origen === 'worker') {
                var rid = ids[0];
                var origenTr = this.data.trabajadores.find(function (t) {
                    return t.cola.some(function (h) { return h.habitacion_id === rid; });
                });
                if (!origenTr || origenTr.usuario.id === workerId) return;
                var hab = origenTr.cola.find(function (h) { return h.habitacion_id === rid; });
                if (hab && hab.estado === 'en_progreso') {
                    if (!confirm('La habitación ' + hab.numero + ' está en progreso. Si la mueves, se reinicia y el trabajador pierde lo avanzado. ¿Continuar?')) return;
                }
                // Optimista: mover de una cola a la otra.
                origenTr.cola = origenTr.cola.filter(function (h) { return h.habitacion_id !== rid; });
                if (hab) destino.cola.push(hab);
                this.$nextTick(function () { lucide.createIcons(); });
                try {
                    var r = await apiPost('/api/asignaciones/reasignar', {
                        habitacion_id: rid, usuario_id: workerId, fecha: this.fechaHoy, motivo: 'Reasignación manual'
                    });
                    if (r && r.ok) this.mostrarToast('exito', 'Habitación ' + (hab ? hab.numero : '') + ' movida a ' + destino.usuario.nombre + '.');
                    else this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos mover la habitación.');
                } catch (e) {
                    this.mostrarToast('error', 'No pudimos conectar con el servidor.');
                }
                this.cargar();
                return;
            }

            // origen === 'pool' : asignar en lote.
            var movidas = [];
            ids.forEach(function (id) {
                var idxS = self.data.sin_asignar.findIndex(function (h) { return h.id === id; });
                if (idxS !== -1) { movidas.push(self.data.sin_asignar[idxS]); self.data.sin_asignar.splice(idxS, 1); return; }
                if (self.data.re_limpiar) {
                    var idxR = self.data.re_limpiar.findIndex(function (h) { return h.id === id; });
                    if (idxR !== -1) { movidas.push(self.data.re_limpiar[idxR]); self.data.re_limpiar.splice(idxR, 1); }
                }
            });
            movidas.forEach(function (h) {
                destino.cola.push({ habitacion_id: h.id, numero: h.numero, tipo_nombre: h.tipo_nombre, estado: 'sucia', franja: null });
            });
            this.seleccionadas = [];
            this.$nextTick(function () { lucide.createIcons(); });
            try {
                var r2 = await apiPost('/api/asignaciones', {
                    habitacion_ids: ids, usuario_id: workerId, fecha: this.fechaHoy
                });
                if (r2 && r2.ok) this.mostrarToast('exito', 'Asignadas ' + ((r2.data && r2.data.total) || ids.length) + ' a ' + destino.usuario.nombre + '.');
                else this.mostrarToast('error', (r2 && r2.error && r2.error.mensaje) || 'No pudimos asignar.');
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
            this.cargar();
        },

        // Soltar en el pool, o click en la X de una tarjeta: quitar la asignación.
        async _dropEnPool(ids, origen) {
            if (origen !== 'worker') return;
            var rid = ids[0];
            var origenTr = this.data.trabajadores.find(function (t) {
                return t.cola.some(function (h) { return h.habitacion_id === rid; });
            });
            if (!origenTr) return;
            var hab = origenTr.cola.find(function (h) { return h.habitacion_id === rid; });
            if (hab && hab.estado === 'en_progreso') {
                if (!confirm('La habitación ' + hab.numero + ' está en progreso. Si la quitas, se reinicia y el trabajador pierde lo avanzado. ¿Continuar?')) return;
            }
            // Optimista: sacar de la cola del trabajador (cargar() la re-ubica en el pool).
            origenTr.cola = origenTr.cola.filter(function (h) { return h.habitacion_id !== rid; });
            this.$nextTick(function () { lucide.createIcons(); });
            try {
                var r = await apiPost('/api/asignaciones/desasignar', { habitacion_id: rid, fecha: this.fechaHoy });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Habitación ' + (hab ? hab.numero : '') + ' sin asignar.');
                } else if (r && r.error && r.error.codigo === 'ESTADO_NO_DESASIGNABLE') {
                    this.mostrarToast('error', 'La habitación ' + (hab ? hab.numero : '') + ' ya fue completada; no se puede quitar.');
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos quitar la habitación.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
            this.cargar();
        },

        // Reordenar la cola de un trabajador (arrastre dentro de su cuadro).
        async _reordenarEnWorker(workerId, roomId, indice) {
            var tr = this.data.trabajadores.find(function (t) { return t.usuario.id === workerId; });
            if (!tr) return;
            var from = tr.cola.findIndex(function (h) { return h.habitacion_id === roomId; });
            if (from === -1) return;
            var to = indice > from ? indice - 1 : indice;
            if (to === from) return; // sin cambio
            var item = tr.cola.splice(from, 1)[0];
            tr.cola.splice(to, 0, item);
            this.$nextTick(function () { lucide.createIcons(); });
            var orden = tr.cola.map(function (h) { return h.habitacion_id; });
            try {
                var r = await apiPut('/api/asignaciones/orden', { usuario_id: workerId, fecha: this.fechaHoy, orden: orden });
                if (!r || !r.ok) {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos reordenar.');
                    this.cargar();
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
                this.cargar();
            }
        },

        // ═══════════════ CLÁSICO: asignar múltiples (chips + modal) ═══════════════

        abrirAsignar() {
            this.modalAsignar = { abierto: true, enviando: false, busqueda: '', franja: null };
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrarAsignar() {
            this.modalAsignar = { abierto: false, enviando: false, busqueda: '', franja: null };
        },

        trabajadoresOrdenados() {
            if (!this.data) return [];
            return this.data.trabajadores.slice().sort(function (a, b) {
                var cA = a.progreso.pendientes + a.progreso.en_progreso;
                var cB = b.progreso.pendientes + b.progreso.en_progreso;
                return cA - cB;
            });
        },

        trabajadoresFiltrados() {
            var lista = this.trabajadoresOrdenados();
            var q = (this.modalAsignar.busqueda || '').trim().toLowerCase();
            if (!q) return lista;
            return lista.filter(function (tr) {
                return (tr.usuario.nombre || '').toLowerCase().includes(q);
            });
        },

        async confirmarAsignar(tr) {
            if (this.modalAsignar.enviando) return;
            if (this.seleccionadas.length === 0) return;
            this.modalAsignar.enviando = true;
            try {
                var r = await apiPost('/api/asignaciones', {
                    habitacion_ids: this.seleccionadas,
                    usuario_id: tr.usuario.id,
                    fecha: this.fechaHoy,
                    franja: this.modalAsignar.franja
                });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Asignadas ' + r.data.total + ' habitaciones a ' + tr.usuario.nombre + '.');
                    this.seleccionadas = [];
                    this.cerrarAsignar();
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos asignar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.modalAsignar.enviando = false;
            }
        },

        // --- Auto-asignar ---

        async autoAsignar() {
            if (this.autoEjecutando) return;
            if (!confirm('Se repartirán las habitaciones sucias entre los trabajadores con turno, usando round-robin. ¿Continuar?')) return;
            this.autoEjecutando = true;
            try {
                var r = await apiPost('/api/asignaciones/auto', {
                    hotel: this.hotel,
                    fecha: this.fechaHoy
                });
                if (r && r.ok) {
                    var n = (r.data.asignaciones || []).length;
                    this.mostrarToast('exito', 'Round-robin: ' + n + ' habitaciones asignadas.');
                    this.seleccionadas = [];
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos auto-asignar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.autoEjecutando = false;
            }
        },

        // --- Reasignar (modal clásico) ---

        esReasignable(estado) {
            return estado === 'sucia' || estado === 'en_progreso' || estado === 'rechazada';
        },

        abrirReasignar(tr, hab) {
            this.modalReasignar = {
                abierto: true,
                origen: tr,
                habitacion: hab,
                motivo: '',
                enviando: false
            };
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrarReasignar() {
            this.modalReasignar = { abierto: false, origen: null, habitacion: null, motivo: '', enviando: false };
        },

        destinosReasignar() {
            if (!this.data) return [];
            var origenId = this.modalReasignar.origen ? this.modalReasignar.origen.usuario.id : null;
            return this.data.trabajadores
                .filter(function (t) { return t.usuario.id !== origenId; })
                .slice()
                .sort(function (a, b) {
                    var cA = a.progreso.pendientes + a.progreso.en_progreso;
                    var cB = b.progreso.pendientes + b.progreso.en_progreso;
                    return cA - cB;
                });
        },

        async confirmarReasignar(dest) {
            if (this.modalReasignar.enviando) return;
            if (!this.modalReasignar.habitacion) return;
            this.modalReasignar.enviando = true;
            try {
                var r = await apiPost('/api/asignaciones/reasignar', {
                    habitacion_id: this.modalReasignar.habitacion.habitacion_id,
                    usuario_id: dest.usuario.id,
                    fecha: this.fechaHoy,
                    motivo: this.modalReasignar.motivo || 'Reasignación manual'
                });
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

        // --- Desasignar (botón clásico) ---

        async desasignar(tr, hab) {
            // DEFAULT APLICADO (aprobado por el usuario): confirm() nativo, mismo
            // patrón que autoAsignar() en esta misma página.
            if (!confirm('¿Quitar la habitación ' + hab.numero + ' de la cola de ' + tr.usuario.nombre + '? Quedará sin asignar.')) return;
            try {
                var r = await apiPost('/api/asignaciones/desasignar', {
                    habitacion_id: hab.habitacion_id,
                    fecha: this.fechaHoy
                });
                if (r && r.ok) {
                    this.mostrarToast('exito', 'Habitación ' + hab.numero + ' desasignada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos desasignar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            }
        },

        // --- Helpers de UI ---

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

        claseBadgeHab(estado) {
            // Colores por estado: clases semánticas .chip-estado-* (editables en
            // Ajustes → Colores). Unifica esta vista con el resto de la app.
            var map = {
                'sucia': 'chip-estado-sucia',
                'en_progreso': 'chip-estado-en_progreso',
                'completada_pendiente_auditoria': 'chip-estado-completada_pendiente_auditoria',
                'aprobada': 'chip-estado-aprobada',
                'aprobada_con_observacion': 'chip-estado-aprobada_con_observacion',
                'rechazada': 'chip-estado-rechazada'
            };
            return map[estado] || 'bg-gray-200 text-gray-800';
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
            return '<span class="w-9 h-9 rounded-full ' + color + ' text-white font-bold flex items-center justify-center flex-shrink-0 text-sm">' + escapeHtml(inicial) + '</span>';
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        }
    };
}
</script>
