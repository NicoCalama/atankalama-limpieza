<?php
/**
 * Filtro del pool «Sin asignar» de un día futuro por lo que pasa ese día en cada pieza, según las
 * reservas de Cloudbeds (v7.15): Todas · Se va · Cambio · Sábanas · Sigue, cada uno con su conteo.
 * Pensado para el celular, donde la supervisora planifica: botones de 44 px, y la lista larga se
 * achica a lo que interesa. Lo usan el Tablero y el Clásico de Asignaciones (asignacionesApp()).
 *
 * Solo aparece si el backend mandó etiquetas (tipo_dia): si Cloudbeds no contestó, no hay qué filtrar.
 */
?>
<template x-if="esFuturo && hayTiposDia()">
    <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrar por lo que pasa ese día">
        <template x-for="op in opcionesFiltroDia()" :key="op.valor">
            <button type="button" @click="setFiltroDia(op.valor)" :aria-pressed="filtroDia === op.valor ? 'true' : 'false'"
                    class="min-h-[44px] px-3 rounded-full border text-sm font-medium inline-flex items-center gap-1.5 transition"
                    :class="op.c + (filtroDia === op.valor ? ' ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-gray-800' : '')">
                <span x-text="op.label"></span>
                <span class="text-xs font-semibold" x-text="op.n"></span>
            </button>
        </template>
    </div>
</template>
