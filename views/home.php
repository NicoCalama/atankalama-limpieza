<?php
/**
 * Home shell — carga el dashboard correcto según permisos del usuario.
 * La cascada vive en Support\PantallaInicio (única fuente; también la usan el menú y la vista guiada).
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

use Atankalama\Limpieza\Support\PantallaInicio;

switch (PantallaInicio::de($usuario)) {
    case PantallaInicio::ADMIN:
        include __DIR__ . '/home-admin.php';
        return;
    case PantallaInicio::SUPERVISORA:
        include __DIR__ . '/home-supervisora.php';
        return;
    case PantallaInicio::HABITACIONES:
        // Recepción no gestiona aseo: su punto de partida es Habitaciones (ahí también hace la
        // inspección pre-entrega). PaginasController::home ya redirige en el servidor; esto queda como
        // respaldo si la vista se incluye por otro camino. JS y no header(): el layout ya escribió HTML.
        echo '<script>window.location.replace(' . json_encode(u('/habitaciones')) . ');</script>';
        return;
    default:
        include __DIR__ . '/home-trabajador.php';
}
