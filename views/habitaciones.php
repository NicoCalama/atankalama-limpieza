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
$puedeSincronizar = $usuario->tienePermiso('cloudbeds.forzar_sincronizacion');
// Menú contextual (marcar limpia/sucia/sin aseo a mano): mismo permiso que ya
// gatea el atajo "marcar limpia" existente (ver Kernel.php).
$puedeGestionarEstado = $usuario->tienePermiso('habitaciones.marcar_limpia_manual');
// Mensaje de alerta a la mucama (nota_recepcion): mismo permiso que ya gatea la nota
// en el detalle de la habitación. Recepción lo tiene aunque no tenga marcar_limpia_manual.
$puedeAgregarNota = $usuario->tienePermiso('habitaciones.agregar_nota');
?>

<div x-data="habitacionesApp(<?= $puedeVerTodas ? 'true' : 'false' ?>, <?= (int) $usuario->id ?>, <?= $puedeGestionarEstado ? 'true' : 'false' ?>, <?= $puedeAgregarNota ? 'true' : 'false' ?>)"
     x-init="cargar()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Habitaciones</h1>
            <div class="flex items-center gap-1 flex-shrink-0">
                <?php if ($puedeSincronizar): ?>
                <button @click="sincronizarAhora()" :disabled="sincronizando"
                        class="min-h-[44px] flex items-center gap-1.5 px-3 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50">
                    <span :class="sincronizando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="cloud-download" class="w-4 h-4"></i>
                    </span>
                    <span class="hidden sm:inline" x-text="sincronizando ? 'Sincronizando...' : 'Sincronizar ahora'"></span>
                </button>
                <?php endif; ?>
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Refrescar">
                    <span :class="cargando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                    </span>
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

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto">

        <?php if ($puedeVerTodas): ?>
        <!-- Filtros (solo roles con habitaciones.ver_todas) -->
        <div class="mb-4 space-y-3" data-tour="hb.filtros">
            <!-- Hotel -->
            <div>
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">Hotel</p>
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <template x-for="(h, idx) in hotelesOpciones" :key="h.codigo">
                        <span class="inline-flex items-center">
                            <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setHotel(h.codigo)"
                                    :class="hotel === h.codigo
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition">
                                <span x-text="h.etiqueta"></span>
                            </button>
                        </span>
                    </template>
                </div>
            </div>

            <!-- Estado -->
            <div>
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">Estado</p>
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <template x-for="(e, idx) in estadosOpciones" :key="e.valor || 'todos'">
                        <span class="inline-flex items-center">
                            <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setEstado(e.valor)"
                                    :class="estado === e.valor
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition">
                                <span x-text="e.etiqueta"></span>
                            </button>
                        </span>
                    </template>
                </div>
            </div>

            <!-- Edificio (solo si el listado actual tiene más de uno) -->
            <div x-show="edificiosFiltroOpciones.length > 1">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">Edificio</p>
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <template x-for="(ed, idx) in edificiosFiltroOpciones" :key="ed.id">
                        <span class="inline-flex items-center">
                            <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setEdificio(ed.id)"
                                    :class="edificioFiltro === ed.id
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition">
                                <span x-text="ed.nombre"></span>
                            </button>
                        </span>
                    </template>
                </div>
            </div>

            <!-- Piso (solo con un edificio elegido y más de un piso con habitaciones) -->
            <div x-show="edificioFiltro !== '' && pisosFiltroOpciones.length > 1">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">Piso</p>
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <button @click="setPiso('')"
                            :class="pisoFiltro === ''
                                ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                            class="py-1 text-xs sm:text-sm transition">
                        Todos
                    </button>
                    <template x-for="p in pisosFiltroOpciones" :key="p">
                        <span class="inline-flex items-center">
                            <span class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setPiso(p)"
                                    :class="pisoFiltro === p
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition">
                                <span x-text="'Piso ' + p"></span>
                            </button>
                        </span>
                    </template>
                </div>
            </div>

            <!-- Buscador por número (filtra en el cliente, igual que edificio/piso) -->
            <div>
                <label class="relative block">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" x-model.trim="busqueda" placeholder="Buscar por número de habitación..."
                           class="w-full min-h-[40px] pl-9 pr-9 py-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button x-show="busqueda" x-cloak @click="busqueda = ''"
                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            aria-label="Limpiar búsqueda">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </label>
            </div>

            <!-- Quitar nochero masivo: solo aparece si hay alguna activa con los filtros actuales -->
            <div x-show="nocherosActivosFiltrados.length > 0" x-cloak
                 class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-300 dark:border-yellow-800">
                <p class="text-sm text-yellow-900 dark:text-yellow-200">
                    <span x-text="nocherosActivosFiltrados.length"></span>
                    habitación<span x-text="nocherosActivosFiltrados.length === 1 ? '' : 'es'"></span>
                    marcada<span x-text="nocherosActivosFiltrados.length === 1 ? '' : 's'"></span> como nochero (con los filtros actuales).
                </p>
                <button type="button" @click="quitarTodosNocheros()" :disabled="quitandoTodosNocheros"
                        class="shrink-0 min-h-[36px] px-3 py-1.5 rounded-lg border border-yellow-500 text-yellow-800 dark:text-yellow-200 text-sm font-medium hover:bg-yellow-100 dark:hover:bg-yellow-900/40 disabled:opacity-50 transition">
                    <span x-text="quitandoTodosNocheros ? 'Quitando...' : 'Quitar todas'"></span>
                </button>
            </div>

            <!-- Contador de resultados -->
            <p class="text-sm text-gray-500 dark:text-gray-400" x-text="habitacionesFiltradas.length + ' habitación' + (habitacionesFiltradas.length === 1 ? '' : 'es')"></p>
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

        <!-- Sin resultados por el filtro de edificio/piso (hay habitaciones, pero ninguna calza) -->
        <template x-if="!cargando && !error && habitaciones.length > 0 && habitacionesFiltradas.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center px-4">
                <div class="text-center max-w-xs">
                    <i data-lucide="building-2" class="w-12 h-12 text-gray-400 dark:text-gray-500 mx-auto mb-3"></i>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Sin habitaciones</h2>
                    <p class="text-gray-600 dark:text-gray-400">Ninguna habitación calza con el edificio/piso/búsqueda elegidos.</p>
                </div>
            </div>
        </template>

        <!-- Grid de tarjetas -->
        <template x-if="habitacionesFiltradas.length > 0">
            <div data-tour="hb.grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                <template x-for="hab in habitacionesFiltradas" :key="hab.id">
                    <div @click="if (!$event.target.closest('button')) window.location.href = u('/habitaciones/' + hab.id)"
                       class="relative rounded-xl border border-gray-200 dark:border-gray-700 border-l-4 overflow-hidden hover:shadow-md transition shadow-sm flex flex-col cursor-pointer group bg-white dark:bg-gray-800"
                       :class="[colorHotelBorde(hab.hotel_codigo), estadoAuditado(hab.estado) ? 'opacity-60' : '']">
                        <div class="p-4 flex flex-col gap-2">
                            <div class="flex items-start justify-between">
                                <span class="text-2xl font-bold text-gray-900 dark:text-gray-100" x-text="hab.numero"></span>
                                <div class="flex flex-col items-end gap-1">
                                    <span class="text-xs uppercase tracking-wide font-semibold"
                                          :class="etiquetaHotel(hab.hotel_codigo)"
                                          x-text="hotelCorto(hab.hotel_codigo)"></span>
                                    <div class="flex items-center gap-0.5">
                                        <template x-if="puedeVerTodas">
                                            <button @click.stop="abrirModalEstructura(hab)"
                                                    class="opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-blue-600 transition"
                                                    title="Editar estructura">
                                                <i data-lucide="edit-3" class="w-4 h-4"></i>
                                            </button>
                                        </template>
                                        <template x-if="puedeGestionarEstado || puedeAgregarNota">
                                            <button @click.stop="abrirMenuEstado(hab)"
                                                    class="p-1 text-gray-400 hover:text-blue-600 transition"
                                                    title="Más opciones">
                                                <i data-lucide="more-vertical" class="w-4 h-4"></i>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400" x-text="hab.tipo_nombre || hab.tipo"></p>
                                <template x-if="hab.edificio || hab.piso">
                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5 inline-flex items-center gap-1">
                                        <i data-lucide="building-2" class="w-3 h-3"></i>
                                        <span x-text="(hab.edificio || 'Sin edificio') + (hab.piso ? ' · Piso ' + hab.piso : '')"></span>
                                    </p>
                                </template>
                                <!-- Asignada a: solo supervisor/admin (habitaciones.ver_todas). La cola del
                                     trabajador no trae este campo, pero el guard queda explícito igual. -->
                                <template x-if="puedeVerTodas && hab.asignado_a_nombre">
                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5 inline-flex items-center gap-1">
                                        <i data-lucide="user" class="w-3 h-3"></i>
                                        <span x-text="hab.asignado_a_nombre"></span>
                                    </p>
                                </template>
                            </div>
                            <template x-if="hab.cloudbeds_room_name">
                                <div class="flex justify-end">
                                    <span x-html="badgeCodigoRoomName(hab.cloudbeds_room_name)"></span>
                                </div>
                            </template>
                        </div>

                        <!-- Franja de estado: ancho completo, color exacto de Ajustes → Colores.
                             Es la señal principal de la ficha — el resto (edificio, ocupación,
                             sábanas) es contexto secundario. -->
                        <div class="mt-auto px-4 py-2.5 flex items-center gap-2" :class="claseBannerEstado(hab.estado)">
                            <span class="font-oswald text-sm font-semibold uppercase tracking-wide text-white" x-text="textoEstado(hab.estado)"></span>
                        </div>

                        <!-- Franja de badges + "N" (nochero): siempre presente para que el botón
                             "N" tenga un lugar fijo (esquina inferior derecha); los badges de
                             ocupación/sábanas siguen condicionales adentro. Ver docs/nocheros.md -->
                        <div class="px-4 py-1.5 flex flex-wrap gap-1 items-center border-t border-gray-100 dark:border-gray-700">
                            <template x-if="hab.cb_frontdesk_status && hab.cb_frontdesk_status !== 'unused'">
                                <span x-html="badgeOcupacion(hab.cb_frontdesk_status)"></span>
                            </template>
                            <template x-if="hab.toca_sabanas">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Sábanas hoy</span>
                            </template>
                            <!-- Solo indicador: la nota se agrega/edita en el detalle de la habitación. -->
                            <template x-if="hab.nota_recepcion">
                                <span :title="hab.nota_recepcion"
                                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">
                                    <i data-lucide="sticky-note" class="w-3 h-3"></i> Nota
                                </span>
                            </template>
                            <template x-if="puedeVerTodas">
                                <button type="button" @click.stop="abrirNochero(hab)"
                                        class="ml-auto shrink-0 w-7 h-7 rounded-full text-xs font-bold border-2 transition flex items-center justify-center"
                                        :class="hab.es_nochero
                                            ? 'bg-yellow-400 border-red-500 text-red-600'
                                            : 'bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-300 dark:text-gray-500 hover:border-gray-400'"
                                        :title="hab.es_nochero ? ('Nochero hasta ' + hab.nochero_hasta + ' — clic para quitar') : 'Marcar como nochero'">N</button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>

    </main>

    <!-- Modal Editar Estructura -->
    <template x-if="modalEstructura.abierta">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cerrarModalEstructura()">
            <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-5 shadow-xl relative">
                <button @click="cerrarModalEstructura()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Editar Habitación <span x-text="modalEstructura.hab.numero"></span></h3>
                
                <form @submit.prevent="guardarEstructura()">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Edificio</label>
                            <select x-model="modalEstructura.form.edificio_id" @change="alCambiarEdificioModal()"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                                <option value="">Sin edificio</option>
                                <template x-for="ed in edificiosDisponibles" :key="ed.id">
                                    <option :value="ed.id" x-text="ed.nombre"></option>
                                </template>
                            </select>
                        </div>
                        <div x-show="modalEstructura.form.edificio_id">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Piso</label>
                            <select x-model.number="modalEstructura.form.piso"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                                <option value="">Selecciona...</option>
                                <template x-for="piso in pisosDisponiblesModal" :key="piso">
                                    <option :value="piso" x-text="'Piso ' + piso"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" @click="cerrarModalEstructura()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="modalEstructura.guardando" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50">
                            <span x-show="!modalEstructura.guardando">Guardar</span>
                            <span x-show="modalEstructura.guardando">Guardando...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    <!-- Modal Marcar Nochero -->
    <template x-if="modalNochero.abierta">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cerrarModalNochero()">
            <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-5 shadow-xl relative">
                <button @click="cerrarModalNochero()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">Marcar como nochero</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Habitación <span x-text="modalNochero.hab.numero"></span>: desde las 16:00 volverá a aparecer sucia mientras esté vigente, aunque ya esté aprobada.
                </p>

                <form @submit.prevent="guardarNochero()">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Vigente hasta</label>
                        <input type="date" x-model="modalNochero.form.hasta" :min="fechaMinimaNochero"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <!-- Vigencias típicas: hoy + N días con un clic. Sin mínimo de días: puede
                             ser desde mañana mismo (ver HabitacionService::marcarNochero). -->
                        <div class="flex items-center gap-4 mt-1.5">
                            <button type="button" @click="fijarVigenciaNochero(4)"
                                    class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">4 días</button>
                            <button type="button" @click="fijarVigenciaNochero(7)"
                                    class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">7 días</button>
                            <button type="button" @click="fijarVigenciaNochero(10)"
                                    class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">10 días</button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Puedes editarla después.</p>
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" @click="cerrarModalNochero()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="modalNochero.guardando" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50">
                            <span x-show="!modalNochero.guardando">Guardar</span>
                            <span x-show="modalNochero.guardando">Guardando...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    <!-- Modal Cambiar Estado (menú contextual: marcar limpia/sucia, cliente no desea aseo).
         Modal centrado en vez de dropdown: la tarjeta tiene overflow-hidden (para la franja
         de estado con esquinas redondeadas) y recorta cualquier dropdown que se salga de
         sus bordes. -->
    <template x-if="modalAccionesEstado.abierta">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="cerrarMenuEstado()">
            <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-5 shadow-xl relative">
                <button @click="cerrarMenuEstado()" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    Habitación <span x-text="modalAccionesEstado.hab.numero"></span>
                </h3>

                <div class="space-y-2">
                    <template x-if="puedeMarcarLimpia(modalAccionesEstado.hab)">
                        <button type="button" @click="marcarLimpia(modalAccionesEstado.hab)" :disabled="accionEstadoEnCurso"
                                class="w-full min-h-[44px] text-left px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50">
                            Marcar limpia
                        </button>
                    </template>
                    <template x-if="puedeMarcarSucia(modalAccionesEstado.hab)">
                        <button type="button" @click="marcarSucia(modalAccionesEstado.hab)" :disabled="accionEstadoEnCurso"
                                class="w-full min-h-[44px] text-left px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50">
                            Marcar sucia
                        </button>
                    </template>
                    <template x-if="puedeSinAseoCliente(modalAccionesEstado.hab)">
                        <button type="button" @click="sinAseoCliente(modalAccionesEstado.hab)" :disabled="accionEstadoEnCurso"
                                class="w-full min-h-[44px] text-left px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50">
                            Cliente no desea aseo
                        </button>
                    </template>

                    <!-- Mensaje a la mucama: se pega a la ficha (nota_recepcion) y manda push —
                         solo tiene sentido si hay alguien asignado hoy a quien avisarle. -->
                    <template x-if="puedeAgregarNota && !formMensaje.abierto">
                        <button type="button" @click="abrirFormMensaje()" :disabled="!modalAccionesEstado.hab.asignado_a_nombre"
                                class="w-full min-h-[44px] text-left px-4 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2">
                            <i data-lucide="sticky-note" class="w-4 h-4 text-blue-600 flex-shrink-0"></i>
                            <span x-text="modalAccionesEstado.hab.asignado_a_nombre ? 'Enviar mensaje a la mucama' : 'Enviar mensaje (sin asignar hoy)'"></span>
                        </button>
                    </template>

                    <template x-if="formMensaje.abierto">
                        <div class="space-y-2">
                            <textarea x-model="formMensaje.texto" maxlength="100" rows="2"
                                      placeholder="Ej: cambió a 3 camas, revisar antes de entrar"
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                            <p class="text-xs text-gray-400 text-right" x-text="formMensaje.texto.length + '/100'"></p>
                            <div class="flex gap-2">
                                <button type="button" @click="formMensaje.abierto = false; formMensaje.texto = ''"
                                        class="flex-1 min-h-[40px] px-3 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                    Cancelar
                                </button>
                                <button type="button" @click="enviarMensajeMucama()" :disabled="formMensaje.enviando || !formMensaje.texto.trim()"
                                        class="flex-1 min-h-[40px] px-3 text-sm font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition disabled:opacity-50">
                                    <span x-text="formMensaje.enviando ? 'Enviando...' : 'Enviar'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" @click="cerrarMenuEstado()" class="w-full min-h-[44px] mt-4 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                    Cancelar
                </button>
            </div>
        </div>
    </template>
</div>

<script>
function habitacionesApp(puedeVerTodas, usuarioId, puedeGestionarEstado, puedeAgregarNota) {
    return {
        puedeVerTodas: puedeVerTodas,
        usuarioId: usuarioId,
        puedeGestionarEstado: puedeGestionarEstado,
        puedeAgregarNota: puedeAgregarNota,
        modalAccionesEstado: { abierta: false, hab: null },
        accionEstadoEnCurso: false,
        // Formulario de mensaje a la mucama (dentro del mismo modal de acciones): se pega
        // a nota_recepcion, la mucama lo ve al abrir la habitación y recibe push. Se limpia
        // sola al completar la limpieza (ChecklistService). Ver docs y HabitacionService::agregarNota().
        formMensaje: { abierto: false, texto: '', enviando: false },
        habitaciones: [],
        edificios: [], // All buildings from API
        cargando: false,
        sincronizando: false,
        error: null,
        sinConexion: !navigator.onLine,

        hotel: localStorage.getItem('habitaciones_hotel') || 'ambos',
        estado: localStorage.getItem('habitaciones_estado') || '',
        // Edificio/piso: filtran en el cliente sobre lo ya cargado (hotel+estado son
        // los únicos filtros que van al servidor). edificioFiltro guarda el edificio_id
        // (number, '' = todos); pisoFiltro guarda el número de piso ('' = todos).
        edificioFiltro: localStorage.getItem('habitaciones_edificio') ? parseInt(localStorage.getItem('habitaciones_edificio'), 10) : '',
        pisoFiltro: localStorage.getItem('habitaciones_piso') ? parseInt(localStorage.getItem('habitaciones_piso'), 10) : '',
        // Buscador por número: filtra en el cliente sobre lo ya cargado, igual que
        // edificio/piso. No se persiste (búsqueda puntual, no una preferencia).
        busqueda: '',

        modalEstructura: {
            abierta: false,
            guardando: false,
            hab: null,
            form: { edificio_id: '', piso: '' }
        },

        modalNochero: {
            abierta: false,
            guardando: false,
            hab: null,
            form: { hasta: '' }
        },
        quitandoTodosNocheros: false,

        get edificiosDisponibles() {
            if (!this.modalEstructura.hab) return [];
            // Filtrar edificios por el hotel de la habitacion seleccionada
            return this.edificios.filter(ed => ed.hotel_id == this.modalEstructura.hab.hotel_id);
        },

        get pisosDisponiblesModal() {
            if (!this.modalEstructura.form.edificio_id) return [];
            var ed = this.edificios.find(e => e.id == this.modalEstructura.form.edificio_id);
            if (!ed) return [];
            var pisos = [];
            for (var i = 1; i <= ed.pisos; i++) pisos.push(i);
            return pisos;
        },

        // Fecha mínima seleccionable en el modal de nochero: hoy (sin vigencia mínima —
        // ver HabitacionService::marcarNochero, el backend es la autoridad real).
        // hoyServidor() y no new Date(): la fecha del día es la de Chile, no la del navegador.
        get fechaMinimaNochero() {
            return window.hoyServidor();
        },

        // Atajos de vigencia del modal de nochero: hoy + N días. Suma sobre componentes de
        // fecha LOCALES (no toISOString, que convierte a UTC) para no correrse de día según
        // el huso horario del navegador de quien esté usando la app.
        fijarVigenciaNochero(dias) {
            var partes = window.hoyServidor().split('-').map(Number);
            var d = new Date(partes[0], partes[1] - 1, partes[2]);
            d.setDate(d.getDate() + dias);
            var pad = function (n) { return String(n).padStart(2, '0'); };
            this.modalNochero.form.hasta = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        },

        // Edificios presentes en el listado ya cargado (hotel+estado del servidor),
        // como {id, nombre}. Se arma desde las propias habitaciones (no desde el
        // catálogo completo de /api/edificios) para no ofrecer edificios sin
        // habitaciones visibles con los filtros actuales.
        get edificiosFiltroOpciones() {
            var vistos = {};
            var out = [];
            this.habitaciones.forEach(function (h) {
                if (h.edificio_id && !vistos[h.edificio_id]) {
                    vistos[h.edificio_id] = true;
                    out.push({ id: h.edificio_id, nombre: h.edificio || ('Edificio ' + h.edificio_id) });
                }
            });
            out.sort(function (a, b) { return a.nombre.localeCompare(b.nombre); });
            return out;
        },

        // Pisos con habitaciones dentro del edificio elegido.
        get pisosFiltroOpciones() {
            if (this.edificioFiltro === '') return [];
            var vistos = {};
            var out = [];
            this.habitaciones.forEach(function (h) {
                if (h.edificio_id === this.edificioFiltro && h.piso !== null && h.piso !== undefined && !vistos[h.piso]) {
                    vistos[h.piso] = true;
                    out.push(h.piso);
                }
            }, this);
            out.sort(function (a, b) { return a - b; });
            return out;
        },

        // Lista efectivamente renderizada: habitaciones ya cargadas, filtradas en el
        // cliente por edificio/piso (hotel y estado ya vienen filtrados del servidor).
        // Solo aplica a roles con habitaciones.ver_todas (los únicos con los chips
        // visibles): sin este resguardo, un edificioFiltro/pisoFiltro que quedó en
        // localStorage de una sesión anterior en el mismo navegador podría recortar
        // en silencio la cola de un trabajador que no tiene forma de verlo ni limpiarlo.
        get habitacionesFiltradas() {
            var self = this;
            var texto = this.busqueda.trim().toLowerCase();
            return this.habitaciones.filter(function (h) {
                if (self.puedeVerTodas) {
                    if (self.edificioFiltro !== '' && h.edificio_id !== self.edificioFiltro) return false;
                    if (self.pisoFiltro !== '' && h.piso !== self.pisoFiltro) return false;
                }
                if (texto !== '' && String(h.numero).toLowerCase().indexOf(texto) === -1) return false;
                return true;
            });
        },

        // Habitaciones nochero activas dentro de lo que se está viendo ahora (respeta
        // hotel/estado/edificio/piso/búsqueda) — es lo que afecta "Quitar todas".
        get nocherosActivosFiltrados() {
            return this.habitacionesFiltradas.filter(function (h) { return h.es_nochero; });
        },

        alCambiarEdificioModal() {
            this.modalEstructura.form.piso = ''; // Resetear piso si cambia el edificio
        },

        abrirModalEstructura(hab) {
            this.modalEstructura.hab = hab;
            // Buscar si ya tiene edificio_id o tratar de adivinar por nombre
            var edId = hab.edificio_id || '';
            if (!edId && hab.edificio) {
                var match = this.edificios.find(e => e.hotel_id == hab.hotel_id && e.nombre.toLowerCase() == hab.edificio.toLowerCase());
                if (match) edId = match.id;
            }
            this.modalEstructura.form.edificio_id = edId;
            this.modalEstructura.form.piso = hab.piso || '';
            this.modalEstructura.abierta = true;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrarModalEstructura() {
            this.modalEstructura.abierta = false;
            this.modalEstructura.hab = null;
        },

        async guardarEstructura() {
            if (this.modalEstructura.guardando || !this.modalEstructura.hab) return;
            this.modalEstructura.guardando = true;
            try {
                // Find selected building name
                var edId = this.modalEstructura.form.edificio_id || null;
                var edNombre = null;
                if (edId) {
                    var ed = this.edificios.find(e => e.id == edId);
                    if (ed) edNombre = ed.nombre;
                }

                var payload = {
                    edificio_id: edId,
                    edificio: edNombre,
                    piso: this.modalEstructura.form.piso ? parseInt(this.modalEstructura.form.piso, 10) : null
                };
                var r = await apiPut('/api/habitaciones/' + this.modalEstructura.hab.id + '/estructura', payload);
                if (r.ok) {
                    this.modalEstructura.hab.edificio_id = payload.edificio_id;
                    this.modalEstructura.hab.edificio = payload.edificio || null;
                    this.modalEstructura.hab.piso = payload.piso;
                    this.cerrarModalEstructura();
                } else {
                    alert((r.error && r.error.mensaje) || 'Error al guardar.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.modalEstructura.guardando = false;
            }
        },

        // Botón "N": si ya es nochero, quitarlo es un solo paso (confirm nativo, sin
        // formulario); si no, abre el modal a elegir la vigencia. Ver docs/nocheros.md
        abrirNochero(hab) {
            if (hab.es_nochero) {
                this.quitarNochero(hab);
                return;
            }
            this.modalNochero.hab = hab;
            this.modalNochero.form.hasta = this.fechaMinimaNochero;
            this.modalNochero.abierta = true;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cerrarModalNochero() {
            this.modalNochero.abierta = false;
            this.modalNochero.hab = null;
        },

        async guardarNochero() {
            if (this.modalNochero.guardando || !this.modalNochero.hab) return;
            this.modalNochero.guardando = true;
            try {
                var hasta = this.modalNochero.form.hasta;
                var r = await apiPut('/api/habitaciones/' + this.modalNochero.hab.id + '/nochero', { hasta: hasta });
                if (r.ok) {
                    this.modalNochero.hab.es_nochero = true;
                    this.modalNochero.hab.nochero_hasta = hasta;
                    this.cerrarModalNochero();
                } else {
                    alert((r.error && r.error.mensaje) || 'No pudimos marcar la habitación como nochero.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.modalNochero.guardando = false;
            }
        },

        async quitarNochero(hab) {
            if (!confirm('¿Quitar la marca de nochero de esta habitación?')) return;
            try {
                var r = await apiFetch('/api/habitaciones/' + hab.id + '/nochero', { method: 'DELETE' });
                if (r && r.ok) {
                    hab.es_nochero = false;
                    hab.nochero_hasta = null;
                } else {
                    alert((r && r.error && r.error.mensaje) || 'No pudimos quitar la marca de nochero.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            }
        },

        // Quita nochero de todas las que se ven ahora mismo (respeta los filtros).
        // Reusa el mismo endpoint DELETE que "N" ya usa uno por uno (Promise.allSettled: si
        // alguna falla, las demás igual se procesan) — cada quite queda igual auditado
        // individualmente en Logger::audit, no se pierde trazabilidad por hacerlo en lote.
        async quitarTodosNocheros() {
            var lista = this.nocherosActivosFiltrados;
            if (lista.length === 0 || this.quitandoTodosNocheros) return;
            if (!confirm('¿Quitar la marca de nochero de las ' + lista.length + ' habitaciones marcadas (las que ves con los filtros actuales)?')) return;

            this.quitandoTodosNocheros = true;
            try {
                var resultados = await Promise.allSettled(lista.map(function (h) {
                    return apiFetch('/api/habitaciones/' + h.id + '/nochero', { method: 'DELETE' });
                }));
                var fallidas = resultados.filter(function (r) {
                    return r.status !== 'fulfilled' || !r.value || !r.value.ok;
                }).length;
                await this.cargar();
                if (fallidas > 0) {
                    alert('Se quitó la marca de ' + (lista.length - fallidas) + ' de ' + lista.length + ' habitaciones. ' + fallidas + ' no se pudieron actualizar — intenta de nuevo.');
                }
            } finally {
                this.quitandoTodosNocheros = false;
            }
        },

        // --- Menú contextual: marcar limpia/sucia/sin aseo a mano ---
        // Los conjuntos de estados de abajo espejan EXACTAMENTE los guards del backend
        // (HabitacionesController::marcarLimpiaManual / marcarSuciaManual / marcarSinAseoCliente):
        // es solo para no ofrecer en la UI una acción que el servidor va a rechazar con 409;
        // la autoridad real sigue siendo el backend.
        abrirMenuEstado(hab) {
            this.modalAccionesEstado.hab = hab;
            this.modalAccionesEstado.abierta = true;
        },

        cerrarMenuEstado() {
            this.modalAccionesEstado.abierta = false;
            this.modalAccionesEstado.hab = null;
            this.formMensaje.abierto = false;
            this.formMensaje.texto = '';
        },

        abrirFormMensaje() {
            if (!this.modalAccionesEstado.hab.asignado_a_nombre) return;
            this.formMensaje.abierto = true;
        },

        // Reusa el mismo endpoint que la nota de Recepción del detalle de la habitación
        // (HabitacionService::agregarNota): se pega a la ficha hasta que la mucama termine
        // la limpieza, y manda push a quien esté asignado hoy. maxlength=100 en el textarea
        // (el backend acepta hasta 500 — ver conversación de diseño).
        async enviarMensajeMucama() {
            var texto = this.formMensaje.texto.trim();
            var hab = this.modalAccionesEstado.hab;
            if (!texto || !hab || this.formMensaje.enviando) return;
            this.formMensaje.enviando = true;
            try {
                var r = await apiPost('/api/habitaciones/' + hab.id + '/nota', { nota: texto });
                if (r && r.ok) {
                    hab.nota_recepcion = r.data.habitacion.nota_recepcion;
                    this.cerrarMenuEstado();
                } else {
                    alert((r && r.error && r.error.mensaje) || 'No pudimos enviar el mensaje.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.formMensaje.enviando = false;
            }
        },

        puedeMarcarLimpia(hab) {
            return !!hab && ['sucia', 'en_progreso', 'rechazada'].indexOf(hab.estado) !== -1;
        },

        puedeMarcarSucia(hab) {
            return !!hab && ['en_progreso', 'aprobada', 'aprobada_con_observacion', 'rechazada'].indexOf(hab.estado) !== -1;
        },

        puedeSinAseoCliente(hab) {
            return !!hab && ['sucia', 'en_progreso', 'rechazada'].indexOf(hab.estado) !== -1;
        },

        marcarLimpia(hab) {
            if (!confirm('¿Marcar la habitación ' + hab.numero + ' como limpia? Quedará pendiente de inspección.')) {
                return;
            }
            this.ejecutarAccionEstado(hab, '/marcar-limpia', 'No pudimos marcar la habitación como limpia.');
        },

        marcarSucia(hab) {
            if (!confirm('¿Marcar la habitación ' + hab.numero + ' como sucia?')) {
                return;
            }
            this.ejecutarAccionEstado(hab, '/marcar-sucia', 'No pudimos marcar la habitación como sucia.');
        },

        sinAseoCliente(hab) {
            if (!confirm('¿Registrar que el cliente no desea aseo en la habitación ' + hab.numero + '? Quedará aprobada sin limpiar, y no se le asignan créditos a nadie.')) {
                return;
            }
            this.ejecutarAccionEstado(hab, '/sin-aseo-cliente', 'No pudimos registrar "sin aseo".');
        },

        async ejecutarAccionEstado(hab, ruta, mensajeError) {
            this.cerrarMenuEstado();
            if (this.accionEstadoEnCurso) return;
            this.accionEstadoEnCurso = true;
            try {
                var r = await apiPost('/api/habitaciones/' + hab.id + ruta, {});
                if (r.ok) {
                    hab.estado = r.data.habitacion.estado;
                } else {
                    alert((r.error && r.error.mensaje) || mensajeError);
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.accionEstadoEnCurso = false;
            }
        },

        hotelesOpciones: [
            { codigo: 'ambos', etiqueta: 'Ambos' },
            { codigo: '1_sur', etiqueta: 'Atankalama' },
            { codigo: 'inn', etiqueta: 'Atankalama INN' }
        ],

        estadosOpciones: [
            { valor: '', etiqueta: 'Todos' },
            { valor: 'sucia', etiqueta: 'Sucias' },
            { valor: 'en_progreso', etiqueta: 'En progreso' },
            { valor: 'completada_pendiente_auditoria', etiqueta: 'Por inspeccionar' },
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
                    // vista=completa: este listado muestra TODAS mis asignaciones de hoy
                    // (todos los estados), a diferencia de home (solo la actual). El orden
                    // real para iniciar limpieza lo sigue exigiendo el backend en /iniciar.
                    url = '/api/usuarios/' + this.usuarioId + '/cola?vista=completa';
                }

                // Load edificios and habitaciones in parallel
                var [rHab, rEd] = await Promise.all([
                    apiFetch(url),
                    this.puedeVerTodas ? apiFetch('/api/edificios') : Promise.resolve({ ok: true, data: { edificios: [] } })
                ]);

                if (rEd && rEd.ok) {
                    this.edificios = rEd.data.edificios;
                }

                if (rHab && rHab.ok) {
                    if (this.puedeVerTodas) {
                        this.habitaciones = rHab.data.habitaciones;
                    } else {
                        var cola = rHab.data.cola || [];
                        this.habitaciones = cola.map(function (a) {
                            return {
                                id: a.habitacion_id,
                                numero: a.numero,
                                edificio_id: a.edificio_id,
                                edificio: a.edificio,
                                piso: a.piso,
                                estado: a.estado,
                                hotel_codigo: a.hotel_codigo,
                                tipo_nombre: a.tipo_nombre,
                                cb_frontdesk_status: a.cb_frontdesk_status,
                                toca_sabanas: a.toca_sabanas
                            };
                        });
                    }
                } else {
                    this.error = (rHab && rHab.error && rHab.error.mensaje) || 'Error al cargar.';
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        // Trae desde Cloudbeds el estado (sucia/limpia + ocupación) de todas las
        // habitaciones y recarga el listado. Requiere cloudbeds.forzar_sincronizacion
        // (el botón ya viene oculto sin el permiso; el endpoint también lo exige).
        async sincronizarAhora() {
            if (this.sincronizando) return;
            this.sincronizando = true;
            try {
                var r = await apiPost('/api/cloudbeds/sync', {});
                if (r.ok) {
                    var sync = r.data.sync || {};
                    if (sync.resultado === 'error') {
                        alert('La sincronización con Cloudbeds falló. Revisa Ajustes → Cloudbeds.');
                    } else {
                        var n = sync.habitaciones_sincronizadas || 0;
                        alert(n > 0 ? 'Sincronizado: ' + n + ' habitación(es) actualizadas.' : 'Sincronizado. Sin cambios de estado.');
                    }
                    await this.cargar();
                } else {
                    alert((r.error && r.error.mensaje) || 'No se pudo sincronizar con Cloudbeds.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.sincronizando = false;
            }
        },

        setHotel(codigo) {
            this.hotel = codigo;
            localStorage.setItem('habitaciones_hotel', codigo);
            // El listado se recarga completo: el edificio/piso elegido puede no
            // existir en el hotel nuevo, así que se limpia el filtro.
            this.setEdificio('');
            this.cargar();
        },

        setEdificio(id) {
            this.edificioFiltro = id;
            localStorage.setItem('habitaciones_edificio', id === '' ? '' : String(id));
            this.setPiso('');
        },

        setPiso(piso) {
            this.pisoFiltro = piso;
            localStorage.setItem('habitaciones_piso', piso === '' ? '' : String(piso));
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

        // Borde izquierdo por hotel (la ficha va con fondo blanco y la franja de
        // estado al pie, ver claseBannerEstado): clase semántica .hotel-border-*
        // de custom.css. Los colores son editables en Ajustes → Colores.
        colorHotelBorde(codigo) {
            if (codigo === '1_sur' || codigo === 'inn') return 'hotel-border-' + codigo;
            return 'border-l-gray-200 dark:border-l-gray-700';
        },

        // Franja de estado al pie de la ficha: clase semántica .banner-estado-*
        // de custom.css, relleno sólido con el color exacto de Ajustes → Colores.
        claseBannerEstado(estado) {
            var validos = ['sucia', 'en_progreso', 'completada_pendiente_auditoria', 'aprobada', 'aprobada_con_observacion', 'aprobada_automatica', 'rechazada'];
            return validos.indexOf(estado) !== -1 ? 'banner-estado-' + estado : 'bg-gray-400 dark:bg-gray-600';
        },

        // Color del texto de la etiqueta del hotel, a juego con el acento de la tarjeta.
        etiquetaHotel(codigo) {
            if (codigo === '1_sur' || codigo === 'inn') return 'hotel-chip-' + codigo;
            return 'text-gray-500 dark:text-gray-400';
        },

        estadoAuditado(estado) {
            return estado === 'aprobada' || estado === 'aprobada_con_observacion' || estado === 'aprobada_automatica' || estado === 'rechazada';
        },

        // Texto de la franja de estado (y de cualquier otro lugar que necesite el
        // nombre amigable del estado). 'aprobada_con_observacion' se muestra como
        // "Aprobada" a secas al trabajador (sin habitaciones.ver_todas): no debe
        // distinguirla de una aprobada normal. Solo supervisora/auditor ven "c/obs.".
        // Ver CLAUDE.md y docs/auditoria.md.
        textoEstado(estado) {
            var textos = {
                'sucia': 'Pendiente',
                'en_progreso': 'En progreso',
                'completada_pendiente_auditoria': 'Por inspeccionar',
                'aprobada': 'Aprobada',
                'aprobada_con_observacion': this.puedeVerTodas ? 'Aprobada c/obs.' : 'Aprobada',
                'aprobada_automatica': this.puedeVerTodas ? 'Aprobada auto.' : 'Aprobada',
                'rechazada': 'Rechazada'
            };
            return textos[estado] || estado;
        },

        // Badge de ocupación (frontdeskStatus de Cloudbeds). Ver docs/ocupacion-y-sabanas.md
        badgeOcupacion(fs) {
            var map = {
                'check-in': { t: 'Llega hoy', c: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200' },
                'check-out': { t: 'Se va hoy', c: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200' },
                'turnover': { t: 'Cambio', c: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200' },
                'stayover': { t: 'Sigue', c: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }
            };
            var c = map[fs];
            if (!c) return '';
            return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ' + c.c + '">' + escapeHtml(c.t) + '</span>';
        },

        // Badge del código de ocupación de Cloudbeds: último token del roomName completo
        // ('511-EXE3 2S' -> '2S'). Espejo crudo de Cloudbeds (cloudbeds_room_name), se
        // refresca solo, nunca editable en la app. Sin espacio en el nombre -> sin badge.
        badgeCodigoRoomName(nombreCompleto) {
            var partes = (nombreCompleto || '').trim().split(/\s+/);
            if (partes.length < 2) return '';
            var codigo = partes[partes.length - 1];
            return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-pink-500 text-white">' + escapeHtml(codigo) + '</span>';
        }
    };
}
</script>
