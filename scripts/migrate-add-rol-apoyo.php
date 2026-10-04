<?php

declare(strict_types=1);

/**
 * Migración: rol «Apoyo» + permisos `kpis.excluido` y `asignaciones.excluir_auto` (v6.16; se armó como v6.15.2).
 *
 * Personal de otras áreas que limpia de vez en cuando y cuyo sueldo no depende del aseo (pedido de
 * Nicolás, 01/10/2026): trabaja igual que un Trabajador, pero no suma créditos, no entra en KPIs ni en
 * el bono de RRHH y no recibe piezas del reparto automático (solo asignación manual).
 *
 * 1. Siembra los dos permisos si faltan (en installs frescos los trae database/seeds/permisos.php).
 * 2. Crea el rol «Apoyo» (no de sistema) si no existe, con los permisos que Trabajador tiene HOY en
 *    esta base (pueden haberse editado desde Ajustes) menos `kpis.ver_propios`, más los dos nuevos.
 *    Si el rol ya existe no se tocan sus permisos, salvo asegurar los dos nuevos.
 * Ningún otro rol recibe estos permisos: RESTAN en vez de habilitar (RbacService::PERMISOS_QUE_RESTAN).
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

const ROL = 'Apoyo';
const PERMISOS_NUEVOS = [
    ['kpis.excluido', 'No suma créditos ni entra en los KPIs, Reportes ni el bono de aseo (personal de apoyo de otras áreas)', 'KPIs', 'propio'],
    ['asignaciones.excluir_auto', 'No recibe piezas del reparto automático; solo asignación manual (personal de apoyo)', 'Asignaciones', 'propio'],
];

// 1. Permisos (permisos.codigo es la PK; columna de alcance = scope)
foreach (PERMISOS_NUEVOS as [$codigo, $descripcion, $categoria, $scope]) {
    if (Database::fetchOne('SELECT codigo FROM #__permisos WHERE codigo = ?', [$codigo]) === null) {
        Database::execute(
            'INSERT INTO #__permisos (codigo, descripcion, categoria, scope) VALUES (?, ?, ?, ?)',
            [$codigo, $descripcion, $categoria, $scope]
        );
        echo "Permiso {$codigo} creado.\n";
    } else {
        echo "Permiso {$codigo} ya existía.\n";
    }
}

// 2. Rol
$rol = Database::fetchOne('SELECT id FROM #__roles WHERE nombre = ?', [ROL]);
if ($rol === null) {
    Database::execute(
        'INSERT INTO #__roles (nombre, descripcion, es_sistema) VALUES (?, ?, 0)',
        [ROL, 'Personal de otras áreas que apoya en limpieza (sin créditos ni KPIs)']
    );
    $rolId = Database::lastInsertId();

    $base = array_column(Database::fetchAll(
        'SELECT rp.permiso_codigo
           FROM #__rol_permisos rp
           JOIN #__roles r ON r.id = rp.rol_id
          WHERE r.nombre = ?',
        ['Trabajador']
    ), 'permiso_codigo');
    if ($base === []) {
        // Sin rol Trabajador en esta base: la lista del seed.
        foreach (require dirname(__DIR__) . '/database/seeds/roles.php' as $r) {
            if ($r['nombre'] === ROL) {
                $base = $r['permisos'];
            }
        }
        echo "No hay rol Trabajador: se usan los permisos del seed.\n";
    }
    $permisos = array_values(array_unique(array_merge(
        array_diff($base, ['kpis.ver_propios']),
        array_column(PERMISOS_NUEVOS, 0)
    )));
    echo "Rol " . ROL . " creado (id={$rolId}) con " . count($permisos) . " permisos.\n";
} else {
    $rolId = (int) $rol['id'];
    $permisos = array_column(PERMISOS_NUEVOS, 0);
    echo "Rol " . ROL . " ya existía (id={$rolId}): solo se aseguran los permisos nuevos.\n";
}

foreach ($permisos as $codigo) {
    if (Database::fetchOne('SELECT 1 FROM #__rol_permisos WHERE rol_id = ? AND permiso_codigo = ?', [$rolId, $codigo]) === null) {
        Database::execute('INSERT INTO #__rol_permisos (rol_id, permiso_codigo) VALUES (?, ?)', [$rolId, $codigo]);
    }
}

echo "Migración completa.\n";
