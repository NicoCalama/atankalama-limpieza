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
    include __DIR__ . '/home-recepcion.php';
    return;
}

include __DIR__ . '/home-trabajador.php';
