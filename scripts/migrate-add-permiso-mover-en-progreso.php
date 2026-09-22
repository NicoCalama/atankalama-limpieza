<?php

declare(strict_types=1);

/**
 * Migración: permiso `asignaciones.mover_en_progreso` (v6.7).
 *
 * Decisión de jefatura (22/09/2026): una habitación EN PROGRESO solo la puede reasignar o
 * quitar un administrador, porque moverla le borra al trabajador lo que llevaba avanzado.
 * La supervisora la sigue viendo, pero bloqueada.
 *
 * 1. Siembra el permiso si falta (en installs frescos lo trae database/seeds/permisos.php).
 * 2. Se lo concede a todo rol que hoy administra la matriz (tiene `permisos.asignar_a_rol`,
 *    la misma definición de "administrador" de la regla anti-bloqueo). OJO: el '__ALL__' de
 *    Admin se expande al momento del seed, así que un permiso agregado DESPUÉS no le llega solo.
 *    A las supervisoras NO se les concede: es justamente lo que la regla evita.
 *
 * Portable (SQLite dev + MariaDB prod) e idempotente: seguro de correr varias veces.
 */

// Solo consola: nunca debe poder ejecutarse abriendo su URL.
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use Atankalama\Limpieza\Core\Config;
use Atankalama\Limpieza\Core\Database;

Config::load(dirname(__DIR__));

const CODIGO = 'asignaciones.mover_en_progreso';

// 1. Permiso (permisos.codigo es la PK; columna de alcance = scope)
$permiso = Database::fetchOne('SELECT codigo FROM #__permisos WHERE codigo = ?', [CODIGO]);
if ($permiso === null) {
    Database::execute(
        'INSERT INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
        [CODIGO, 'Reasignar o quitar habitaciones que están en progreso (el trabajador pierde lo avanzado)', 'Asignaciones', 'global']
    );
    echo "Permiso " . CODIGO . " creado.\n";
} else {
    echo "Permiso " . CODIGO . " ya existía.\n";
}

// 2. Concederlo a los roles administradores (los que asignan permisos a roles)
$rolesAdmin = Database::fetchAll(
    'SELECT DISTINCT r.id, r.nombre
       FROM #__roles r
       JOIN #__rol_permisos rp ON rp.rol_id = r.id
      WHERE rp.permiso_codigo = ?
      ORDER BY r.id',
    ['permisos.asignar_a_rol']
);
if ($rolesAdmin === []) {
    echo "Ningún rol tiene permisos.asignar_a_rol — concede " . CODIGO . " desde Ajustes → Roles.\n";
}
foreach ($rolesAdmin as $rol) {
    $rolId = (int) $rol['id'];
    $ya = Database::fetchOne(
        'SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?',
        [$rolId, CODIGO]
    );
    if ($ya === null) {
        Database::execute(
            'INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)',
            [$rolId, CODIGO]
        );
        echo "Permiso concedido al rol {$rol['nombre']}.\n";
    } else {
        echo "El rol {$rol['nombre']} ya tenía el permiso.\n";
    }
}

echo "Migración completa.\n";
