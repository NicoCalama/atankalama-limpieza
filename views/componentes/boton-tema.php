<?php
/**
 * Botón de tema día/noche para la barra superior.
 *
 * Toggle rápido claro↔oscuro. Llama a la función global window.toggleTema()
 * (definida en public/assets/js/app.js): alterna la clase `dark` del <html> y
 * persiste la preferencia en localStorage('tema'). Usa onclick nativo (no Alpine),
 * así funciona en cualquier header tenga o no un scope x-data alrededor.
 *
 * El ícono se intercambia por CSS según la clase `dark` del <html> (sin JS):
 * luna en modo claro (tap → noche), sol en modo oscuro (tap → día). Por eso van
 * dos SVG inline en vez de <i data-lucide>: evita depender de lucide.createIcons.
 *
 * Pensado para incluirse como último elemento del header (lo más a la derecha).
 */
?>
<button type="button" onclick="toggleTema()"
        class="ml-auto min-h-[44px] min-w-[44px] flex items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 flex-shrink-0"
        aria-label="Cambiar entre modo día y noche" title="Modo día / noche">
    <!-- Luna: visible en modo claro (tap → noche) -->
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         class="w-5 h-5 text-gray-600 block dark:hidden" aria-hidden="true">
        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
    </svg>
    <!-- Sol: visible en modo oscuro (tap → día) -->
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         class="w-5 h-5 text-amber-400 hidden dark:block" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41" />
    </svg>
</button>
