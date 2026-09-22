<?php
/**
 * Pantalla de Auditoría.
 * Spec: docs/auditoria.md, docs/home-recepcion.md
 *
 * Dos modos:
 *  - Pendiente: estado='completada_pendiente_auditoria', sin registro en auditorias.
 *    Muestra 3 botones (Aprobar / Aprobar c/obs. / Rechazar) filtrados por permisos.
 *  - Histórica: habitación ya auditada (aprobada / aprobada_con_observacion / rechazada).
 *    Vista read-only con badge "Auditada", resumen, sin botones.
 *
 * Reglas:
 *  - Aprobar: confirm modal simple.
 *  - Aprobar con observación: entra a modo edición — checklist desmarcable,
 *    textarea comentario (min 10 chars), botón "Enviar observación".
 *  - Rechazar: desmarca los ítems fallidos (>=1, se re-limpian) + textarea comentario
 *    (min 10 chars), botón "Confirmar rechazo".
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
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <span x-html="badgeEstado(habitacion.estado)"></span>
                        <template x-if="esAuditada">
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
                                'bg-red-100 dark:bg-red-900/30': auditoria.veredicto === 'rechazado'
                             }">
                            <i :data-lucide="iconoVeredicto(auditoria.veredicto)" class="w-5 h-5"
                               :class="{
                                    'text-green-600 dark:text-green-400': auditoria.veredicto === 'aprobado',
                                    'text-amber-600 dark:text-amber-400': auditoria.veredicto === 'aprobado_con_observacion',
                                    'text-cyan-600 dark:text-cyan-400': auditoria.veredicto === 'aprobado_automatico',
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
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 uppercase tracking-wide">Comentario</p>
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
                        <p>Desmarca los items que encontraste mal y escribe un comentario (mínimo 10 caracteres).</p>
                    </div>
                </div>
            </template>
            <template x-if="modo === 'rechazo'">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 flex items-start gap-3">
                    <i data-lucide="x-circle" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-red-900 dark:text-red-200">
                        <p class="font-semibold">Rechazando habitación</p>
                        <p>Marca los ítems que quedaron mal (se re-limpiarán) y escribe el motivo (mínimo 10 caracteres). La habitación volverá a estado sucia.</p>
                        <p class="mt-1 font-medium" x-show="itemsDesmarcadosNuevos.length === 0">Selecciona al menos un ítem fallido.</p>
                    </div>
                </div>
            </template>

            <!-- Checklist -->
            <template x-if="items.length > 0">
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

            <!-- Textarea comentario (observacion/rechazo) -->
            <template x-if="modo === 'observacion' || modo === 'rechazo'">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Comentario <span class="text-red-500">*</span>
                        </label>
                        <template x-if="soportaDictado">
                            <button type="button"
                                    @click="toggleDictado()"
                                    :aria-pressed="grabando"
                                    :aria-label="grabando ? 'Detener dictado' : 'Dictar comentario por voz'"
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
                    <textarea x-model="comentario"
                              rows="4"
                              maxlength="2000"
                              :placeholder="modo === 'observacion' ? 'Describe qué encontraste...' : 'Describe el motivo del rechazo...'"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        <span x-text="comentario.trim().length"></span> / 2000 caracteres
                        <template x-if="comentario.trim().length < 10">
                            <span class="text-red-600 dark:text-red-400 ml-2">Faltan <span x-text="10 - comentario.trim().length"></span> para el mínimo (10)</span>
                        </template>
                    </p>
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
                    <template x-if="puedeAprobarConObservacion">
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
                    <template x-if="!puedeAprobar && !puedeAprobarConObservacion && !puedeRechazar">
                        <p class="text-center text-sm text-gray-500 dark:text-gray-400 py-4">
                            No tienes permisos para emitir veredicto en esta habitación.
                        </p>
                    </template>
                </div>
            </template>

            <!-- Botones en modo observación / rechazo -->
            <template x-if="modo === 'observacion' || modo === 'rechazo'">
                <div class="flex gap-3">
                    <button @click="cancelarModo()"
                            :disabled="enviando"
                            class="flex-1 min-h-[56px] bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-semibold rounded-xl transition">
                        <span :class="altActivo ? 'underline' : ''">C</span>ancelar
                    </button>
                    <button @click="enviarVeredictoModo()"
                            :disabled="!puedeConfirmarVeredicto || enviando"
                            class="flex-1 min-h-[56px] text-white font-semibold rounded-xl transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            :class="modo === 'observacion' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-red-600 hover:bg-red-700'">
                        <template x-if="enviando"><span>Enviando...</span></template>
                        <template x-if="!enviando"><span>C<span :class="altActivo ? 'underline' : ''">o</span>nfirmar <span x-text="modo === 'observacion' ? 'observación' : 'rechazo'"></span></span></template>
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

        // Estado de interacción
        modo: null, // null | 'observacion' | 'rechazo'
        comentario: '',
        itemsDesmarcadosNuevos: [],
        mostrarConfirmarAprobar: false,
        altActivo: false, // Alt (Windows) / Option (Mac) presionado: subraya las teclas de acceso

        // Dictado por voz del comentario (Web Speech API)
        grabando: false,
        reconocimiento: null,
        dictadoReinicios: 0,

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

        get comentarioValido() {
            return this.comentario.trim().length >= 10;
        },

        get soportaDictado() {
            return !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        },

        get puedeConfirmarVeredicto() {
            if (!this.comentarioValido) return false;
            // El rechazo exige marcar al menos un ítem fallido (lo que hay que rehacer).
            if (this.modo === 'rechazo') return this.itemsDesmarcadosNuevos.length > 0;
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
            if (v === 'rechazado') return 'x-circle';
            return 'circle';
        },

        etiquetaVeredicto(v) {
            if (v === 'aprobado') return 'Aprobado';
            if (v === 'aprobado_con_observacion') return 'Aprobado con observación';
            if (v === 'aprobado_automatico') return 'Aprobado automático (sin auditoría real)';
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
                if (!this.esAuditada && (this.puedeAprobar || this.puedeAprobarConObservacion || this.puedeRechazar)) {
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
            await this.enviarVeredicto('aprobado', '', []);
            this.mostrarConfirmarAprobar = false;
        },

        iniciarObservacion() {
            if (!this.puedeAprobarConObservacion || this.esAuditada) return;
            this.modo = 'observacion';
            this.comentario = '';
            this.itemsDesmarcadosNuevos = [];
            this.$nextTick(function () { lucide.createIcons(); });
        },

        iniciarRechazo() {
            if (!this.puedeRechazar || this.esAuditada) return;
            this.modo = 'rechazo';
            this.comentario = '';
            this.itemsDesmarcadosNuevos = [];
            this.$nextTick(function () { lucide.createIcons(); });
        },

        cancelarModo() {
            this.detenerDictado();
            this.modo = null;
            this.comentario = '';
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
            else if (e.code === 'KeyO' && this.puedeAprobarConObservacion) { e.preventDefault(); this.iniciarObservacion(); }
            else if (e.code === 'KeyR' && this.puedeRechazar) { e.preventDefault(); this.iniciarRechazo(); }
        },

        // Escape: cierra el modal, o sale del modo observación/rechazo (equivalente a Cancelar).
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

        // Dicta el comentario por voz. El texto reconocido se agrega al comentario
        // existente (no lo reemplaza), para poder mezclar teclado y voz.
        toggleDictado() {
            if (this.grabando) {
                this.detenerDictado();
                return;
            }
            if (!this.soportaDictado) return;

            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var reco = new SpeechRecognition();
            var self = this;
            reco.lang = 'es-CL';
            reco.continuous = true;
            reco.interimResults = false;

            reco.onresult = function (event) {
                var textoNuevo = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        textoNuevo += event.results[i][0].transcript;
                    }
                }
                textoNuevo = textoNuevo.trim();
                if (textoNuevo === '') return;
                self.dictadoReinicios = 0; // hubo voz real: el contador de seguridad se reinicia
                var actual = self.comentario.trim();
                self.comentario = (actual === '' ? textoNuevo : actual + ' ' + textoNuevo).slice(0, 2000);
            };

            reco.onerror = function (event) {
                // 'no-speech' (pausa del supervisor) y 'aborted' (nuestro propio stop) son normales:
                // no cortan el dictado, los maneja onend.
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    self.grabando = false;
                    self.mostrarToast('error', 'Permiso de micrófono denegado.');
                } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
                    self.grabando = false;
                    self.mostrarToast('error', 'No pudimos usar el micrófono.');
                }
            };

            reco.onend = function () {
                // Safari/iOS ignora continuous y corta al primer silencio. Mientras el supervisor
                // no toque "detener", reanudamos para que pueda hacer pausas y seguir hablando.
                if (!self.grabando) return;
                if (self.dictadoReinicios >= 30) { self.grabando = false; return; } // tope: micrófono en silencio
                self.dictadoReinicios++;
                setTimeout(function () {
                    if (!self.grabando) return;
                    try { reco.start(); } catch (e) { self.grabando = false; }
                }, 250);
            };

            this.reconocimiento = reco;
            this.dictadoReinicios = 0;
            this.grabando = true;
            reco.start();
        },

        detenerDictado() {
            this.grabando = false;
            this.dictadoReinicios = 0;
            if (this.reconocimiento) {
                try { this.reconocimiento.stop(); } catch (e) {}
            }
        },

        async enviarVeredictoModo() {
            if (!this.puedeConfirmarVeredicto || this.enviando) return;
            var veredicto = this.modo === 'observacion' ? 'aprobado_con_observacion' : 'rechazado';
            // Observación y rechazo mandan los ítems desmarcados por el auditor.
            var items = this.itemsDesmarcadosNuevos.slice();
            await this.enviarVeredicto(veredicto, this.comentario.trim(), items);
        },

        async enviarVeredicto(veredicto, comentario, items) {
            if (this.enviando) return;
            this.detenerDictado();
            this.enviando = true;
            try {
                var payload = { veredicto: veredicto };
                if (comentario && comentario !== '') payload.comentario = comentario;
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
