<?php
/**
 * v7.25 (reunión del 08/11/2026): la pestaña Noche de Asignaciones antes de la hora de la tarde (Ajustes →
 * Hora de la tarde). Hoy, hasta esa hora, las piezas Noche no se pueden asignar (el backend también las frena:
 * AsignacionService::exigirNocheDisponible). Lo que ya está en una cola se queda. Lo usan el Tablero y el
 * Clásico de Asignaciones (asignacionesApp()).
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
