<?php
/**
 * Modal reutilizable "Nuevo ticket".
 * Incluir en layout.php (si el usuario tiene permiso tickets.crear).
 *
 * Para abrirlo desde cualquier parte, dispatch del evento:
 *   this.$dispatch('abrir-modal-ticket', { habitacionId: 42, hotelCodigo: '1_sur' })
 *
 * Al crearse exitosamente, emite 'ticket-creado' con el ticket como detail.
 * La página consumidora puede escuchar para refrescar listas.
 */
// Asignar de inmediato al crear: mismo permiso que ya gatea /asignar (panel "Asignar
// responsable" de tickets.php) y el mismo componente se usa en toda la app (aquí y en
// "Reportar un problema" de habitacion-detalle.php), así que la regla aplica parejo
// donde sea que se abra.
$ticketPuedeAsignar = $usuario->tienePermiso('tickets.ver_todos');
// Elegir la prioridad al crear (en vez de nacer siempre en 'normal') exige el mismo
// permiso que después permite cambiarla — ver TicketsController::crear() y ::cambiarPrioridad().
$ticketPuedeEditarPrioridad = $usuario->tienePermiso('tickets.editar_prioridad');

$modalTicketNuevoJsFile = __DIR__ . '/../recursos/componentes/modal-ticket-nuevo.js';
$modalTicketNuevoJsV = @filemtime($modalTicketNuevoJsFile) ?: '1';
?>
<script src="<?= u('/views/recursos/componentes/modal-ticket-nuevo.js') ?>?v=<?= $modalTicketNuevoJsV ?>"></script>

<div x-data="modalTicketNuevo(<?= $ticketPuedeAsignar ? 'true' : 'false' ?>, <?= $ticketPuedeEditarPrioridad ? 'true' : 'false' ?>)"
     @abrir-modal-ticket.window="abrir($event.detail || {})">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()">
        <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reportar problema</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Cuéntanos qué pasa y lo revisaremos.</p>
                </div>
                <button @click="cerrar()" class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cerrar">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <form @submit.prevent="crear()" class="space-y-3">
                <!-- Hotel (requerido) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Hotel *</label>
                    <select x-model.number="form.hotel_id"
                            :disabled="form.habitacion_id !== null"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px] disabled:opacity-60">
                        <option :value="null">Selecciona un hotel</option>
                        <template x-for="h in hoteles" :key="h.id">
                            <option :value="h.id" x-text="h.nombre"></option>
                        </template>
                    </select>
                </div>

                <!-- Habitación (opcional) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Habitación (opcional)</label>
                    <template x-if="form.habitacion_id !== null">
                        <div class="flex items-center gap-2 px-3 py-2 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-sm">
                            <i data-lucide="bed" class="w-4 h-4"></i>
                            <span x-text="'Habitación ' + (habitacionSeleccionadaNumero || form.habitacion_id)"></span>
                            <button type="button" @click="quitarHabitacion()" class="ml-auto text-xs text-blue-700 dark:text-blue-300 hover:underline">Cambiar</button>
                        </div>
                    </template>
                    <template x-if="form.habitacion_id === null">
                        <input type="text"
                               x-model="busquedaHabitacion"
                               @focus="abrirBuscador = true"
                               @input="abrirBuscador = true"
                               placeholder="Buscar por número..."
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                    </template>
                    <template x-if="form.habitacion_id === null && abrirBuscador && habitacionesFiltradas().length > 0">
                        <ul class="mt-1 max-h-40 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="hab in habitacionesFiltradas().slice(0, 10)" :key="hab.id">
                                <li>
                                    <button type="button" @click="seleccionarHabitacion(hab)"
                                            class="w-full text-left px-3 py-2 text-sm text-gray-900 dark:text-gray-100 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                                        <span class="font-semibold" x-text="hab.numero"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 ml-2" x-text="nombreHotelCorto(hab.hotel_codigo)"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>

                <!-- Descripción -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Descripción del problema *</label>
                        <template x-if="soportaDictado">
                            <button type="button"
                                    @click="toggleDictado()"
                                    :aria-pressed="grabando"
                                    :aria-label="grabando ? 'Detener dictado' : 'Dictar descripción por voz'"
                                    class="min-h-[36px] min-w-[36px] flex items-center justify-center rounded-lg transition"
                                    :class="grabando ? 'bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 animate-pulse' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'">
                                <!-- SVG inline (no data-lucide): lucide.createIcons() reemplaza el nodo <i> por un
                                     <svg> nuevo y Alpine pierde la referencia, dejando iconos huérfanos al alternar. -->
                                <svg x-show="!grabando" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="2" width="6" height="13" rx="3"></rect>
                                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                                    <path d="M12 19v3"></path>
                                </svg>
                                <svg x-show="grabando" x-cloak xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                </svg>
                            </button>
                        </template>
                    </div>
                    <textarea x-model="form.descripcion" rows="4" maxlength="500" required
                              placeholder="Cuéntanos qué pasa..."
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                </div>

                <!-- Prioridad (opcional, solo quien tiene tickets.editar_prioridad) -->
                <template x-if="puedeEditarPrioridad">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Prioridad</label>
                        <select x-model="form.prioridad"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm min-h-[44px]">
                            <option value="baja">Baja</option>
                            <option value="normal">Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </template>

                <!-- Asignar a (opcional, solo Admin/Supervisor — tickets.ver_todos). Mismo patrón
                     (agrupado por perfil, alfabético, atajos de grupo completo) que el panel de
                     "Asignar responsable" de un ticket ya creado, ver tickets.php. -->
                <template x-if="puedeAsignar">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Asignar a (opcional, <span x-text="form.asignadoIds.length"></span> seleccionados)
                            </label>
                            <button type="button" @click="limpiarResponsables()"
                                    x-show="form.asignadoIds.length > 0"
                                    class="text-xs text-rose-600 hover:text-rose-700 dark:text-rose-400 font-medium">
                                Limpiar selección
                            </button>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 mb-1.5">
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

                        <div class="asignar-responsables-scroll border border-gray-200 dark:border-gray-700 rounded-lg divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-900">
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
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Fotos (opcional) -->
                <div>
                    <label class="block text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Fotos (opcional, máx. 3)</label>
                    <div class="flex flex-wrap gap-2 mb-2" x-show="fotos.length > 0">
                        <template x-for="(foto, idx) in fotos" :key="foto.url">
                            <div class="relative w-16 h-16">
                                <img :src="foto.url" class="w-16 h-16 object-cover rounded-lg border border-gray-300 dark:border-gray-600">
                                <button type="button" @click="quitarFoto(idx)"
                                        class="absolute -top-1.5 -right-1.5 w-5 h-5 flex items-center justify-center rounded-full bg-red-600 text-white text-xs leading-none"
                                        aria-label="Quitar foto">×</button>
                            </div>
                        </template>
                    </div>
                    <div x-show="fotos.length < 3" class="flex gap-2">
                        <label class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                            <i data-lucide="camera" class="w-4 h-4"></i>
                            <span>Tomar foto</span>
                            <!-- Sin "multiple": la cámara solo entrega 1 archivo por captura -->
                            <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFotosSeleccionadas($event)">
                        </label>
                        <label class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                            <i data-lucide="image" class="w-4 h-4"></i>
                            <span>Galería</span>
                            <!-- Sin "capture": deja elegir varias fotos de la librería en Android e iOS -->
                            <input type="file" accept="image/*" multiple class="hidden" @change="onFotosSeleccionadas($event)">
                        </label>
                    </div>
                </div>

                <!-- Error inline -->
                <template x-if="error">
                    <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
                </template>

                <!-- Acciones -->
                <div class="flex gap-2 pt-2">
                    <button type="button" @click="cerrar()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="enviando || !formValido()"
                            class="flex-1 min-h-[44px] px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white transition inline-flex items-center justify-center gap-2">
                        <template x-if="enviando">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                        </template>
                        <span x-text="enviando ? 'Enviando...' : 'Crear ticket'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

