<?php
/**
 * Ventana de la inspección pre-entrega (v6.18; en código «revision_entrega»). Spec: docs/revision-entrega.md.
 * La incluye layout.php solo si el usuario tiene revision_entrega.registrar.
 *
 * Se abre desde el botón grande de la tarjeta de la pieza en Habitaciones:
 *   window.dispatchEvent(new CustomEvent('abrir-inspeccion-pre-entrega', { detail: { habitacion: { id, numero, hotel_codigo, revision_vigente } } }))
 * Al guardar emite 'inspeccion-pre-entrega-registrada' con { habitacion_id, revision } para que la tarjeta
 * se actualice sin recargar.
 */

$modalInspeccionJsFile = __DIR__ . '/../recursos/componentes/modal-inspeccion-pre-entrega.js';
$modalInspeccionJsV = @filemtime($modalInspeccionJsFile) ?: '1';
?>
<script src="<?= u('/views/recursos/componentes/modal-inspeccion-pre-entrega.js') ?>?v=<?= $modalInspeccionJsV ?>"></script>

<div x-data="modalInspeccionPreEntrega()"
     @abrir-inspeccion-pre-entrega.window="abrir($event.detail)"
     @keydown.escape.window="cerrar()">

    <!-- Paso 1: ¿se puede entregar? -->
    <div x-show="abierto && paso === 'pregunta'" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()" role="dialog" aria-modal="true" aria-labelledby="insp-pregunta-titulo">
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl max-w-sm w-full p-5 shadow-xl">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400">Inspección pre-entrega</p>
                    <h3 id="insp-pregunta-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                        x-text="hab ? ('Habitación ' + hab.numero + ' · ' + hotelNombre(hab.hotel_codigo)) : ''"></h3>
                </div>
                <button type="button" @click="cerrar()" :disabled="enviando !== null" aria-label="Cerrar"
                        class="min-h-[44px] min-w-[44px] -mr-2 -mt-2 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <p class="text-base text-gray-800 dark:text-gray-200 mb-3">¿La habitación está en condiciones para entregarse a un cliente?</p>
            <p x-show="hab && hab.revision_vigente" x-cloak class="text-xs text-gray-500 dark:text-gray-400 mb-3" x-text="textoRevisionVigente()"></p>

            <div class="grid grid-cols-2 gap-3">
                <button type="button" @click="enviar('si')" :disabled="enviando !== null"
                        class="min-h-[56px] rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-lg font-bold transition disabled:opacity-50 flex items-center justify-center gap-2">
                    <span x-show="enviando === 'si'" class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                    SÍ
                </button>
                <button type="button" @click="irANo()" :disabled="enviando !== null"
                        class="min-h-[56px] rounded-xl bg-red-600 hover:bg-red-700 active:bg-red-800 text-white text-lg font-bold transition disabled:opacity-50">
                    NO
                </button>
            </div>

            <p x-show="lento && enviando === 'si'" x-cloak class="mt-3 text-sm text-amber-700 dark:text-amber-300">La señal está lenta. Seguimos intentando: no cierres esta ventana.</p>
            <div x-show="error && paso === 'pregunta'" x-cloak class="mt-3 p-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>
        </div>
    </div>

    <!-- Paso 2: el NO, con el checklist de motivos -->
    <div x-show="abierto && paso === 'no'" x-cloak
         class="fixed inset-0 z-[60] flex items-end md:items-center justify-center p-4 bg-black/50"
         @click.self="cerrar()" role="dialog" aria-modal="true" aria-labelledby="insp-no-titulo">
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl max-w-md w-full p-5 shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-start justify-between gap-2 mb-3">
                <button type="button" @click="volver()" :disabled="enviando !== null" aria-label="Volver"
                        class="min-h-[44px] min-w-[44px] -ml-2 -mt-2 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
                    <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
                <div class="min-w-0 flex-1">
                    <h3 id="insp-no-titulo" class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                        x-text="hab ? ('Habitación ' + hab.numero + ': no aprobada') : ''"></h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Elige el motivo. Las supervisoras reciben el aviso al toque.</p>
                </div>
                <button type="button" @click="cerrar()" :disabled="enviando !== null" aria-label="Cerrar"
                        class="min-h-[44px] min-w-[44px] -mr-2 -mt-2 flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
                    <i data-lucide="x" class="w-5 h-5 text-gray-600 dark:text-gray-400"></i>
                </button>
            </div>

            <!-- Motivos -->
            <div x-show="cargandoFormulario && !formularioListo" class="flex items-center gap-2 py-4 text-sm text-gray-500 dark:text-gray-400">
                <span class="w-4 h-4 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin"></span> Cargando motivos...
            </div>
            <div x-show="errorFormulario && !formularioListo" x-cloak class="p-3 mb-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-sm text-red-700 dark:text-red-300">
                <p x-text="errorFormulario"></p>
                <button type="button" @click="cargarFormulario()" class="mt-2 min-h-[40px] px-3 rounded-lg bg-white dark:bg-gray-800 border border-red-300 dark:border-red-700 font-medium">Reintentar</button>
            </div>
            <div x-show="formularioListo && motivos.length === 0" x-cloak class="p-3 mb-3 rounded-lg bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 text-sm">
                No hay motivos cargados. Pídele a tu supervisora que los agregue en Ajustes → Inspección pre-entrega.
            </div>
            <div class="grid grid-cols-2 gap-2 mb-4" role="radiogroup" aria-label="Motivo">
                <template x-for="m in motivos" :key="m.id">
                    <button type="button" role="radio" :aria-checked="motivoId === m.id ? 'true' : 'false'"
                            @click="motivoId = m.id" :disabled="enviando !== null"
                            :class="motivoId === m.id
                                ? 'border-red-500 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200'
                                : 'border-gray-200 dark:border-gray-600 text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="min-h-[56px] rounded-xl border-2 px-3 text-base font-medium text-left flex items-center justify-between gap-2">
                        <span x-text="m.nombre"></span>
                        <span x-show="motivoId === m.id" aria-hidden="true">✓</span>
                    </button>
                </template>
            </div>

            <!-- Observaciones -->
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="insp-observaciones">Observaciones (opcional)</label>
            <textarea id="insp-observaciones" rows="2" maxlength="300" x-model="comentario" :disabled="enviando !== null"
                      placeholder="Ej: la tina quedó con pelos"
                      class="w-full mb-4 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-base p-2 focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>

            <!-- Foto -->
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Foto (opcional)</p>
            <div class="mb-4">
                <div x-show="fotoPreview" x-cloak class="relative inline-block mb-2">
                    <img :src="fotoPreview" alt="Foto adjunta" class="w-16 h-16 object-cover rounded-lg">
                    <button type="button" @click="quitarFoto()" :disabled="enviando !== null" aria-label="Quitar foto"
                            class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-gray-800 text-white text-sm flex items-center justify-center">×</button>
                </div>
                <p x-show="procesandoFoto" x-cloak class="text-xs text-gray-500 dark:text-gray-400 mb-2">Procesando foto...</p>
                <label x-show="!fotoPreview && !procesandoFoto"
                       class="inline-flex items-center gap-2 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 min-h-[44px]">
                    <i data-lucide="camera" class="w-4 h-4"></i>
                    <span>Tomar foto</span>
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFoto($event)">
                </label>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3"
               x-text="noEnsucia
                   ? 'Un NO avisa a las supervisoras y, si la pieza estaba aprobada, vuelve a limpieza.'
                   : 'Un NO solo avisa a las supervisoras: la pieza no cambia de estado.'"></p>

            <p x-show="lento && enviando === 'no'" x-cloak class="mb-3 text-sm text-amber-700 dark:text-amber-300">La señal está lenta. Seguimos intentando: no cierres esta ventana.</p>
            <div x-show="error && paso === 'no'" x-cloak class="p-3 mb-3 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded-lg" x-text="error"></div>

            <div class="flex gap-2">
                <button type="button" @click="volver()" :disabled="enviando !== null"
                        class="flex-1 min-h-[44px] rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 font-medium disabled:opacity-50">Volver</button>
                <button type="button" @click="enviar('no')" :disabled="motivoId === null || enviando !== null || procesandoFoto"
                        class="flex-1 min-h-[44px] rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold disabled:opacity-50"
                        x-text="enviando === 'no' ? 'Enviando...' : (reintentar ? 'Reintentar' : 'Avisar NO')"></button>
            </div>
        </div>
    </div>

    <!-- Confirmación (sobre el menú, debajo de las ventanas) -->
    <div x-show="toast.visible" x-cloak x-transition
         :class="toast.tipo === 'aviso' ? 'bg-amber-600' : 'bg-emerald-600'"
         class="fixed bottom-20 left-1/2 -translate-x-1/2 text-white px-4 py-2 rounded-lg shadow-lg text-sm z-[55] max-w-[90vw] text-center"
         x-text="toast.mensaje"></div>
</div>
