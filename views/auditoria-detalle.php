<?php
/**
 * Pantalla de Auditoría.
 * Spec: docs/auditoria.md, docs/home-recepcion.md
 *
 * Dos modos:
 *  - Pendiente: estado='completada_pendiente_auditoria', sin registro en auditorias.
 *    Muestra 4 botones (Aprobar / Aprobar c/obs. / Rechazar / No se pudo inspeccionar) filtrados por permisos.
 *  - Histórica: habitación ya auditada (aprobada / aprobada_con_observacion / rechazada).
 *    Vista read-only con badge "Auditada", resumen, sin botones.
 *
 * Reglas:
 *  - Aprobar: confirm modal simple.
 *  - Aprobar con observación: entra a modo edición — checklist desmarcable; las casillas
 *    desmarcadas (>=1) SON la observación (v7.3, sin comentario escrito).
 *  - Rechazar: desmarca los ítems fallidos (>=1, se re-limpian) + elige el motivo de la
 *    lista «rechazo» (v7.3, Ajustes → Checklists y listas), botón "Confirmar rechazo".
 *  - No se pudo inspeccionar (v7.11, veredicto 'sin_inspeccion'; en reportes «Sin inspección»): ya
 *    hay un huésped, por ejemplo. Elige el motivo de la lista «sin_inspeccion», sin tocar el checklist;
 *    la pieza sale de la bandeja y queda como las que aprueba el sistema (aprobada_automatica, Clean).
 *  Desde la v7.3 (gerencia, 10/10/2026) no hay cuadro de texto ni dictado por voz.
 *  - Inmutabilidad: backend rechaza con 409 si ya hay auditoría.
 *
 * Variables requeridas: $usuario, $habitacionId (int)
 */

require_once __DIR__ . '/componentes/badge-estado.php';
?>

<div x-data="auditoriaDetalleApp(<?= (int) $habitacionId ?>)"
     x-init="cargar()"
     @keydown.window="if ($event.key === 'Alt') { altActivo = true; } else { manejarAccessKey($event); }"
     @keyup.window="altActivo = false"
     @keydown.escape.window="manejarEscape()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-3xl mx-auto gap-3">
            <a href="<?= u('/home') ?>"
               class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
               aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
            </a>
            <h1 class="flex-1 text-lg font-semibold text-gray-900 dark:text-gray-100 text-center truncate">
                <template x-if="habitacion">
                    <span>
                        <span x-text="etiquetaHabitacion()"></span>
                    </span>
                </template>
                <template x-if="!habitacion">
                    <span>Inspección</span>
                </template>
            </h1>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <!-- Toast de resultado -->
    <div x-show="toast.visible" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg shadow-lg text-white text-sm font-medium max-w-sm w-[90%] text-center"
         :class="toast.tipo === 'exito' ? 'bg-green-600' : 'bg-red-600'"
         x-text="toast.mensaje"></div>

    <!-- Carga -->
    <template x-if="cargando && !habitacion">
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
    <template x-if="error && !habitacion">
        <div class="min-h-[60vh] flex items-center justify-center px-4">
            <div class="text-center max-w-xs">
                <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mx-auto mb-3"></i>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar la inspección</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-4" x-text="error"></p>
                <button @click="cargar()"
                        class="min-h-[44px] px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Reintentar
                </button>
            </div>
        </div>
    </template>

    <!-- Contenido -->
    <template x-if="habitacion">
        <main class="pb-24 md:pb-8 px-4 py-4 max-w-3xl mx-auto space-y-4"
              :class="esAuditada ? 'opacity-75' : ''">

            <!-- Tarjeta habitación -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        <p class="text-4xl font-bold text-gray-900 dark:text-gray-100" x-text="habitacion.numero"></p>
                        <p class="text-base text-gray-600 dark:text-gray-400 mt-1" x-text="habitacion.tipo_nombre"></p>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-0.5" x-text="habitacion.hotel_nombre"></p>
                        <!-- v7.5: habitación de prueba. Ver docs/habitacion-prueba.md -->
                        <template x-if="habitacion.es_prueba">
                            <p class="mt-2"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">De prueba · sin Cloudbeds</span></p>
                        </template>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <span x-html="badgeEstado(habitacion.estado)"></span>
                        <!-- «No se pudo inspeccionar» (v7.11) no lleva «Inspeccionada»: lo dice el resumen de abajo. -->
                        <template x-if="esAuditada && !(auditoria && auditoria.veredicto === 'sin_inspeccion')">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs font-medium rounded-full">
                                <i data-lucide="lock" class="w-3 h-3"></i> Inspeccionada
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Resumen histórico -->
            <template x-if="esAuditada && auditoria">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                             :class="{
                                'bg-green-100 dark:bg-green-900/30': auditoria.veredicto === 'aprobado',
                                'bg-amber-100 dark:bg-amber-900/30': auditoria.veredicto === 'aprobado_con_observacion',
                                'bg-cyan-100 dark:bg-cyan-900/30': auditoria.veredicto === 'aprobado_automatico',
                                'bg-slate-200 dark:bg-slate-700/50': auditoria.veredicto === 'sin_inspeccion',
                                'bg-red-100 dark:bg-red-900/30': auditoria.veredicto === 'rechazado'
                             }">
                            <i :data-lucide="iconoVeredicto(auditoria.veredicto)" class="w-5 h-5"
                               :class="{
                                    'text-green-600 dark:text-green-400': auditoria.veredicto === 'aprobado',
                                    'text-amber-600 dark:text-amber-400': auditoria.veredicto === 'aprobado_con_observacion',
                                    'text-cyan-600 dark:text-cyan-400': auditoria.veredicto === 'aprobado_automatico',
                                    'text-slate-600 dark:text-slate-300': auditoria.veredicto === 'sin_inspeccion',
                                    'text-red-600 dark:text-red-400': auditoria.veredicto === 'rechazado'
                               }"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-gray-900 dark:text-gray-100" x-text="etiquetaVeredicto(auditoria.veredicto)"></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400" x-text="fechaFormateada(auditoria.created_at)"></p>
                        </div>
                    </div>
                    <template x-if="auditoria.comentario">
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide" x-text="(auditoria.veredicto === 'rechazado' || auditoria.veredicto === 'sin_inspeccion') ? 'Motivo' : 'Observación'"></p>
                            <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap" x-text="auditoria.comentario"></p>
                        </div>
                    </template>
                    <template x-if="auditoria.items_desmarcados && auditoria.items_desmarcados.length > 0">
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">
                                Items desmarcados por el inspector (<span x-text="auditoria.items_desmarcados.length"></span>)
                            </p>
                            <ul class="text-sm text-gray-800 dark:text-gray-200 space-y-1">
                                <template x-for="itId in auditoria.items_desmarcados" :key="itId">
                                    <li class="flex items-start gap-2">
                                        <i data-lucide="x" class="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"></i>
                                        <span x-text="descripcionItem(itId)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Banner modo (observación / rechazo) -->
            <template x-if="modo === 'observacion'">
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 flex items-start gap-3">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-amber-900 dark:text-amber-200">
                        <p class="font-semibold">Aprobando con observación</p>
                        <p>Desmarca las casillas que encontraste mal: esas casillas son la observación. La habitación queda limpia igual.</p>
                        <p class="mt-1 font-medium" x-show="itemsDesmarcadosNuevos.length === 0">Desmarca al menos una casilla.</p>
                    </div>
                </div>
            </template>
            <template x-if="modo === 'rechazo'">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 flex items-start gap-3">
                    <i data-lucide="x-circle" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-red-900 dark:text-red-200">
                        <p class="font-semibold">Rechazando habitación</p>
                        <p>Marca los ítems que quedaron mal (se re-limpiarán) y elige el motivo más abajo. La habitación volverá a estado sucia.</p>
                        <p class="mt-1 font-medium" x-show="itemsDesmarcadosNuevos.length === 0">Selecciona al menos un ítem fallido.</p>
                        <p class="mt-1 font-medium" x-show="itemsDesmarcadosNuevos.length > 0 && motivoId === null">Elige el motivo del rechazo.</p>
                    </div>
                </div>
            </template>
            <template x-if="modo === 'sin_inspeccion'">
                <div class="bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg p-3 flex items-start gap-3">
                    <i data-lucide="door-closed" class="w-5 h-5 text-slate-600 dark:text-slate-300 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-slate-900 dark:text-slate-100">
                        <p class="font-semibold">No se pudo inspeccionar</p>
                        <p>
                            <span>Elige por qué. Sale de la lista de Inspección para que nadie más vaya y queda como las que aprueba el sistema</span><span x-show="!esEspacio"> (Clean en Cloudbeds)</span><span>. No se puede deshacer.</span>
                        </p>
                        <p class="mt-1 font-medium" x-show="motivoId === null">Elige el motivo.</p>
                    </div>
                </div>
            </template>

            <!-- Checklist (en «No se pudo inspeccionar» no se muestra: no se revisó nada) -->
            <template x-if="items.length > 0 && modo !== 'sin_inspeccion'">
                <div data-tour="aud2.checklist" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Checklist ejecutado</p>
                    </div>
                    <template x-for="item in items" :key="item.id">
                        <label class="flex items-start gap-3 px-4 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0"
                               :class="puedeDesmarcar ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50' : 'cursor-default'">
                            <input type="checkbox"
                                   :checked="estaMarcadoVisual(item)"
                                   :disabled="!puedeDesmarcar"
                                   @change="toggleDesmarcado(item, $event)"
                                   class="mt-1 w-6 h-6 rounded border-2 border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-2 focus:ring-blue-500 disabled:opacity-60 flex-shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-base text-gray-900 dark:text-gray-100"
                                   :class="estaMarcadoVisual(item) ? 'line-through text-gray-400 dark:text-gray-500' : ''"
                                   x-text="item.descripcion"></p>
                                <div class="flex items-center gap-2 mt-1">
                                    <template x-if="item.obligatorio == 0">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Opcional</span>
                                    </template>
                                    <template x-if="item.desmarcado_por_auditor == 1">
                                        <span class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                            <i data-lucide="alert-triangle" class="w-3 h-3"></i> Inspector desmarcó
                                        </span>
                                    </template>
                                    <template x-if="itemsDesmarcadosNuevos.includes(item.id)">
                                        <span class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                            <i data-lucide="minus-circle" class="w-3 h-3"></i> Desmarcado ahora
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </label>
                    </template>
                </div>
            </template>

            <!-- Motivo del rechazo y de «No se pudo inspeccionar» (v7.11): se elige de la lista (v7.3, gerencia
                 10/10/2026: sin cuadros de texto). La observación no lleva motivo: son las casillas desmarcadas.
                 Opciones en Ajustes → Checklists y listas. -->
            <template x-if="modo === 'rechazo' || modo === 'sin_inspeccion'">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-tour="aud2.motivo">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"><span x-text="modo === 'rechazo' ? 'Motivo del rechazo' : '¿Por qué no se pudo inspeccionar?'"></span> <span class="text-red-500">*</span></p>
                    <template x-if="motivosCargando && motivosDelModo.length === 0">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Cargando motivos...</p>
                    </template>
                    <template x-if="motivosError">
                        <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-sm text-red-700 dark:text-red-300">
                            <p x-text="motivosError"></p>
                            <button type="button" @click="cargarMotivos()" class="mt-2 min-h-[40px] px-3 rounded-lg bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 font-medium">Reintentar</button>
                        </div>
                    </template>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup" :aria-label="modo === 'rechazo' ? 'Motivo del rechazo' : 'Motivo de no inspeccionar'">
                        <template x-for="m in motivosDelModo" :key="m.id">
                            <button type="button" role="radio" :aria-checked="motivoId === m.id ? 'true' : 'false'"
                                    @click="motivoId = m.id" :disabled="enviando"
                                    :class="motivoId !== m.id
                                        ? 'border-gray-200 dark:border-gray-600 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'
                                        : (modo === 'rechazo'
                                            ? 'border-red-500 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200'
                                            : 'border-slate-600 bg-slate-100 text-slate-900 dark:border-slate-300 dark:bg-slate-600 dark:text-white')"
                                    class="min-h-[56px] rounded-xl border-2 px-3 text-base font-medium text-left flex items-center justify-between gap-2">
                                <span x-text="m.nombre"></span>
                                <span x-show="motivoId === m.id" aria-hidden="true">✓</span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Botones de acción (modo inicial) -->
            <template x-if="!esAuditada && modo === null">
                <div class="space-y-2" data-tour="aud2.acciones">
                    <template x-if="puedeAprobar">
                        <button @click="pedirConfirmacionAprobar()"
                                class="w-full min-h-[56px] bg-green-600 hover:bg-green-700 active:bg-green-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="check-circle" class="w-5 h-5"></i>
                            <span><span :class="altActivo ? 'underline' : ''">A</span>probar</span>
                        </button>
                    </template>
                    <!-- La observación son casillas desmarcadas (v7.3): sin permiso para desmarcar no se puede dar. -->
                    <template x-if="puedeAprobarConObservacion && puedeEditarChecklist">
                        <button @click="iniciarObservacion()"
                                class="w-full min-h-[56px] bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                            <span>Aprobar con <span :class="altActivo ? 'underline' : ''">o</span>bservación</span>
                        </button>
                    </template>
                    <template x-if="puedeRechazar">
                        <button @click="iniciarRechazo()"
                                class="w-full min-h-[56px] bg-red-600 hover:bg-red-700 active:bg-red-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="x-circle" class="w-5 h-5"></i>
                            <span><span :class="altActivo ? 'underline' : ''">R</span>echazar</span>
                        </button>
                    </template>
                    <!-- v7.11: ya hay un huésped (u otro motivo de la lista): sale de la bandeja para que nadie más vaya. -->
                    <template x-if="puedeSinInspeccion">
                        <button @click="iniciarSinInspeccion()"
                                class="w-full min-h-[56px] bg-slate-600 hover:bg-slate-700 active:bg-slate-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="door-closed" class="w-5 h-5"></i>
                            <span><span :class="altActivo ? 'underline' : ''">N</span>o se pudo inspeccionar</span>
                        </button>
                    </template>
                    <template x-if="!puedeAprobar && !puedeAprobarConObservacion && !puedeRechazar && !puedeSinInspeccion">
                        <p class="text-center text-sm text-gray-500 dark:text-gray-400 py-4">
                            No tienes permisos para emitir veredicto en esta habitación.
                        </p>
                    </template>
                </div>
            </template>

            <!-- Botones en modo observación / rechazo / no se pudo inspeccionar -->
            <template x-if="modo !== null">
                <div class="flex gap-3">
                    <button @click="cancelarModo()"
                            :disabled="enviando"
                            class="flex-1 min-h-[56px] bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-semibold rounded-xl transition">
                        <span :class="altActivo ? 'underline' : ''">C</span>ancelar
                    </button>
                    <button @click="enviarVeredictoModo()"
                            :disabled="!puedeConfirmarVeredicto || enviando"
                            class="flex-1 min-h-[56px] text-white font-semibold rounded-xl transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            :class="modo === 'observacion' ? 'bg-amber-500 hover:bg-amber-600' : (modo === 'rechazo' ? 'bg-red-600 hover:bg-red-700' : 'bg-slate-600 hover:bg-slate-700')">
                        <template x-if="enviando"><span>Enviando...</span></template>
                        <template x-if="!enviando"><span>C<span :class="altActivo ? 'underline' : ''">o</span>nfirmar<span x-show="modo !== 'sin_inspeccion'" x-text="modo === 'observacion' ? ' observación' : ' rechazo'"></span></span></template>
                    </button>
                </div>
            </template>

            <!-- Modal confirmación aprobar -->
            <div x-show="mostrarConfirmarAprobar" x-cloak
                 x-effect="mostrarConfirmarAprobar && $nextTick(() => $refs.btnAprobarModal.focus())"
                 @keydown.tab.window="atraparTabConfirmarAprobar($event)"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="mostrarConfirmarAprobar = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-600 dark:text-green-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            <span x-text="esEspacio ? '¿Aprobar el área?' : '¿Aprobar habitación?'"></span>
                        </h3>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                        <template x-if="esEspacio">
                            <span>Se marcará como aprobada. Esta acción no se puede deshacer.</span>
                        </template>
                        <template x-if="!esEspacio">
                            <span>Se marcará como aprobada y pasará a estado "Clean" en Cloudbeds. Esta acción no se puede deshacer.</span>
                        </template>
                    </p>
                    <div class="flex gap-3">
                        <button x-ref="btnCancelarAprobar" @click="mostrarConfirmarAprobar = false"
                                :disabled="enviando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            <span :class="altActivo ? 'underline' : ''">C</span>ancelar
                        </button>
                        <button x-ref="btnAprobarModal" @click="confirmarAprobar()"
                                :disabled="enviando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <template x-if="enviando"><span>Enviando...</span></template>
                            <template x-if="!enviando"><span><span :class="altActivo ? 'underline' : ''">A</span>probar</span></template>
                        </button>
                    </div>
                </div>
            </div>

        </main>
    </template>
</div>

<script>
function auditoriaDetalleApp(habitacionId) {
    return {
        habitacionId: habitacionId,

        habitacion: null,
        ejecucion: null,
        items: [],
        auditoria: null,

        cargando: false,
        error: null,
        enviando: false,

        // Permisos (del store)
        puedeAprobar: false,
        puedeAprobarConObservacion: false,
        puedeRechazar: false,
        puedeEditarChecklist: false,
        puedeSinInspeccion: false, // v7.11 «No se pudo inspeccionar»

        // Estado de interacción
        modo: null, // null | 'observacion' | 'rechazo' | 'sin_inspeccion'
        itemsDesmarcadosNuevos: [],
        mostrarConfirmarAprobar: false,
        altActivo: false, // Alt (Windows) / Option (Mac) presionado: subraya las teclas de acceso

        // Motivo del rechazo (v7.3): opción de la lista «rechazo», se pide al entrar en modo rechazo. El de
        // «No se pudo inspeccionar» (v7.11) sale de la lista «sin_inspeccion». Una sola a la vez (un modo).
        motivoId: null,
        motivosRechazo: [],
        motivosSinInspeccion: [],
        motivosCargando: false,
        motivosError: '',

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        // Áreas comunes (EspacioService::TIPO_NOMBRE) también pasan por acá desde que
        // se auditan igual que las habitaciones de huésped — ver docs/areas-comunes.md.
        // No sincronizan con Cloudbeds, así que el modal de aprobar no debe prometerlo.
        get esEspacio() {
            return !!this.habitacion && this.habitacion.tipo_nombre === 'Área común';
        },

        get esAuditada() {
            if (this.auditoria) return true;
            if (!this.habitacion) return false;
            var e = this.habitacion.estado;
            return e === 'aprobada' || e === 'aprobada_con_observacion' || e === 'rechazada';
        },

        get puedeDesmarcar() {
            if (this.esAuditada) return false;
            // Observación: requiere el permiso de editar checklist. Rechazo: seleccionar los
            // ítems fallidos es parte del propio rechazo (define qué se re-limpia).
            if (this.modo === 'observacion') return this.puedeEditarChecklist;
            if (this.modo === 'rechazo') return true;
            return false;
        },

        // Opciones de la lista del modo en curso (rechazo o «No se pudo inspeccionar»).
        get motivosDelModo() {
            return this.modo === 'sin_inspeccion' ? this.motivosSinInspeccion : this.motivosRechazo;
        },

        get puedeConfirmarVeredicto() {
            // «No se pudo inspeccionar» solo lleva su motivo: no se revisó nada, no hay casillas.
            if (this.modo === 'sin_inspeccion') return this.motivoId !== null;
            // Los otros dos exigen al menos una casilla: en el rechazo es lo que hay que rehacer y en la
            // observación es la observación misma. El rechazo además lleva su motivo de la lista.
            if (this.itemsDesmarcadosNuevos.length === 0) return false;
            if (this.modo === 'rechazo') return this.motivoId !== null;
            return true;
        },

        etiquetaHabitacion() {
            if (!this.habitacion) return 'Inspección';
            var prefijo = this.habitacion.hotel_codigo === '1_sur' ? 'ATAN' :
                          (this.habitacion.hotel_codigo === 'inn' ? 'INN' : this.habitacion.hotel_codigo);
            return prefijo + '-' + this.habitacion.numero;
        },

        estaMarcadoVisual(item) {
            if (this.itemsDesmarcadosNuevos.indexOf(item.id) !== -1) return false;
            return item.marcado == 1;
        },

        toggleDesmarcado(item, event) {
            if (!this.puedeDesmarcar) {
                // Revertir visualmente
                event.target.checked = this.estaMarcadoVisual(item);
                return;
            }
            // Sólo permitimos desmarcar items que estaban marcados.
            if (item.marcado != 1) {
                event.target.checked = false;
                return;
            }
            var idx = this.itemsDesmarcadosNuevos.indexOf(item.id);
            if (idx === -1) {
                this.itemsDesmarcadosNuevos.push(item.id);
            } else {
                this.itemsDesmarcadosNuevos.splice(idx, 1);
            }
        },

        descripcionItem(itemId) {
            var it = this.items.find(function (i) { return i.id === itemId; });
            return it ? it.descripcion : ('Item #' + itemId);
        },

        iconoVeredicto(v) {
            if (v === 'aprobado') return 'check-circle';
            if (v === 'aprobado_con_observacion') return 'alert-triangle';
            if (v === 'aprobado_automatico') return 'clock';
            if (v === 'sin_inspeccion') return 'door-closed';
            if (v === 'rechazado') return 'x-circle';
            return 'circle';
        },

        etiquetaVeredicto(v) {
            if (v === 'aprobado') return 'Aprobado';
            if (v === 'aprobado_con_observacion') return 'Aprobado con observación';
            if (v === 'aprobado_automatico') return 'Aprobado automático (sin auditoría real)';
            if (v === 'sin_inspeccion') return 'No se pudo inspeccionar';
            if (v === 'rechazado') return 'Rechazado';
            return v;
        },

        fechaFormateada(iso) {
            if (!iso) return '';
            try {
                var d = new Date(iso);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var min = String(d.getMinutes()).padStart(2, '0');
                return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + min;
            } catch (e) {
                return iso;
            }
        },

        async cargar() {
            this.cargando = true;
            this.error = null;

            // Permisos desde el store
            var auth = Alpine.store('auth');
            if (auth && !auth.cargado) {
                await auth.cargar();
            }
            if (auth && auth.cargado) {
                this.puedeAprobar = auth.tienePermiso('auditoria.aprobar');
                this.puedeAprobarConObservacion = auth.tienePermiso('auditoria.aprobar_con_observacion');
                this.puedeRechazar = auth.tienePermiso('auditoria.rechazar');
                this.puedeEditarChecklist = auth.tienePermiso('auditoria.editar_checklist_durante_auditoria');
                this.puedeSinInspeccion = auth.tienePermiso('auditoria.sin_inspeccion');
            }

            try {
                var json = await apiFetch('/api/habitaciones/' + this.habitacionId + '/auditoria');
                if (!json || !json.ok) {
                    this.error = (json && json.error && json.error.mensaje) || 'No pudimos cargar.';
                    return;
                }
                this.habitacion = json.data.habitacion;
                this.ejecucion = json.data.ejecucion;
                this.items = json.data.items || [];
                this.auditoria = json.data.auditoria;

                // Avisar la apertura (KPI "tiempo por auditación") solo si la pieza está pendiente
                // y quien entra puede emitir veredicto. Fire-and-forget: nunca bloquea la pantalla.
                if (!this.esAuditada && (this.puedeAprobar || this.puedeAprobarConObservacion || this.puedeRechazar || this.puedeSinInspeccion)) {
                    apiPost('/api/auditoria/' + this.habitacionId + '/iniciar', {}).catch(function () {});
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        pedirConfirmacionAprobar() {
            if (!this.puedeAprobar || this.esAuditada) return;
            this.mostrarConfirmarAprobar = true;
            this.$nextTick(function () { lucide.createIcons(); });
        },

        async confirmarAprobar() {
            await this.enviarVeredicto('aprobado', null, []);
            this.mostrarConfirmarAprobar = false;
        },

        iniciarObservacion() {
            if (!this.puedeAprobarConObservacion || !this.puedeEditarChecklist || this.esAuditada) return;
            this.modo = 'observacion';
            this.motivoId = null;
            this.itemsDesmarcadosNuevos = [];
            this.$nextTick(function () { lucide.createIcons(); });
        },

        iniciarRechazo() {
            if (!this.puedeRechazar || this.esAuditada) return;
            this.modo = 'rechazo';
            this.motivoId = null;
            this.itemsDesmarcadosNuevos = [];
            this.motivosError = '';
            if (this.motivosRechazo.length === 0) this.cargarMotivos();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        // v7.11: «No se pudo inspeccionar» (ya hay un huésped…). Solo se elige el motivo, el checklist no se toca.
        iniciarSinInspeccion() {
            if (!this.puedeSinInspeccion || this.esAuditada) return;
            this.modo = 'sin_inspeccion';
            this.motivoId = null;
            this.itemsDesmarcadosNuevos = [];
            this.motivosError = '';
            if (this.motivosSinInspeccion.length === 0) this.cargarMotivos();
            this.$nextTick(function () { lucide.createIcons(); });
        },

        // Carga la lista del modo en curso: «rechazo» o «sin_inspeccion».
        async cargarMotivos() {
            var modo = this.modo;
            var lista = modo === 'sin_inspeccion' ? 'sin_inspeccion' : 'rechazo';
            this.motivosCargando = true;
            this.motivosError = '';
            try {
                var json = await apiFetch('/api/listas/' + lista);
                if (json && json.ok) {
                    var opciones = json.data.opciones || [];
                    if (lista === 'sin_inspeccion') { this.motivosSinInspeccion = opciones; } else { this.motivosRechazo = opciones; }
                    if (opciones.length === 0 && this.modo === modo) {
                        this.motivosError = 'No hay motivos cargados. Pídele a administración que los agregue en Ajustes → Checklists y listas.';
                    }
                } else {
                    this.motivosError = (json && json.error && json.error.mensaje) || 'No pudimos cargar los motivos.';
                }
            } catch (e) {
                this.motivosError = 'No pudimos cargar los motivos, revisa tu conexión.';
            } finally {
                this.motivosCargando = false;
            }
        },

        cancelarModo() {
            this.modo = null;
            this.motivoId = null;
            this.itemsDesmarcadosNuevos = [];
        },

        // Accesibilidad de teclado (rules/accesibilidad-teclado.md). Un solo despachador:
        // revisa primero el modal (tiene prioridad porque tapa visualmente al resto), luego
        // el modo observación/rechazo, luego los botones iniciales. Letras por e.code, no
        // e.key (en Mac, Option+letra escribe acentos/símbolos en vez de la letra).
        manejarAccessKey(e) {
            if (!e.altKey || this.esAuditada) return;
            if (this.mostrarConfirmarAprobar) {
                if (this.enviando) return;
                if (e.code === 'KeyC') { e.preventDefault(); this.mostrarConfirmarAprobar = false; }
                else if (e.code === 'KeyA') { e.preventDefault(); this.confirmarAprobar(); }
                return;
            }
            if (this.modo !== null) {
                if (this.enviando) return;
                if (e.code === 'KeyC') { e.preventDefault(); this.cancelarModo(); }
                else if (e.code === 'KeyO' && this.puedeConfirmarVeredicto) { e.preventDefault(); this.enviarVeredictoModo(); }
                return;
            }
            if (e.code === 'KeyA' && this.puedeAprobar) { e.preventDefault(); this.pedirConfirmacionAprobar(); }
            else if (e.code === 'KeyO' && this.puedeAprobarConObservacion && this.puedeEditarChecklist) { e.preventDefault(); this.iniciarObservacion(); }
            else if (e.code === 'KeyR' && this.puedeRechazar) { e.preventDefault(); this.iniciarRechazo(); }
            else if (e.code === 'KeyN' && this.puedeSinInspeccion) { e.preventDefault(); this.iniciarSinInspeccion(); }
        },

        // Escape: cierra el modal, o sale del modo observación/rechazo/sin inspección (equivalente a Cancelar).
        manejarEscape() {
            if (this.mostrarConfirmarAprobar) {
                if (!this.enviando) this.mostrarConfirmarAprobar = false;
                return;
            }
            if (this.modo !== null && !this.enviando) this.cancelarModo();
        },

        // Focus trap del modal "Confirmar aprobar" (Tab/Shift+Tab no se escapan del modal).
        atraparTabConfirmarAprobar(e) {
            if (!this.mostrarConfirmarAprobar) return;
            var f = [this.$refs.btnCancelarAprobar, this.$refs.btnAprobarModal];
            var i = f.indexOf(document.activeElement);
            if (e.shiftKey) {
                if (i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
            } else {
                if (i === f.length - 1) { e.preventDefault(); f[0].focus(); }
            }
        },

        async enviarVeredictoModo() {
            if (!this.puedeConfirmarVeredicto || this.enviando) return;
            var veredicto = { observacion: 'aprobado_con_observacion', rechazo: 'rechazado', sin_inspeccion: 'sin_inspeccion' }[this.modo];
            // Observación y rechazo mandan los ítems desmarcados por el auditor; el rechazo, además, su motivo.
            // «No se pudo inspeccionar» manda solo su motivo.
            var items = this.modo === 'sin_inspeccion' ? [] : this.itemsDesmarcadosNuevos.slice();
            var conMotivo = this.modo === 'rechazo' || this.modo === 'sin_inspeccion';
            await this.enviarVeredicto(veredicto, conMotivo ? this.motivoId : null, items);
        },

        async enviarVeredicto(veredicto, motivoId, items) {
            if (this.enviando) return;
            this.enviando = true;
            try {
                var payload = { veredicto: veredicto };
                if (motivoId !== null && motivoId !== undefined) payload.motivo_id = motivoId;
                if (items && items.length > 0) payload.items_desmarcados = items;

                var json = await apiPost('/api/auditoria/' + this.habitacionId, payload);
                if (json && json.ok) {
                    this.mostrarToast('exito', this.mensajeExito(veredicto));
                    setTimeout(function () { window.location.href = u('/auditoria'); }, 1200);
                } else {
                    var msg = (json && json.error && json.error.mensaje) || 'No pudimos guardar el veredicto.';
                    this.mostrarToast('error', msg);
                }
            } catch (e) {
                this.mostrarToast('error', 'No pudimos conectar con el servidor.');
            } finally {
                this.enviando = false;
            }
        },

        mensajeExito(veredicto) {
            if (veredicto === 'aprobado') return 'Habitación aprobada.';
            if (veredicto === 'aprobado_con_observacion') return 'Aprobada con observación.';
            if (veredicto === 'rechazado') return 'Habitación rechazada. Volverá a estar sucia.';
            if (veredicto === 'sin_inspeccion') return 'Listo, salió de la lista de Inspección.';
            return 'Veredicto guardado.';
        },

        mostrarToast(tipo, mensaje) {
            this.toast = { visible: true, tipo: tipo, mensaje: mensaje };
            var self = this;
            setTimeout(function () { self.toast.visible = false; }, 3000);
        },

        badgeEstado(estado) {
            // Colores por estado: clases semánticas .chip-estado-* (editables en Ajustes → Colores).
            var configs = {
                'sucia': { texto: 'Pendiente', clase: 'chip-estado-sucia' },
                'en_progreso': { texto: 'En progreso', clase: 'chip-estado-en_progreso' },
                'completada_pendiente_auditoria': { texto: 'Por inspeccionar', clase: 'chip-estado-completada_pendiente_auditoria' },
                'aprobada': { texto: 'Aprobada', clase: 'chip-estado-aprobada' },
                'aprobada_con_observacion': { texto: 'Aprobada c/obs.', clase: 'chip-estado-aprobada_con_observacion' },
                'aprobada_automatica': { texto: 'Aprobada auto.', clase: 'chip-estado-aprobada_automatica' },
                'rechazada': { texto: 'Rechazada', clase: 'chip-estado-rechazada' }
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        }
    };
}
</script>
