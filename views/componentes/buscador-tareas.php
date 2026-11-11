<?php
/**
 * Buscador del catálogo de tareas de las áreas beta (v7.27). Lista en el flujo del modal, sin capa flotante: los
 * modales tienen scroll y la recortarían. Usa el estado `busc` y los métodos de espaciosApp() (views/espacios.php).
 */
?>
<div class="space-y-2">
    <div class="relative">
        <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        <input type="search" x-model="busc.q" @input.debounce.200ms="buscarTareas()" @focus="buscarTareas()"
               placeholder="Buscar tarea..." aria-label="Buscar tarea en el catálogo" autocomplete="off"
               class="w-full pl-9 pr-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
    </div>

    <template x-if="busc.error">
        <p class="text-sm text-red-600 dark:text-red-400" x-text="busc.error"></p>
    </template>

    <template x-if="busc.resultados.length > 0">
        <ul class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
            <template x-for="t in busc.resultados" :key="t.id">
                <li>
                    <button type="button" @click="elegirTarea(t)"
                            class="w-full min-h-[44px] px-3 py-2 flex items-center justify-between gap-3 text-left text-sm text-gray-900 dark:text-gray-100 hover:bg-teal-50 dark:hover:bg-teal-900/30">
                        <span class="min-w-0 break-words" x-text="t.nombre"></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0" x-text="fmtNum(t.creditos_m2) + ' por m²'"></span>
                    </button>
                </li>
            </template>
        </ul>
    </template>

    <!-- Se puede crear mientras no exista ese nombre exacto, aunque salgan otras parecidas. -->
    <template x-if="busc.q.trim() !== '' && !busc.cargando && !busc.error && busc.buscado === busc.q && !busc.creando && !existeNombreExacto()">
        <div class="text-sm text-gray-500 dark:text-gray-400">
            <p x-show="busc.resultados.length === 0">No hay tareas con ese nombre.</p>
            <template x-if="puedeCrearTarea">
                <button type="button" @click="iniciarCrearTarea()"
                        class="min-h-[44px] text-sm font-medium text-teal-600 dark:text-teal-400 hover:underline inline-flex items-center gap-1">
                    <i data-lucide="plus" class="w-4 h-4"></i> Crear nueva tarea
                </button>
            </template>
        </div>
    </template>

    <template x-if="busc.creando">
        <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3 space-y-2">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Nueva tarea: <span x-text="busc.q.trim()"></span></p>
            <label class="block text-xs text-gray-500 dark:text-gray-400">Créditos por m² (igual en todas las áreas)</label>
            <input x-model="busc.valorNuevo" type="number" inputmode="decimal" min="0" step="0.001" aria-label="Créditos por metro cuadrado"
                   class="w-full px-3 py-2 min-h-[44px] border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-lg text-sm">
            <div class="flex gap-2">
                <button type="button" @click="busc.creando = false"
                        class="flex-1 min-h-[44px] px-3 text-sm font-medium rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100">Cancelar</button>
                <button type="button" @click="crearTarea()" :disabled="busc.enviando"
                        class="flex-1 min-h-[44px] px-3 text-sm font-semibold rounded-lg bg-teal-600 hover:bg-teal-700 text-white disabled:opacity-50">Crear</button>
            </div>
        </div>
    </template>
</div>
