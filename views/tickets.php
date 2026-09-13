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
?>

<div x-data="ticketsApp()"
     x-init="cargar().then(() => abrirDesdeUrl()); iniciarRefresco()"
     @ticket-creado.window="onTicketCreado($event.detail)"
     @visibilitychange.window="alVolverVisible()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-5xl mx-auto gap-3">
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

    <main class="pb-24 md:pb-8 px-4 py-4 max-w-5xl mx-auto space-y-4">

        <!-- Filtros -->
        <section data-tour="tk.filtros" class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">
            <div class="flex flex-wrap gap-2 items-center">
                <div class="flex items-center gap-1 flex-wrap">
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Alcance:</span>
                    <template x-for="a in alcanceFiltro" :key="a.valor">
                        <button @click="setAlcance(a.valor)"
                                :class="alcance === a.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                class="min-h-[36px] px-3 py-1 text-xs font-medium rounded-lg border transition"
                                x-text="a.etiqueta"></button>
                    </template>
                </div>
                <div class="flex items-center gap-1 flex-wrap">
                    <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1">Estado:</span>
                    <template x-for="e in estadosFiltro" :key="e.valor">
                        <button @click="setEstado(e.valor)"
                                :class="estado === e.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                class="min-h-[36px] px-3 py-1 text-xs font-medium rounded-lg border transition"
                                x-text="e.etiqueta"></button>
                    </template>
                </div>
                <template x-if="puedeVerTodos">
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mr-1 ml-2">Hotel:</span>
                        <template x-for="h in hotelesFiltro" :key="h.valor">
                            <button @click="setHotel(h.valor)"
                                    :class="hotel === h.valor ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600'"
                                    class="min-h-[36px] px-3 py-1 text-xs font-medium rounded-lg border transition"
                                    x-text="h.etiqueta"></button>
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
                                        <template x-if="t.asignado_a_nombre">
                                            <!-- SVG inline (no data-lucide): ver docs/contexto/errores-conocidos.md
                                                 — createIcons() rompe la referencia de Alpine y duplica el icono. -->
                                            <span> · <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 inline" viewBox="0 0 24 24"
                                                           fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                                <circle cx="9" cy="7" r="4"></circle>
                                                <polyline points="16 11 18 13 22 9"></polyline>
                                            </svg> <span x-text="t.asignado_a_nombre"></span></span>
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
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400" x-text="t.asignado_a_nombre || '—'"></td>
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
                            <template x-if="detalle.ticket.asignado_a">
                                <div>
                                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide mb-1">Asignado a</p>
                                    <p class="text-gray-900 dark:text-gray-100" x-text="detalle.ticket.asignado_a_nombre || ('#' + detalle.ticket.asignado_a)"></p>
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
                                    <template x-if="detalle.ticket.estado === 'abierto' || detalle.ticket.estado === 'en_progreso'">
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

                                <!-- Panel de asignar responsable -->
                                <template x-if="puedeGestionar && detalle.mostrarAsignar">
                                    <div class="space-y-2">
                                        <p class="text-xs uppercase text-gray-500 dark:text-gray-400 tracking-wide">Asignar responsable</p>
                                        <select x-model.number="detalle.asignarUsuarioId"
                                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                                            <option :value="null">Selecciona una persona</option>
                                            <template x-for="g in gruposAsignables()" :key="g.perfil">
                                                <optgroup :label="g.perfil">
                                                    <template x-for="u in g.usuarios" :key="u.id">
                                                        <option :value="u.id" x-text="u.nombre"></option>
                                                    </template>
                                                </optgroup>
                                            </template>
                                        </select>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="detalle.mostrarAsignar = false"
                                                    class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                                Cancelar
                                            </button>
                                            <button type="button" @click="asignarResponsable()"
                                                    :disabled="detalle.enviando || !detalle.asignarUsuarioId"
                                                    class="min-h-[40px] px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white transition">
                                                Asignar
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

<script>
function ticketsApp() {
    return {
        tickets: [],
        total: 0,
        cargando: false,
        sinConexion: !navigator.onLine,
        estado: localStorage.getItem('tickets_estado') || 'abierto',
        hotel: localStorage.getItem('tickets_hotel') || 'ambos',
        // null la primera vez (o si el valor guardado no es válido para el rol actual —
        // localStorage no distingue quién inició sesión en un dispositivo compartido):
        // el default por rol (mios / sin_asignar) se resuelve en cargar(), una vez que
        // se sabe si el usuario gestiona (tickets.ver_todos) o no.
        alcance: localStorage.getItem('tickets_alcance'),
        _intervalId: null,

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        detalle: { abierto: false, ticket: null, enviando: false, mostrarCierre: false, mostrarAsignar: false, asignarUsuarioId: null },
        cierreFotos: [], // [{ file, url }] — fotos opcionales al cerrar, máx. 3
        usuariosAsignables: [], // cargados on-demand la primera vez que se abre el panel de asignar

        // Sin chip "Resueltos" a propósito: "Cerrados" agrupa resuelto+cerrado (ver
        // TicketService::listar()). El estado 'resuelto' sigue existiendo igual — solo
        // no tiene un chip de filtro separado.
        estadosFiltro: [
            { valor: '', etiqueta: 'Todos' },
            { valor: 'abierto', etiqueta: 'Abiertos' },
            { valor: 'en_progreso', etiqueta: 'En progreso' },
            { valor: 'cerrado', etiqueta: 'Cerrados' }
        ],

        hotelesFiltro: [
            { valor: 'ambos', etiqueta: 'Ambos' },
            { valor: '1_sur', etiqueta: 'Atankalama' },
            { valor: 'inn', etiqueta: 'Atankalama INN' }
        ],

        // Trabajador (solo tickets.ver_propios): sus asignados vs. los que puede tomar.
        alcanceFiltroPropio: [
            { valor: 'mios', etiqueta: 'Asignados a mí' },
            { valor: 'sin_asignar', etiqueta: 'Sin asignar' }
        ],
        // Quien gestiona (tickets.ver_todos — Supervisora/Recepción/Admin/Mantenimiento):
        // por defecto los que nadie ha tomado, con "Asignados a mí" para roles que también
        // ejecutan tickets (ej. Mantenimiento) y "Todos" para ver el histórico completo.
        alcanceFiltroGestion: [
            { valor: 'sin_asignar', etiqueta: 'Sin asignar' },
            { valor: 'mios', etiqueta: 'Asignados a mí' },
            { valor: 'todos', etiqueta: 'Todos' }
        ],
        get alcanceFiltro() {
            return this.puedeVerTodos ? this.alcanceFiltroGestion : this.alcanceFiltroPropio;
        },

        get puedeCrear() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.crear'));
        },
        get puedeVerTodos() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.ver_todos'));
        },
        get puedeGestionar() {
            return this.puedeVerTodos;
        },
        get puedeEditarPrioridad() {
            return !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.editar_prioridad'));
        },
        get puedeResolverAsignado() {
            var yo = Alpine.store('auth') && Alpine.store('auth').usuario;
            return !!(yo && this.detalle.ticket && Number(this.detalle.ticket.asignado_a) === Number(yo.id));
        },
        // "Tomar": autoasignarse un ticket abierto y SIN dueño — "todos los tickets pueden ser
        // tomados por cualquier persona". Quien gestiona también puede (además de reasignar).
        get puedeTomar() {
            if (!this.detalle.ticket || this.detalle.ticket.estado !== 'abierto') return false;
            if (this.puedeGestionar) return true;
            var tienePropios = !!(Alpine.store('auth') && Alpine.store('auth').tienePermiso && Alpine.store('auth').tienePermiso('tickets.ver_propios'));
            return tienePropios && !this.detalle.ticket.asignado_a;
        },

        async cargar() {
            this.cargando = true;
            // puedeVerTodos depende de Alpine.store('auth'), que carga los permisos en
            // paralelo (async) apenas arranca la página. Sin esto, la primera carga (el
            // x-init de arriba) puede correr ANTES de que los permisos lleguen y el filtro
            // por defecto (alcance/hotel) saldría mal solo la primera vez. Ver mismo patrón
            // en auditoria-detalle.php.
            var auth = Alpine.store('auth');
            if (auth && !auth.cargado) {
                await auth.cargar();
            }
            // Default de "alcance" según rol (mios / sin_asignar) — se resuelve acá porque
            // depende de puedeVerTodos. Si lo guardado en localStorage no es válido para el
            // rol actual (dispositivo compartido entre trabajador y supervisor, por ejemplo),
            // se recalcula.
            var alcancesValidos = this.alcanceFiltro.map(function (a) { return a.valor; });
            if (alcancesValidos.indexOf(this.alcance) === -1) {
                this.alcance = this.puedeVerTodos ? 'sin_asignar' : 'mios';
                localStorage.setItem('tickets_alcance', this.alcance);
            }
            try {
                var params = [];
                if (this.estado) params.push('estado=' + encodeURIComponent(this.estado));
                if (this.puedeVerTodos && this.hotel && this.hotel !== 'ambos') params.push('hotel=' + encodeURIComponent(this.hotel));
                params.push('alcance=' + encodeURIComponent(this.alcance));
                var url = '/api/tickets' + (params.length ? '?' + params.join('&') : '');
                var r = await apiFetch(url);
                if (r && r.ok) {
                    this.tickets = r.data.tickets || [];
                    this.total = r.data.total || 0;
                }
            } catch (e) {
                // Silencioso — estado de error por toast si falla al actuar
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

        setEstado(valor) {
            this.estado = valor;
            localStorage.setItem('tickets_estado', valor);
            this.cargar();
        },

        setHotel(valor) {
            this.hotel = valor;
            localStorage.setItem('tickets_hotel', valor);
            this.cargar();
        },

        setAlcance(valor) {
            this.alcance = valor;
            localStorage.setItem('tickets_alcance', valor);
            this.cargar();
        },

        onTicketCreado(ticket) {
            var fallidas = (ticket && ticket._adjuntos_fallidos) || [];
            if (fallidas.length > 0) {
                this.mostrarToast('error', 'Ticket creado, pero ' + fallidas.length + ' foto(s) no se pudieron subir.');
            } else {
                this.mostrarToast('exito', 'Ticket creado. Gracias por reportar.');
            }
            this.cargar();
        },

        // Deep-link desde la notificación de un comentario (ver TicketService::comentar,
        // url '/tickets?ticket={id}') — sin esto, la campanita solo llevaría a la lista
        // genérica y la Supervisora tendría que buscar el ticket a mano.
        async abrirDesdeUrl() {
            var id = parseInt(new URLSearchParams(window.location.search).get('ticket'), 10);
            if (!id) return;
            var enLista = this.tickets.find(function (t) { return t.id === id; });
            if (enLista) {
                this.abrirDetalle(enLista);
                return;
            }
            // No está en la página actual (los filtros activos no lo incluyen) — se pide suelto.
            try {
                var r = await apiFetch('/api/tickets/' + id);
                if (r && r.ok) this.abrirDetalle(r.data.ticket);
            } catch (e) {
                // Deep-link no crítico: si falla, el usuario igual puede buscarlo a mano.
            }
        },

        async abrirDetalle(t) {
            this.detalle = {
                abierto: true, ticket: t, enviando: false, mostrarCierre: false, mostrarAsignar: false,
                asignarUsuarioId: null, mostrarPrioridad: false, prioridadNueva: null,
                comentarios: [], comentarioNuevo: '', avisarSupervisora: false, enviandoComentario: false,
            };
            this.$nextTick(function () { lucide.createIcons(); });
            // La fila de la lista no trae adjuntos (listar() no hace ese join) — se
            // completan acá sin perder hotel_codigo/habitacion_numero/levantado_por_nombre
            // que sí trae la lista y que obtener() no devuelve.
            try {
                var r = await apiFetch('/api/tickets/' + t.id);
                if (r && r.ok && this.detalle.ticket && this.detalle.ticket.id === t.id) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, { adjuntos: r.data.adjuntos });
                }
            } catch (e) {
                // Sin adjuntos no bloqueamos el detalle — se ve igual, solo sin fotos.
            }
            try {
                var rc = await apiFetch('/api/tickets/' + t.id + '/comentarios');
                if (rc && rc.ok && this.detalle.ticket && this.detalle.ticket.id === t.id) {
                    this.detalle.comentarios = rc.data.comentarios || [];
                }
            } catch (e) {
                // Sin comentarios no bloqueamos el detalle — se ve igual, solo sin historial.
            }
        },

        cerrarDetalle() {
            this.limpiarCierreFotos();
            this.detalle = {
                abierto: false, ticket: null, enviando: false, mostrarCierre: false, mostrarAsignar: false,
                asignarUsuarioId: null, mostrarPrioridad: false, prioridadNueva: null,
                comentarios: [], comentarioNuevo: '', avisarSupervisora: false, enviandoComentario: false,
            };
        },

        async comentar() {
            if (!this.detalle.ticket || !this.detalle.comentarioNuevo || this.detalle.enviandoComentario) return;
            this.detalle.enviandoComentario = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/comentarios', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        comentario: this.detalle.comentarioNuevo,
                        avisar_supervisora: this.detalle.avisarSupervisora,
                    })
                });
                if (r && r.ok) {
                    this.detalle.comentarios = r.data.comentarios || [];
                    this.detalle.comentarioNuevo = '';
                    this.detalle.avisarSupervisora = false;
                    this.mostrarToast('exito', 'Comentario agregado.');
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos agregar el comentario.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviandoComentario = false;
            }
        },

        async abrirAsignar() {
            this.detalle.asignarUsuarioId = (this.detalle.ticket && this.detalle.ticket.asignado_a) || null;
            this.detalle.mostrarAsignar = true;
            if (this.usuariosAsignables.length === 0) {
                try {
                    var r = await apiFetch('/api/tickets/usuarios-asignables');
                    if (r && r.ok) this.usuariosAsignables = r.data.usuarios || [];
                } catch (e) {
                    this.mostrarToast('error', 'No pudimos cargar la lista de personas.');
                }
            }
        },

        // Agrupa por perfil respetando la jerarquía y ordena alfabético dentro del grupo.
        // localeCompare('es') para que los nombres con tilde queden donde corresponde.
        // El servidor ya filtra a solo Trabajadores cuando quien asigna no tiene
        // tickets.asignar_a_cualquier_perfil (ver TicketsController::usuariosAsignables).
        gruposAsignables() {
            var orden = ['Admin', 'Supervisora', 'Recepción', 'Trabajador'];
            var mapa = {};
            this.usuariosAsignables.forEach(function (u) {
                var p = u.perfil || 'Sin perfil';
                (mapa[p] = mapa[p] || []).push(u);
            });
            // Perfiles fuera de la jerarquía (roles creados a mano en RBAC) van al final.
            var extras = Object.keys(mapa).filter(function (p) { return orden.indexOf(p) === -1; })
                                          .sort(function (a, b) { return a.localeCompare(b, 'es'); });
            return orden.concat(extras).filter(function (p) { return mapa[p]; }).map(function (p) {
                return {
                    perfil: p,
                    usuarios: mapa[p].sort(function (a, b) { return a.nombre.localeCompare(b.nombre, 'es'); }),
                };
            });
        },

        async asignarResponsable() {
            if (!this.detalle.ticket || !this.detalle.asignarUsuarioId || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/asignar', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario_id: this.detalle.asignarUsuarioId })
                });
                if (r && r.ok) {
                    var nombre = (this.usuariosAsignables.find((u) => u.id === this.detalle.asignarUsuarioId) || {}).nombre;
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket, {
                        asignado_a_nombre: nombre || this.detalle.ticket.asignado_a_nombre,
                    });
                    this.detalle.mostrarAsignar = false;
                    this.mostrarToast('exito', 'Responsable asignado.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos asignar el responsable.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        abrirPrioridad() {
            this.detalle.prioridadNueva = this.detalle.ticket.prioridad;
            this.detalle.mostrarPrioridad = true;
        },

        async guardarPrioridad() {
            if (!this.detalle.ticket || !this.detalle.prioridadNueva || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/prioridad', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prioridad: this.detalle.prioridadNueva })
                });
                if (r && r.ok) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket);
                    this.detalle.mostrarPrioridad = false;
                    this.mostrarToast('exito', 'Prioridad actualizada.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cambiar la prioridad.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        urlAdjunto(ruta) {
            return (window.BASE_PATH || '') + '/uploads/' + ruta;
        },

        async onCierreFotoSeleccionada(event) {
            var espacio = 3 - this.cierreFotos.length;
            var archivos = Array.from(event.target.files || []).slice(0, espacio);
            event.target.value = '';
            // Comprimir antes de mostrar/subir — ver comprimirFotoParaSubir() en app.js.
            for (var i = 0; i < archivos.length; i++) {
                var comprimido = await comprimirFotoParaSubir(archivos[i], 1600, 0.8);
                this.cierreFotos.push({ file: comprimido, url: URL.createObjectURL(comprimido) });
            }
        },

        quitarCierreFoto(idx) {
            URL.revokeObjectURL(this.cierreFotos[idx].url);
            this.cierreFotos.splice(idx, 1);
        },

        limpiarCierreFotos() {
            this.cierreFotos.forEach(function (f) { URL.revokeObjectURL(f.url); });
            this.cierreFotos = [];
        },

        cancelarCierre() {
            this.detalle.mostrarCierre = false;
            this.limpiarCierreFotos();
        },

        async confirmarCierre() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var datos = new FormData();
                this.cierreFotos.forEach(function (f) { datos.append('fotos[]', f.file); });
                var r = await apiPostForm('/api/tickets/' + this.detalle.ticket.id + '/cerrar', datos);
                if (r && r.ok) {
                    this.detalle.ticket = Object.assign({}, this.detalle.ticket, r.data.ticket, { adjuntos: r.data.adjuntos });
                    this.detalle.mostrarCierre = false;
                    this.limpiarCierreFotos();
                    var fallidas = r.data.adjuntos_fallidos || [];
                    this.mostrarToast(
                        fallidas.length > 0 ? 'error' : 'exito',
                        fallidas.length > 0 ? 'Ticket cerrado, pero ' + fallidas.length + ' foto(s) no se pudieron subir.' : 'Ticket cerrado.'
                    );
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos cerrar el ticket.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        async tomar() {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            var usuarioId = Alpine.store('auth') && Alpine.store('auth').usuario && Alpine.store('auth').usuario.id;
            if (!usuarioId) return;
            this.detalle.enviando = true;
            try {
                var rA = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/asignar', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario_id: usuarioId })
                });
                if (!rA || !rA.ok) {
                    this.mostrarToast('error', (rA && rA.error && rA.error.mensaje) || 'No pudimos tomar el ticket.');
                    return;
                }
                await this.cambiarEstado('en_progreso');
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        async cambiarEstado(nuevo) {
            if (!this.detalle.ticket || this.detalle.enviando) return;
            this.detalle.enviando = true;
            try {
                var r = await apiFetch('/api/tickets/' + this.detalle.ticket.id + '/estado', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ estado: nuevo })
                });
                if (r && r.ok) {
                    this.detalle.ticket = { ...this.detalle.ticket, ...r.data.ticket };
                    this.mostrarToast('exito', 'Ticket actualizado.');
                    this.cargar();
                } else {
                    this.mostrarToast('error', (r && r.error && r.error.mensaje) || 'No pudimos actualizar.');
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.detalle.enviando = false;
            }
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 2500);
        },

        // --- Helpers visuales ---

        etiquetaAlcanceActual() {
            var op = this.alcanceFiltro.find(function (a) { return a.valor === this.alcance; }, this);
            return op ? op.etiqueta : '';
        },

        nombreHotelCorto(codigo) {
            if (codigo === 'inn') return 'Atankalama INN';
            if (codigo === '1_sur') return 'Atankalama';
            return codigo || '';
        },

        etiquetaPrioridad(p) {
            if (p === 'urgente') return 'Urgente';
            if (p === 'alta') return 'Alta';
            if (p === 'normal') return 'Normal';
            return 'Baja';
        },

        etiquetaEstado(e) {
            if (e === 'abierto') return 'Abierto';
            if (e === 'en_progreso') return 'En progreso';
            if (e === 'resuelto') return 'Resuelto';
            return 'Cerrado';
        },

        claseBordePrioridad(p) {
            if (p === 'urgente') return 'border-l-4 border-l-red-600';
            if (p === 'alta') return 'border-l-4 border-l-amber-500';
            if (p === 'normal') return 'border-l-4 border-l-blue-500';
            return '';
        },

        claseBadgePrioridad(p) {
            if (p === 'urgente') return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            if (p === 'alta') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (p === 'normal') return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
        },

        claseBadgeEstado(e) {
            if (e === 'abierto') return 'bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-300';
            if (e === 'en_progreso') return 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300';
            if (e === 'resuelto') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            return 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400';
        },

        // Semáforo de espera: cuánto lleva el ticket sin resolverse (created_at → ahora).
        // Un ticket resuelto/cerrado congela el reloj y se pinta verde. Umbrales de
        // negocio en horas: 12 / 24 / 36.
        estaTerminado(t) {
            return !!t && (t.estado === 'resuelto' || t.estado === 'cerrado');
        },

        esperaMinutos(t) {
            if (!t || !t.created_at) return null;
            var inicio = new Date(t.created_at).getTime();
            var fin = this.estaTerminado(t)
                ? new Date(t.resuelto_at || t.updated_at || t.created_at).getTime()
                : Date.now();
            if (isNaN(inicio) || isNaN(fin)) return null;
            return Math.max(0, Math.floor((fin - inicio) / 60000));
        },

        esperaTexto(t) {
            var min = this.esperaMinutos(t);
            if (min === null) return '';
            if (min < 60) return min + ' min';
            var hrs = Math.floor(min / 60);
            if (hrs < 24) return hrs + ' h';
            var dias = Math.floor(hrs / 24);
            var resto = hrs % 24;
            return dias + ' d' + (resto > 0 ? ' ' + resto + ' h' : '');
        },

        // 'verde' | 'azul' | 'amarillo' | 'naranjo' | 'rojo'
        nivelEspera(t) {
            if (this.estaTerminado(t)) return 'verde';
            var min = this.esperaMinutos(t);
            if (min === null) return 'azul';
            var hrs = min / 60;
            if (hrs < 12) return 'azul';
            if (hrs < 24) return 'amarillo';
            if (hrs < 36) return 'naranjo';
            return 'rojo';
        },

        claseEsperaChip(t) {
            var n = this.nivelEspera(t);
            if (n === 'verde')    return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            if (n === 'amarillo') return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300';
            if (n === 'naranjo')  return 'bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300';
            if (n === 'rojo')     return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
            return 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300';
        },

        claseEsperaPunto(t) {
            var n = this.nivelEspera(t);
            if (n === 'verde')    return 'bg-green-500';
            if (n === 'amarillo') return 'bg-yellow-500';
            if (n === 'naranjo')  return 'bg-orange-500';
            if (n === 'rojo')     return 'bg-red-600';
            return 'bg-blue-500';
        },

        tituloEspera(t) {
            return this.estaTerminado(t)
                ? 'Tiempo total hasta resolverse'
                : 'Tiempo en espera desde que se reportó';
        },

        fechaCorta(iso) {
            try {
                var d = new Date(iso);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var mi = String(d.getMinutes()).padStart(2, '0');
                return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi;
            } catch (e) { return iso; }
        },

        fechaRelativa(iso) {
            try {
                var d = new Date(iso);
                var diffMs = Date.now() - d.getTime();
                var diffMin = Math.floor(diffMs / 60000);
                if (diffMin < 1) return 'ahora';
                if (diffMin < 60) return 'hace ' + diffMin + ' min';
                var diffHr = Math.floor(diffMin / 60);
                if (diffHr < 24) return 'hace ' + diffHr + ' h';
                var diffD = Math.floor(diffHr / 24);
                if (diffD < 7) return 'hace ' + diffD + ' d';
                return this.fechaCorta(iso).slice(0, 10);
            } catch (e) { return ''; }
        }
    };
}
</script>
