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
 *  - Rechazar: textarea comentario (min 10 chars), botón "Confirmar rechazo".
 *  - Inmutabilidad: backend rechaza con 409 si ya hay auditoría.
 *
 * Variables requeridas: $usuario, $habitacionId (int)
 */

require_once __DIR__ . '/componentes/badge-estado.php';
?>

<div x-data="auditoriaDetalleApp(<?= (int) $habitacionId ?>)"
     x-init="cargar()">

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-3xl mx-auto gap-3">
            <a href="/home"
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
                    <span>Auditoría</span>
                </template>
            </h1>
            <div class="w-11"></div>
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
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar la auditoría</h2>
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
                                <i data-lucide="lock" class="w-3 h-3"></i> Auditada
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
                                'bg-red-100 dark:bg-red-900/30': auditoria.veredicto === 'rechazado'
                             }">
                            <i :data-lucide="iconoVeredicto(auditoria.veredicto)" class="w-5 h-5"
                               :class="{
                                    'text-green-600 dark:text-green-400': auditoria.veredicto === 'aprobado',
                                    'text-amber-600 dark:text-amber-400': auditoria.veredicto === 'aprobado_con_observacion',
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
                                Items desmarcados por el auditor (<span x-text="auditoria.items_desmarcados.length"></span>)
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
                        <p>Escribe el motivo del rechazo (mínimo 10 caracteres). La habitación volverá a estado sucia.</p>
                    </div>
                </div>
            </template>

            <!-- Checklist -->
            <template x-if="items.length > 0">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
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
                                            <i data-lucide="alert-triangle" class="w-3 h-3"></i> Auditor desmarcó
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Comentario <span class="text-red-500">*</span>
                    </label>
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
                <div class="space-y-2">
                    <template x-if="puedeAprobar">
                        <button @click="pedirConfirmacionAprobar()"
                                class="w-full min-h-[56px] bg-green-600 hover:bg-green-700 active:bg-green-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="check-circle" class="w-5 h-5"></i>
                            Aprobar
                        </button>
                    </template>
                    <template x-if="puedeAprobarConObservacion">
                        <button @click="iniciarObservacion()"
                                class="w-full min-h-[56px] bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                            Aprobar con observación
                        </button>
                    </template>
                    <template x-if="puedeRechazar">
                        <button @click="iniciarRechazo()"
                                class="w-full min-h-[56px] bg-red-600 hover:bg-red-700 active:bg-red-800 text-white text-lg font-semibold rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i data-lucide="x-circle" class="w-5 h-5"></i>
                            Rechazar
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
                        Cancelar
                    </button>
                    <button @click="enviarVeredictoModo()"
                            :disabled="!comentarioValido || enviando"
                            class="flex-1 min-h-[56px] text-white font-semibold rounded-xl transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            :class="modo === 'observacion' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-red-600 hover:bg-red-700'">
                        <span x-text="enviando ? 'Enviando...' : (modo === 'observacion' ? 'Confirmar observación' : 'Confirmar rechazo')"></span>
                    </button>
                </div>
            </template>

            <!-- Modal confirmación aprobar -->
            <div x-show="mostrarConfirmarAprobar" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="mostrarConfirmarAprobar = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                            <i data-lucide="check-circle" class="w-5 h-5 text-green-600 dark:text-green-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">¿Aprobar habitación?</h3>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                        Se marcará como aprobada y pasará a estado "Clean" en Cloudbeds. Esta acción no se puede deshacer.
                    </p>
                    <div class="flex gap-3">
                        <button @click="mostrarConfirmarAprobar = false"
                                :disabled="enviando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            Cancelar
                        </button>
                        <button @click="confirmarAprobar()"
                                :disabled="enviando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <span x-text="enviando ? 'Enviando...' : 'Aprobar'"></span>
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

        toast: { visible: false, tipo: 'exito', mensaje: '' },

        get esAuditada() {
            if (this.auditoria) return true;
            if (!this.habitacion) return false;
            var e = this.habitacion.estado;
            return e === 'aprobada' || e === 'aprobada_con_observacion' || e === 'rechazada';
        },

        get puedeDesmarcar() {
            // Sólo en modo observación y si tiene el permiso correspondiente.
            return this.modo === 'observacion' && this.puedeEditarChecklist && !this.esAuditada;
        },

        get comentarioValido() {
            return this.comentario.trim().length >= 10;
        },

        etiquetaHabitacion() {
            if (!this.habitacion) return 'Auditoría';
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
            if (v === 'rechazado') return 'x-circle';
            return 'circle';
        },

        etiquetaVeredicto(v) {
            if (v === 'aprobado') return 'Aprobado';
            if (v === 'aprobado_con_observacion') return 'Aprobado con observación';
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
            this.modo = null;
            this.comentario = '';
            this.itemsDesmarcadosNuevos = [];
        },

        async enviarVeredictoModo() {
            if (!this.comentarioValido || this.enviando) return;
            var veredicto = this.modo === 'observacion' ? 'aprobado_con_observacion' : 'rechazado';
            var items = this.modo === 'observacion' ? this.itemsDesmarcadosNuevos.slice() : [];
            await this.enviarVeredicto(veredicto, this.comentario.trim(), items);
        },

        async enviarVeredicto(veredicto, comentario, items) {
            if (this.enviando) return;
            this.enviando = true;
            try {
                var payload = { veredicto: veredicto };
                if (comentario && comentario !== '') payload.comentario = comentario;
                if (items && items.length > 0) payload.items_desmarcados = items;

                var json = await apiPost('/api/auditoria/' + this.habitacionId, payload);
                if (json && json.ok) {
                    this.mostrarToast('exito', this.mensajeExito(veredicto));
                    setTimeout(function () { window.location.href = '/home'; }, 1200);
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
            var configs = {
                'sucia': { texto: 'Pendiente', clase: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200' },
                'en_progreso': { texto: 'En progreso', clase: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' },
                'completada_pendiente_auditoria': { texto: 'Por auditar', clase: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200' },
                'aprobada': { texto: 'Aprobada', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'aprobada_con_observacion': { texto: 'Aprobada c/obs.', clase: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' },
                'rechazada': { texto: 'Rechazada', clase: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' }
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        }
    };
}
</script>
