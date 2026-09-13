<?php
/**
 * Home shell — carga el dashboard correcto según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

if ($usuario->tienePermiso('ajustes.acceder')) {
    include __DIR__ . '/home-admin.php';
    return;
}

if ($usuario->tienePermiso('alertas.recibir_predictivas') && $usuario->tienePermiso('asignaciones.asignar_manual')) {
    include __DIR__ . '/home-supervisora.php';
    return;
}

if ($usuario->tienePermiso('auditoria.ver_bandeja')) {
    // Recepción no gestiona aseo: su punto de partida es Habitaciones, no la bandeja
    // de auditoría (que sigue disponible desde el menú "Auditoría", sidebar y barra
    // inferior — ver componentes/sidebar.php y componentes/bottom-nav.php). Redirect
    // en JS y no header() porque el layout ya viene escribiendo HTML en este punto.
    echo '<script>window.location.replace(' . json_encode(u('/habitaciones')) . ');</script>';
    return;
}

include __DIR__ . '/home-trabajador.php';
