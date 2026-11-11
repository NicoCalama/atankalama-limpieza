<?php
/**
 * Lista de extras de una limpieza de un área beta (v7.27), con su buscador. Va en «Pedir limpieza» y en la ventana
 * «Extras». Usa el estado `extrasEd` de espaciosApp() (views/espacios.php).
 */
?>
<div class="space-y-2">
    <template x-if="extrasEd.cargando">
        <p class="text-sm text-gray-500 dark:text-gray-400">Cargando...</p>
    </template>
    <template x-for="(extra, idx) in extrasEd.lista" :key="extra.tarea_id">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 border-l-4 border-l-amber-500 p-2 flex flex-wrap items-center gap-2">
            <span class="flex-1 min-w-0 break-words text-sm text-gray-900 dark:text-gray-100" x-text="extra.nombre"></span>
            <template x-if="extra.m2_nuevo">
                <label class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                    <input x-model="extra.m2" @input="extra.creditos = creditosDe(extra.m2, extra.creditos_m2)"
                           type="number" inputmode="decimal" min="0" step="0.1" aria-label="Metros cuadrados"
                           class="w-20 px-2 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm text-center">
                    m²
                </label>
            </template>
            <template x-if="!extra.m2_nuevo">
                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="fmtNum(extra.m2) + ' m²'"></span>
            </template>
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-300" x-text="extra.creditos + ' créd.'"></span>
            <button type="button" @click="extrasEd.lista.splice(idx, 1)" aria-label="Quitar extra"
                    class="min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
    </template>
    <?php include __DIR__ . '/buscador-tareas.php'; ?>
</div>
