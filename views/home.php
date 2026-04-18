<?php
/**
 * Home shell — carga el dashboard correcto según permisos del usuario.
 * Variable requerida: $usuario (Atankalama\Limpieza\Models\Usuario)
 */

use Atankalama\Limpieza\Services\AuthService;

$authSvc = new AuthService();
$homeTarget = $authSvc->calcularHomeTarget($usuario);

// Cada Home tiene su propia vista completa según rol (items 44-47).
if ($homeTarget === '/home-trabajador') {
    include __DIR__ . '/home-trabajador.php';
    return;
}

if ($homeTarget === '/home-supervisora') {
    include __DIR__ . '/home-supervisora.php';
    return;
}

if ($homeTarget === '/home-recepcion') {
    include __DIR__ . '/home-recepcion.php';
    return;
}

if ($homeTarget === '/home-admin') {
    include __DIR__ . '/home-admin.php';
    return;
}
