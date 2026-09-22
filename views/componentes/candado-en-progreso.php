<?php
/**
 * Candado de las piezas EN PROGRESO que el usuario no puede reasignar ni quitar (le falta
 * asignaciones.mover_en_progreso, que por defecto solo tiene Admin). Lo usan Asignaciones y
 * el Inicio de la supervisora.
 *
 * Uso: <?= svgCandado() ?> y <?= avisoEnProgreso() ?>
 *
 * SVG en línea (ícono "lock" de Lucide) y no <i data-lucide>: el candado aparece recién
 * cuando llegan los permisos, después del último lucide.createIcons(), y un <i> sin
 * convertir quedaría invisible.
 */

function svgCandado(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
        . ' class="w-4 h-4 flex-shrink-0"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>'
        . '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
}

function avisoEnProgreso(): string
{
    return 'En progreso: solo un administrador puede moverla';
}
