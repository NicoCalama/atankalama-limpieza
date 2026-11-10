<?php
/**
 * v7.25 (reunión del 08/11/2026): la pestaña Noche de Asignaciones antes de la hora de la tarde (Ajustes →
 * Hora de la tarde). Hoy, hasta esa hora, las piezas Noche no se pueden asignar (el backend también las frena:
 * AsignacionService::exigirNocheDisponible). Lo que ya está en una cola se queda. Lo usan el Tablero y el
 * Clásico de Asignaciones (asignacionesApp()).
 *
 * v7.26 (dos tarjetas, interruptor TARJETA_NOCHE_DOBLE): el pool de Noche se ve igual antes de la hora (la
 * tarjeta de noche de una Día/Noche se puede dejar asignada desde la mañana) y solo queda una línea con las
 * piezas Noche que esperan (nocheSoloEsperan).
 */
?>
<template x-if="nocheEnEspera">
    <div class="text-center py-4">
        <i data-lucide="moon" class="w-8 h-8 text-indigo-500 mx-auto mb-2"></i>
        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
            Disponibles desde las <span x-text="data.ventana_noche.hora"></span>
        </p>
        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1" x-text="textoNocheEnEspera()"></p>
    </div>
</template>
<template x-if="nocheSoloEsperan">
    <p class="flex items-center gap-1.5 text-xs text-indigo-700 dark:text-indigo-300">
        <i data-lucide="moon" class="w-3.5 h-3.5 flex-shrink-0"></i>
        <span x-text="textoNocheSoloEsperan()"></span>
    </p>
</template>
