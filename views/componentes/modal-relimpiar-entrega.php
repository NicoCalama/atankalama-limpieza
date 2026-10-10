<?php
/**
 * Ventana «Re-limpiar» (v7): la supervisora manda a re-limpiar una pieza aprobada que Recepción no
 * aprobó para entregar (inspección pre-entrega). Spec: docs/revision-entrega.md §«Re-limpieza».
 * La incluye layout.php solo si el usuario tiene asignaciones.asignar_manual.
 *
 * Se abre desde la franja de la tarjeta (Habitaciones) o desde la tarjeta roja del detalle:
 *   window.dispatchEvent(new CustomEvent('abrir-relimpiar-entrega', { detail: { revision, habitacion: { id, numero, hotel_codigo } } }))
 * Al terminar emite 'relimpieza-pedida' con { habitacion_id, revision, trabajador_nombre }.
 *
 * Variable requerida: $usuario
 */

$modalRelimpiarJsFile = __DIR__ . '/../recursos/componentes/modal-relimpiar-entrega.js';
$modalRelimpiarJsV = @filemtime($modalRelimpiarJsFile) ?: '1';
$puedePriorizarRelimpieza = $usuario->tienePermiso('asignaciones.reordenar_cola_trabajador');
?>
<script src="<?= u('/views/recursos/componentes/modal-relimpiar-entrega.js') ?>?v=<?= $modalRelimpiarJsV ?>"></script>

<div x-data="modalRelimpiarEntrega(<?= $puedePriorizarRelimpieza ? 'true' : 'false' ?>)"
     @abrir-relimpiar-entrega.window="abrir($event.detail)"
     @keydown.escape.window="cerrar()">

    <div x-show="abierto" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()" role="dialog" aria-modal="true" aria-labelledby="relimpiar-titulo">
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">No aprobada por Recepción</p>
                    <h3 id="relimpiar-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                        x-text="hab ? ('Re-limpiar la habitación ' + hab.numero) : ''"></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300" x-text="textoMotivo()"></p>
                    <p x-show="revision && revision.comentario" x-cloak
                       class="text-sm text-gray-500 dark:text-gray-400 italic line-clamp-3 break-words"
                       x-text="revision ? ('«' + (revision.comentario || '') + '»') : ''"></p>
                </div>
                <button type="button" @click="cerrar()" :disabled="enviando" aria-label="Cerrar"
                        class="min-h-[44px] min-w-[44px] -mr-2 -mt-2 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">¿Quién la limpia?</p>

            <!-- DEFAULT APLICADO (aprobado por el usuario): spinner azul con «Cargando...» como estado de carga. -->
            <div x-show="cargando" class="flex flex-col items-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
                <span class="w-6 h-6 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></span>
                Cargando...
            </div>
            <div x-show="errorCarga && !cargando" x-cloak class="p-3 mb-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-sm text-red-700 dark:text-red-300">
                <p x-text="errorCarga"></p>
                <button type="button" @click="cargarTrabajadoras()" class="mt-2 min-h-[40px] px-3 rounded-lg bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 font-medium">Reintentar</button>
            </div>
            <div x-show="!cargando && !errorCarga && trabajadoras.length === 0" x-cloak
                 class="p-3 mb-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 text-sm">
                No hay trabajadoras con turno hoy en este hotel. Revisa Turnos o asígnala desde Asignaciones.
            </div>

            <div x-show="!cargando && trabajadoras.length > 0" class="space-y-2 mb-4" role="radiogroup" aria-label="Trabajadora">
                <template x-for="t in trabajadoras" :key="t.id">
                    <button type="button" role="radio" :aria-checked="elegida === t.id ? 'true' : 'false'"
                            @click="elegida = t.id" :disabled="enviando"
                            :class="elegida === t.id
                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 text-blue-900 dark:text-blue-100'
                                : 'border-gray-200 dark:border-gray-600 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="w-full min-h-[52px] rounded-xl border-2 px-3 text-left flex items-center justify-between gap-2">
                        <span class="min-w-0">
                            <span class="block font-medium truncate" x-text="t.nombre"></span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="textoCarga(t)"></span>
                        </span>
                        <span x-show="elegida === t.id" aria-hidden="true" class="text-blue-600 dark:text-blue-300">✓</span>
                    </button>
                </template>
            </div>

            <label x-show="puedePriorizar && trabajadoras.length > 0" x-cloak
                   class="flex items-start gap-3 p-3 mb-3 rounded-lg border border-gray-200 dark:border-gray-700 cursor-pointer min-h-[44px]">
                <input type="checkbox" x-model="prioridad" :disabled="enviando" class="mt-1 w-5 h-5 rounded text-blue-600">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-200">Que sea la siguiente de su cola</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">Si está limpiando otra, termina esa primero.</span>
                </span>
            </label>

            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Le llega el aviso con el motivo. La re-limpieza no suma en los KPIs de quien la haga: el NO de Recepción cuenta para la supervisora que la aprobó.
            </p>

            <div x-show="error" x-cloak class="p-3 mb-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>

            <div class="flex gap-2">
                <button type="button" @click="cerrar()" :disabled="enviando"
                        class="flex-1 min-h-[44px] rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 font-medium disabled:opacity-50">Cancelar</button>
                <button type="button" @click="enviar()" :disabled="elegida === null || enviando"
                        class="flex-1 min-h-[44px] rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold disabled:opacity-50"
                        x-text="enviando ? 'Asignando...' : 'Re-limpiar'"></button>
            </div>
        </div>
    </div>

    <div x-show="toast" x-cloak x-transition
         class="fixed bottom-20 left-1/2 -translate-x-1/2 bg-emerald-600 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-[55] max-w-[90vw] text-center"
         x-text="toast"></div>
</div>
