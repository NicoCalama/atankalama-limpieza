<?php
/**
 * Barra de progreso del checklist con contador de items obligatorios.
 * Uso dentro de un template Alpine que tenga `progreso` en scope:
 *   - progreso.marcados, progreso.total
 *   - progreso.obligatorios_marcados, progreso.obligatorios_total, progreso.obligatorios_pendientes
 *   - progreso.porcentaje
 */
?>
<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
    <div class="flex items-center justify-between mb-2">
        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Progreso</p>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            <span x-text="progreso.marcados"></span> / <span x-text="progreso.total"></span>
        </p>
    </div>
    <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
        <div class="h-full transition-all duration-300"
             :class="progreso.obligatorios_pendientes === 0 ? 'bg-green-500' : 'bg-blue-500'"
             :style="'width:' + progreso.porcentaje + '%'"></div>
    </div>
    <p class="text-xs mt-2"
       :class="progreso.obligatorios_pendientes === 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400'">
        <template x-if="progreso.obligatorios_pendientes > 0">
            <span>Faltan <span x-text="progreso.obligatorios_pendientes"></span> items obligatorios.</span>
        </template>
        <template x-if="progreso.obligatorios_pendientes === 0">
            <span>Todos los items obligatorios completados.</span>
        </template>
    </p>
</div>
