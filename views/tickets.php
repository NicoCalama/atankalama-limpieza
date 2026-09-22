<?php
/**
 * Página de Tickets de mantenimiento.
 * Spec: docs/tickets.md
 *
 * Listado con filtros (hotel, estado, prioridad), detalle en modal.
 * Acciones según permisos: tomar/asignar/marcar resuelto/cerrar/reabrir.
 *
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

require_once __DIR__ . '/componentes/avatar.php';

$ticketsCssFile = __DIR__ . '/recursos/tickets/tickets.css';
$ticketsJsFile  = __DIR__ . '/recursos/tickets/tickets.js';
$ticketsCssV = @filemtime($ticketsCssFile) ?: '1';
$ticketsJsV  = @filemtime($ticketsJsFile) ?: '1';
?>

<link rel="stylesheet" href="<?= u('/views/recursos/tickets/tickets.css') ?>?v=<?= $ticketsCssV ?>">
<script src="<?= u('/views/recursos/tickets/tickets.js') ?>?v=<?= $ticketsJsV ?>"></script>

<div x-data="ticketsApp()"
     x-init="cargar().then(() => abrirDesdeUrl()); iniciarRefresco()"
     @ticket-creado.window="onTicketCreado($event.detail)"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3 xl:px-8">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <i data-lucide="wrench" class="w-6 h-6 text-rose-600 dark:text-rose-400 flex-shrink-0"></i>
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">Tickets</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="total"></span> en total · <span x-text="etiquetaAlcanceActual()"></span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
                <template x-if="puedeCrear">
                    <button @click="$dispatch('abrir-modal-ticket', {})" data-tour="tk.nuevo"
                            class="min-h-[44px] inline-flex items-center gap-2 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        <span class="hidden sm:inline">Nuevo</span>
                    </button>
                </template>
                <button @click="cargar()" :disabled="cargando"
                        class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="Refrescar">
                    <span :class="cargando ? 'animate-spin' : ''" class="inline-flex">
                        <i data-lucide="rotate-cw" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                    </span>
                </button>
                <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
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

    <main class="pb-24 md:pb-8 px-4 py-4 xl:px-8 space-y-4">

        <!-- Filtros -->
        <section data-tour="tk.filtros" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
            <div class="flex flex-wrap gap-x-4 gap-y-2 items-center">
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Alcance:</span>
                    <template x-for="(a, idx) in alcanceFiltro" :key="a.valor">
                        <span class="inline-flex items-center">
                            <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setAlcance(a.valor)"
                                    :class="alcance === a.valor
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition"
                                    x-text="a.etiqueta"></button>
                        </span>
                    </template>
                </div>
                <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Estado:</span>
                    <template x-for="(e, idx) in estadosFiltro" :key="e.valor">
                        <span class="inline-flex items-center">
                            <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                            <button @click="setEstado(e.valor)"
                                    :class="estado === e.valor
                                        ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                    class="py-1 text-xs sm:text-sm transition"
                                    x-text="e.etiqueta"></button>
                        </span>
                    </template>
                </div>
                <template x-if="puedeVerTodos">
                    <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                        <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Hotel:</span>
                        <template x-for="(h, idx) in hotelesFiltro" :key="h.valor">
                            <span class="inline-flex items-center">
                                <span x-show="idx > 0" class="text-gray-300 dark:text-gray-600 mx-1.5" aria-hidden="true">·</span>
                                <button @click="setHotel(h.valor)"
                                        :class="hotel === h.valor
                                            ? 'text-blue-600 dark:text-blue-400 font-semibold'
                                            : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 font-medium'"
                                        class="py-1 text-xs sm:text-sm transition"
                                        x-text="h.etiqueta"></button>
                            </span>
                        </template>
                    </div>
                </template>
            </div>
        </section>

        <!-- Carga inicial -->
        <template x-if="cargando && tickets.length === 0">
            <div class="min-h-[40vh] flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-gray-600 dark:text-gray-400">Cargando tickets...</p>
                </div>
            </div>
        </template>

        <!-- Vacío -->
        <template x-if="!cargando && tickets.length === 0">
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-8 text-center">
                <i data-lucide="wrench" class="w-12 h-12 text-gray-400 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">Sin tickets</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    <template x-if="estado === ''">
                        <span>Todavía no hay tickets con estos filtros.</span>
                    </template>
                    <template x-if="estado !== ''">
                        <span>No hay tickets en este estado.</span>
                    </template>
                </p>
                <template x-if="puedeCrear">
                    <button @click="$dispatch('abrir-modal-ticket', {})"
                            class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        <i data-lucide="plus" class="w-4 h-4"></i>
                        Reportar problema
                    </button>
                </template>
            </div>
        </template>

        <!-- Listado -->
        <template x-if="tickets.length > 0">
            <div>
                <!-- Celular: tarjetas (oculto desde md) -->
                <ul class="md:hidden space-y-2" data-tour="tk.lista">
                    <template x-for="t in tickets" :key="t.id">
                        <li class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 cursor-pointer hover:border-blue-300 dark:hover:border-blue-700 transition"
                            :class="claseBordePrioridad(t.prioridad)"
                            @click="abrirDetalle(t)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300" x-text="'#' + t.id"></span>
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0"
                                              :class="claseBadgePrioridad(t.prioridad)"
                                              x-text="etiquetaPrioridad(t.prioridad)"></span>
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0"
                                              :class="claseBadgeEstado(t.estado)"
                                              x-text="etiquetaEstado(t.estado)"></span>
                                        <!-- Semáforo de espera. El punto es un <span> con fondo, no un icono: dentro de
                                             un x-for, lucide.createIcons() rompe la referencia de Alpine y duplica el
                                             nodo (ver docs/contexto/errores-conocidos.md). -->
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold flex-shrink-0 inline-flex items-center gap-1.5"
                                              :class="claseEsperaChip(t)" :title="tituloEspera(t)">
                                            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="claseEsperaPunto(t)"></span>
                                            <span x-text="esperaTexto(t)"></span>
                                        </span>
                                        <template x-if="t.habitacion_numero">
                                            <span class="text-xs text-gray-500 dark:text-gray-400 inline-flex items-center gap-1">
                                                <i data-lucide="bed" class="w-3 h-3"></i>
                                                <span x-text="t.habitacion_numero"></span>
                                            </span>
                                        </template>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="nombreHotelCorto(t.hotel_codigo)"></span>
                                    </div>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="t.titulo"></p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 line-clamp-2 mt-0.5" x-text="t.descripcion"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                        <span x-text="'Por ' + (t.levantado_por_nombre || 'usuario')"></span>
                                        · <span x-text="fechaRelativa(t.created_at)"></span>
                                        <template x-if="(t.responsables && t.responsables.length > 0) || t.asignado_a_nombre">
                                            <!-- SVG inline (no data-lucide): ver docs/contexto/errores-conocidos.md
                                                 — createIcons() rompe la referencia de Alpine y duplica el icono. -->
                                            <span> · <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 inline" viewBox="0 0 24 24"
                                                           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                                <circle cx="9" cy="7" r="4"></circle>
                                                <polyline points="16 11 18 13 22 9"></polyline>
                                            </svg> <span x-text="formatearResponsables(t.responsables) || t.asignado_a_nombre"></span></span>
                                        </template>
                                    </p>
                                </div>
                                <i data-lucide="chevron-right" class="w-5 h-5 text-gray-400 flex-shrink-0 mt-1"></i>
                            </div>
                        </li>
                    </template>
                </ul>

                <!-- PC: tabla (oculta bajo md) -->
                <div class="hidden md:block bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-tour="tk.tabla">
                            <thead class="bg-gray-50 dark:bg-gray-700/40">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Prioridad</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Estado</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">Ticket</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Hotel / Hab.</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Espera</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Asignado a</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 whitespace-nowrap">Reportado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template x-for="t in tickets" :key="t.id">
                                    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/30 transition"
                                        :class="claseBordePrioridad(t.prioridad)"
                                        @click="abrirDetalle(t)">
                                        <td class="px-3 py-3 whitespace-nowrap font-mono text-xs font-bold text-gray-600 dark:text-gray-300" x-text="'#' + t.id"></td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                                  :class="claseBadgePrioridad(t.prioridad)"
                                                  x-text="etiquetaPrioridad(t.prioridad)"></span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                                  :class="claseBadgeEstado(t.estado)"
                                                  x-text="etiquetaEstado(t.estado)"></span>
                                        </td>
                                        <td class="px-4 py-3 max-w-xs">
                                            <p class="font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="t.titulo"></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="t.descripcion"></p>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                            <span x-text="nombreHotelCorto(t.hotel_codigo)"></span>
                                            <template x-if="t.habitacion_numero">
                                                <span> · <span x-text="t.habitacion_numero"></span></span>
                                            </template>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 py-0.5 rounded text-xs font-semibold inline-flex items-center gap-1.5"
                                                  :class="claseEsperaChip(t)" :title="tituloEspera(t)">
                                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="claseEsperaPunto(t)"></span>
                                                <span x-text="esperaTexto(t)"></span>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400" x-text="formatearResponsables(t.responsables) || t.asignado_a_nombre || '—'"></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-500"
                                            :title="'Por ' + (t.levantado_por_nombre || 'usuario') + ' · ' + fechaCorta(t.created_at)">
                                            <span x-text="fechaRelativa(t.created_at)"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>
    </main>

    <!-- Modal detalle -->
    <div x-show="detalle.abierto" x-cloak
         class="fixed inset-0 z-50 flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrarDetalle()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto"
             x-show="detalle.ticket">
            <template x-if="detalle.ticket">
                <div>
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="font-mono text-xs font-bold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300" x-text="'#' + detalle.ticket.id"></span>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                      :class="claseBadgePrioridad(detalle.ticket.prioridad)"
                                      x-text="etiquetaPrioridad(detalle.ticket.prioridad)"></span>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold"
                                      :class="claseBadgeEstado(detalle.ticket.estado)"
                                      x-text="etiquetaEstado(detalle.ticket.estado)"></span>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold inline-flex items-center gap-1.5"
                                      :class="claseEsperaChip(detalle.ticket)" :title="tituloEspera(detalle.ticket)">
                                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :class="claseEsperaPunto(detalle.ticket)"></span>
                                    <span x-text="esperaTexto(detalle.ticket)"></span>
                                </span>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="detalle.ticket.titulo"></h3>
                        </div>
                        <button @click="cerrarDetalle()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                            <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div>
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Descripción</p>
                            <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap" x-text="detalle.ticket.descripcion"></p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Reportado por</p>
                                <p class="text-gray-900 dark:text-gray-100" x-text="detalle.ticket.levantado_por_nombre || '—'"></p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Fecha</p>
                                <p class="text-gray-900 dark:text-gray-100" x-text="fechaCorta(detalle.ticket.created_at)"></p>
                            </div>
                            <template x-if="detalle.ticket.habitacion_numero">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Habitación</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="detalle.ticket.habitacion_numero + ' · ' + nombreHotelCorto(detalle.ticket.hotel_codigo)"></p>
                                </div>
                            </template>
                            <template x-if="(detalle.ticket.responsables && detalle.ticket.responsables.length > 0) || detalle.ticket.asignado_a">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Responsables</p>
                                    <div class="flex flex-wrap gap-1.5 mt-1">
                                        <template x-if="detalle.ticket.responsables && detalle.ticket.responsables.length > 0">
                                            <template x-for="r in detalle.ticket.responsables" :key="r.id">
                                                <span class="badge-responsable">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                                        <circle cx="9" cy="7" r="4"></circle>
                                                        <polyline points="16 11 18 13 22 9"></polyline>
                                                    </svg>
                                                    <span x-text="r.nombre"></span>
                                                </span>
                                            </template>
                                        </template>
                                        <template x-if="(!detalle.ticket.responsables || detalle.ticket.responsables.length === 0) && detalle.ticket.asignado_a">
                                            <span class="badge-responsable">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                                    <circle cx="9" cy="7" r="4"></circle>
                                                    <polyline points="16 11 18 13 22 9"></polyline>
                                                </svg>
                                                <span x-text="detalle.ticket.asignado_a_nombre || ('#' + detalle.ticket.asignado_a)"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <template x-if="detalle.ticket.resuelto_at">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Resuelto</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="fechaCorta(detalle.ticket.resuelto_at)"></p>
                                </div>
                            </template>
                        </div>

                        <!-- Fotos -->
                        <template x-if="detalle.ticket.adjuntos && detalle.ticket.adjuntos.length > 0">
                            <div>
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Fotos</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="foto in detalle.ticket.adjuntos" :key="foto.id">
                                        <a :href="urlAdjunto(foto.ruta)" target="_blank" rel="noopener">
                                            <img :src="urlAdjunto(foto.ruta)"
                                                 class="w-16 h-16 object-cover rounded-lg border border-gray-200 dark:border-gray-700"
                                                 :alt="foto.contexto === 'cierre' ? 'Foto de cierre' : 'Foto del reporte'">
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <!-- Acciones: quien gestiona (ver_todos) las ve todas. Quien solo tiene
                             tickets.ver_propios puede tomar un ticket sin dueño, y una vez que
                             lo tiene asignado a sí mismo, marcarlo resuelto — no cierra ni reabre
                             (esa verificación final queda para quien gestiona). Ver
                             TicketsController::asignar() y cambiarEstado(). -->
                        <template x-if="(puedeGestionar || puedeResolverAsignado || puedeTomar || puedeEditarPrioridad) && detalle.ticket.estado !== 'cerrado'">
                            <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                                <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">Acciones</p>
                                <div class="flex flex-wrap gap-2" x-show="!detalle.mostrarCierre && !detalle.mostrarAsignar && !detalle.mostrarPrioridad">
                                    <template x-if="puedeTomar">
                                        <button @click="tomar()" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                            Tomar
                                        </button>
                                    </template>
                                    <template x-if="puedeGestionar">
                                        <button @click="abrirAsignar()" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 transition">
                                            Asignar responsable
                                        </button>
                                    </template>
                                    <template x-if="puedeEditarPrioridad">
                                        <button @click="abrirPrioridad()" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 transition">
                                            Cambiar prioridad
                                        </button>
                                    </template>
                                    <template x-if="(puedeGestionar || puedeResolverAsignado) && (detalle.ticket.estado === 'abierto' || detalle.ticket.estado === 'en_progreso')">
                                        <button @click="cambiarEstado('resuelto')" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white transition">
                                            Marcar resuelto
                                        </button>
                                    </template>
                                    <template x-if="puedeGestionar && detalle.ticket.estado === 'resuelto'">
                                        <button @click="detalle.mostrarCierre = true" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-600 hover:bg-gray-700 disabled:opacity-50 text-white transition">
                                            Cerrar
                                        </button>
                                    </template>
                                    <template x-if="puedeGestionar && (detalle.ticket.estado === 'resuelto' || detalle.ticket.estado === 'en_progreso')">
                                        <button @click="cambiarEstado('abierto')" :disabled="detalle.enviando"
                                                class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-amber-100 dark:bg-amber-900/30 hover:bg-amber-200 dark:hover:bg-amber-900/50 text-amber-800 dark:text-amber-300 transition">
                                            Reabrir
                                        </button>
                                    </template>
                                </div>

                                <!-- Panel de asignar responsable (múltiple y por grupo) -->
                                <template x-if="puedeGestionar && detalle.mostrarAsignar">
                                    <div class="space-y-3 p-3 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide font-semibold">
                                                Asignar responsables (<span x-text="detalle.asignarUsuarioIds.length"></span> seleccionados)
                                            </p>
                                            <button type="button" @click="limpiarResponsables()"
                                                    x-show="detalle.asignarUsuarioIds.length > 0"
                                                    class="text-xs text-rose-600 hover:text-rose-700 dark:text-rose-400 font-medium">
                                                Limpiar selección
                                            </button>
                                        </div>

                                        <!-- Atajos rápidos de grupo (Supervisora, Trabajador) -->
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span class="text-xs text-gray-500 dark:text-gray-400 mr-1">Atajos:</span>
                                            <button type="button" @click="asignarGrupo('Supervisora')"
                                                    :class="estaGrupoSeleccionado('Supervisora') ? 'btn-grupo-pill btn-grupo-pill-activo' : 'btn-grupo-pill btn-grupo-pill-inactivo'">
                                                <span>+ Todas las Supervisoras</span>
                                            </button>
                                            <button type="button" @click="asignarGrupo('Trabajador')"
                                                    :class="estaGrupoSeleccionado('Trabajador') ? 'btn-grupo-pill btn-grupo-pill-activo' : 'btn-grupo-pill btn-grupo-pill-inactivo'">
                                                <span>+ Todos los Trabajadores</span>
                                            </button>
                                            <button type="button" @click="asignarGrupo('Mantenimiento')"
                                                    :class="estaGrupoSeleccionado('Mantenimiento') ? 'btn-grupo-pill btn-grupo-pill-activo' : 'btn-grupo-pill btn-grupo-pill-inactivo'">
                                                <span>+ Todo Mantenimiento</span>
                                            </button>
                                        </div>

                                        <!-- Lista seleccionable con checkboxes agrupada por perfil -->
                                        <div class="asignar-responsables-scroll border border-gray-200 dark:border-gray-700 rounded-lg divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-800">
                                            <template x-for="g in gruposAsignables()" :key="g.perfil">
                                                <div class="p-2">
                                                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-2 py-1" x-text="g.perfil"></p>
                                                    <div class="space-y-0.5">
                                                        <template x-for="u in g.usuarios" :key="u.id">
                                                            <div @click="toggleResponsable(u.id)"
                                                                 class="responsable-item"
                                                                 :class="estaResponsableSeleccionado(u.id) ? 'seleccionado' : ''">
                                                                <div class="flex items-center gap-2 min-w-0">
                                                                    <input type="checkbox"
                                                                           :checked="estaResponsableSeleccionado(u.id)"
                                                                           class="rounded text-blue-600 focus:ring-blue-500 pointer-events-none">
                                                                    <span class="text-sm text-gray-900 dark:text-gray-100 truncate" x-text="u.nombre"></span>
                                                                </div>
                                                                <span class="text-xs text-gray-400 dark:text-gray-500" x-text="u.perfil"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>

                                        <template x-if="responsablesOcultos.length > 0">
                                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                                Responsables que no aparecen en esta lista:
                                                <span class="font-medium" x-text="responsablesOcultos.map(function (r) { return r.nombre; }).join(', ')"></span>.
                                                Se mantienen al guardar si siguen activos; «Limpiar selección» los quita.
                                            </p>
                                        </template>

                                        <div class="flex justify-end gap-2 pt-1">
                                            <button type="button" @click="detalle.mostrarAsignar = false"
                                                    class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                                Cancelar
                                            </button>
                                            <button type="button" @click="asignarResponsables()"
                                                    :disabled="detalle.enviando || detalle.asignarUsuarioIds.length === 0"
                                                    class="min-h-[40px] px-4 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition inline-flex items-center gap-1.5">
                                                <span x-text="'Guardar asignación (' + detalle.asignarUsuarioIds.length + ')'"></span>
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                <!-- Panel de cambiar prioridad -->
                                <template x-if="puedeEditarPrioridad && detalle.mostrarPrioridad">
                                    <div class="space-y-2">
                                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide">Cambiar prioridad</p>
                                        <select x-model="detalle.prioridadNueva"
                                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                                            <option value="baja">Baja</option>
                                            <option value="normal">Normal</option>
                                            <option value="alta">Alta</option>
                                            <option value="urgente">Urgente</option>
                                        </select>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="detalle.mostrarPrioridad = false"
                                                    class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                                Cancelar
                                            </button>
                                            <button type="button" @click="guardarPrioridad()"
                                                    :disabled="detalle.enviando || detalle.prioridadNueva === detalle.ticket.prioridad"
                                                    class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                                Guardar
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                <!-- Panel de cierre: foto opcional antes de confirmar -->
                                <template x-if="detalle.mostrarCierre">
                                    <div class="space-y-2">
                                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide">Foto de cierre (opcional)</p>
                                        <div class="flex flex-wrap gap-2" x-show="cierreFotos.length > 0">
                                            <template x-for="(foto, idx) in cierreFotos" :key="foto.url">
                                                <div class="relative w-16 h-16">
                                                    <img :src="foto.url" class="w-16 h-16 object-cover rounded-lg border border-gray-300 dark:border-gray-600">
                                                    <button type="button" @click="quitarCierreFoto(idx)"
                                                            class="absolute -top-1.5 -right-1.5 w-5 h-5 flex items-center justify-center rounded-full bg-red-600 text-white text-xs leading-none"
                                                            aria-label="Quitar foto">×</button>
                                                </div>
                                            </template>
                                        </div>
                                        <label x-show="cierreFotos.length < 3"
                                               class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                                            <i data-lucide="camera" class="w-4 h-4"></i>
                                            <span>Agregar foto</span>
                                            <input type="file" accept="image/*" multiple class="hidden" @change="onCierreFotoSeleccionada($event)">
                                        </label>
                                        <div class="flex gap-2 pt-1">
                                            <button type="button" @click="cancelarCierre()" :disabled="detalle.enviando"
                                                    class="flex-1 min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                                Cancelar
                                            </button>
                                            <button type="button" @click="confirmarCierre()" :disabled="detalle.enviando"
                                                    class="flex-1 min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-600 hover:bg-gray-700 disabled:opacity-50 text-white transition">
                                                <span x-text="detalle.enviando ? 'Cerrando...' : (cierreFotos.length > 0 ? 'Cerrar con foto' : 'Cerrar sin foto')"></span>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="detalle.ticket.estado === 'cerrado'">
                            <p class="text-xs text-gray-500 dark:text-gray-400 italic pt-3 border-t border-gray-200 dark:border-gray-700">
                                Este ticket está cerrado y no puede modificarse.
                            </p>
                        </template>

                        <!-- Comentarios: historial solo-append, visible para quien pudo abrir
                             el detalle (mismo criterio de acceso, ver TicketsController). -->
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-2">
                                Comentarios <span x-text="'(' + detalle.comentarios.length + ')'"></span>
                            </p>
                            <template x-if="detalle.comentarios.length === 0">
                                <p class="text-xs text-gray-500 dark:text-gray-400 italic mb-2">Sin comentarios todavía.</p>
                            </template>
                            <ul class="space-y-2 mb-3 max-h-52 overflow-y-auto">
                                <template x-for="c in detalle.comentarios" :key="c.id">
                                    <li class="text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-2">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            <span x-text="c.usuario_nombre"></span> · <span x-text="fechaCorta(c.created_at)"></span>
                                        </p>
                                        <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap" x-text="c.comentario"></p>
                                    </li>
                                </template>
                            </ul>
                            <textarea x-model="detalle.comentarioNuevo" rows="2" maxlength="2000"
                                      placeholder="Escribe un comentario..."
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm mb-2"></textarea>
                            <div class="flex items-center justify-between gap-2">
                                <label class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                    <input type="checkbox" x-model="detalle.avisarSupervisora">
                                    Avisar a Supervisora / Admin
                                </label>
                                <button type="button" @click="comentar()"
                                        :disabled="!detalle.comentarioNuevo || detalle.enviandoComentario"
                                        class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                    Comentar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

