<?php
/**
 * Detalle de habitación + checklist persistente tap-a-tap.
 * Spec: docs/habitaciones.md, docs/checklist.md
 *
 * Flujos:
 *  - sucia + asignada a mí → botón "Comenzar limpieza" (POST iniciar → recarga)
 *  - en_progreso + asignada → checklist editable con cola offline
 *  - completada_pendiente_auditoria → checklist read-only, aviso "En auditoría"
 *  - aprobada / aprobada_con_observacion / rechazada → vista histórica (opacidad, badge "Auditada")
 *
 * Variables requeridas: $usuario, $habitacionId (int)
 */

require_once __DIR__ . '/componentes/badge-estado.php';
?>

<div x-data="habitacionDetalleApp(<?= (int) $habitacionId ?>, <?= (int) $usuario->id ?>)"
     x-init="cargar(); iniciarListeners();">

    <?php /* Bandera para la Vista Guiada: gatea el recorrido «Dar por limpia» (solo supervisión). */ ?>
    <div data-vg-context='{"puede_marcar_limpia": <?= $usuario->tienePermiso('habitaciones.marcar_limpia_manual') ? 'true' : 'false' ?>}' hidden></div>

    <!-- Header sticky -->
    <header class="sticky top-0 z-40 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between max-w-3xl mx-auto gap-3">
            <?php
            // El listado /habitaciones exige habitaciones.ver_todas (su API lo pide);
            // un Trabajador no lo tiene y esa página le queda en "Error al cargar" sin
            // salida — sobre todo grave como PWA instalada en el celular, sin chrome de
            // navegador con botón atrás. Vuelve a /home, que sí es válido para cualquiera.
            $volverA = $usuario->tienePermiso('habitaciones.ver_todas') ? '/habitaciones' : '/home';
            ?>
            <a href="<?= u($volverA) ?>"
               class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800"
               aria-label="Volver">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
            </a>
            <h1 class="flex-1 text-lg font-semibold text-gray-900 dark:text-gray-100 text-center truncate">
                <template x-if="habitacion">
                    <span>Habitación <span x-text="habitacion.numero"></span></span>
                </template>
                <template x-if="!habitacion">
                    <span>Habitación</span>
                </template>
            </h1>
            <?php include __DIR__ . '/componentes/boton-tema.php'; ?>
        </div>
    </header>

    <!-- Banner sin conexión -->
    <div x-show="sinConexion" x-cloak
         class="bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-4 py-2 text-sm text-center">
        Sin conexión a internet. Tus cambios se sincronizarán cuando vuelva.
    </div>

    <!-- Banner sincronizando -->
    <div x-show="cola.length > 0 && !sinConexion" x-cloak
         class="bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 px-4 py-2 text-sm text-center flex items-center justify-center gap-2">
        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span x-text="colaTextoBanner"></span>
    </div>

    <!-- Banner fallos permanentes -->
    <div x-show="errorPermanente" x-cloak
         class="bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 px-4 py-2 text-sm text-center">
        Algunos cambios no se guardaron. Intenta más tarde o contacta soporte.
    </div>

    <!-- Estado de carga -->
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
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No pudimos cargar</h2>
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

            <!-- Tarjeta info habitación -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div class="flex items-start justify-between mb-3">
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

                <!-- Nota de Recepción para la mucama: instrucción puntual para esta limpieza
                     (ej. "cliente pidió cama extra"). Se limpia sola al completar la limpieza. -->
                <template x-if="habitacion.nota_recepcion">
                    <div class="mt-3 rounded-lg p-3 border bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700">
                        <div class="flex items-start gap-2">
                            <i data-lucide="sticky-note" class="w-4 h-4 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-blue-900 dark:text-blue-200 uppercase tracking-wide">Nota de Recepción</p>
                                <p class="text-sm text-blue-900 dark:text-blue-100 mt-0.5 whitespace-pre-wrap" x-text="habitacion.nota_recepcion"></p>
                            </div>
                            <template x-if="puedeAgregarNota">
                                <button @click="quitarNota()" :disabled="notaEnviando" aria-label="Quitar nota"
                                        class="flex-shrink-0 text-blue-400 hover:text-red-600 dark:hover:text-red-400 transition">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Agregar/editar nota — solo Recepción/Supervisora/Admin (habitaciones.agregar_nota) -->
                <template x-if="puedeAgregarNota && !editandoNota">
                    <button @click="editandoNota = true; formNota = habitacion.nota_recepcion || ''"
                            class="mt-3 text-sm text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                        <span x-text="habitacion.nota_recepcion ? 'Editar nota' : 'Agregar nota para la mucama'"></span>
                    </button>
                </template>
                <template x-if="puedeAgregarNota && editandoNota">
                    <div class="mt-3 space-y-2">
                        <textarea x-model="formNota" maxlength="500" rows="2" placeholder="Ej: cliente pidió cama extra"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm"></textarea>
                        <div class="flex gap-2">
                            <button @click="editandoNota = false"
                                    class="flex-1 min-h-[40px] px-3 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 transition">
                                Cancelar
                            </button>
                            <button @click="guardarNota()" :disabled="notaEnviando || !formNota.trim()"
                                    class="flex-1 min-h-[40px] px-3 text-sm font-semibold rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition disabled:opacity-50">
                                <span x-text="notaEnviando ? 'Guardando...' : 'Guardar nota'"></span>
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Ocupación Cloudbeds: aviso visual (no bloquea) + actualizar antes de entrar -->
                <template x-if="habitacion.cb_ocupada === true || puedeSincronizar">
                    <div class="mt-3 rounded-lg p-3 border"
                         :class="habitacion.cb_ocupada === true
                             ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700'
                             : 'bg-gray-50 dark:bg-gray-700/40 border-gray-200 dark:border-gray-600'">
                        <template x-if="habitacion.cb_ocupada === true">
                            <div class="flex items-start gap-2 mb-2">
                                <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"></i>
                                <p class="text-sm font-medium text-amber-900 dark:text-amber-200">Cloudbeds indica huésped en la pieza — verifica antes de entrar.</p>
                            </div>
                        </template>

                        <!-- Huésped/empresa (cb_huesped, texto libre de Cloudbeds) + fechas de
                             ingreso/salida (cb_arrival_date/cb_departure_date). Solo si hay algún
                             dato — pieza sin reserva activa no muestra nada acá. -->
                        <template x-if="habitacion.cb_huesped || habitacion.cb_arrival_date || habitacion.cb_departure_date">
                            <div class="mb-2 space-y-1">
                                <template x-if="habitacion.cb_huesped">
                                    <p class="text-sm text-gray-700 dark:text-gray-300 inline-flex items-start gap-1.5">
                                        <i data-lucide="user" class="w-3.5 h-3.5 mt-0.5 flex-shrink-0"></i>
                                        <span x-text="habitacion.cb_huesped"></span>
                                    </p>
                                </template>
                                <template x-if="habitacion.cb_arrival_date || habitacion.cb_departure_date">
                                    <p class="text-sm text-gray-700 dark:text-gray-300 inline-flex items-center gap-1.5">
                                        <i data-lucide="calendar" class="w-3.5 h-3.5 flex-shrink-0"></i>
                                        <span>
                                            Ingreso <span x-text="fechaCorta(habitacion.cb_arrival_date) || '—'"></span>
                                            · Salida <span x-text="fechaCorta(habitacion.cb_departure_date) || '—'"></span>
                                        </span>
                                    </p>
                                </template>
                            </div>
                        </template>

                        <div class="flex items-center justify-between gap-2">
                            <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="textoUltimaSync || 'Sin dato de ocupación de Cloudbeds.'"></p>
                            <template x-if="puedeSincronizar">
                                <button @click="sincronizarAhora()" :disabled="sincronizando"
                                        class="shrink-0 min-h-[32px] px-2.5 text-xs font-medium rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 inline-flex items-center gap-1">
                                    <span :class="sincronizando ? 'animate-spin' : ''" class="inline-flex">
                                        <i data-lucide="cloud-download" class="w-3.5 h-3.5"></i>
                                    </span>
                                    <span x-text="sincronizando ? 'Actualizando...' : 'Actualizar ahora'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- CTA: Comenzar limpieza (sucia o rechazada + asignada). 'rechazada'
                     reabre: iniciarEjecucion() la acepta igual que 'sucia' y crea una
                     ejecución nueva (empieza de cero). -->
                <template x-if="(habitacion.estado === 'sucia' || habitacion.estado === 'rechazada') && estaAsignada && !esAuditada">
                    <button @click="iniciar()" :disabled="iniciando" data-tour="hab.comenzar"
                            class="w-full min-h-[56px] mt-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 disabled:opacity-50 text-white text-lg font-semibold rounded-xl transition shadow-sm">
                        <span x-text="iniciando ? 'Iniciando...' : (habitacion.estado === 'rechazada' ? 'Volver a limpiar' : 'Comenzar limpieza')"></span>
                    </button>
                </template>

                <!-- Válvula de escape desde 'sucia'/'rechazada': no pasa por el checklist,
                     abre el mismo modal de "No puedo terminar esta ahora" (ver saltar()).
                     Pensada para el aviso de ocupación de arriba: si el huésped no ha
                     salido, postergar sin tener que tocar "Comenzar limpieza" primero. -->
                <template x-if="(habitacion.estado === 'sucia' || habitacion.estado === 'rechazada') && estaAsignada && !esAuditada">
                    <button @click="mostrarSaltar = true"
                            class="w-full min-h-[44px] text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 text-sm font-medium rounded-xl transition">
                        No puedo entrar ahora
                    </button>
                </template>

                <!-- No asignada y sucia -->
                <template x-if="habitacion.estado === 'sucia' && !estaAsignada && puedeVerTodas">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        Esta habitación está pendiente de asignación.
                    </p>
                </template>

                <!-- Auditoría pendiente (solo aviso, read-only) -->
                <template x-if="habitacion.estado === 'completada_pendiente_auditoria'">
                    <div class="mt-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg p-3 flex items-start gap-3">
                        <i data-lucide="clock" class="w-5 h-5 text-indigo-600 dark:text-indigo-400 flex-shrink-0 mt-0.5"></i>
                        <p class="text-sm text-indigo-900 dark:text-indigo-200">Esta habitación está esperando auditoría.</p>
                    </div>
                </template>

                <!-- Rechazada: reasignar (Supervisora/Admin) -->
                <template x-if="habitacion.estado === 'rechazada' && puedeAsignar">
                    <div class="mt-3 space-y-2">
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 flex items-start gap-3">
                            <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5"></i>
                            <p class="text-sm text-red-900 dark:text-red-200">Esta habitación fue rechazada en auditoría y necesita re-limpieza.</p>
                        </div>
                        <a href="<?= u('/asignaciones') ?>"
                           class="w-full min-h-[48px] inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
                            <i data-lucide="user-check" class="w-5 h-5"></i>
                            Reasignar a un trabajador
                        </a>
                    </div>
                </template>

                <!-- Atajo administrativo: marcar limpia sin checklist (Admin/Supervisora) -->
                <template x-if="puedeMarcarLimpia && ['sucia', 'en_progreso', 'rechazada'].includes(habitacion.estado)">
                    <button type="button" @click="mostrarMarcarLimpia = true" data-tour="hab.marcar-limpia"
                            class="w-full min-h-[48px] mt-3 inline-flex items-center justify-center gap-2
                                   bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                                   text-gray-700 dark:text-gray-200 rounded-xl font-medium text-sm
                                   hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <i data-lucide="sparkles" class="w-5 h-5 text-emerald-600"></i>
                        Marcar como limpia
                    </button>
                </template>
            </div>

            <!-- Checklist (en_progreso o completada_pendiente_auditoria o auditada con ejecución) -->
            <template x-if="ejecucion && items.length > 0">
                <div class="space-y-3">
                    <?php include __DIR__ . '/componentes/progreso-checklist.php'; ?>

                    <!-- Lista de items -->
                    <div data-tour="hab.checklist" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <template x-for="item in items" :key="item.id">
                            <label class="flex items-start gap-3 px-4 py-4 border-b border-gray-200 dark:border-gray-700 last:border-b-0"
                                   :class="puedeEditar ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50' : 'cursor-default'">
                                <input type="checkbox"
                                       :checked="item.marcado == 1"
                                       :disabled="!puedeEditar || item._guardando || esHeredado(item)"
                                       @change="toggleItem(item, $event.target.checked)"
                                       class="mt-1 w-6 h-6 rounded border-2 border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-2 focus:ring-blue-500 disabled:opacity-60 flex-shrink-0">
                                <div class="flex-1 min-w-0">
                                    <p class="text-base text-gray-900 dark:text-gray-100"
                                       :class="item.marcado == 1 ? 'line-through text-gray-400 dark:text-gray-500' : ''"
                                       x-text="item.descripcion"></p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <template x-if="item.obligatorio == 0">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Opcional</span>
                                        </template>
                                        <template x-if="item.es_cambio_sabanas == 1">
                                            <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                                <i data-lucide="bed-double" class="w-3 h-3"></i> Sábanas
                                            </span>
                                        </template>
                                        <template x-if="item.desmarcado_por_auditor == 1">
                                            <span class="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                                <i data-lucide="alert-triangle" class="w-3 h-3"></i> Auditor desmarcó
                                            </span>
                                        </template>
                                        <template x-if="esHeredado(item)">
                                            <span class="inline-flex items-center gap-1 text-xs text-teal-700 dark:text-teal-400">
                                                <i data-lucide="check" class="w-3 h-3"></i> Ya limpiado
                                            </span>
                                        </template>
                                        <template x-if="item._error">
                                            <span class="text-xs text-red-600 dark:text-red-400" x-text="item._error"></span>
                                        </template>
                                    </div>
                                </div>
                            </label>
                        </template>
                    </div>

                    <!-- Botón "Habitación terminada" -->
                    <template x-if="puedeEditar">
                        <button @click="confirmarCompletar()" data-tour="hab.terminar"
                                :disabled="progreso.obligatorios_pendientes > 0 || completando || completarPendiente || delayRestante > 0"
                                class="w-full min-h-[56px] bg-green-600 hover:bg-green-700 active:bg-green-800 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed text-white text-lg font-semibold rounded-xl transition shadow-sm">
                            <span x-text="completarPendiente ? 'Confirmando...' : (completando ? 'Enviando...' : (progreso.obligatorios_pendientes > 0 ? 'Faltan items obligatorios' : (delayRestante > 0 ? 'Puedes terminar en ' + textoDelayRestante() : 'Habitación terminada')))"></span>
                        </button>
                    </template>

                    <!-- Válvula de escape: no puedo terminar esta habitación ahora -->
                    <template x-if="puedeEditar">
                        <button @click="mostrarSaltar = true" data-tour="hab.saltar"
                                class="w-full min-h-[48px] text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 text-sm font-medium rounded-xl transition">
                            No puedo terminar esta ahora
                        </button>
                    </template>
                </div>
            </template>

            <!-- Historial de limpiezas (permiso habitaciones.ver_historial; el
                 trabajador nunca lo ve — incluye horas, que le son invisibles) -->
            <template x-if="puedeVerHistorial">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                        <i data-lucide="history" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Historial de limpiezas</h2>
                        <span class="text-xs text-gray-400" x-text="historial.length ? 'últimas ' + historial.length : ''"></span>
                        <!-- En pantalla solo se ven las últimas 20 (tope del backend); acá se
                             baja el historial completo. -->
                        <template x-if="historial.length > 0">
                            <a :href="u('/api/habitaciones/' + habitacionId + '/historial/exportar')"
                               class="ml-auto shrink-0 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Excel
                            </a>
                        </template>
                    </div>
                    <template x-if="historial.length === 0">
                        <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">Esta habitación aún no registra limpiezas.</p>
                    </template>
                    <template x-if="historial.length > 0">
                        <ul>
                            <template x-for="h in historial" :key="h.id">
                                <li class="px-4 py-3 border-b border-gray-100 dark:border-gray-700/60 last:border-b-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="h.trabajador_nombre"></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="fechaHistorial(h.timestamp_inicio)"></p>
                                        </div>
                                        <span class="text-[11px] px-2 py-0.5 rounded-full flex-shrink-0"
                                              :class="claseHistorial(h)" x-text="etiquetaHistorial(h)"></span>
                                    </div>
                                    <template x-if="h.veredicto && h.auditor_nombre">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            Auditada por <span x-text="h.auditor_nombre"></span><template x-if="h.auditoria_comentario"><span> — «<span x-text="h.auditoria_comentario"></span>»</span></template>
                                        </p>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </template>

            <!-- Historial de movimientos: cambios de estado manuales (menú contextual de
                 /habitaciones: marcar limpia/sucia, "cliente no desea aseo") y automáticos
                 (sync Cloudbeds, cron nochero). Viene de audit_log — HabitacionService::
                 cambiarEstado() ya escribe ahí en CADA cambio. Mismo permiso que el
                 historial de limpiezas: habitaciones.ver_historial. -->
            <template x-if="puedeVerHistorial">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                        <i data-lucide="git-commit-horizontal" class="w-4 h-4 text-gray-500 dark:text-gray-400"></i>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Historial de movimientos</h2>
                        <span class="text-xs text-gray-400" x-text="movimientos.length ? 'últimos ' + movimientos.length : ''"></span>
                        <!-- En pantalla solo se ven los últimos 20 (tope del backend); acá se
                             baja el historial completo. -->
                        <template x-if="movimientos.length > 0">
                            <a :href="u('/api/habitaciones/' + habitacionId + '/movimientos/exportar')"
                               class="ml-auto shrink-0 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Excel
                            </a>
                        </template>
                    </div>
                    <template x-if="movimientos.length === 0">
                        <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400">Esta habitación aún no registra movimientos.</p>
                    </template>
                    <template x-if="movimientos.length > 0">
                        <ul>
                            <template x-for="m in movimientos" :key="m.id">
                                <li class="px-4 py-3 border-b border-gray-100 dark:border-gray-700/60 last:border-b-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <!-- Mensaje de Recepción (tipo 'nota'): el texto quedó en audit_log aunque
                                             la nota ya se haya borrado sola de la ficha al completar. -->
                                        <template x-if="m.tipo === 'nota'">
                                            <p class="text-sm text-gray-900 dark:text-gray-100 flex items-center gap-1.5 min-w-0">
                                                <i data-lucide="sticky-note" class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400 flex-shrink-0"></i>
                                                <span class="truncate" x-text="m.mensaje"></span>
                                            </p>
                                        </template>
                                        <template x-if="m.tipo !== 'nota'">
                                            <p class="text-sm flex items-center gap-1.5 flex-wrap">
                                                <span x-html="badgeEstado(m.desde)"></span>
                                                <i data-lucide="arrow-right" class="w-3 h-3 text-gray-400"></i>
                                                <span x-html="badgeEstado(m.hasta)"></span>
                                            </p>
                                        </template>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0" x-text="fechaHistorial(m.created_at)"></span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"
                                       x-text="m.usuario_nombre || 'Automático (sync Cloudbeds / cron)'"></p>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </template>

            <!-- Reportar un problema: visible en cualquier estado de la habitación
                 (no solo mientras se limpia — un problema puede notarse en una ya terminada). -->
            <template x-if="puedeReportar">
                <button type="button" @click="reportarProblema()" data-tour="hab.reportar"
                        class="w-full min-h-[52px] inline-flex items-center justify-center gap-2 px-4 py-2
                               bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600
                               text-gray-700 dark:text-gray-200 rounded-xl font-medium text-sm
                               hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500"></i>
                    Reportar un problema
                </button>
            </template>

            <!-- Modal confirmación completar -->
            <div x-show="mostrarConfirmar" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="mostrarConfirmar = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">¿Habitación terminada?</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                        Confirma que terminaste esta habitación. Pasará a auditoría y no podrás editarla.
                    </p>
                    <div class="flex gap-3">
                        <button @click="mostrarConfirmar = false"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            Cancelar
                        </button>
                        <button @click="completar()" :disabled="completando"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <span x-text="completando ? 'Enviando...' : 'Confirmar'"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal "No puedo terminar ahora" -->
            <div x-show="mostrarSaltar" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="cerrarSaltar()">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">No puedo terminar ahora</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        Elige un motivo. Se avisará a tu supervisora y esta habitación volverá más adelante a tu lista.
                        <span class="block mt-1 text-amber-700 dark:text-amber-400">Se perderá lo que ya marcaste aquí: al retomarla, empiezas de cero.</span>
                    </p>
                    <div class="space-y-2 mb-4">
                        <template x-for="m in motivosSaltar" :key="m">
                            <button type="button"
                                    @click="motivoSaltar = m"
                                    :class="motivoSaltar === m
                                        ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200'
                                        : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                    class="w-full min-h-[44px] text-left px-4 py-2 border rounded-lg text-sm font-medium transition">
                                <span x-text="m"></span>
                            </button>
                        </template>
                    </div>
                    <template x-if="motivoSaltar === 'Otro'">
                        <textarea x-model="motivoOtro" rows="2" maxlength="200"
                                  placeholder="Cuéntanos brevemente qué pasó"
                                  class="w-full mb-4 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500"></textarea>
                    </template>
                    <div class="flex gap-3">
                        <button @click="cerrarSaltar()"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            Cancelar
                        </button>
                        <button @click="saltar()" :disabled="saltando || !motivoSaltarValido"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg transition">
                            <span x-text="saltando ? 'Enviando...' : 'Confirmar'"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal confirmación marcar limpia manual (Admin/Supervisora) -->
            <div x-show="mostrarMarcarLimpia" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
                 @click.self="mostrarMarcarLimpia = false">
                <div class="bg-white dark:bg-gray-800 rounded-xl max-w-sm w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">¿Marcar como limpia?</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
                        Se registrará sin pasar por el checklist y quedará <strong>pendiente de auditoría</strong>, igual que una limpieza normal.
                    </p>
                    <div class="flex gap-3">
                        <button @click="mostrarMarcarLimpia = false"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-900 dark:text-gray-100 font-medium rounded-lg transition">
                            Cancelar
                        </button>
                        <button @click="marcarLimpiaManual()" :disabled="marcandoLimpia"
                                class="flex-1 min-h-[44px] px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-medium rounded-lg transition">
                            <span x-text="marcandoLimpia ? 'Guardando...' : 'Confirmar'"></span>
                        </button>
                    </div>
                </div>
            </div>

        </main>
    </template>
</div>

<script>
function habitacionDetalleApp(habitacionId, usuarioId) {
    return {
        habitacionId: habitacionId,
        usuarioId: usuarioId,

        habitacion: null,
        ejecucion: null,
        items: [],
        progreso: { marcados: 0, total: 0, porcentaje: 0, obligatorios_total: 0, obligatorios_marcados: 0, obligatorios_pendientes: 0 },
        estaAsignada: false,
        puedeVerTodas: false,
        puedeAsignar: false,
        puedeVerHistorial: false,
        puedeReportar: false,
        puedeMarcarLimpia: false,
        puedeSincronizar: false,
        puedeAgregarNota: false,
        editandoNota: false,
        formNota: '',
        notaEnviando: false,
        historial: [],
        movimientos: [],

        cargando: false,
        error: null,
        iniciando: false,
        sincronizando: false,
        completando: false,
        // Delay mínimo de 5 min antes de poder completar (piezas no-nochero). Conteo
        // regresivo client-side derivado de delay_restante_segundos del backend — nunca
        // se le muestra al trabajador un timestamp absoluto (ver estadoEjecucion()).
        delayRestante: 0,
        _delayTimer: null,
        mostrarConfirmar: false,
        mostrarSaltar: false,
        saltando: false,
        mostrarMarcarLimpia: false,
        marcandoLimpia: false,
        motivoSaltar: null,
        motivoOtro: '',
        motivosSaltar: ['Huésped no ha salido', 'Falta un insumo', 'Requiere mantención', 'Otro'],
        sinConexion: !navigator.onLine,
        cola: [],
        errorPermanente: false,
        _procesandoCola: false,

        // 'rechazada' NO es un estado cerrado: el mismo trabajador puede reabrirla y
        // volver a cerrarla (iniciarEjecucion() la acepta igual que 'sucia'). Solo
        // aprobada/aprobada_con_observacion son historia inmutable.
        get esAuditada() {
            if (!this.habitacion) return false;
            var e = this.habitacion.estado;
            return e === 'aprobada' || e === 'aprobada_con_observacion';
        },

        get puedeEditar() {
            if (!this.habitacion || !this.ejecucion) return false;
            return this.habitacion.estado === 'en_progreso'
                && this.estaAsignada
                && this.ejecucion.estado === 'en_progreso';
        },

        get colaKey() {
            return this.ejecucion ? ('checklist_queue_' + this.ejecucion.id) : null;
        },

        // true si el "completar" está encolado esperando reintento (ver completar()
        // y encolarCompletar()) — evita que el trabajador dispare otro POST mientras
        // el pendiente se reintenta solo.
        get completarPendiente() {
            return this.cola.some(function (c) { return c.tipo === 'completar'; });
        },

        get colaTextoBanner() {
            if (this.completarPendiente) return 'Confirmando que terminaste la habitación...';
            return 'Sincronizando ' + this.cola.length + ' ' + (this.cola.length === 1 ? 'cambio' : 'cambios') + '...';
        },

        get motivoSaltarValido() {
            if (this.motivoSaltar === 'Otro') return this.motivoOtro.trim().length > 0;
            return !!this.motivoSaltar;
        },

        // YYYY-MM-DD (cb_arrival_date/cb_departure_date) → DD/MM/YYYY, sin pasar por Date():
        // son fechas sin hora, y Date() las interpreta en UTC — con la noche de Chile en UTC-3/-4
        // eso corre el día mostrado. Se parsea el string tal cual.
        fechaCorta(iso) {
            if (!iso) return '';
            var p = String(iso).split('-');
            if (p.length !== 3) return iso;
            return p[2] + '/' + p[1] + '/' + p[0];
        },

        // Antigüedad del dato de ocupación de Cloudbeds (cb_ocupacion_sync_at), para que
        // la trabajadora sepa si vale la pena tocar "Actualizar ahora" antes de entrar.
        get textoUltimaSync() {
            if (!this.habitacion || !this.habitacion.cb_ocupacion_sync_at) return null;
            var ms = Date.now() - new Date(this.habitacion.cb_ocupacion_sync_at).getTime();
            if (isNaN(ms) || ms < 0) return null;
            var min = Math.floor(ms / 60000);
            if (min < 1) return 'Cloudbeds actualizado hace instantes.';
            if (min < 60) return 'Cloudbeds actualizado hace ' + min + ' min.';
            var horas = Math.floor(min / 60);
            return 'Cloudbeds actualizado hace ' + horas + (horas === 1 ? ' hora.' : ' horas.');
        },

        async cargar() {
            this.cargando = true;
            this.error = null;

            // Verificar permiso ver_todas y asignación
            try {
                var yo = Alpine.store('auth');
                if (yo && yo.cargado) {
                    this.puedeVerTodas = yo.tienePermiso('habitaciones.ver_todas');
                    this.puedeAsignar = yo.tienePermiso('asignaciones.asignar_manual');
                    this.puedeVerHistorial = yo.tienePermiso('habitaciones.ver_historial');
                    this.puedeReportar = yo.tienePermiso('tickets.crear');
                    this.puedeMarcarLimpia = yo.tienePermiso('habitaciones.marcar_limpia_manual');
                    this.puedeSincronizar = yo.tienePermiso('cloudbeds.forzar_sincronizacion');
                    this.puedeAgregarNota = yo.tienePermiso('habitaciones.agregar_nota');
                }

                var r1 = await apiFetch('/api/habitaciones/' + this.habitacionId);
                if (!r1 || !r1.ok) {
                    this.error = (r1 && r1.error && r1.error.mensaje) || 'No encontrada.';
                    return;
                }
                this.habitacion = r1.data.habitacion;

                // Consultar cola del trabajador para saber si está asignada
                var r2 = await apiFetch('/api/usuarios/' + this.usuarioId + '/cola');
                if (r2 && r2.ok) {
                    var ids = (r2.data.cola || []).map(function (a) { return a.habitacion_id; });
                    this.estaAsignada = ids.indexOf(this.habitacion.id) !== -1;
                }

                // Sólo el trabajador asignado carga el checklist editable
                // (el backend de iniciar es idempotente y retorna la ejecución existente si ya hay una).
                if (this.habitacion.estado === 'en_progreso' && this.estaAsignada) {
                    await this.cargarEjecucion();
                }

                // Historial de limpiezas: la autoridad es el backend (403 si el rol
                // no tiene habitaciones.ver_historial). No podemos depender solo del
                // store de auth: puede no haber terminado de cargar en este punto.
                // Si el store YA cargó y dice que no hay permiso, ni intentamos.
                var stAuth = Alpine.store('auth');
                var intentarHistorial = !(stAuth && stAuth.cargado)
                    || stAuth.tienePermiso('habitaciones.ver_historial');
                if (intentarHistorial) {
                    try {
                        var rHist = await apiFetch('/api/habitaciones/' + this.habitacionId + '/historial');
                        if (rHist && rHist.ok) {
                            this.historial = rHist.data.historial || [];
                            this.puedeVerHistorial = true;
                        }
                    } catch (e) { /* sección opcional: sin historial no se rompe la página */ }

                    // Historial de movimientos (cambios de estado manuales/automáticos):
                    // mismo permiso que el historial de limpiezas, mismo criterio de "opcional".
                    try {
                        var rMov = await apiFetch('/api/habitaciones/' + this.habitacionId + '/movimientos');
                        if (rMov && rMov.ok) {
                            this.movimientos = rMov.data.movimientos || [];
                        }
                    } catch (e) { /* sección opcional: sin movimientos no se rompe la página */ }
                }

                // Cargar cola offline
                this.cargarColaLocal();
                if (this.cola.length > 0 && !this.sinConexion) {
                    this.procesarCola();
                }
            } catch (e) {
                this.error = 'No pudimos conectar con el servidor.';
            } finally {
                this.cargando = false;
                this.$nextTick(function () { lucide.createIcons(); });
            }
        },

        async cargarEjecucion() {
            // El backend de /iniciar es idempotente: si ya hay una ejecución 'en_progreso' para la habitación
            // + asignación del trabajador, la retorna sin efectos colaterales.
            try {
                var rPost = await apiPost('/api/habitaciones/' + this.habitacionId + '/iniciar', {});
                if (rPost && rPost.ok && rPost.data && rPost.data.ejecucion) {
                    var ejecId = rPost.data.ejecucion.id;
                    var rEjec = await apiFetch('/api/ejecuciones/' + ejecId);
                    if (rEjec && rEjec.ok) {
                        this.ejecucion = rEjec.data.ejecucion;
                        this.items = (rEjec.data.items || []).map(function (it) {
                            it._guardando = false;
                            it._error = null;
                            return it;
                        });
                        this.progreso = rEjec.data.progreso;
                        this.iniciarDelayRestante(rEjec.data.delay_restante_segundos || 0);
                    }
                }
            } catch (e) {
                // Silencioso — checklist no disponible para este rol/estado.
            }
        },

        // Arranca (o no) el conteo regresivo del delay mínimo para completar. Decrementa
        // cada segundo del lado del cliente — no vuelve a pedirle nada al servidor hasta
        // que el trabajador toque "Habitación terminada".
        iniciarDelayRestante(segundos) {
            if (this._delayTimer) {
                clearInterval(this._delayTimer);
                this._delayTimer = null;
            }
            this.delayRestante = segundos;
            if (segundos <= 0) return;
            var self = this;
            this._delayTimer = setInterval(function () {
                self.delayRestante = Math.max(0, self.delayRestante - 1);
                if (self.delayRestante === 0) {
                    clearInterval(self._delayTimer);
                    self._delayTimer = null;
                }
            }, 1000);
        },

        // mm:ss para el botón "Habitación terminada" mientras corre el delay.
        textoDelayRestante() {
            var mm = Math.floor(this.delayRestante / 60);
            var ss = this.delayRestante % 60;
            return mm + ':' + (ss < 10 ? '0' : '') + ss;
        },

        async iniciar() {
            if (this.iniciando) return;
            this.iniciando = true;
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/iniciar', {});
                if (json && json.ok) {
                    await this.cargar();
                } else {
                    alert((json && json.error && json.error.mensaje) || 'No pudimos iniciar.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.iniciando = false;
            }
        },

        reportarProblema() {
            // Mismo contrato que home-trabajador.php: modal-ticket-nuevo.php (incluido
            // globalmente en layout.php) escucha este evento y precarga la habitación.
            window.dispatchEvent(new CustomEvent('abrir-modal-ticket', { detail: { habitacionId: this.habitacionId } }));
        },

        // Trae desde Cloudbeds el estado (ocupación incluida) de todas las habitaciones
        // del hotel y recarga esta pieza. Mismo endpoint que "Sincronizar ahora" en
        // /habitaciones; el servidor limita a 1 llamada cada 3 min por usuario (429 con
        // mensaje de espera si se excede). Requiere cloudbeds.forzar_sincronizacion
        // (el botón ya viene oculto sin el permiso; el endpoint también lo exige).
        async sincronizarAhora() {
            if (this.sincronizando) return;
            this.sincronizando = true;
            try {
                var r = await apiPost('/api/cloudbeds/sync', {});
                if (r.ok) {
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

        async guardarNota() {
            var texto = this.formNota.trim();
            if (!texto || this.notaEnviando) return;
            this.notaEnviando = true;
            try {
                var r = await apiPost('/api/habitaciones/' + this.habitacionId + '/nota', { nota: texto });
                if (r && r.ok) {
                    this.habitacion.nota_recepcion = r.data.habitacion.nota_recepcion;
                    this.editandoNota = false;
                } else {
                    alert((r && r.error && r.error.mensaje) || 'No pudimos guardar la nota.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.notaEnviando = false;
            }
        },

        async quitarNota() {
            if (this.notaEnviando) return;
            if (!confirm('¿Quitar la nota de esta habitación?')) return;
            this.notaEnviando = true;
            try {
                var r = await apiFetch('/api/habitaciones/' + this.habitacionId + '/nota', { method: 'DELETE' });
                if (r && r.ok) {
                    this.habitacion.nota_recepcion = null;
                } else {
                    alert((r && r.error && r.error.mensaje) || 'No pudimos quitar la nota.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.notaEnviando = false;
            }
        },

        async marcarLimpiaManual() {
            if (this.marcandoLimpia) return;
            this.marcandoLimpia = true;
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/marcar-limpia', {});
                if (json && json.ok) {
                    this.mostrarMarcarLimpia = false;
                    await this.cargar();
                } else {
                    alert((json && json.error && json.error.mensaje) || 'No pudimos marcarla como limpia.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.marcandoLimpia = false;
            }
        },

        esHeredado(item) {
            // Ítem ya limpiado por otro trabajador en un intento previo (re-limpieza): solo lectura.
            return item.marcado == 1 && item.marcado_por && Number(item.marcado_por) !== Number(this.usuarioId);
        },

        toggleItem(item, nuevoValor) {
            if (!this.puedeEditar || this.esHeredado(item)) return;
            // Optimistic update
            var previo = item.marcado == 1;
            item.marcado = nuevoValor ? 1 : 0;
            item._error = null;

            // Recalcular progreso local (estimación)
            this.recalcularProgresoLocal();

            // Enviar o encolar
            if (this.sinConexion) {
                this.encolarMarca(item.id, nuevoValor);
                return;
            }

            item._guardando = true;
            this.enviarMarca(item.id, nuevoValor).then((exito) => {
                item._guardando = false;
                if (!exito) {
                    // Rollback
                    item.marcado = previo ? 1 : 0;
                    item._error = 'No se guardó';
                    this.recalcularProgresoLocal();
                    this.encolarMarca(item.id, nuevoValor);
                }
            });
        },

        async enviarMarca(itemId, marcado) {
            try {
                var json = await apiPut(
                    '/api/ejecuciones/' + this.ejecucion.id + '/items/' + itemId,
                    { marcado: !!marcado }
                );
                if (json && json.ok) {
                    this.progreso = json.data.progreso;
                    return true;
                }
                return false;
            } catch (e) {
                return false;
            }
        },

        recalcularProgresoLocal() {
            var marcados = 0, obligMarcados = 0, obligTotal = 0, total = 0;
            this.items.forEach(function (it) {
                total++;
                if (it.obligatorio == 1) obligTotal++;
                if (it.marcado == 1) {
                    marcados++;
                    if (it.obligatorio == 1) obligMarcados++;
                }
            });
            this.progreso = {
                marcados: marcados,
                total: total,
                porcentaje: total === 0 ? 0 : Math.round(marcados * 100 / total),
                obligatorios_total: obligTotal,
                obligatorios_marcados: obligMarcados,
                obligatorios_pendientes: obligTotal - obligMarcados
            };
        },

        // --- Cola offline ---

        cargarColaLocal() {
            if (!this.colaKey) return;
            var raw = localStorage.getItem(this.colaKey);
            this.cola = raw ? (JSON.parse(raw) || []) : [];
        },

        guardarColaLocal() {
            if (!this.colaKey) return;
            if (this.cola.length === 0) {
                localStorage.removeItem(this.colaKey);
            } else {
                localStorage.setItem(this.colaKey, JSON.stringify(this.cola));
            }
        },

        encolarMarca(itemId, marcado) {
            // Dedupe: remover entradas previas para el mismo item
            this.cola = this.cola.filter(function (c) { return c.item_id !== itemId; });
            this.cola.push({
                tipo: 'item',
                item_id: itemId,
                marcado: !!marcado,
                timestamp_local: new Date().toISOString(),
                intentos: 0
            });
            this.guardarColaLocal();
        },

        encolarCompletar() {
            // Dedupe: solo puede haber un "completar" pendiente por ejecución.
            this.cola = this.cola.filter(function (c) { return c.tipo !== 'completar'; });
            this.cola.push({
                tipo: 'completar',
                timestamp_local: new Date().toISOString(),
                intentos: 0
            });
            this.guardarColaLocal();
        },

        async enviarCompletar() {
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/completar', {});
                return !!(json && json.ok);
            } catch (e) {
                return false;
            }
        },

        async procesarCola() {
            if (this._procesandoCola || this.cola.length === 0 || this.sinConexion || !this.ejecucion) return;
            this._procesandoCola = true;
            this.errorPermanente = false;

            var pendientes = this.cola.slice();
            for (var i = 0; i < pendientes.length; i++) {
                var entrada = pendientes[i];
                var tipo = entrada.tipo || 'item';
                var exito = tipo === 'completar'
                    ? await this.enviarCompletar()
                    : await this.enviarMarca(entrada.item_id, entrada.marcado);

                if (exito) {
                    // Quitar de la cola real
                    this.cola = this.cola.filter(function (c) { return c.timestamp_local !== entrada.timestamp_local; });
                    this.guardarColaLocal();
                    if (tipo === 'completar') {
                        // Recién confirmada por el servidor: ahora sí se navega.
                        window.location.href = u('/home');
                        return;
                    }
                } else {
                    // Incrementar intentos
                    var idx = this.cola.findIndex(function (c) { return c.timestamp_local === entrada.timestamp_local; });
                    if (idx !== -1) {
                        this.cola[idx].intentos = (this.cola[idx].intentos || 0) + 1;
                        if (this.cola[idx].intentos >= 3) {
                            this.errorPermanente = true;
                            this.cola.splice(idx, 1);
                        }
                    }
                    this.guardarColaLocal();
                    // Backoff simple antes de seguir
                    await new Promise(function (r) { setTimeout(r, 1000); });
                }
            }
            this._procesandoCola = false;
        },

        iniciarListeners() {
            var self = this;
            window.addEventListener('online', function () {
                self.sinConexion = false;
                self.procesarCola();
            });
            window.addEventListener('offline', function () {
                self.sinConexion = true;
            });
        },

        confirmarCompletar() {
            if (this.progreso.obligatorios_pendientes > 0) return;
            this.mostrarConfirmar = true;
        },

        async completar() {
            if (this.completando) return;
            this.completando = true;
            try {
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/completar', {});
                if (json && json.ok) {
                    this.mostrarConfirmar = false;
                    window.location.href = u('/home');
                    return;
                }
                // Rechazo real del servidor (ej. checklist incompleto): sí hay que avisar.
                alert((json && json.error && json.error.mensaje) || 'No pudimos completar.');
            } catch (e) {
                // Falla de red/timeout, no un rechazo del servidor — el POST puede haber
                // llegado igual (completar() es idempotente en el backend, ver
                // ChecklistService::ultimaEjecucionCompletadaPorUsuario). En vez de un
                // alert() que bloquea y desanima al trabajador, se encola y se reintenta
                // solo; el aviso queda en el banner de "Confirmando..." en vez de un error.
                this.mostrarConfirmar = false;
                this.encolarCompletar();
                this.procesarCola();
            } finally {
                this.completando = false;
            }
        },

        cerrarSaltar() {
            this.mostrarSaltar = false;
            this.motivoSaltar = null;
            this.motivoOtro = '';
        },

        async saltar() {
            if (this.saltando || !this.motivoSaltarValido) return;
            this.saltando = true;
            var motivo = this.motivoSaltar === 'Otro' ? this.motivoOtro.trim() : this.motivoSaltar;
            try {
                // Si todavía está 'sucia' (el botón "No puedo entrar ahora" se abrió sin
                // pasar por "Comenzar limpieza"), /saltar no tiene ejecución que saltar —
                // primero hay que abrirla con /iniciar (idempotente). Mismo patrón que
                // home-trabajador.php::confirmarSaltar(): mismo backend, misma regla de
                // negocio, sin duplicar lógica.
                if (this.habitacion.estado !== 'en_progreso') {
                    var rIniciar = await apiPost('/api/habitaciones/' + this.habitacionId + '/iniciar', {});
                    if (!rIniciar || !rIniciar.ok) {
                        alert((rIniciar && rIniciar.error && rIniciar.error.mensaje) || 'No pudimos abrir la habitación.');
                        return;
                    }
                }
                var json = await apiPost('/api/habitaciones/' + this.habitacionId + '/saltar', { motivo: motivo });
                if (json && json.ok) {
                    this.mostrarSaltar = false;
                    window.location.href = u('/home');
                } else {
                    alert((json && json.error && json.error.mensaje) || 'No pudimos registrar el salto.');
                }
            } catch (e) {
                alert('No pudimos conectar con el servidor.');
            } finally {
                this.saltando = false;
            }
        },

        badgeEstado(estado) {
            // 'aprobada_con_observacion' se muestra como "Aprobada" a secas para el
            // trabajador (no debe distinguirla de una aprobada normal — filosofía sin
            // ansiedad). Solo quien tiene 'habitaciones.ver_todas' (supervisora/auditor)
            // ve la distinción "c/obs.". Ver CLAUDE.md y docs/auditoria.md.
            var textoConObs = this.puedeVerTodas ? 'Aprobada c/obs.' : 'Aprobada';
            // Colores por estado: clases semánticas .chip-estado-* (editables en Ajustes → Colores).
            var configs = {
                'sucia': { texto: 'Pendiente', clase: 'chip-estado-sucia' },
                'en_progreso': { texto: 'En progreso', clase: 'chip-estado-en_progreso' },
                'completada_pendiente_auditoria': { texto: 'Por auditar', clase: 'chip-estado-completada_pendiente_auditoria' },
                'aprobada': { texto: 'Aprobada', clase: 'chip-estado-aprobada' },
                'aprobada_con_observacion': { texto: textoConObs, clase: 'chip-estado-aprobada_con_observacion' },
                'rechazada': { texto: 'Rechazada', clase: 'chip-estado-rechazada' }
            };
            var c = configs[estado] || { texto: estado, clase: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' };
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + c.clase + '">' + escapeHtml(c.texto) + '</span>';
        },

        // --- Historial de limpiezas ---

        fechaHistorial(ts) {
            // Timestamps ISO-UTC de la BD → fecha/hora local de Chile (DD/MM/YYYY HH:mm)
            if (!ts) return '';
            try {
                var d = new Date(ts);
                return d.toLocaleString('es-CL', {
                    timeZone: 'America/Santiago',
                    day: '2-digit', month: '2-digit', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) { return ts; }
        },

        etiquetaHistorial(h) {
            if (h.veredicto === 'aprobado') return 'Aprobada';
            if (h.veredicto === 'aprobado_con_observacion') return 'Aprobada c/obs.';
            if (h.veredicto === 'rechazado') return 'Rechazada';
            if (h.estado === 'en_progreso') return 'En progreso';
            if (h.estado === 'completada') return 'Completada';
            return 'Auditada';
        },

        claseHistorial(h) {
            if (h.veredicto === 'rechazado') return 'chip-estado-rechazada';
            if (h.veredicto) return 'chip-estado-aprobada';
            if (h.estado === 'en_progreso') return 'chip-estado-en_progreso';
            return 'chip-estado-completada_pendiente_auditoria';
        }
    };
}
</script>
